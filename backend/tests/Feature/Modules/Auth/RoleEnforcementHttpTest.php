<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Auth;

use App\Modules\AdminUsers\Enums\AdminRole;
use App\Modules\Auth\Domain\SessionTimeout;
use App\Shared\Utils\Uuid;
use Tests\Feature\HttpTestCase;

/**
 * The route→roles map enforced through the real stack (ADR-0044, #519).
 *
 * `RouteRoleMapTest` asserts what the table says and
 * `RouteRoleMapCompletenessTest` asserts that it covers every route; neither
 * proves a request is actually refused. This drives real sessions through
 * `AdminSessionAuth` and reads the status code, which is the only place the
 * security property is real.
 *
 * A refusal is asserted as **403 with `insufficient_role`**, never merely as
 * "not 200": a 404 from a missing fixture or a 422 from validation would
 * otherwise read as a successful denial and the suite would stay green with
 * enforcement removed.
 */
class RoleEnforcementHttpTest extends HttpTestCase
{
    private const PASSWORD_HASH = '$2y$12$Pp5DqCBrNhBDThRmWYwPlegkBrYSDKxoGguH1K2XnUlVzQxoUPygG';

    /** @var list<string> */
    private array $createdAdmins = [];

    private string $csrfToken = '';

    protected function tearDown(): void
    {
        foreach ($this->createdAdmins as $id) {
            $this->db->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
        }
        $this->createdAdmins = [];

        parent::tearDown();
    }

    // ── the treasurer ────────────────────────────────────────────────────

    public function test_a_kassenwart_reaches_the_treasury_surfaces(): void
    {
        $this->signInHolding(AdminRole::KASSENWART);

        foreach ([
            '/api/admin/dashboard',
            '/api/admin/members',
            '/api/admin/transactions',
            '/api/admin/settlements',
            '/api/admin/sepa-config',
            '/api/admin/notifications',
        ] as $path) {
            $this->assertNotRefused('GET', $path);
        }
    }

    public function test_a_kassenwart_cannot_reach_account_key_terminal_or_mail_management(): void
    {
        $this->signInHolding(AdminRole::KASSENWART);

        foreach ([
            '/api/admin/admin-users',
            '/api/admin/audit-log',
            '/api/admin/encryption-keys',
            '/api/admin/terminals',
            '/api/admin/mail-config',
            '/api/admin/security-check',
            '/api/admin/scheduler',
        ] as $path) {
            $this->assertRefused('GET', $path);
        }
    }

    /**
     * The boundary inside the member module. Reading and editing are the
     * office; erasing and exporting one named person are not.
     */
    public function test_a_kassenwart_cannot_erase_or_export_a_member(): void
    {
        $this->signInHolding(AdminRole::KASSENWART);
        $memberId = Uuid::v4();

        $this->assertRefused('DELETE', "/api/admin/members/{$memberId}");
        $this->assertRefused('POST', "/api/admin/members/{$memberId}/anonymize");
        $this->assertRefused('POST', "/api/admin/members/{$memberId}/export");
    }

    /** Same path, different verb, different grant. */
    public function test_a_kassenwart_reads_sepa_config_but_cannot_write_it(): void
    {
        $this->signInHolding(AdminRole::KASSENWART);

        $this->assertNotRefused('GET', '/api/admin/sepa-config');
        $this->assertRefused('PATCH', '/api/admin/sepa-config');
    }

    // ── the bar ──────────────────────────────────────────────────────────

    public function test_a_getraenkewart_reaches_the_drinks_list(): void
    {
        $this->signInHolding(AdminRole::GETRAENKEWART);

        $this->assertNotRefused('GET', '/api/admin/products');
        $this->assertNotRefused('GET', '/api/admin/categories');
        $this->assertNotRefused('GET', '/api/admin/reports/terminal-activity');
    }

    public function test_a_getraenkewart_reaches_no_member_or_money_surface(): void
    {
        $this->signInHolding(AdminRole::GETRAENKEWART);

        foreach ([
            '/api/admin/dashboard',
            '/api/admin/statistics/monthly',
            '/api/admin/members',
            '/api/admin/settlements',
            '/api/admin/transactions',
            '/api/admin/sepa-config',
            '/api/admin/bank-lookup',
        ] as $path) {
            $this->assertRefused('GET', $path);
        }
    }

    // ── composition, and the root ────────────────────────────────────────

