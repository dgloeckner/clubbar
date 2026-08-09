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

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new AuditLogRepository($this->db, $this->logger);
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData('audit_log', $this->testEntryIds);
        parent::tearDown();
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
    private function insertEntry(
        string $entityId,
        string $action,
        string $createdAt,
        string $entityType = 'member',
        ?array $payload = null,
    ): int {
        $this->repository->insert([
            'admin_user_id' => null,
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
