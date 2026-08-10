<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Auth;

use App\Shared\Config\AppConfig;
use App\Shared\Utils\Uuid;
use Tests\Feature\HttpTestCase;

/**
 * The session name actually reaching `session_name()` (issue #251).
 *
 * `AuthController::login()`/`mfa()` and `ServiceFactory::getMfaRateLimitMiddleware()`
 * each guard their `session_name($config->sessionCookieName)` call with
 * `session_status() === PHP_SESSION_NONE` — unit tests deliberately pre-start a
 * session before calling the controller (see `AuthControllerMfaTest`) precisely
 * to skip that branch and isolate the rest of the method. These tests exercise
 * it through the real route wiring instead, with no session pre-started.
 */
class SessionCookieNameTest extends HttpTestCase
{
    private const ADMIN_PASSWORD = 'test-password-123';

    private string $ip;
    private string $adminId;
    private string $adminEmail;

    protected function setUp(): void
    {
        parent::setUp();

        // Own admin row rather than the seeded one (Pattern 001): the CI job
        // that runs this suite only migrates the schema, it does not run
        // db/seed.sql, so admin@example.com does not exist there at all.
        $this->adminId = Uuid::v4();
        $this->adminEmail = 'session-cookie-name-' . bin2hex(random_bytes(4)) . '@example.test';
        $this->db->prepare('INSERT INTO admin_users (id, email, password_hash, is_active) VALUES (?, ?, ?, 1)')
            ->execute([$this->adminId, $this->adminEmail, password_hash(self::ADMIN_PASSWORD, PASSWORD_BCRYPT)]);

        // A fresh IP per run so this test's request is never itself rate-limited.
        $this->ip = '198.51.100.' . (hexdec(bin2hex(random_bytes(1))) % 200 + 1);
        $this->db->prepare('DELETE FROM login_attempts WHERE ip_address = ? OR email = ?')
            ->execute([$this->ip, $this->adminEmail]);
    }

    protected function tearDown(): void
    {
        $this->db->prepare('DELETE FROM login_attempts WHERE ip_address = ? OR email = ?')
            ->execute([$this->ip, $this->adminEmail]);
        $this->db->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$this->adminId]);
        $_SESSION = [];
        parent::tearDown();
    }

    public function test_login_starts_a_session_under_the_configured_cookie_name(): void
    {
        $this->assertSame(PHP_SESSION_NONE, session_status(), 'no session must be active before the request');

        $response = $this->request(
            'POST',
            '/api/auth/login',
            ['email' => $this->adminEmail, 'password' => self::ADMIN_PASSWORD],
            ['REMOTE_ADDR' => $this->ip],
        );

        // Fresh admin, TOTP never enrolled — login() takes the "setup required"
        // branch, but that split happens after the session is already started.
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(true, $this->decode($response)['requiresTotpSetup'] ?? null);

        // PHP's session cookie travels through header()/setcookie(), which the
        // in-process PSR-7 dispatch used here does not capture — session_name()
        // itself is the process-global state login() just set, and is what E2E's
        // admin-auth.spec.ts observes as the actual Set-Cookie name over HTTP.
        $this->assertSame(
            (new AppConfig())->sessionCookieName,
            session_name(),
            'the session started by login() must carry the name AppConfig derives'
        );
    }

    /**
     * The MFA route's rate limiter resolves the account from the MFA-pending
     * session (#78) — reached only when the IP dimension does not already
     * short-circuit the middleware, which is what a fresh IP guarantees here.
     */
    public function test_mfa_rate_limiter_starts_its_own_session_to_read_the_pending_account(): void
    {
        $this->assertSame(PHP_SESSION_NONE, session_status(), 'no session must be active before the request');

        $response = $this->request(
            'POST',
            '/api/auth/mfa',
            ['code' => '000000'],
            ['REMOTE_ADDR' => $this->ip],
        );

        // No MFA-pending session exists yet — the request reaches the controller,
        // which is what proves the middleware did not block it.
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('mfa_session_expired', $this->decode($response)['error']);
    }
}
