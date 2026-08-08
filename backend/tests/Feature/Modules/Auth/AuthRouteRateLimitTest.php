<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Auth;

use Tests\Feature\HttpTestCase;

/**
 * The rate limiter, as the routes actually mount it (#78, ruling #145).
 *
 * `/api/auth/login` was wrapped in `RateLimitMiddleware`; `/api/auth/mfa` on the
 * very next line carried no middleware at all. No unit test could see that, and
 * the E2E suite cannot vary the source IP from a single host — so the account
 * dimension and the route wiring are both pinned here, through the real stack.
 */
class AuthRouteRateLimitTest extends HttpTestCase
{
    private const MAX_ATTEMPTS = 5;

    private string $ip;
    private string $email;

    protected function setUp(): void
    {
        parent::setUp();

        // Documentation ranges (RFC 5737): never a real client, and unique per run.
        $suffix = bin2hex(random_bytes(4));
        $this->ip = '198.51.100.' . (hexdec(substr($suffix, 0, 2)) % 200 + 1);
        $this->email = "route-{$suffix}@example.test";

        $this->db->prepare('DELETE FROM login_attempts WHERE ip_address = ? OR email = ?')
            ->execute([$this->ip, $this->email]);

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
        }
    }

    protected function tearDown(): void
    {
        $this->db->prepare('DELETE FROM login_attempts WHERE ip_address = ? OR email = ?')
            ->execute([$this->ip, $this->email]);
        $_SESSION = [];
        parent::tearDown();
    }

    /** Fill an attempt window. `$ip` defaults to this test's IP. */
    private function recordAttempts(int $count, ?string $ip = null, ?string $email = null): void
    {
        $stmt = $this->db->prepare('INSERT INTO login_attempts (ip_address, email) VALUES (?, ?)');
        for ($i = 0; $i < $count; $i++) {
            $stmt->execute([$ip ?? $this->ip, $email ?? $this->email]);
        }
    }

    public function test_the_mfa_route_is_rate_limited(): void
    {
        $this->recordAttempts(self::MAX_ATTEMPTS);

        // Before the fix this reached the controller and accepted another guess.
        $response = $this->request('POST', '/api/auth/mfa', ['code' => '000000'], ['REMOTE_ADDR' => $this->ip]);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('too_many_attempts', $this->decode($response)['error']);
        $this->assertSame('900', $response->getHeaderLine('Retry-After'));
    }

    public function test_the_login_route_is_rate_limited(): void
    {
        $this->recordAttempts(self::MAX_ATTEMPTS);

        $response = $this->request(
            'POST',
            '/api/auth/login',
            ['email' => $this->email, 'password' => 'wrong'],
            ['REMOTE_ADDR' => $this->ip],
        );

        $this->assertSame(429, $response->getStatusCode());
    }

    public function test_one_account_is_protected_across_many_source_addresses(): void
    {
        // A distributed attack on one admin: every attempt from a different host,
        // so the IP dimension never fires. This is what the per-account counter is for.
        for ($i = 1; $i <= self::MAX_ATTEMPTS; $i++) {
            $this->recordAttempts(1, ip: "198.51.100.2{$i}");
        }

        $response = $this->request(
            'POST',
            '/api/auth/login',
            ['email' => $this->email, 'password' => 'wrong'],
            ['REMOTE_ADDR' => '198.51.100.99'],
        );

        $this->assertSame(429, $response->getStatusCode());

        $this->db->prepare("DELETE FROM login_attempts WHERE email = ?")->execute([$this->email]);
    }

    public function test_the_mfa_route_counts_the_account_named_by_the_pending_session(): void
    {
        // The MFA body carries only a code, so the account comes from the session
        // the password step wrote. Without it the second factor would be counted
        // per IP alone — and a NAT-shared club WiFi is one IP for everyone.
        for ($i = 1; $i <= self::MAX_ATTEMPTS; $i++) {
            $this->recordAttempts(1, ip: "198.51.100.3{$i}");
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['mfa_pending_email'] = $this->email;

        $response = $this->request('POST', '/api/auth/mfa', ['code' => '000000'], ['REMOTE_ADDR' => '198.51.100.98']);

        $this->assertSame(429, $response->getStatusCode());

        $this->db->prepare("DELETE FROM login_attempts WHERE email = ?")->execute([$this->email]);
    }

    public function test_an_exhausted_window_does_not_block_unrelated_routes(): void
    {
        $this->recordAttempts(self::MAX_ATTEMPTS);

        // The limiter is mounted per route, not globally: a locked-out admin's
        // host must not take the terminals or the health check down with it.
        $response = $this->request('GET', '/api/health', server: ['REMOTE_ADDR' => $this->ip]);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_a_fresh_address_and_account_are_let_through(): void
    {
        $response = $this->request(
            'POST',
            '/api/auth/login',
            ['email' => $this->email, 'password' => 'wrong'],
            ['REMOTE_ADDR' => $this->ip],
        );

        // Wrong password, but not rate-limited — and the attempt is now on record.
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('invalid_credentials', $this->decode($response)['error']);

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND email = ?');
        $stmt->execute([$this->ip, $this->email]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }
}
