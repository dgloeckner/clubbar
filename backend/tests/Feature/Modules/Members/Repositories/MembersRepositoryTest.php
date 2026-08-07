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

    public function test_sync_cursor_prevents_race_condition_when_no_changes(): void
    {
        // Get current cursor
        $cursor = time() * 1000;
        
        // Sync with cursor (no changes should exist after this timestamp)
        $results = $this->membersRepository->findModifiedSince($cursor);
        
        // Should return empty (no items modified after cursor)
        $this->assertEmpty($results, 'Should return no results when cursor is current time');
        
        // Verify query would catch items created AFTER cursor
        // Create a member after the cursor timestamp
        sleep(1);
        $afterCursor = time() * 1000;
        $testMemberId = $this->generateUuid();
        $testMember = [
            'id' => $testMemberId,
            'card_uid' => '04:AA:BB:CC:DD:EE:99',
            'first_name' => 'RaceTest',
            'last_name' => 'Member',
            'email' => 'race-test@example.com',
            'preferred_language' => 'de',
            'is_active' => 1,
            'iban' => 'DE89370400440532013000',
            'mandate_reference' => 'MANDATE' . substr($testMemberId, 0, 8),
            'mandate_signed_at' => '2025-01-01',
        ];
        $this->membersRepository->create($testMember);
        $this->testMemberIds[] = $testMemberId;
        
        // Query with original cursor should now find the new member
        $resultsAfter = $this->membersRepository->findModifiedSince($cursor);
        $found = false;
        foreach ($resultsAfter as $result) {
            if ($result['id'] === $testMemberId) {
                $found = true;
                break;
            }
        }
        
        $this->assertTrue($found, 'Member created after cursor should be found in next sync');
    }

    // ------------------------------------------------------------------
    // Banking data, which lives on the append-only mandate record (#164)
    // ------------------------------------------------------------------

    public function test_create_mints_a_mandate_reference_when_an_iban_is_given(): void
    {
        // No reference named at all — ADR-0006 says mint one.
        $id = $this->generateUuid();
        $this->testMemberIds[] = $id;

        $member = $this->membersRepository->create([
            'id' => $id,
            'first_name' => 'Minted',
            'last_name' => 'Member',
            'email' => "minted-{$id}@example.com",
            'iban' => 'DE89370400440532013000',
            'mandate_signed_at' => '2025-01-01',
        ]);

        $this->assertSame('DE89370400440532013000', $member['iban']);
        $this->assertNotEmpty($member['mandate_reference']);
        $this->assertSame('2025-01-01', $member['mandate_signed_at']);
    }

    public function test_a_member_without_an_iban_has_no_mandate_reference(): void
    {
        $id = $this->generateUuid();
        $this->testMemberIds[] = $id;

        $member = $this->membersRepository->create([
            'id' => $id,
            'first_name' => 'NoBank',
            'last_name' => 'Member',
            'email' => "nobank-{$id}@example.com",
        ]);

        $this->assertNull($member['iban']);
        $this->assertNull(
            $member['mandate_reference'],
            'a reference minted from an IBAN-less member would assert a mandate that does not exist'
        );
    }

    public function test_an_explicitly_empty_reference_opens_no_mandate_even_with_an_iban(): void
    {
        // Auto-minting a reference is what makes a missing mandate invisible
        // (#164). The caller saying "no reference" must therefore leave the
        // member SEPA-invalid rather than have one minted on their behalf.
        $member = $this->createMemberWithBankingData(['mandate_reference' => '']);

        $this->assertNull($member['mandate_reference']);
        $this->assertNull($member['iban']);
        $this->assertSame(0, $this->countMandates($member['id']));
    }

    public function test_an_empty_iban_is_stored_as_no_banking_data_at_all(): void
    {
        $member = $this->createMemberWithBankingData(['iban' => '']);

        $this->assertNull($member['iban']);
        $this->assertSame(0, $this->countMandates($member['id']));
    }

    public function test_resubmitting_the_same_banking_data_leaves_the_mandate_alone(): void
    {
        // The admin edit form round-trips every field, so an unrelated edit
        // re-sends the unchanged IBAN and reference. That must not be read as a
        // bank change: opening a second mandate carrying the same reference
        // would collide, and the member would appear to have moved banks.
        $member = $this->createMemberWithBankingData();
        $reference = $member['mandate_reference'];

        $updated = $this->membersRepository->updateById($member['id'], [
            'first_name' => 'Renamed',
            'iban' => $member['iban'],
            'mandate_reference' => $reference,
            'mandate_signed_at' => $member['mandate_signed_at'],
        ]);

        $this->assertSame('Renamed', $updated['first_name']);
        $this->assertSame($reference, $updated['mandate_reference']);
        $this->assertSame(1, $this->countMandates($member['id']));
    }

    public function test_a_bank_change_ends_the_old_mandate_and_opens_a_new_one(): void
    {
        $member = $this->createMemberWithBankingData();
        $originalReference = $member['mandate_reference'];

        $updated = $this->membersRepository->updateById($member['id'], [
            'iban' => 'DE02120300000000202051',
        ]);

        $this->assertSame('DE02120300000000202051', $updated['iban']);
        $this->assertNotSame(
            $originalReference,
            $updated['mandate_reference'],
            'a new bank account is a new mandate, and SEPA convention gives it a new reference'
        );
        $this->assertSame(
            2,
            $this->countMandates($member['id']),
            'the superseded mandate must survive so a return quoting its MREF+ still resolves'
        );
    }

    public function test_clearing_the_iban_revokes_the_mandate(): void
    {
        $member = $this->createMemberWithBankingData();

        $updated = $this->membersRepository->updateById($member['id'], ['iban' => null]);

        $this->assertNull($updated['iban']);
        $this->assertNull($updated['mandate_reference']);
        $this->assertSame(1, $this->countMandates($member['id']), 'the ended mandate is kept, not deleted');
    }

    public function test_anonymize_ends_the_mandate_but_keeps_the_record(): void
    {
        $member = $this->createMemberWithBankingData();

        $this->assertTrue($this->membersRepository->anonymize($member['id']));

        $anonymized = $this->membersRepository->findByIdIncludingDeleted($member['id']);
        $this->assertNull($anonymized['iban']);
        $this->assertNull($anonymized['mandate_reference']);
        $this->assertSame(
            1,
            $this->countMandates($member['id']),
            'erasure removes the person; the mandate record is what a bank return still has to resolve (#165)'
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function createMemberWithBankingData(array $overrides = []): array
    {
        $id = $this->generateUuid();
        $this->testMemberIds[] = $id;

        return $this->membersRepository->create(array_merge([
            'id' => $id,
            'first_name' => 'Banking',
            'last_name' => 'Member',
            'email' => "banking-{$id}@example.com",
            'iban' => 'DE89370400440532013000',
            'mandate_reference' => 'MANDATE' . substr($id, 0, 8),
            'mandate_signed_at' => '2025-01-01',
        ], $overrides));
    }

    private function countMandates(string $memberId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM mandates WHERE member_id = ?');
        $stmt->execute([$memberId]);

        return (int) $stmt->fetchColumn();
    }
}
