<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\AdminUsers;

use App\Modules\AdminUsers\Enums\AdminRole;
use App\Modules\Auth\Domain\SessionTimeout;
use App\Shared\Utils\Uuid;
use Tests\Feature\HttpTestCase;

/**
 * Granting a role through the API (ADR-0044 rule 2, #520).
 *
 * The hole this closes is specific. `POST /admin-users` has carried a step-up
 * since #510 and a pinned limiter since #511; `PATCH /admin-users/{id}` carried
 * neither. The moment role became a field on that row, *creating* an admin cost
 * a password and a fresh TOTP code while **promoting an existing Getränkewart
 * to `admin` cost nothing but a live session** — and the promotion is the
 * quieter path, since lifecycle mail fires on creation. That is the hole #510
 * and #511 closed on the adjacent endpoint, reopened one field over.
 */
class AdminUserRolesHttpTest extends HttpTestCase
{
    /** bcrypt of 'password123' — the same fixture hash db/seed.sql uses. */
    private const PASSWORD_HASH = '$2y$12$Pp5DqCBrNhBDThRmWYwPlegkBrYSDKxoGguH1K2XnUlVzQxoUPygG';
    private const PASSWORD = 'password123';

    /** @var list<string> */
    private array $createdAdmins = [];

    private string $callerId = '';
    private string $csrfToken = '';

