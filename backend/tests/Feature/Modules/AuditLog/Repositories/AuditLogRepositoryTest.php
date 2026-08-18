<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\AuditLog\Repositories;

use App\Modules\AuditLog\Repositories\AuditLogRepository;
use Tests\Feature\DatabaseTestCase;

/**
 * Ordering of the audit trail (#125), and the scrub that answers an erasure
 * request (#115).
 *
 * The audit screen has always shown a sort control over the timestamp column.
 * The query behind it had `ORDER BY al.created_at DESC` hard-coded, so the
 * arrow flipped and the rows did not. These tests pin the order the endpoint
 * now answers with.
 */
class AuditLogRepositoryTest extends DatabaseTestCase
{
    private AuditLogRepository $repository;

    /** @var list<int> */
    private array $testEntryIds = [];

    /** @var list<string> */
    private array $testAdminUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new AuditLogRepository($this->db, $this->logger);
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData('audit_log', $this->testEntryIds);
        $this->cleanupTestData('admin_users', $this->testAdminUserIds);
        parent::tearDown();
    }

    /**
     * The audit log page's Admin column reads `admin_user_email` off each
     * entry (matching the OpenAPI contract). The repository used to join in
     * `admin_users.display_name` under the key `admin_user_name` instead, so
     * every row showed a blank Admin column regardless of action type.
     */
    public function test_listWithFilters_resolves_the_acting_admins_email(): void
    {
        $adminId = $this->createAdminUser('auditor@example.com');
        $entityId = $this->entityId();
        $this->insertEntry($entityId, 'update', '2026-04-01 09:00:00', adminUserId: $adminId);

        $result = $this->repository->listWithFilters(10, 0, ['entity_id' => $entityId]);

        $this->assertSame('auditor@example.com', $result['items'][0]['admin_user_email']);
    }

    /**
     * `date_from`/`date_to` used to wrap `created_at` in `DATE()`, which reads
     * identically but stops the optimizer using any index on the column at
     * all — a full-table scan on every filtered page (594k rows examined for a
     * 30-day window on a 600k-row table in the query-plan audit that replaced
     * it with plain range comparisons). These pin the boundary semantics that
     * rewrite has to preserve: `date_to` is a calendar day, inclusive of every
     * second in it, not just its midnight instant.
     */
    public function test_listWithFilters_date_range_includes_both_boundary_instants(): void
    {
        $entityId = $this->entityId();
        $onFromDay = $this->insertEntry($entityId, 'create', '2026-01-01 00:00:00');
        $onToDay = $this->insertEntry($entityId, 'update', '2026-01-03 23:59:59');
        $beforeRange = $this->insertEntry($entityId, 'delete', '2025-12-31 23:59:59');
        $afterRange = $this->insertEntry($entityId, 'export', '2026-01-04 00:00:00');

        $result = $this->repository->listWithFilters(10, 0, [
            'entity_id' => $entityId,
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-03',
        ], 'created_at', 'asc');

        $this->assertSame([$onFromDay, $onToDay], $this->ids($result['items']));
        $this->assertNotContains($beforeRange, $this->ids($result['items']));
        $this->assertNotContains($afterRange, $this->ids($result['items']));
    }

    private function createAdminUser(string $email): string
    {
        $adminId = $this->generateUuid();
        $this->testAdminUserIds[] = $adminId;

        $this->db->prepare(
            'INSERT INTO admin_users (id, email, password_hash, display_name, is_active) VALUES (?, ?, ?, ?, 1)'
        )->execute([$adminId, $email, password_hash('test123', PASSWORD_BCRYPT), 'Auditor']);

        return $adminId;
    }

    public function test_listWithFilters_defaults_to_newest_first(): void
    {
        $entityId = $this->entityId();
        $oldest = $this->insertEntry($entityId, 'create', '2026-01-01 09:00:00');
        $newest = $this->insertEntry($entityId, 'delete', '2026-01-03 09:00:00');
        $middle = $this->insertEntry($entityId, 'update', '2026-01-02 09:00:00');

        $result = $this->repository->listWithFilters(10, 0, ['entity_id' => $entityId]);

        $this->assertSame([$newest, $middle, $oldest], $this->ids($result['items']));
        $this->assertSame(3, (int) $result['total']);
    }

    public function test_listWithFilters_orders_oldest_first_when_asked(): void
    {
        $entityId = $this->entityId();
        $oldest = $this->insertEntry($entityId, 'create', '2026-01-01 09:00:00');
        $newest = $this->insertEntry($entityId, 'delete', '2026-01-03 09:00:00');
        $middle = $this->insertEntry($entityId, 'update', '2026-01-02 09:00:00');

        $result = $this->repository->listWithFilters(10, 0, ['entity_id' => $entityId], 'created_at', 'asc');

        $this->assertSame([$oldest, $middle, $newest], $this->ids($result['items']));
    }

    public function test_listWithFilters_breaks_a_timestamp_tie_by_insertion_order(): void
    {
        // Everything a single request writes lands in the same second, and a
        // tie the database resolves arbitrarily can show one entry on two
        // pages of the same listing while another appears on none.
        $entityId = $this->entityId();
        $first = $this->insertEntry($entityId, 'create', '2026-02-01 12:00:00');
        $second = $this->insertEntry($entityId, 'update', '2026-02-01 12:00:00');
        $third = $this->insertEntry($entityId, 'delete', '2026-02-01 12:00:00');

        $ascending = $this->repository->listWithFilters(10, 0, ['entity_id' => $entityId], 'created_at', 'asc');
        $this->assertSame([$first, $second, $third], $this->ids($ascending['items']));

        $descending = $this->repository->listWithFilters(10, 0, ['entity_id' => $entityId], 'created_at', 'desc');
        $this->assertSame([$third, $second, $first], $this->ids($descending['items']));
    }

    public function test_listWithFilters_rejects_a_column_it_cannot_order_by(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->repository->listWithFilters(10, 0, [], 'ip_address', 'asc');
    }

    // ------------------------------------------------------------------
    // The scrub behind an Art. 17 erasure (#115)
    // ------------------------------------------------------------------

    public function test_scrubByEntityId_empties_the_payloads_and_keeps_the_entries(): void
    {
        $memberId = $this->entityId();
        $created = $this->insertEntry($memberId, 'create', '2026-03-01 09:00:00', payload: [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ]);

        $this->assertSame(1, $this->repository->scrubByEntityId($memberId));

        $row = $this->fetchEntry($created);
        // The entry survives: *that* an admin created this member, and when,
        // is the accountability record. Only the personal data goes.
        $this->assertNotNull($row);
        $this->assertSame('create', $row['action']);
        $this->assertNull($row['old_values']);
        $this->assertNull($row['new_values']);
    }

    /**
     * The sweep is keyed on the id and not on the entity type the row claims.
     * A member-scoped entry filed under the wrong type used to survive the
     * erasure with its payload intact, and the completeness of an erasure
     * cannot depend on every writer having got the type right (#115).
     */
    public function test_scrubByEntityId_reaches_an_entry_filed_under_another_entity_type(): void
    {
        $memberId = $this->entityId();
        $mistyped = $this->insertEntry(
            $memberId,
            'update',
            '2026-03-01 10:00:00',
            entityType: 'terminal',
            payload: ['email' => 'ada@example.com'],
        );

        $this->assertSame(1, $this->repository->scrubByEntityId($memberId));

        $this->assertNull($this->fetchEntry($mistyped)['new_values']);
    }

    public function test_scrubByEntityId_leaves_every_other_entity_alone(): void
    {
        $memberId = $this->entityId();
        $otherId = $this->entityId();
        $this->insertEntry($memberId, 'create', '2026-03-01 09:00:00', payload: ['first_name' => 'Ada']);
        $untouched = $this->insertEntry($otherId, 'create', '2026-03-01 09:00:00', payload: ['first_name' => 'Grace']);

        $this->assertSame(1, $this->repository->scrubByEntityId($memberId));

        $this->assertSame(['first_name' => 'Grace'], json_decode($this->fetchEntry($untouched)['new_values'], true));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<int> PDO hands back the id as a string on some drivers.
     */
    private function ids(array $rows): array
    {
        return array_map('intval', array_column($rows, 'id'));
    }

    private function entityId(): string
    {
        return $this->generateUuid();
    }

    /** @param array<string, mixed>|null $payload */
    /**
     * The dedup primitive behind the once-per-occurrence audit write (#395).
     *
     * A condition being *observed* — a terminal presenting an expired token —
     * is not an act, and a terminal polls for as long as it stays switched on.
     * Anchoring the window on the instant the condition began keeps exactly one
     * entry per occurrence, which is what these cases pin.
     */
    public function test_hasEntrySince_finds_an_entry_inside_the_window(): void
    {
        $entityId = $this->entityId();
        $this->insertEntry($entityId, 'terminal_token_expired', '2026-01-02 09:00:00', 'terminal');

        $this->assertTrue(
            $this->repository->hasEntrySince($entityId, 'terminal_token_expired', '2026-01-01 00:00:00')
        );
    }

    public function test_hasEntrySince_ignores_an_entry_from_before_the_window(): void
    {
        $entityId = $this->entityId();
        // Last year's expiry, already recorded. This year's is a new occurrence
        // and must not be suppressed by it.
        $this->insertEntry($entityId, 'terminal_token_expired', '2025-01-02 09:00:00', 'terminal');

        $this->assertFalse(
            $this->repository->hasEntrySince($entityId, 'terminal_token_expired', '2026-01-01 00:00:00')
        );
    }

    public function test_hasEntrySince_is_scoped_to_the_action_it_was_asked_about(): void
    {
        $entityId = $this->entityId();
        $this->insertEntry($entityId, 'update', '2026-01-02 09:00:00', 'terminal');

        $this->assertFalse(
            $this->repository->hasEntrySince($entityId, 'terminal_token_expired', '2026-01-01 00:00:00')
        );
    }

    public function test_hasEntrySince_is_scoped_to_the_entity_it_was_asked_about(): void
    {
        $this->insertEntry($this->entityId(), 'terminal_token_expired', '2026-01-02 09:00:00', 'terminal');

        $this->assertFalse(
            $this->repository->hasEntrySince($this->entityId(), 'terminal_token_expired', '2026-01-01 00:00:00')
        );
    }

    /** An entry stamped exactly at the boundary counts as inside it. */
    public function test_hasEntrySince_includes_an_entry_on_the_boundary(): void
    {
        $entityId = $this->entityId();
        $this->insertEntry($entityId, 'terminal_token_expired', '2026-01-01 00:00:00', 'terminal');

        $this->assertTrue(
            $this->repository->hasEntrySince($entityId, 'terminal_token_expired', '2026-01-01 00:00:00')
        );
    }

    private function insertEntry(
        string $entityId,
        string $action,
        string $createdAt,
        string $entityType = 'member',
        ?array $payload = null,
        ?string $adminUserId = null,
    ): int {
        $this->repository->insert([
            'admin_user_id' => $adminUserId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'new_values' => $payload,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'created_at' => $createdAt,
        ]);

        $id = (int) $this->db->lastInsertId();
        $this->testEntryIds[] = $id;

        return $id;
    }

    /** @return array<string, mixed>|null */
    private function fetchEntry(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM audit_log WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }
}
