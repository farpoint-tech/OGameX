<?php

namespace OGame\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use OGame\Actions\Fortify\CreateNewUser;
use OGame\Actions\Fortify\ResetUserPassword;
use OGame\Actions\Fortify\UpdateUserPassword;
use OGame\Actions\Fortify\UpdateUserProfileInformation;
use OGame\Models\User;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        RateLimiter::for('login', function (Request $request) {
            // Key the limit by email+IP. Deliberately NOT keyed by email alone:
            // an email-only bucket would let an unauthenticated attacker lock an
            // arbitrary account out of login entirely by exhausting the victim's
            // bucket from any IP (targeted denial of service).
            $email = Str::transliterate(Str::lower((string) $request->input(Fortify::username())));

            return Limit::perMinute(5)->by($email . '|' . $request->ip());
        });

        RateLimiter::for('two-factor', function (Request $request) {
            // Fall back to the client IP when the session has no login.id yet:
            // with a null key all such requests would share one global bucket,
            // letting a single client 429 every user on the 2FA challenge.
            return Limit::perMinute(5)->by($request->session()->get('login.id') ?? $request->ip());
        });

        Fortify::loginView(function () {
            return view('outgame.login');
        });

        Fortify::authenticateUsing(function (Request $request) {
            $user = User::where('email', $request->email)->first();

            // Constant-time authentication: ALWAYS run a bcrypt hash comparison,
            // even when the user does not exist, so response timing does not
            // reveal whether an email is registered (user enumeration / timing
            // attack protection). When there is no user we hash-check against a
            // fixed dummy bcrypt hash which can never match.
            $dummyHash = '$2y$12$usdvIvVELErVXqBoADgxSuWNb3JXpVVVJGw3EKW.iC.8UvUtGyxJK';
            $passwordCorrect = Hash::check(
                $request->password,
                $user?->password ?? $dummyHash
            );

            // Log every attempt (success + failure) to the security channel.
            Log::channel('security')->info('Login attempt', [
                'email' => $request->input(Fortify::username()),
                'ip' => $request->ip(),
                'success' => $user && $passwordCorrect,
            ]);

            if (!$user || !$passwordCorrect) {
                return;
            }

            if ($user->isBanned()) {
                $ban   = $user->currentBan();
                $until = $ban?->banned_until
                    ? $ban->banned_until->format('Y-m-d H:i') . ' UTC'
                    : 'permanently';

                throw ValidationException::withMessages([
                    'email' => ["Your account has been banned: {$ban?->reason}. Expires: {$until}."],
                ]);
            }

            return $user;
        });

        /*Fortify::registerView(function () {
            return view('auth.register');
        });

        Fortify::requestPasswordResetLinkView(function () {
            return view('auth.forgot-password');
        });

        Fortify::resetPasswordView(function () {
            return view('auth.reset-password');
        });*/
    }
}