    /**
     * A source address of this test's own.
     *
     * The step-up limiter counts failures per IP as well as per account
     * (#500), and several tests here fail a step-up on purpose. Sharing
     * `127.0.0.1` with every other suite in the process makes a later test
     * answer 429 for a reason that has nothing to do with what it asserts —
     * E2E Pattern 004's rule applied to the one resource an in-process HTTP
     * test cannot otherwise vary.
     */
    private string $clientIp = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientIp = '198.51.100.' . random_int(1, 254);
        $this->callerId = $this->createAdmin(AdminRole::ADMIN);
        $this->signInAs($this->callerId);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdAdmins as $id) {
            $this->db->prepare('DELETE FROM audit_log WHERE entity_id = ? OR admin_user_id = ?')->execute([$id, $id]);
            $this->db->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
        }
        $this->createdAdmins = [];

        parent::tearDown();
    }

    // ── the gate ─────────────────────────────────────────────────────────

    public function test_changing_a_role_set_without_a_step_up_is_refused(): void
    {
        $target = $this->createAdmin(AdminRole::GETRAENKEWART);

        $response = $this->patch($target, ['roles' => ['admin']]);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('invalid_credentials', $this->decode($response)['error']);
        $this->assertSame(['getraenkewart'], $this->rolesOf($target), 'the promotion must not have happened');
    }

    public function test_a_wrong_password_does_not_change_a_role_set(): void
    {
        $target = $this->createAdmin(AdminRole::GETRAENKEWART);

        $response = $this->patch($target, ['roles' => ['admin'], 'current_password' => 'not-the-password']);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(['getraenkewart'], $this->rolesOf($target));
    }

    public function test_a_step_up_promotes(): void
    {
        $target = $this->createAdmin(AdminRole::GETRAENKEWART);

        $response = $this->patch($target, [
            'roles' => ['admin'],
            'current_password' => self::PASSWORD,
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['admin'], $this->rolesOf($target));
        $this->assertSame(['admin'], $this->decode($response)['admin']['roles']);
    }

    /**
     * The gate is on the *change*, not on the endpoint. A display-name edit
     * demanding a password and a TOTP code would make the ordinary case
     * expensive and teach admins to keep a code to hand for routine edits.
     */
    public function test_editing_a_name_demands_no_credential(): void
    {
        $target = $this->createAdmin(AdminRole::KASSENWART);

        $response = $this->patch($target, ['display_name' => 'Renamed']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Renamed', $this->decode($response)['admin']['display_name']);
    }

    /** Re-submitting the roles an account already holds is a save, not a grant. */
    public function test_resubmitting_an_unchanged_role_set_demands_no_credential(): void
    {
        $target = $this->createAdmin(AdminRole::KASSENWART);

        $response = $this->patch($target, ['roles' => ['kassenwart'], 'display_name' => 'Same Roles']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['kassenwart'], $this->rolesOf($target));
    }

    // ── the audit trail ──────────────────────────────────────────────────

    public function test_a_grant_and_a_revocation_each_write_their_own_row(): void
    {
        $target = $this->createAdmin(AdminRole::KASSENWART);

        $this->patch($target, [
            'roles' => ['getraenkewart'],
            'current_password' => self::PASSWORD,
        ]);

        $granted = $this->auditRow($target, 'role_granted');
        $revoked = $this->auditRow($target, 'role_revoked');

        $this->assertNotNull($granted, 'gaining getraenkewart must be findable as a grant');
        $this->assertNotNull($revoked, 'losing kassenwart must be findable as a revocation');

        $this->assertSame($this->callerId, $granted['admin_user_id'], 'the row names who acted');
        $this->assertSame($target, $granted['entity_id'], 'and who it was done to');

        $this->assertSame(['getraenkewart'], json_decode($granted['new_values'], true)['granted']);
        $this->assertSame(['kassenwart'], json_decode($revoked['new_values'], true)['revoked']);
        $this->assertSame(['kassenwart'], json_decode($granted['old_values'], true)['roles'], 'and what was held before');
    }

    /** A save that moves no role is not an escalation and must not read like one. */
    public function test_an_unchanged_role_set_writes_no_role_audit_row(): void
    {
        $target = $this->createAdmin(AdminRole::KASSENWART);

        $this->patch($target, ['roles' => ['kassenwart'], 'current_password' => self::PASSWORD]);

        $this->assertNull($this->auditRow($target, 'role_granted'));
        $this->assertNull($this->auditRow($target, 'role_revoked'));
    }

    /**
     * "Who gained `admin` last quarter" has to return the accounts created as
     * admin, not only the ones promoted later — otherwise the cheapest way to
     * mint an admin is also the one the query misses.
     */
    public function test_creating_an_account_records_its_initial_grant(): void
    {
        $email = 'created-' . Uuid::v4() . '@example.test';

        $response = $this->request('POST', '/api/admin/admin-users', [
            'email' => $email,
            'display_name' => 'Created With Roles',
            'locale' => 'de',
            'roles' => ['kassenwart', 'getraenkewart'],
            'current_password' => self::PASSWORD,
        ], ['REMOTE_ADDR' => $this->clientIp], ['X-CSRF-Token' => $this->csrfToken]);

        $this->assertSame(201, $response->getStatusCode());
        $created = $this->decode($response)['admin'];
        $this->createdAdmins[] = $created['id'];

        $this->assertSame(['kassenwart', 'getraenkewart'], $created['roles']);
        $this->assertSame(['kassenwart', 'getraenkewart'], $this->rolesOf($created['id']));

        $granted = $this->auditRow($created['id'], 'role_granted');
        $this->assertNotNull($granted);
        $this->assertSame(['kassenwart', 'getraenkewart'], json_decode($granted['new_values'], true)['granted']);
    }

    /** Behaviour preservation: an account created without a role set is an admin. */
    public function test_creating_an_account_without_roles_makes_an_admin(): void
    {
        $response = $this->request('POST', '/api/admin/admin-users', [
            'email' => 'default-' . Uuid::v4() . '@example.test',
            'display_name' => 'Default Roles',
            'locale' => 'de',
            'current_password' => self::PASSWORD,
        ], ['REMOTE_ADDR' => $this->clientIp], ['X-CSRF-Token' => $this->csrfToken]);

        $this->assertSame(201, $response->getStatusCode());
        $created = $this->decode($response)['admin'];
        $this->createdAdmins[] = $created['id'];

        $this->assertSame(['admin'], $created['roles']);
    }

    // ── what a role set may say ──────────────────────────────────────────

    /**
     * Rejected, not filtered. Silently storing `["kassenwart"]` for a body that
     * said `["kassenwart", "superuser"]` answers 200 to a caller who believes
     * they granted something else.
     */
    public function test_a_role_that_does_not_exist_is_refused_rather_than_dropped(): void
    {
        $target = $this->createAdmin(AdminRole::KASSENWART);

        $response = $this->patch($target, [
            'roles' => ['kassenwart', 'superuser'],
            'current_password' => self::PASSWORD,
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['kassenwart'], $this->rolesOf($target));
    }

    public function test_an_empty_role_set_is_refused(): void
    {
        $target = $this->createAdmin(AdminRole::KASSENWART);

        $response = $this->patch($target, ['roles' => [], 'current_password' => self::PASSWORD]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['kassenwart'], $this->rolesOf($target));
    }

    // ── who may do it at all ─────────────────────────────────────────────

    /**
     * A Kassenwart cannot grant a role, including to a peer and including to
     * themselves — which is the property that makes the rest of this epic hold.
     */
    public function test_a_kassenwart_can_neither_create_an_account_nor_grant_a_role(): void
    {
        $target = $this->createAdmin(AdminRole::GETRAENKEWART);
        $kassenwart = $this->createAdmin(AdminRole::KASSENWART);
        $this->signInAs($kassenwart);

        $patch = $this->patch($target, ['roles' => ['admin'], 'current_password' => self::PASSWORD]);
        $this->assertSame(403, $patch->getStatusCode());
        $this->assertSame('insufficient_role', $this->decode($patch)['error']);

        $post = $this->request('POST', '/api/admin/admin-users', [
            'email' => 'nope-' . Uuid::v4() . '@example.test',
            'display_name' => 'Nope',
            'locale' => 'de',
            'current_password' => self::PASSWORD,
        ], ['REMOTE_ADDR' => $this->clientIp], ['X-CSRF-Token' => $this->csrfToken]);
        $this->assertSame(403, $post->getStatusCode());
        $this->assertSame('insufficient_role', $this->decode($post)['error']);

        $this->assertSame(['getraenkewart'], $this->rolesOf($target));
    }

    /**
     * The step-up credential in the body is not a substitute for the CSRF
     * token: a cross-site form post cannot read a response, but it can carry a
     * password the victim's browser never typed if the attacker already knows
     * it. Mirrors the regression test #524 added on `POST /admin-users`.
     */
    public function test_a_role_change_without_a_csrf_token_is_refused(): void
    {
        $target = $this->createAdmin(AdminRole::GETRAENKEWART);

        $response = $this->request('PATCH', "/api/admin/admin-users/{$target}", [
            'roles' => ['admin'],
            'current_password' => self::PASSWORD,
        ], ['REMOTE_ADDR' => $this->clientIp]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNotSame('insufficient_role', $this->decode($response)['error'] ?? null);
        $this->assertSame(['getraenkewart'], $this->rolesOf($target));
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function createAdmin(AdminRole ...$roles): string
    {
        $id = Uuid::v4();
        $this->createdAdmins[] = $id;

        $this->db->prepare(
            'INSERT INTO admin_users (id, email, password_hash, display_name, locale, is_active, totp_enabled, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, 0, NOW(), NOW())'
        )->execute([$id, "roles-http-{$id}@example.test", self::PASSWORD_HASH, 'Roles Http', 'de']);

        $grant = $this->db->prepare('INSERT INTO admin_user_roles (admin_user_id, role) VALUES (?, ?)');
        foreach ($roles as $role) {
            $grant->execute([$id, $role->value]);
        }

        return $id;
    }

    private function signInAs(string $adminId): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        $_SESSION['admin_user_id'] = $adminId;
        SessionTimeout::begin($_SESSION);
        $this->csrfToken = bin2hex(random_bytes(16));
        $_SESSION['csrf_token'] = $this->csrfToken;
    }

    /** @param array<string, mixed> $body */
    private function patch(string $targetId, array $body): \Psr\Http\Message\ResponseInterface
    {
        return $this->request(
            'PATCH',
            "/api/admin/admin-users/{$targetId}",
            $body,
            ['REMOTE_ADDR' => $this->clientIp],
            ['X-CSRF-Token' => $this->csrfToken],
        );
    }

    /** @return list<string> */
    private function rolesOf(string $adminId): array
    {
        $stmt = $this->db->prepare('SELECT role FROM admin_user_roles WHERE admin_user_id = ? ORDER BY role');
        $stmt->execute([$adminId]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** @return array<string, mixed>|null */
    private function auditRow(string $entityId, string $action): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM audit_log WHERE entity_id = ? AND action = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$entityId, $action]);

        return $stmt->fetch() ?: null;
    }
}
