<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Auth;

use App\Shared\Config\AppConfig;
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
    private const ADMIN_EMAIL = 'admin@example.com';
    private const ADMIN_PASSWORD = 'password123';

    private string $ip;

    protected function setUp(): void
    {
        parent::setUp();

        // A fresh IP per run so this test's request is never itself rate-limited,
        // and clearing this seeded account's attempts keeps it that way even
        // when another suite (e.g. the E2E login-failure specs) ran against the
        // same database minutes earlier and left rows in the account's window.
        $this->ip = '198.51.100.' . (hexdec(bin2hex(random_bytes(1))) % 200 + 1);
        $this->db->prepare('DELETE FROM login_attempts WHERE ip_address = ? OR email = ?')
            ->execute([$this->ip, self::ADMIN_EMAIL]);
    }

    protected function tearDown(): void
    {
        $this->db->prepare('DELETE FROM login_attempts WHERE ip_address = ? OR email = ?')
            ->execute([$this->ip, self::ADMIN_EMAIL]);
        $_SESSION = [];
        parent::tearDown();
    }

    public function test_login_starts_a_session_under_the_configured_cookie_name(): void
    {
        $this->assertSame(PHP_SESSION_NONE, session_status(), 'no session must be active before the request');

        $response = $this->request(
            'POST',
            '/api/auth/login',
            ['email' => self::ADMIN_EMAIL, 'password' => self::ADMIN_PASSWORD],
            ['REMOTE_ADDR' => $this->ip],
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['requiresMfa' => true], $this->decode($response));

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
