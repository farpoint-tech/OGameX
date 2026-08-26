<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Verify the "login" rate limiter balances three competing requirements:
 *
 *  1. Brute force against one account must be throttled.
 *  2. An attacker must not be able to lock a victim out of their own account
 *     by exhausting a bucket keyed on the victim's e-mail alone.
 *  3. An attacker must not be able to force unbounded bcrypt work by rotating
 *     the submitted e-mail address, since authentication always runs a hash
 *     comparison to keep the response time constant for unknown accounts.
 */
class LoginRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('login');
    }

    /**
     * Post a login attempt with deliberately wrong credentials.
     */
    private function attempt(string $email, string $ip = '203.0.113.10'): \Illuminate\Testing\TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/login', [
                'email' => $email,
                'password' => 'definitely-not-the-password',
            ]);
    }

    /**
     * Repeated failed attempts against one account from one source are
     * throttled after a handful of tries.
     */
    public function testBruteForceAgainstOneAccountIsThrottled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->attempt('victim@example.com')->assertStatus(302);
        }

        $this->attempt('victim@example.com')->assertStatus(429);
    }

    /**
     * An attacker must NOT be able to lock a victim out of their own account.
     * After the attacker exhausts the per-account bucket from their own IP,
     * the victim logging in from a different IP must still be served.
     */
    public function testAttackerCannotLockVictimOutFromAnotherIp(): void
    {
        // Attacker burns the victim's per-account budget from their own IP.
        for ($i = 0; $i < 8; $i++) {
            $this->attempt('victim@example.com', '198.51.100.7');
        }

        // The victim, on a different IP, must not be locked out.
        $this->attempt('victim@example.com', '203.0.113.99')->assertStatus(302);
    }

    /**
     * Rotating the submitted e-mail must not grant a fresh budget: every login
     * attempt costs a full bcrypt comparison, so an unbounded number of them
     * from one source is a CPU exhaustion vector. A per-IP bucket must stop it
     * even though every single attempt uses a brand new e-mail address.
     */
    public function testRotatingEmailDoesNotBypassTheLimit(): void
    {
        $limited = false;

        for ($i = 0; $i < 40; $i++) {
            $response = $this->attempt("nonexistent-{$i}@example.com", '198.51.100.42');

            if ($response->getStatusCode() === 429) {
                $limited = true;
                break;
            }
        }

        $this->assertTrue(
            $limited,
            'Rotating the e-mail address bypassed the login rate limit: an attacker '
            . 'could force unbounded bcrypt computations from a single IP.'
        );
    }

    /**
     * The per-IP bucket must not be global: exhausting it from one address
     * must leave a different address unaffected.
     */
    public function testPerIpLimitDoesNotAffectOtherAddresses(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $this->attempt("nonexistent-{$i}@example.com", '198.51.100.42');
        }

        $this->attempt('someone@example.com', '203.0.113.200')->assertStatus(302);
    }
}
