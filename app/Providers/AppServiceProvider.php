<?php

namespace OGame\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use OGame\Exceptions\Handler;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\User;
use OGame\Observers\UserObserver;
use OGame\Services\SettingsService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    final public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Legacy JS bundles are plain concatenated scripts that rely on jQuery globals
        // and must not be treated as ES modules.
        Vite::useScriptTagAttributes(['type' => false]);

        // Register composer file for the main ingame layout.
        view()->composer('ingame.layouts.main', 'OGame\Http\ViewComposers\IngameMainComposer');

        // Register model observers
        User::observe(UserObserver::class);

        $this->configureRateLimiting();
    }

    /**
     * Configure the rate limiters for the application.
     *
     * The "game" limiter protects expensive game endpoints (galaxy, fleet,
     * espionage, phalanx, etc.) against bots and DoS abuse. It is applied
     * via the "throttle:game" middleware in routes/web.php.
     *
     * Configurable via .env:
     * - THROTTLE_GAME_PER_MINUTE (default: 120)
     * - THROTTLE_GAME_BY: "user" (default) or "ip"
     *
     * @return void
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('game', function (Request $request) {
            $user = $request->user();

            // Admins/developers are exempt from game rate limiting.
            if ($user !== null && $user->hasRole('admin')) {
                return Limit::none();
            }

            // Determine throttle key: per user id (fallback to IP when
            // unauthenticated) or strictly per IP, depending on config.
            // Defaults live in config/throttle.php only, to avoid drift.
            $key = config('throttle.game_by') === 'ip'
                ? $request->ip()
                : ($user?->id ?: $request->ip());

            return Limit::perMinute((int)config('throttle.game_per_minute'))->by($key);
        });
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    final public function register(): void
    {
        $this->app->singleton(function ($app): SettingsService {
            return new SettingsService();
        });

        $this->app->singleton(function ($app): PlayerServiceFactory {
            return new PlayerServiceFactory();
        });

        $this->app->singleton(function ($app): PlanetServiceFactory {
            return new PlanetServiceFactory(
                $app->make(SettingsService::class),
                $app->make(PlayerServiceFactory::class)
            );
        });

        $this->app->singleton(ExceptionHandler::class, Handler::class);
    }
}
