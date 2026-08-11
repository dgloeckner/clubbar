<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Shared\Utils\Uuid;
use Tests\Feature\HttpTestCase;

/**
 * `Cache-Control: no-store` on authenticated responses (#341).
 *
 * Set by `AdminSessionAuth`/`TerminalTokenAuth` on the response they return
 * from `$handler->handle()`, not by `SecurityHeaders` — the header must cover
 * exactly the authenticated surface, so a public/cacheable route like the
 * health check stays untouched.
 */
class CacheControlHeadersTest extends HttpTestCase
{
    private string $adminId;
    private string $adminEmail;

    protected function setUp(): void
    {
        parent::setUp();

        // Own admin row rather than the seeded one (Pattern 001): the CI job
        // that runs this suite only migrates the schema, it does not run
        // db/seed.sql.
        $this->adminId = Uuid::v4();
        $this->adminEmail = 'cache-control-' . bin2hex(random_bytes(4)) . '@example.test';
        $this->db->prepare('INSERT INTO admin_users (id, email, password_hash, is_active) VALUES (?, ?, ?, 1)')
            ->execute([$this->adminId, $this->adminEmail, password_hash('irrelevant', PASSWORD_BCRYPT)]);
    }

    protected function tearDown(): void
    {
        $this->db->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$this->adminId]);
        $_SESSION = [];
        parent::tearDown();
    }

    /**
     * Bypasses the login flow: the middleware only reads `$_SESSION['admin_user_id']`,
     * so a directly-seeded session exercises the same code path AdminSessionAuthTest
     * uses, through the full stack this time.
     */
    private function authenticateAsAdmin(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = ['admin_user_id' => $this->adminId];
    }

    public function test_an_authenticated_admin_response_carries_no_store(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->request('GET', '/api/auth/profile');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('no-cache', $response->getHeaderLine('Pragma'));
    }

    public function test_the_public_health_route_is_unaffected(): void
    {
        $response = $this->request('GET', '/api/health');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('', $response->getHeaderLine('Pragma'));
    }

    public function test_an_unauthenticated_admin_request_carries_no_such_header(): void
    {
        // The 401 is built before $handler->handle() ever runs — option A only
        // covers the path that reaches the protected handler.
        $response = $this->request('GET', '/api/admin/members');

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('', $response->getHeaderLine('Cache-Control'));
    }
}
