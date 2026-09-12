<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Auth;

use App\Modules\AdminUsers\Enums\AdminRole;
use App\Modules\Auth\Domain\SessionTimeout;
use App\Shared\Utils\Uuid;
use Tests\Feature\HttpTestCase;

/**
 * `POST /api/auth/logout` through the real route (#887).
 *
 * `logout()` used to call bare `session_destroy()`, which left `$_SESSION`
 * populated and never told the browser to drop its cookie — a gap masked
 * only by `session.use_strict_mode` refusing to adopt the dead id. It now
 * shares `BrowserSession::endIfPresent()` with `InvitationController`, which
 * this drives through the real stack rather than by calling the controller
 * method directly.
 */
class LogoutHttpTest extends HttpTestCase
{
    private const PASSWORD_HASH = '$2y$12$Pp5DqCBrNhBDThRmWYwPlegkBrYSDKxoGguH1K2XnUlVzQxoUPygG';

    /** @var list<string> */
    private array $createdAdmins = [];

    protected function tearDown(): void
    {
        foreach ($this->createdAdmins as $id) {
            $this->db->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
        }
        $this->createdAdmins = [];

        parent::tearDown();
    }

    private function signIn(): string
    {
        $id = Uuid::v4();
        $this->createdAdmins[] = $id;

        $this->db->prepare(
            'INSERT INTO admin_users (id, email, password_hash, display_name, locale, is_active, totp_enabled, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, 0, NOW(), NOW())'
        )->execute([$id, "logout-http-{$id}@example.test", self::PASSWORD_HASH, 'Logout Test', 'de']);

        $this->db->prepare('INSERT INTO admin_user_roles (admin_user_id, role) VALUES (?, ?)')
            ->execute([$id, AdminRole::ADMIN->value]);

        if (session_status() !== \PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        $_SESSION['admin_user_id'] = $id;
        SessionTimeout::begin($_SESSION);
        $csrfToken = bin2hex(random_bytes(16));
        $_SESSION['csrf_token'] = $csrfToken;

        return $csrfToken;
    }

    public function test_logout_ends_the_session_rather_than_leaving_it_populated(): void
    {
        $csrfToken = $this->signIn();

        $response = $this->request('POST', '/api/auth/logout', headers: ['X-CSRF-Token' => $csrfToken]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Logout successful', $this->decode($response)['message']);

        // The gap #887 found: session_destroy() alone leaves $_SESSION as PHP
        // last populated it, in the same request that just destroyed the
        // session file. BrowserSession::endIfPresent() clears it explicitly.
        $this->assertSame([], $_SESSION, 'logout must clear $_SESSION, not just destroy the session file');
    }

    public function test_a_request_without_a_session_still_answers_unauthenticated(): void
    {
        if (session_status() === \PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_write_close();
        }

        $response = $this->request('POST', '/api/auth/logout');

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('admin_not_authenticated', $this->decode($response)['error']);
    }
}
