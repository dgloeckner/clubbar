<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\Services;

use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Settlements\Repositories\CollectionHoldRepository;
use App\Modules\Settlements\Services\CollectionHoldService;
use App\Shared\Enums\AuditAction;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

/**
 * Seeing and clearing collection holds (#196, ruling #148 §4-§5).
 */
class CollectionHoldServiceTest extends TestCase
{
    private CollectionHoldRepository $collectionHoldRepository;
    private MembersRepository $membersRepository;
    private AuditService $auditService;
    private CollectionHoldService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->collectionHoldRepository = $this->createMock(CollectionHoldRepository::class);
        $this->membersRepository = $this->createMock(MembersRepository::class);
        $this->auditService = $this->createMock(AuditService::class);

        $this->service = new CollectionHoldService(
            $this->collectionHoldRepository,
            $this->membersRepository,
            $this->auditService,
        );
    }

    public function test_the_listing_carries_each_hold_with_its_reason_and_sums_what_is_held(): void
    {
        $this->collectionHoldRepository->method('findAllHeld')->willReturn([
            $this->heldRow('m-1', 'Alice', 1500),
            $this->heldRow('m-2', 'Bob', 2500),
        ]);

        $result = $this->service->listHeld();

        $this->assertCount(2, $result['items']);
        $this->assertSame(4000, $result['total_held_cents']);
        $this->assertSame('Returned unpaid', $result['items'][0]->reason);
    }

    public function test_an_empty_listing_totals_zero(): void
    {
        $this->collectionHoldRepository->method('findAllHeld')->willReturn([]);

        $this->assertSame(['items' => [], 'total_held_cents' => 0], $this->service->listHeld());
    }

    public function test_clearing_a_hold_releases_the_member_and_records_who_did_it(): void
    {
        $this->membersRepository->method('findById')->willReturn([
            'id' => 'm-1',
            'first_name' => 'Alice',
            'last_name' => 'Held',
            'collection_hold' => 1,
            'collection_hold_reason' => 'Returned unpaid',
            'held_at' => '2026-08-08 10:00:00',
            'held_by_admin_id' => 'admin-1',
        ]);

        $this->collectionHoldRepository->expects($this->once())->method('clear')->with('m-1', 'admin-2');
        $this->auditService->expects($this->once())
            ->method('log')
            ->with(
                AuditAction::COLLECTION_HOLD_CLEARED,
                $this->anything(),
                'm-1',
                $this->anything(),
                $this->anything(),
                'admin-2',
            );

        $hold = $this->service->clearHold('m-1', 'admin-2');

        $this->assertSame('m-1', $hold->memberId);
        $this->assertSame('Returned unpaid', $hold->reason, 'why the hold existed outlives the hold itself');
    }

    public function test_clearing_a_hold_on_an_unknown_member_is_a_404(): void
    {
        $this->membersRepository->method('findById')->willReturn(null);

        $this->collectionHoldRepository->expects($this->never())->method('clear');

        $this->expectException(NotFoundException::class);
        $this->service->clearHold('nope', 'admin-1');
    }

    public function test_clearing_a_hold_that_is_not_there_is_a_409(): void
    {
        // Silently succeeding would put a second "cleared" entry in the audit
        // log for a hold somebody else already released.
        $this->membersRepository->method('findById')->willReturn([
            'id' => 'm-1',
            'first_name' => 'Alice',
            'last_name' => 'Free',
            'collection_hold' => 0,
        ]);

        $this->collectionHoldRepository->expects($this->never())->method('clear');

        $this->expectException(BusinessRuleException::class);
        $this->service->clearHold('m-1', 'admin-1');
    }

    /** @return array<string, mixed> */
    private function heldRow(string $memberId, string $firstName, int $balanceCents): array
    {
        return [
            'member_id' => $memberId,
            'first_name' => $firstName,
            'last_name' => 'Held',
            'collection_hold_reason' => 'Returned unpaid',
            'held_at' => '2026-08-08 10:00:00',
            'held_by_admin_id' => 'admin-1',
            'admin_display_name' => 'The Treasurer',
            'balance_cents' => $balanceCents,
        ];
    }
}
