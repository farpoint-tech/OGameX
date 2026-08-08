<?php

namespace OGame\Exceptions;

use Exception;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Spatie\DiscordAlerts\Facades\DiscordAlert;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * Taps into the render function allowing us to send to discord.
     *
     * @param  Request  $request
     *
     * @throws Throwable
     */
    public function render($request, Exception|Throwable $e): Response
    {
        $this->sendToDiscord($e);

        return parent::render($request, $e);
    }

    /**
     * Queue a sanitized Discord alert to be sent *after* the response has been
     * delivered to the client. This keeps the (potentially slow) HTTP call to
     * Discord out of the request/error-render path so it can never block or
     * slow down the response the user receives.
     */
    protected function sendToDiscord(Throwable $e): void
    {
        // No webhook configured or exception is not reportable -> nothing to do.
        if (!config('app.discord_alert_webhook') || !$this->shouldReport($e)) {
            return;
        }

        // In production we only alert when explicitly opted-in. This avoids
        // leaking any diagnostic information to Discord by default on prod.
        if (app()->isProduction() && !config('app.send_discord_in_production')) {
            return;
        }

        // Defer the actual delivery until after the response is sent. We only
        // capture the exception here; all sanitization + the network call
        // happen in sendToDiscordAsync().
        dispatch(function () use ($e): void {
            $this->sendToDiscordAsync($e);
        })->afterResponse();
    }

    /**
     * Actually deliver the alert to Discord. Runs after the response has been
     * flushed. Wrapped in a try/catch so that a Discord/network failure can
     * never bubble up and break the error handling flow.
     */
    protected function sendToDiscordAsync(Throwable $e): void
    {
        try {
            DiscordAlert::message($this->buildDiscordMessage($e));
        } catch (Throwable $discordError) {
            // Swallow the failure but log it so we still have visibility into
            // broken Discord alerting without affecting the original request.
            Log::error('Failed to send exception alert to Discord', [
                'discord_error' => $discordError->getMessage(),
                'original_exception' => get_class($e),
            ]);
        }
    }

    /**
     * Build the (sanitized) Discord message body.
     *
     * Development: include a sanitized stack trace to aid debugging.
     * Production:  only the sanitized message + line number, no file paths
     *              and no stack trace to minimize information disclosure.
     */
    protected function buildDiscordMessage(Throwable $e): string
    {
        $sanitized = $this->sanitizeException($e);

        if (app()->environment('local', 'development', 'testing')) {
            $body = sprintf(
                "\nMessage: %s\nFile: %s (Line: %d)\nStack Trace:\n%s\n",
                $sanitized['message'],
                $sanitized['file'],
                $sanitized['line'],
                $sanitized['trace']
            );
        } else {
            $body = sprintf(
                "\nMessage: %s\nLine: %d\n",
                $sanitized['message'],
                $sanitized['line']
            );
        }

        return sprintf(
            "🚨 **Exception in %s environment**\n```%s```",
            app()->environment(),
            $body
        );
    }

    /**
     * Turn an exception into a fully sanitized set of fields safe to transmit.
     *
     * @return array{message: string, file: string, line: int, trace: string}
     */
    protected function sanitizeException(Throwable $e): array
    {
        // Limit the trace to the first few frames to keep the message short and
        // to avoid dumping the entire (potentially sensitive) call stack.
        $trace = implode("\n", array_slice(explode("\n", $e->getTraceAsString()), 0, 5));

        return [
            'message' => $this->sanitizeMessage($e->getMessage()),
            'file' => $this->sanitizePath($e->getFile()),
            'line' => $e->getLine(),
            'trace' => $this->sanitizeMessage($this->sanitizePath($trace)),
        ];
    }

    /**
     * Redact sensitive data (credentials, API keys, tokens) from a string and
     * relativize any absolute file paths it may contain.
     */
    protected function sanitizeMessage(string $message): string
    {
        $patterns = [
            // Credentials embedded in DSN / connection URLs: scheme://user:pass@host
            '/\b([a-z][a-z0-9+.\-]*):\/\/([^:\/\s@]+):[^@\s]+@/i' => '$1://$2:[REDACTED]@',

            // key=value / key: value style secrets (password, secret, api_key, token, ...)
            '/\b(passwords?|passwd|pwd|secret|api[_-]?key|apikey|access[_-]?token|auth[_-]?token|refresh[_-]?token|token|client[_-]?secret|private[_-]?key|db[_-]?password)\b\s*[:=]\s*[\'"]?[^\s\'"&,;)}\]]+/i' => '$1=[REDACTED]',

            // Well-known API key / token prefixes (Stripe, OpenAI, GitHub, Slack, ...)
            '/\b(sk|pk|rk|xox[baprs]|ghp|gho|ghu|ghs|ghr)[_-][A-Za-z0-9_\-]{10,}/' => '[REDACTED_KEY]',

            // AWS access key IDs: AKIA/ASIA followed directly by 16 uppercase alphanumerics.
            '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/' => '[REDACTED_KEY]',

            // Bearer tokens in Authorization headers.
            '/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/i' => 'Bearer [REDACTED]',
        ];

        $sanitized = preg_replace(array_keys($patterns), array_values($patterns), $message);

        // preg_replace returns null on failure; fall back to the original.
        return $this->sanitizePath($sanitized ?? $message);
    }

    /**
     * Strip the application base path from a string so only relative paths are
     * ever exposed (avoids leaking the server's absolute directory layout).
     */
    protected function sanitizePath(string $value): string
    {
        $basePath = base_path();

        // Remove "<base>/" first so the leading separator is dropped too, then
        // handle any remaining bare occurrences of the base path.
        return str_replace(
            [$basePath . DIRECTORY_SEPARATOR, $basePath],
            '',
            $value
        );
    }
}
