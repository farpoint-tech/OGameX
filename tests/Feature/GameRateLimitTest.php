<?php

namespace Tests\Feature;

use Tests\AccountTestCase;

/**
 * Verify that the "throttle:game" rate limiter protects game endpoints
 * against bots and DoS, is configurable, and exempts admin users.
 */
class GameRateLimitTest extends AccountTestCase
{
    /**
     * Verify that normal requests below the limit pass through and include
     * rate limit headers.
     */
    public function testRequestsBelowLimitPassThrough(): void
    {
        // Move past any requests recorded during account setup.
        $this->travel(2)->minutes();

        $response = $this->get('/overview');
        $response->assertStatus(200);

        // Rate limit headers should be present with the configured limit.
        $response->assertHeader('X-RateLimit-Limit', (string)config('throttle.game_per_minute'));
        $this->assertNotNull($response->headers->get('X-RateLimit-Remaining'));
    }

    /**
     * Verify that exceeding the configured limit returns 429 Too Many Requests
     * and that the limit resets after the decay window.
     */
    public function testExceedingLimitReturns429(): void
    {
        // Lower the limit so the test doesn't need 120+ requests.
        config(['throttle.game_per_minute' => 3]);

        // Move past any requests recorded during account setup.
        $this->travel(2)->minutes();

        // First 3 requests should pass.
        for ($i = 0; $i < 3; $i++) {
            $this->get('/overview')->assertStatus(200);
        }

        // 4th request should be rate limited.
        $response = $this->get('/overview');
        $response->assertStatus(429);
        $this->assertNotNull($response->headers->get('Retry-After'));

        // After the decay window the limit should reset.
        $this->travel(2)->minutes();
        $this->get('/overview')->assertStatus(200);
    }

    /**
     * Verify that different game endpoints share the same "game" rate limit
     * bucket (per user), so bots cannot spread load across endpoints.
     */
    public function testGameEndpointsShareSameBucket(): void
    {
        config(['throttle.game_per_minute' => 3]);
        $this->travel(2)->minutes();

        $this->get('/overview')->assertStatus(200);
        $this->get('/galaxy')->assertStatus(200);
        $this->get('/fleet')->assertStatus(200);

        // Bucket exhausted: a different game endpoint must also be limited.
        $this->get('/highscore')->assertStatus(429);
    }

    /**
     * Verify that admin users are exempt from the game rate limit.
     */
    public function testAdminUsersAreExemptFromRateLimit(): void
    {
        config(['throttle.game_per_minute' => 3]);
        $this->travel(2)->minutes();

        // Assign the admin role to the current user.
        $this->artisan('ogamex:admin:assign-role', ['username' => auth()->user()->username]);

        // Well above the limit of 3: all requests should pass.
        for ($i = 0; $i < 6; $i++) {
            $this->get('/overview')->assertStatus(200);
        }
    }

    /**
     * Verify that admin routes are not rate limited at all (no throttle
     * middleware attached), independent of the limiter exemption.
     */
    public function testAdminRoutesAreNotRateLimited(): void
    {
        config(['throttle.game_per_minute' => 3]);
        $this->travel(2)->minutes();

        $this->artisan('ogamex:admin:assign-role', ['username' => auth()->user()->username]);

        for ($i = 0; $i < 6; $i++) {
            $response = $this->get('/admin/server-settings');
            $response->assertStatus(200);
            // No throttle middleware: rate limit headers must be absent.
            $this->assertNull($response->headers->get('X-RateLimit-Limit'));
        }
    }
}
