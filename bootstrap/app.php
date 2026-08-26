<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use OGame\Http\Middleware\Admin;
use OGame\Http\Middleware\CheckBanned;
use OGame\Http\Middleware\CheckFirstLogin;
use OGame\Http\Middleware\GlobalGame;
use OGame\Http\Middleware\Locale;
use OGame\Http\Middleware\ServerTiming;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', 'auth']],
    )
    ->withMiddleware(function (Middleware $middleware) {
        // The app runs behind an nginx reverse proxy in the Docker setup. Without
        // trusted proxies every request appears to come from the proxy's own
        // container IP, which silently breaks every IP-based decision in the app:
        // the login and two-factor rate limiters would collapse into a single
        // shared bucket, and THROTTLE_GAME_BY=ip would throttle the whole server
        // as one client. Defaults to the private ranges a container network uses;
        // override with TRUSTED_PROXIES when running behind an external LB.
        $middleware->trustProxies(at: array_map(
            trim(...),
            explode(',', (string) env('TRUSTED_PROXIES', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'))
        ));

        $middleware->prepend(ServerTiming::class);
        // Locale must be APPENDED (not prepended) to the web group so that it executes
        // after StartSession. Prepending would place it before StartSession, making
        // $request->hasSession() return false and breaking session-based locale reading.
        // Appending still guarantees the locale is set before any route-specific middleware
        // (auth, globalgame, firstlogin) runs.
        $middleware->web(append: [Locale::class]);
        $middleware->alias([
            'globalgame' => GlobalGame::class,
            'locale' => Locale::class,
            'admin' => Admin::class,
            'firstlogin' => CheckFirstLogin::class,
            'banned' => CheckBanned::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
    })
    ->create();
