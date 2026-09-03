<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Members;

use App\Modules\AdminUsers\Enums\AdminRole;
use App\Modules\Auth\Domain\SessionTimeout;
use App\Shared\Utils\Uuid;
use Tests\Feature\HttpTestCase;

/**
 * A member's own ceiling, through the real stack (ADR-0047, #558).
 *
 * No new route: `POST` and `PATCH /api/admin/members` already carry it, the
 * way `preferred_language` is carried. What is new is a field with **three**
 * meanings, and the tests below exist to keep them apart:
 *
 * | Request carries | Meaning |
 * |---|---|
 * | field omitted | leave the override exactly as it is |
 * | `null` or `""` | clear it — back to the club default |
 * | `0` | a deliberate "no ceiling for this member" |
 *
 * `0` is not a way to clear anything. A form that serialises an emptied input
 * to `0` silently grants unlimited credit, which is the worst failure this
 * feature can have — so the distinction is asserted here, at the boundary,
 * rather than trusted to the admin UI.
 */
class MemberCreditLimitHttpTest extends HttpTestCase
{
    private const PASSWORD_HASH = '$2y$12$Pp5DqCBrNhBDThRmWYwPlegkBrYSDKxoGguH1K2XnUlVzQxoUPygG';

    /** @var list<string> */
    private array $createdAdmins = [];
    /** @var list<string> */
    private array $createdMembers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInHolding(AdminRole::KASSENWART);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdMembers as $id) {
            $this->db->prepare('DELETE FROM audit_log WHERE entity_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM members WHERE id = ?')->execute([$id]);
        }
        foreach ($this->createdAdmins as $id) {
            $this->db->prepare('DELETE FROM audit_log WHERE admin_user_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
        }
        $this->createdMembers = [];
        $this->createdAdmins = [];

        parent::tearDown();
    }

    public function test_a_member_created_without_a_ceiling_follows_the_club(): void
    {
        $id = $this->createMember();

        $this->assertNull($this->storedOverride($id));
        $this->assertNull($this->decode($this->request('GET', "/api/admin/members/{$id}"))['credit_limit_cents']);
    }

    public function test_a_ceiling_given_at_creation_is_stored_and_read_back(): void
    {
        $id = $this->createMember(['credit_limit_cents' => 20_000]);

        $this->assertSame(20_000, (int) $this->storedOverride($id));
        $this->assertSame(20_000, $this->decode($this->request('GET', "/api/admin/members/{$id}"))['credit_limit_cents']);
    }

    public function test_an_update_that_says_nothing_leaves_the_ceiling_alone(): void
    {
        $id = $this->createMember(['credit_limit_cents' => 5_000]);

        $response = $this->patch($id, ['first_name' => 'Magdalena']);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(5_000, (int) $this->storedOverride($id));
    }

    public function test_null_and_a_blank_field_both_send_a_member_back_to_the_club_default(): void
    {
        foreach ([null, ''] as $cleared) {
            $id = $this->createMember(['credit_limit_cents' => 5_000]);

            $response = $this->patch($id, ['credit_limit_cents' => $cleared]);

            $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $this->assertNull(
                $this->storedOverride($id),
                var_export($cleared, true) . ' must clear the override, not store a number',
            );
        }
    }

    public function test_zero_is_stored_as_a_deliberate_absence_of_a_ceiling(): void
    {
        $id = $this->createMember(['credit_limit_cents' => 5_000]);

        $this->patch($id, ['credit_limit_cents' => 0]);

        $stored = $this->storedOverride($id);
        $this->assertNotNull($stored, '0 means unlimited for this member, never "follow the club"');
        $this->assertSame(0, (int) $stored);
    }

    public function test_a_ceiling_outside_the_bounds_is_refused_on_both_paths(): void
    {
        foreach ([-1, 10_000_001, '100.50'] as $value) {
            $create = $this->request('POST', '/api/admin/members', $this->body(['credit_limit_cents' => $value]), [], $this->csrf());
            $this->assertSame(422, $create->getStatusCode(), var_export($value, true) . ' must be refused on create');

            $id = $this->createMember();
            $update = $this->patch($id, ['credit_limit_cents' => $value]);
            $this->assertSame(422, $update->getStatusCode(), var_export($value, true) . ' must be refused on update');
        }
    }

    /**
     * A member who follows the club default is the ordinary case and is worth
     * no line in the audit log; a member singled out is a decision, and the log
     * is what says who made it.
     */
    public function test_an_override_is_audited_when_it_changes_and_not_when_it_is_absent(): void
    {
        $plain = $this->createMember();
        $this->assertStringNotContainsString('credit_limit_cents', $this->auditNewValues($plain));

        $singledOut = $this->createMember(['credit_limit_cents' => 7_500]);
        $this->assertStringContainsString('credit_limit_cents', $this->auditNewValues($singledOut));

        $this->patch($plain, ['credit_limit_cents' => 4_000]);
        $this->assertStringContainsString('credit_limit_cents', $this->auditNewValues($plain));
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        $unique = substr(Uuid::v4(), 0, 8);

        return $overrides + [
            'first_name' => 'Ada',
            'last_name' => "Limit {$unique}",
            'email' => "credit-limit-{$unique}@example.test",
            'preferred_language' => 'de',
            'date_of_birth' => '1990-05-04',
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function createMember(array $overrides = []): string
    {
        $response = $this->request('POST', '/api/admin/members', $this->body($overrides), [], $this->csrf());
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $id = $this->decode($response)['id'];
        $this->createdMembers[] = $id;

        return $id;
    }

    /** @param array<string, mixed> $body */
    private function patch(string $memberId, array $body): \Psr\Http\Message\ResponseInterface
    {
        return $this->request('PATCH', "/api/admin/members/{$memberId}", $body, [], $this->csrf());
    }

    private function storedOverride(string $memberId): int|string|null
    {
        $stmt = $this->db->prepare('SELECT credit_limit_cents FROM members WHERE id = ?');
        $stmt->execute([$memberId]);

        return $stmt->fetch()['credit_limit_cents'];
    }

    private function auditNewValues(string $memberId): string
    {
        $stmt = $this->db->prepare(
            'SELECT new_values FROM audit_log WHERE entity_id = ? ORDER BY created_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$memberId]);

        return (string) ($stmt->fetch()['new_values'] ?? '');
    }

    /** @return array<string, string> */
    private function csrf(): array
    {
        return ['X-CSRF-Token' => $_SESSION['csrf_token'] ?? ''];
    }

    private function signInHolding(AdminRole ...$roles): void
    {
        $id = Uuid::v4();
        $this->createdAdmins[] = $id;

        $this->db->prepare(
            'INSERT INTO admin_users (id, email, password_hash, display_name, locale, is_active, totp_enabled, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, 0, NOW(), NOW())'
        )->execute([$id, "member-limit-{$id}@example.test", self::PASSWORD_HASH, 'Member Limits', 'de']);

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
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
}
