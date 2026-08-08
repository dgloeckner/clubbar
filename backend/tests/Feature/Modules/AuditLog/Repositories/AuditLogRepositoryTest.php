<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\AuditLog\Repositories;

use App\Modules\AuditLog\Repositories\AuditLogRepository;
use Tests\Feature\DatabaseTestCase;

/**
 * Ordering of the audit trail (#125).
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

    private function insertEntry(string $entityId, string $action, string $createdAt): int
    {
        $this->repository->insert([
            'admin_user_id' => null,
            'action' => $action,
            'entity_type' => 'member',
            'entity_id' => $entityId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'created_at' => $createdAt,
        ]);

        $id = (int) $this->db->lastInsertId();
        $this->testEntryIds[] = $id;

        return $id;
    }
}
