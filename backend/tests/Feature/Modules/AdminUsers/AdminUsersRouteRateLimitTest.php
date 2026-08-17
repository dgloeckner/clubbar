<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\AdminUsers;

use App\Modules\Auth\Domain\SessionTimeout;
use Tests\Feature\HttpTestCase;

/**
 * POST /api/admin/admin-users carries the same step-up rate limiter as every
 * other sensitive admin mutation (#500) — a compromised or malicious session
 * could otherwise mass-create admin accounts with no throttling. This pins
 * the route wiring through the real stack, the way AuthRouteRateLimitTest
 * pins /api/auth/login and /api/auth/mfa.
 */
class AdminUsersRouteRateLimitTest extends HttpTestCase
{
    private const MAX_ATTEMPTS = 5;

    /** Seeded test credential (db/seed.sql): password123. */
    private const PASSWORD_HASH = '$2y$12$Pp5DqCBrNhBDThRmWYwPlegkBrYSDKxoGguH1K2XnUlVzQxoUPygG';

    private string $adminId;
    private string $adminEmail;
    private string $csrfToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminId = $this->uuid();
        $this->adminEmail = "admin-users-rate-{$this->adminId}@example.test";
        $this->db->prepare(
            'INSERT INTO admin_users (id, email, password_hash, display_name, locale, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())'
        )->execute([$this->adminId, $this->adminEmail, self::PASSWORD_HASH, 'Rate Limit Admin', 'de']);
        $this->grantRoles($this->adminId);

        // An authenticated admin session, set directly — this test is about the
        // rate limiter in front of the route, not the login flow.
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        $_SESSION['admin_user_id'] = $this->adminId;
        SessionTimeout::begin($_SESSION);
        $this->csrfToken = bin2hex(random_bytes(16));
        $_SESSION['csrf_token'] = $this->csrfToken;
    }

    protected function tearDown(): void
    {
        $this->db->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$this->adminEmail]);
        $this->db->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$this->adminId]);

        parent::tearDown();
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function csrf(): array
    {
        return ['X-CSRF-Token' => $this->csrfToken];
    }

    /** Fill an attempt window against the acting admin's own account. */
    private function recordAttempts(int $count, string $ip): void
    {
        $stmt = $this->db->prepare('INSERT INTO login_attempts (ip_address, email) VALUES (?, ?)');
        for ($i = 0; $i < $count; $i++) {
            $stmt->execute([$ip, $this->adminEmail]);
        }
    }

    public function test_the_admin_users_route_is_rate_limited(): void
    {
        // Documentation range (RFC 5737): never a real client, and distinct
        // from any IP another test in this run might be using — the block
        // here must come from the account dimension, not a shared IP.
        $suffix = bin2hex(random_bytes(4));
        $ip = '198.51.100.' . (hexdec(substr($suffix, 0, 2)) % 200 + 1);

        $this->recordAttempts(self::MAX_ATTEMPTS, $ip);

        // Before the fix this reached the controller and, with a valid body,
        // could mint another admin account.
        $response = $this->request(
            'POST',
            '/api/admin/admin-users',
            ['email' => 'new-admin@example.test', 'display_name' => 'New Admin', 'locale' => 'de', 'current_password' => 'password123'],
            ['REMOTE_ADDR' => $ip],
            $this->csrf(),
        );

        $this->assertSame(429, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('too_many_attempts', $body['error']);
        $this->assertSame(900, $body['retry_after_seconds']);
        $this->assertSame('900', $response->getHeaderLine('Retry-After'));
    }

    public function test_a_fresh_session_reaches_the_route_past_the_limiter(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $ip = '198.51.100.' . (hexdec(substr($suffix, 0, 2)) % 200 + 1);

        // No attempts recorded — the limiter must let this through to the
        // controller, which then fails validation on the empty body. A 429
        // here would mean the route is blocking everyone, not just abusers.
        $response = $this->request('POST', '/api/admin/admin-users', [], ['REMOTE_ADDR' => $ip], $this->csrf());

        $this->assertSame(422, $response->getStatusCode());
    }
}
