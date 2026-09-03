<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\AdminUsers;

use App\Modules\AdminUsers\Enums\AdminRole;
use App\Modules\Auth\Domain\SessionTimeout;
use App\Shared\Utils\Uuid;
use Tests\Feature\HttpTestCase;

/**
 * Deleting an admin account over HTTP (UC-A61).
 *
 * The unit suite covers the rule; this covers the plumbing that only a real
 * database can exercise — the `DELETE` statement itself, the audit-actor
 * lookup behind the history guard, and the cascades that carry an account's
 * roles and its outstanding invitation out with it.
 *
 * `DELETE` used to deactivate, so the pair of endpoints is asserted together:
 * the verb that removes the row and the `POST .../deactivate` that inherited
 * the old behaviour, beside the `/reactivate` it has always paired with.
 */
class AdminUserDeleteHttpTest extends HttpTestCase
{
    /** bcrypt of 'password123' — the same fixture hash db/seed.sql uses. */
    private const PASSWORD_HASH = '$2y$12$Pp5DqCBrNhBDThRmWYwPlegkBrYSDKxoGguH1K2XnUlVzQxoUPygG';

    /** @var list<string> */
    private array $createdAdmins = [];

    private string $callerId = '';
    private string $csrfToken = '';

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_an_account_that_never_signed_in_is_removed(): void
    {
        $target = $this->createAdmin(AdminRole::KASSENWART);

        $response = $this->delete($target);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertNull($this->adminRow($target), 'the row must be gone, not deactivated');
    }

    /**
     * `admin_user_roles` cascades. Without it the account's grants would
     * outlive the account, and a later row reusing the id would inherit them.
     */
    public function test_the_accounts_roles_go_with_it(): void
    {
        $target = $this->createAdmin(AdminRole::KASSENWART, AdminRole::GETRAENKEWART);

        $this->delete($target);

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM admin_user_roles WHERE admin_user_id = ?');
        $stmt->execute([$target]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    /**
     * Once the row is gone the audit entry is the only thing that still says
     * *who* was removed — `entity_id` alone is a UUID nobody can resolve.
     */
    public function test_the_deletion_is_audited_with_the_identity_it_destroys(): void
    {
        $target = $this->createAdmin(AdminRole::KASSENWART);
        $email = $this->adminRow($target)['email'];

        $this->delete($target);

        $row = $this->auditRow($target, 'delete');
        $this->assertNotNull($row, 'the deletion must be audited');
        $this->assertSame($this->callerId, $row['admin_user_id'], 'the actor is the admin who deleted');

        $old = json_decode((string) $row['old_values'], true);
        $this->assertSame($email, $old['email']);
        $this->assertSame(['kassenwart'], $old['roles']);
    }

    /**
     * The half `last_login_at` does not cover. `INVITATION_ACCEPTED` is
     * attributed to the invitee and is the one audit row written by a request
     * carrying no session, so the account owns history while its login
     * timestamp is still null — and it is that row a delete would strand.
     */
    public function test_an_account_that_authored_an_audit_row_is_refused(): void
    {
        $target = $this->createAdmin(AdminRole::KASSENWART);
        $this->db->prepare(
            'INSERT INTO audit_log (admin_user_id, action, entity_type, entity_id, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        )->execute([$target, 'invitation_accepted', 'admin_user', $target]);

        $response = $this->delete($target);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('admin_user_has_history', $this->decode($response)['reason']);
        $this->assertNotNull($this->adminRow($target), 'a refused delete changes nothing');
    }

    public function test_an_account_that_has_signed_in_is_refused(): void
    {
        $target = $this->createAdmin(AdminRole::KASSENWART);
        $this->db->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = ?')->execute([$target]);

        $response = $this->delete($target);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('admin_user_has_history', $this->decode($response)['reason']);
        $this->assertNotNull($this->adminRow($target));
    }

    public function test_deleting_your_own_account_is_refused(): void
    {
        $response = $this->delete($this->callerId);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertNotNull($this->adminRow($this->callerId));
    }

    public function test_an_unknown_account_is_not_found(): void
    {
        $response = $this->delete(Uuid::v4());

        $this->assertSame(404, $response->getStatusCode());
    }

    // ── the deactivate half, which `DELETE` used to be ───────────────────

    public function test_deactivate_switches_the_account_off_and_keeps_the_row(): void
    {
        $target = $this->createAdmin(AdminRole::KASSENWART);

        $response = $this->request(
            'POST',
            "/api/admin/admin-users/{$target}/deactivate",
            [],
            [],
            ['X-CSRF-Token' => $this->csrfToken],
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($this->decode($response)['admin']['is_active']);
        $this->assertSame(0, (int) $this->adminRow($target)['is_active'], 'the row stays, switched off');
    }

    public function test_deactivating_your_own_account_is_refused(): void
    {
        $response = $this->request(
            'POST',
            "/api/admin/admin-users/{$this->callerId}/deactivate",
            [],
            [],
            ['X-CSRF-Token' => $this->csrfToken],
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('cannot_deactivate_self', $this->decode($response)['reason']);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function delete(string $targetId): \Psr\Http\Message\ResponseInterface
    {
        return $this->request(
            'DELETE',
            "/api/admin/admin-users/{$targetId}",
            [],
            [],
            ['X-CSRF-Token' => $this->csrfToken],
        );
    }

    private function createAdmin(AdminRole ...$roles): string
    {
        $id = Uuid::v4();
        $this->createdAdmins[] = $id;

        $this->db->prepare(
            'INSERT INTO admin_users (id, email, password_hash, display_name, locale, is_active, totp_enabled, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, 0, NOW(), NOW())'
        )->execute([$id, "delete-http-{$id}@example.test", self::PASSWORD_HASH, 'Delete Http', 'de']);

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

    /** @return array<string, mixed>|null */
    private function adminRow(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM admin_users WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
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