    /**
     * The reason roles are a relation. One person covering both offices holds
     * the union of them — and still not `admin`.
     */
    public function test_holding_both_lesser_roles_grants_both_and_not_admin(): void
    {
        $this->signInHolding(AdminRole::KASSENWART, AdminRole::GETRAENKEWART);

        $this->assertNotRefused('GET', '/api/admin/members');
        $this->assertNotRefused('GET', '/api/admin/products');
        $this->assertRefused('GET', '/api/admin/admin-users');
    }

    public function test_an_admin_reaches_everything_it_reached_before(): void
    {
        $this->signInHolding(AdminRole::ADMIN);

        foreach ([
            '/api/admin/dashboard',
            '/api/admin/members',
            '/api/admin/products',
            '/api/admin/settlements',
            '/api/admin/admin-users',
            '/api/admin/audit-log',
            '/api/admin/encryption-keys',
            '/api/admin/terminals',
            '/api/admin/mail-config',
            '/api/admin/notifications',
            '/api/admin/scheduler',
        ] as $path) {
            $this->assertNotRefused('GET', $path);
        }
    }

    /**
     * Fail closed. An account holding nothing is refused everywhere, including
     * its own profile — and the 403 it gets there is what the SPA renders as
     * the `insufficient_role` screen (#516), so the refusal is legible rather
     * than a blank panel.
     */
    public function test_an_account_holding_no_role_is_refused_everywhere(): void
    {
        $this->signInHolding();

        $this->assertRefused('GET', '/api/admin/products');
        $this->assertRefused('GET', '/api/auth/profile');
    }

    /** Own account, every office. */
    public function test_every_role_reads_its_own_profile(): void
    {
        foreach (AdminRole::cases() as $role) {
            $this->signInHolding($role);

            $this->assertNotRefused('GET', '/api/auth/profile', "{$role->value} must read its own profile");
        }
    }

    /**
     * The refusal is distinguishable from every other 4xx the panel produces:
     * a role that is wrong is not a session that is missing, and the SPA has
     * to tell them apart to decide between a login screen and a named
     * "not available for your role" page.
     */
    public function test_the_refusal_names_its_reason(): void
    {
        $this->signInHolding(AdminRole::GETRAENKEWART);

        $response = $this->request('GET', '/api/admin/members');
        $body = $this->decode($response);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('insufficient_role', $body['error']);
        $this->assertNotEmpty($body['message'] ?? '');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function signInHolding(AdminRole ...$roles): void
    {
        $id = Uuid::v4();
        $this->createdAdmins[] = $id;

        $this->db->prepare(
            'INSERT INTO admin_users (id, email, password_hash, display_name, locale, is_active, totp_enabled, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, 0, NOW(), NOW())'
        )->execute([$id, "role-enforcement-{$id}@example.test", self::PASSWORD_HASH, 'Role Test', 'de']);

        $grant = $this->db->prepare('INSERT INTO admin_user_roles (admin_user_id, role) VALUES (?, ?)');
        foreach ($roles as $role) {
            $grant->execute([$id, $role->value]);
        }

        if (session_status() !== \PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        $_SESSION['admin_user_id'] = $id;
        SessionTimeout::begin($_SESSION);
        $this->csrfToken = bin2hex(random_bytes(16));
        $_SESSION['csrf_token'] = $this->csrfToken;
    }

    private function assertRefused(string $method, string $path): void
    {
        $response = $this->request($method, $path, headers: ['X-CSRF-Token' => $this->csrfToken]);
        $body = $this->decode($response);

        $this->assertSame(403, $response->getStatusCode(), "{$method} {$path} should have been refused");
        $this->assertSame('insufficient_role', $body['error'] ?? null, "{$method} {$path} was refused for the wrong reason");
    }

    /**
     * Not "succeeded" — several of these paths need fixtures this suite has no
     * business creating, and one answers 404 for a member that does not exist.
     * What is under test is that the *role gate* let the request through.
     */
    private function assertNotRefused(string $method, string $path, string $message = ''): void
    {
        $response = $this->request($method, $path, headers: ['X-CSRF-Token' => $this->csrfToken]);
        $body = $this->decode($response);

        $this->assertNotSame(
            'insufficient_role',
            $body['error'] ?? null,
            $message !== '' ? $message : "{$method} {$path} was refused by the role gate",
        );
    }
}
