<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Members\Repositories;

use App\Modules\Members\Repositories\MembersRepository;
use Tests\Feature\DatabaseTestCase;

class MembersRepositoryTest extends DatabaseTestCase
{
    private MembersRepository $membersRepository;
    private array $testMemberIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->membersRepository = new MembersRepository($this->db, $this->logger);
    }

    protected function tearDown(): void
    {
        // Clean up test members
        $this->cleanupTestData('members', $this->testMemberIds);
        parent::tearDown();
    }

    public function test_findModifiedSince_accepts_milliseconds_and_converts_correctly(): void
    {
        // Create test member with known timestamp
        $testMemberId = $this->generateUuid();
        $testMember = [
            'id' => $testMemberId,
            'card_uid' => '04:AA:BB:CC:DD:EE:FF',
            'first_name' => 'TimestampTest',
            'last_name' => 'User',
            'email' => 'timestamp-test@example.com',
            'preferred_language' => 'de',
            'is_active' => 1,
            'iban' => 'DE89370400440532013000',
            'mandate_reference' => 'MANDATE' . substr($testMemberId, 0, 8),
            'mandate_signed_at' => '2025-01-01',
        ];

        $this->testMemberIds[] = $testMemberId;
        $this->membersRepository->create($testMember);

        // Get member's updated_at timestamp
        $member = $this->membersRepository->findById($testMemberId);
        $this->assertNotNull($member, 'Test member should be created');

        $updatedAt = new \DateTime($member['updated_at']);

        // Convert to milliseconds and subtract 1 second (to ensure we query before the member's timestamp)
        $sinceMs = ($updatedAt->getTimestamp() - 1) * 1000;

        // Query with milliseconds
        $results = $this->membersRepository->findModifiedSince($sinceMs);

        // Should find the test member (and possibly others created during other tests)
        $found = false;
        foreach ($results as $result) {
            if ($result['id'] === $testMemberId) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, "Test member {$testMemberId} should be found in results when querying with milliseconds");
    }

    public function test_findModifiedSince_returns_empty_when_timestamp_in_future(): void
    {
        // Create test member
        $testMemberId = $this->generateUuid();
        $testMember = [
            'id' => $testMemberId,
            'card_uid' => '04:AA:BB:CC:DD:EE:00',
            'first_name' => 'FutureTest',
            'last_name' => 'User',
            'email' => 'future-test@example.com',
            'preferred_language' => 'de',
            'is_active' => 1,
            'iban' => 'DE89370400440532013000',
            'mandate_reference' => 'MANDATE' . substr($testMemberId, 0, 8),
            'mandate_signed_at' => '2025-01-01',
        ];

        $this->testMemberIds[] = $testMemberId;
        $this->membersRepository->create($testMember);

        // Query with future timestamp (in milliseconds)
        $futureTimestampMs = (time() + 3600) * 1000; // 1 hour from now in milliseconds
        $results = $this->membersRepository->findModifiedSince($futureTimestampMs);

        // Should not find this member
        $found = false;
        foreach ($results as $result) {
            if ($result['id'] === $testMemberId) {
                $found = true;
                break;
            }
        }

        $this->assertFalse($found, "Test member should not be found when querying with future timestamp");
    }

    public function test_findModifiedSince_does_not_return_year_57123_bug(): void
    {
        // Create test member
        $testMemberId = $this->generateUuid();
        $testMember = [
            'id' => $testMemberId,
            'card_uid' => '04:AA:BB:CC:DD:EE:11',
            'first_name' => 'BugTest',
            'last_name' => 'User',
            'email' => 'bug-test@example.com',
            'preferred_language' => 'de',
            'is_active' => 1,
            'iban' => 'DE89370400440532013000',
            'mandate_reference' => 'MANDATE' . substr($testMemberId, 0, 8),
            'mandate_signed_at' => '2025-01-01',
        ];

        $this->testMemberIds[] = $testMemberId;
        $this->membersRepository->create($testMember);

        // Get member's updated_at timestamp
        $member = $this->membersRepository->findById($testMemberId);
        $updatedAt = new \DateTime($member['updated_at']);

        // Use milliseconds (simulating frontend timestamp)
        $sinceMs = ($updatedAt->getTimestamp() - 1) * 1000;

        // Query with milliseconds
        $results = $this->membersRepository->findModifiedSince($sinceMs);

        // Verify we don't get dates in year 57123 (which would happen if milliseconds weren't converted)
        foreach ($results as $result) {
            $resultDate = new \DateTime($result['updated_at']);
            $year = (int) $resultDate->format('Y');

            $this->assertLessThan(3000, $year, "Year should be reasonable (not 57123), got {$year} for member {$result['id']}");
            $this->assertGreaterThan(2020, $year, "Year should be recent, got {$year} for member {$result['id']}");
        }
    }

    public function test_findModifiedSince_includes_deleted_members_tombstones(): void
    {
        // Create test member
        $testMemberId = $this->generateUuid();
        $testMember = [
            'id' => $testMemberId,
            'card_uid' => '04:AA:BB:CC:DD:EE:22',
            'first_name' => 'Tombstone',
            'last_name' => 'Test',
            'email' => 'tombstone-test@example.com',
            'preferred_language' => 'de',
            'is_active' => 1,
            'iban' => 'DE89370400440532013000',
            'mandate_reference' => 'MANDATE' . substr($testMemberId, 0, 8),
            'mandate_signed_at' => '2025-01-01',
        ];
        $this->membersRepository->create($testMember);
        $this->testMemberIds[] = $testMemberId;

        // Get timestamp before deletion
        $beforeDelete = time() * 1000;
        sleep(1); // Ensure deleted_at is after created_at

        // Soft delete the member (set deleted_at)
        $this->membersRepository->updateById($testMemberId, ['deleted_at' => date('Y-m-d H:i:s')]);

        // Query with timestamp before deletion
        $results = $this->membersRepository->findModifiedSince($beforeDelete);

        // Should include the deleted member (tombstone)
        $found = false;
        foreach ($results as $result) {
            if ($result['id'] === $testMemberId) {
                $found = true;
                $this->assertNotNull($result['deleted_at'], 'Deleted member should have deleted_at set');
                break;
            }
        }

        $this->assertTrue($found, 'Deleted member (tombstone) should be included in sync results');
    }

    public function test_findModifiedSince_includes_both_updated_and_deleted_members(): void
    {
        // Create two test members
        $updatedMemberId = $this->generateUuid();
        $deletedMemberId = $this->generateUuid();

        $updatedMember = [
            'id' => $updatedMemberId,
            'card_uid' => '04:AA:BB:CC:DD:EE:33',
            'first_name' => 'Updated',
            'last_name' => 'Member',
            'email' => 'updated-member@example.com',
            'preferred_language' => 'de',
            'is_active' => 1,
            'iban' => 'DE89370400440532013000',
            'mandate_reference' => 'MANDATE' . substr($updatedMemberId, 0, 8),
            'mandate_signed_at' => '2025-01-01',
        ];

        $deletedMember = [
            'id' => $deletedMemberId,
            'card_uid' => '04:AA:BB:CC:DD:EE:44',
            'first_name' => 'Deleted',
            'last_name' => 'Member',
            'email' => 'deleted-member@example.com',
            'preferred_language' => 'de',
            'is_active' => 1,
            'iban' => 'DE89370400440532013000',
            'mandate_reference' => 'MANDATE' . substr($deletedMemberId, 0, 8),
            'mandate_signed_at' => '2025-01-01',
        ];

        $this->membersRepository->create($updatedMember);
        $this->membersRepository->create($deletedMember);
        $this->testMemberIds[] = $updatedMemberId;
        $this->testMemberIds[] = $deletedMemberId;

        // Get timestamp before modifications
        $beforeModifications = time() * 1000;
        sleep(1);

        // Update one member
        $this->membersRepository->updateById($updatedMemberId, ['first_name' => 'UpdatedName']);

        // Delete the other member
        $this->membersRepository->updateById($deletedMemberId, ['deleted_at' => date('Y-m-d H:i:s')]);

        // Query with timestamp before modifications
        $results = $this->membersRepository->findModifiedSince($beforeModifications);

        // Should include both the updated member and the deleted member (tombstone)
        $foundUpdated = false;
        $foundDeleted = false;

        foreach ($results as $result) {
            if ($result['id'] === $updatedMemberId) {
                $foundUpdated = true;
                $this->assertNull($result['deleted_at'], 'Updated member should not have deleted_at');
                $this->assertEquals('UpdatedName', $result['first_name']);
            }
            if ($result['id'] === $deletedMemberId) {
                $foundDeleted = true;
                $this->assertNotNull($result['deleted_at'], 'Deleted member should have deleted_at set');
            }
        }

        $this->assertTrue($foundUpdated, 'Updated member should be included in sync results');
        $this->assertTrue($foundDeleted, 'Deleted member (tombstone) should be included in sync results');
    }

    public function test_findModifiedSince_orders_by_coalesce_updated_deleted(): void
    {
        // Create test member
        $testMemberId = $this->generateUuid();
        $testMember = [
            'id' => $testMemberId,
            'card_uid' => '04:AA:BB:CC:DD:EE:55',
            'first_name' => 'Order',
            'last_name' => 'Test',
            'email' => 'order-test@example.com',
            'preferred_language' => 'de',
            'is_active' => 1,
            'iban' => 'DE89370400440532013000',
            'mandate_reference' => 'MANDATE' . substr($testMemberId, 0, 8),
            'mandate_signed_at' => '2025-01-01',
        ];
        $this->membersRepository->create($testMember);
        $this->testMemberIds[] = $testMemberId;

        // Delete the member (sets deleted_at, updated_at stays the same)
        $this->membersRepository->updateById($testMemberId, ['deleted_at' => date('Y-m-d H:i:s')]);

        // Query should return results ordered by COALESCE(updated_at, deleted_at)
        $results = $this->membersRepository->findModifiedSince(0);

        // Verify results are ordered (ASC)
        $previousTimestamp = null;
        foreach ($results as $result) {
            $currentTimestamp = $result['deleted_at'] ?? $result['updated_at'];

            if ($previousTimestamp !== null) {
                $this->assertGreaterThanOrEqual(
                    strtotime($previousTimestamp),
                    strtotime($currentTimestamp),
                    'Results should be ordered by COALESCE(updated_at, deleted_at) ASC'
                );
            }

            $previousTimestamp = $currentTimestamp;
        }
    }
}
