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
}
