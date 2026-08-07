<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\Services;

use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Settlements\DTOs\SettlementDto;
use App\Modules\Settlements\DTOs\SettlementPreviewDto;
use App\Modules\Settlements\Repositories\SettlementsRepository;
use App\Modules\Settlements\Services\SettlementsService;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

class SettlementsServiceTest extends TestCase
{
    private SettlementsRepository $settlementsRepository;
    private MembersRepository $membersRepository;
    private TransactionsRepository $transactionsRepository;
    private AuditService $auditService;
    private \PDO $db;
    private SettlementsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settlementsRepository = $this->createMock(SettlementsRepository::class);
        $this->membersRepository = $this->createMock(MembersRepository::class);
        $this->transactionsRepository = $this->createMock(TransactionsRepository::class);
        $this->auditService = $this->createMock(AuditService::class);
        $this->db = $this->createMock(\PDO::class);

        $this->service = new SettlementsService(
            $this->settlementsRepository,
            $this->membersRepository,
            $this->transactionsRepository,
            $this->auditService,
            $this->db,
        );
    }

    private function member(string $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'first_name' => 'Max',
            'last_name' => 'Mustermann',
            'iban' => 'DE89370400440532013000',
            'mandate_reference' => 'F3332CA866B249E7A202BFBF4836B605',
            'is_active' => 1,
        ], $overrides);
    }

    // ── previewSettlement ────────────────────────────────────────────

    public function test_previewSettlement_returns_empty_dto_when_no_balances(): void
    {
        $this->settlementsRepository->method('calculateMemberBalances')->willReturn([]);

        $result = $this->service->previewSettlement('2026-01-01', '2026-01-31');

        $this->assertInstanceOf(SettlementPreviewDto::class, $result);
        $this->assertSame([], $result->eligibleMembers);
        $this->assertSame([], $result->ineligibleMembers);
        $this->assertSame(0, $result->eligibleTotal);
        $this->assertSame(0, $result->ineligibleTotal);
        $this->assertSame(0, $result->memberCount);
        $this->assertSame([], $result->warnings);
    }

    public function test_previewSettlement_passes_date_range_to_repository(): void
    {
        $this->settlementsRepository
            ->expects($this->once())
            ->method('calculateMemberBalances')
            ->with('2026-01-01', '2026-01-31')
            ->willReturn([]);

        $this->service->previewSettlement('2026-01-01', '2026-01-31');
    }

    public function test_previewSettlement_filters_by_memberId(): void
    {
        $memberIdA = 'member-a';
        $memberIdB = 'member-b';

        $this->settlementsRepository->method('calculateMemberBalances')->willReturn([
            $memberIdA => 1000,
            $memberIdB => 2000,
        ]);

        $this->membersRepository
            ->expects($this->once())
            ->method('findById')
            ->with($memberIdA)
            ->willReturn($this->member($memberIdA));

        $result = $this->service->previewSettlement(memberId: $memberIdA);

        $this->assertCount(1, $result->eligibleMembers);
        $this->assertSame($memberIdA, $result->eligibleMembers[0]['member_id']);
    }

    public function test_previewSettlement_separates_eligible_and_ineligible_members(): void
    {
        $eligibleId = 'eligible-member';
        $ineligibleId = 'ineligible-member';

        $this->settlementsRepository->method('calculateMemberBalances')->willReturn([
            $eligibleId => 1500,
            $ineligibleId => 500,
        ]);

        $this->membersRepository->method('findById')->willReturnMap([
            [$eligibleId, $this->member($eligibleId)],
            [$ineligibleId, $this->member($ineligibleId, ['iban' => null, 'mandate_reference' => null])],
        ]);

        $result = $this->service->previewSettlement();

        $this->assertCount(1, $result->eligibleMembers);
        $this->assertCount(1, $result->ineligibleMembers);
        $this->assertSame($eligibleId, $result->eligibleMembers[0]['member_id']);
        $this->assertSame($ineligibleId, $result->ineligibleMembers[0]['member_id']);
        $this->assertSame(1500, $result->eligibleTotal);
        $this->assertSame(500, $result->ineligibleTotal);
        $this->assertSame(2, $result->memberCount);
        $this->assertCount(1, $result->warnings);
        $this->assertStringContainsString('not SEPA-eligible', $result->warnings[0]);
    }

    public function test_previewSettlement_member_inactive_is_ineligible(): void
    {
        $memberId = 'inactive-member';
        $this->settlementsRepository->method('calculateMemberBalances')->willReturn([$memberId => 100]);
        $this->membersRepository->method('findById')->willReturn($this->member($memberId, ['is_active' => 0]));

        $result = $this->service->previewSettlement();

        $this->assertCount(0, $result->eligibleMembers);
        $this->assertCount(1, $result->ineligibleMembers);
    }

    public function test_previewSettlement_sepaEligibleOnly_drops_ineligible_members_and_warnings(): void
    {
        $eligibleId = 'eligible-member';
        $ineligibleId = 'ineligible-member';

        $this->settlementsRepository->method('calculateMemberBalances')->willReturn([
            $eligibleId => 1500,
            $ineligibleId => 500,
        ]);

        $this->membersRepository->method('findById')->willReturnMap([
            [$eligibleId, $this->member($eligibleId)],
            [$ineligibleId, $this->member($ineligibleId, ['iban' => null, 'mandate_reference' => null])],
        ]);

        $result = $this->service->previewSettlement(sepaEligibleOnly: true);

        $this->assertCount(1, $result->eligibleMembers);
        $this->assertCount(0, $result->ineligibleMembers);
        $this->assertSame([], $result->warnings);
        // NOTE: memberCount only counts members actually placed into a bucket;
        // sepaEligibleOnly silently drops ineligible members from the count too.
        $this->assertSame(1, $result->memberCount);
    }

    public function test_previewSettlement_skips_members_that_no_longer_exist(): void
    {
        $missingId = 'missing-member';
        $this->settlementsRepository->method('calculateMemberBalances')->willReturn([$missingId => 100]);
        $this->membersRepository->method('findById')->willReturn(null);

        $result = $this->service->previewSettlement();

        $this->assertSame([], $result->eligibleMembers);
        $this->assertSame([], $result->ineligibleMembers);
        $this->assertSame(0, $result->memberCount);
    }

    // ── previewByFilters ─────────────────────────────────────────────

    public function test_previewByFilters_delegates_to_transactions_repository(): void
    {
        $filters = ['date_from' => '2026-01-01', 'date_to' => '2026-01-31'];
        $expected = ['transaction_count' => 3, 'member_count' => 2, 'total_amount_cents' => 4500];

        $this->transactionsRepository
            ->expects($this->once())
            ->method('summarizeUnsettledByFilters')
            ->with($filters)
            ->willReturn($expected);

        $result = $this->service->previewByFilters($filters);

        $this->assertSame($expected, $result);
    }

    // ── createSettlement ─────────────────────────────────────────────

    public function test_createSettlement_rejects_transactions_already_settled(): void
    {
        $this->settlementsRepository->method('hasConflicts')->willReturn([
            ['transaction_id' => 'tx-1', 'settlement_date' => '2026-01-01'],
        ]);

        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('rollBack');
        $this->db->expects($this->never())->method('commit');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Some transactions are already settled');

        $this->service->createSettlement(['tx-1'], '2026-01-01', '2026-01-15', null, null, null, null, 'admin-1');
    }

    public function test_createSettlement_rejects_when_no_valid_unsettled_transactions_found(): void
    {
        $this->settlementsRepository->method('hasConflicts')->willReturn([]);
        $this->transactionsRepository->method('findUnsettledByIds')->willReturn([]);

        $this->db->expects($this->once())->method('rollBack');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('No valid unsettled transactions found');

        $this->service->createSettlement(['tx-1'], '2026-01-01', '2026-01-15', null, null, null, null, 'admin-1');
    }

    public function test_createSettlement_persists_items_and_writes_audit_entry(): void
    {
        $transactions = [
            ['id' => 'tx-1', 'member_id' => 'member-a', 'amount_cents' => 500],
            ['id' => 'tx-2', 'member_id' => 'member-b', 'amount_cents' => 750],
        ];
        $settlementRow = [
            'id' => 'settlement-1',
            'manual_reason' => null,
            'settlement_date' => '2026-01-01',
            'execution_date' => '2026-01-15',
            'period_start' => null,
            'period_end' => null,
            'sepa_message_id' => 'SEPA-ABC123',
            'total_amount_cents' => 1250,
            'member_count' => 2,
            'is_cancelled' => 0,
            'cancelled_at' => null,
            'exported_at' => null,
            'notes' => null,
            'created_at' => '2026-01-01 10:00:00',
            'created_by_admin_id' => 'admin-1',
        ];

        $this->settlementsRepository->method('hasConflicts')->willReturn([]);
        $this->transactionsRepository->method('findUnsettledByIds')->willReturn($transactions);
        $this->settlementsRepository->method('getNextSepaMessageId')->willReturn('SEPA-ABC123');

        $this->settlementsRepository
            ->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $data) {
                return $data['total_amount_cents'] === 1250
                    && $data['member_count'] === 2
                    && $data['settlement_date'] === '2026-01-01'
                    && $data['execution_date'] === '2026-01-15'
                    && $data['sepa_message_id'] === 'SEPA-ABC123'
                    && $data['created_by_admin_id'] === 'admin-1';
            }))
            ->willReturn($settlementRow);

        $this->settlementsRepository
            ->expects($this->exactly(2))
            ->method('createItem')
            ->with($this->callback(function (array $data) {
                return $data['settlement_id'] === 'settlement-1'
                    && in_array($data['transaction_id'], ['tx-1', 'tx-2'], true);
            }));

        $this->auditService
            ->expects($this->once())
            ->method('log')
            ->with(
                $this->equalTo(AuditAction::SETTLEMENT_CREATE),
                $this->equalTo(EntityType::SETTLEMENT),
                'settlement-1',
                null,
                ['total_amount_cents' => 1250, 'member_count' => 2, 'transaction_count' => 2],
                'admin-1',
            );

        $this->settlementsRepository->method('findItemsBySettlementId')->willReturn([]);

        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');
        $this->db->expects($this->never())->method('rollBack');

        $result = $this->service->createSettlement(['tx-1', 'tx-2'], '2026-01-01', '2026-01-15', null, null, null, null, 'admin-1');

        $this->assertInstanceOf(SettlementDto::class, $result);
        $this->assertSame('settlement-1', $result->id);
        $this->assertSame(1250, $result->totalAmountCents);
        $this->assertSame(2, $result->memberCount);
    }

    public function test_createSettlement_passes_manualReason_through_for_manual_settlements(): void
    {
        // NOTE: SettlementsService has no settlement_type parameter — manual vs
        // SEPA is not distinguished here. manualReason is simply persisted
        // verbatim as an optional free-text/enum value; there is no validation
        // tying it to a "settlement_type" concept in this service.
        $transactions = [['id' => 'tx-1', 'member_id' => 'member-a', 'amount_cents' => 500]];
        $settlementRow = [
            'id' => 'settlement-1',
            'manual_reason' => 'cash',
            'settlement_date' => '2026-01-01',
            'execution_date' => '2026-01-15',
            'period_start' => null,
            'period_end' => null,
            'sepa_message_id' => null,
            'total_amount_cents' => 500,
            'member_count' => 1,
            'is_cancelled' => 0,
            'cancelled_at' => null,
            'exported_at' => null,
            'notes' => null,
            'created_at' => '2026-01-01 10:00:00',
            'created_by_admin_id' => 'admin-1',
        ];

        $this->settlementsRepository->method('hasConflicts')->willReturn([]);
        $this->transactionsRepository->method('findUnsettledByIds')->willReturn($transactions);
        $this->settlementsRepository->method('getNextSepaMessageId')->willReturn('SEPA-XYZ');

        $this->settlementsRepository
            ->expects($this->once())
            ->method('create')
            ->with($this->callback(fn(array $data) => $data['manual_reason'] === 'cash'))
            ->willReturn($settlementRow);

        $this->settlementsRepository->method('findItemsBySettlementId')->willReturn([]);

        $result = $this->service->createSettlement(['tx-1'], '2026-01-01', '2026-01-15', null, null, 'cash', null, 'admin-1');

        $this->assertSame('cash', $result->manualReason);
    }

    public function test_createSettlement_does_not_validate_execution_date_is_a_business_day(): void
    {
        // NOTE: Contrary to the SEPA export path (SepaExportService, which
        // rejects non-business-day execution dates), SettlementsService::
        // createSettlement performs no such validation itself. A weekend
        // execution date is accepted here; the guard only exists at export
        // time. This test documents the actual current behaviour.
        $transactions = [['id' => 'tx-1', 'member_id' => 'member-a', 'amount_cents' => 500]];
        $settlementRow = [
            'id' => 'settlement-1',
            'manual_reason' => null,
            'settlement_date' => '2026-01-01',
            'execution_date' => '2026-08-09', // a Sunday
            'period_start' => null,
            'period_end' => null,
            'sepa_message_id' => null,
            'total_amount_cents' => 500,
            'member_count' => 1,
            'is_cancelled' => 0,
            'cancelled_at' => null,
            'exported_at' => null,
            'notes' => null,
            'created_at' => '2026-01-01 10:00:00',
            'created_by_admin_id' => 'admin-1',
        ];

        $this->settlementsRepository->method('hasConflicts')->willReturn([]);
        $this->transactionsRepository->method('findUnsettledByIds')->willReturn($transactions);
        $this->settlementsRepository->method('getNextSepaMessageId')->willReturn('SEPA-XYZ');
        $this->settlementsRepository->method('create')->willReturn($settlementRow);
        $this->settlementsRepository->method('findItemsBySettlementId')->willReturn([]);

        $result = $this->service->createSettlement(['tx-1'], '2026-01-01', '2026-08-09', null, null, null, null, 'admin-1');

        $this->assertSame('2026-08-09', $result->executionDate);
    }

    public function test_createSettlement_rolls_back_and_rethrows_on_unexpected_failure(): void
    {
        $this->settlementsRepository->method('hasConflicts')->willReturn([]);
        $this->transactionsRepository->method('findUnsettledByIds')->willReturn([
            ['id' => 'tx-1', 'member_id' => 'member-a', 'amount_cents' => 500],
        ]);
        $this->settlementsRepository->method('getNextSepaMessageId')->willReturn('SEPA-XYZ');
        $this->settlementsRepository->method('create')->willThrowException(new \RuntimeException('db exploded'));

        $this->db->expects($this->once())->method('rollBack');
        $this->db->expects($this->never())->method('commit');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('db exploded');

        $this->service->createSettlement(['tx-1'], '2026-01-01', '2026-01-15', null, null, null, null, 'admin-1');
    }

    // ── createSettlementByFilters ────────────────────────────────────

    public function test_createSettlementByFilters_throws_when_no_unsettled_transactions_match(): void
    {
        $filters = ['date_from' => '2026-01-01', 'date_to' => '2026-01-31'];
        $this->transactionsRepository->method('findAllUnsettledByFilters')->with($filters)->willReturn([]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('No unsettled transactions found for the given filters');

        $this->service->createSettlementByFilters($filters, '2026-01-01', '2026-01-15', 'admin-1');
    }

    public function test_createSettlementByFilters_uses_same_transaction_selection_as_previewByFilters(): void
    {
        $filters = ['date_from' => '2026-01-01', 'date_to' => '2026-01-31'];
        $transactionIds = ['tx-1', 'tx-2'];

        // previewByFilters and createSettlementByFilters both scope the
        // "unsettled" selection using the same $filters array — verify both
        // repository calls receive an identical filters payload.
        $this->transactionsRepository
            ->expects($this->once())
            ->method('summarizeUnsettledByFilters')
            ->with($filters)
            ->willReturn(['transaction_count' => 2, 'member_count' => 1, 'total_amount_cents' => 1250]);

        $this->transactionsRepository
            ->expects($this->once())
            ->method('findAllUnsettledByFilters')
            ->with($filters)
            ->willReturn($transactionIds);

        $this->transactionsRepository->method('findUnsettledByIds')->with($transactionIds)->willReturn([
            ['id' => 'tx-1', 'member_id' => 'member-a', 'amount_cents' => 500],
            ['id' => 'tx-2', 'member_id' => 'member-b', 'amount_cents' => 750],
        ]);
        $this->settlementsRepository->method('hasConflicts')->willReturn([]);
        $this->settlementsRepository->method('getNextSepaMessageId')->willReturn('SEPA-XYZ');
        $this->settlementsRepository->method('create')->willReturn([
            'id' => 'settlement-1',
            'manual_reason' => null,
            'settlement_date' => '2026-01-01',
            'execution_date' => '2026-01-15',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'sepa_message_id' => 'SEPA-XYZ',
            'total_amount_cents' => 1250,
            'member_count' => 2,
            'is_cancelled' => 0,
            'cancelled_at' => null,
            'exported_at' => null,
            'notes' => null,
            'created_at' => '2026-01-01 10:00:00',
            'created_by_admin_id' => 'admin-1',
        ]);
        $this->settlementsRepository->method('findItemsBySettlementId')->willReturn([]);

        $previewResult = $this->service->previewByFilters($filters);
        $createResult = $this->service->createSettlementByFilters($filters, '2026-01-01', '2026-01-15', 'admin-1');

        $this->assertSame(2, $previewResult['transaction_count']);
        $this->assertSame('2026-01-01', $createResult->periodStart);
        $this->assertSame('2026-01-31', $createResult->periodEnd);
    }

    public function test_createSettlementByFilters_derives_period_from_filter_dates(): void
    {
        $filters = ['date_from' => '2026-02-01', 'date_to' => '2026-02-28', 'search' => 'foo'];
        $this->transactionsRepository->method('findAllUnsettledByFilters')->willReturn(['tx-1']);
        $this->transactionsRepository->method('findUnsettledByIds')->willReturn([
            ['id' => 'tx-1', 'member_id' => 'member-a', 'amount_cents' => 500],
        ]);
        $this->settlementsRepository->method('hasConflicts')->willReturn([]);
        $this->settlementsRepository->method('getNextSepaMessageId')->willReturn('SEPA-XYZ');

        $this->settlementsRepository
            ->expects($this->once())
            ->method('create')
            ->with($this->callback(fn(array $data) => $data['period_start'] === '2026-02-01' && $data['period_end'] === '2026-02-28'))
            ->willReturn([
                'id' => 'settlement-1',
                'manual_reason' => null,
                'settlement_date' => '2026-02-01',
                'execution_date' => '2026-02-15',
                'period_start' => '2026-02-01',
                'period_end' => '2026-02-28',
                'sepa_message_id' => 'SEPA-XYZ',
                'total_amount_cents' => 500,
                'member_count' => 1,
                'is_cancelled' => 0,
                'cancelled_at' => null,
                'exported_at' => null,
                'notes' => null,
                'created_at' => '2026-02-01 10:00:00',
                'created_by_admin_id' => 'admin-1',
            ]);
        $this->settlementsRepository->method('findItemsBySettlementId')->willReturn([]);

        $this->service->createSettlementByFilters($filters, '2026-02-01', '2026-02-15', 'admin-1');
    }

    // ── getSettlement ────────────────────────────────────────────────

    public function test_getSettlement_returns_null_when_missing(): void
    {
        $this->settlementsRepository->method('findById')->willReturn(null);

        $this->assertNull($this->service->getSettlement('missing-id'));
    }

    public function test_getSettlement_returns_dto_with_items_when_found(): void
    {
        $settlementRow = [
            'id' => 'settlement-1',
            'manual_reason' => null,
            'settlement_date' => '2026-01-01',
            'execution_date' => '2026-01-15',
            'period_start' => null,
            'period_end' => null,
            'sepa_message_id' => null,
            'total_amount_cents' => 500,
            'member_count' => 1,
            'is_cancelled' => 0,
            'cancelled_at' => null,
            'exported_at' => null,
            'notes' => null,
            'created_at' => '2026-01-01 10:00:00',
            'created_by_admin_id' => 'admin-1',
        ];
        $itemRow = [
            'settlement_id' => 'settlement-1',
            'transaction_id' => 'tx-1',
            'member_id' => 'member-a',
            'amount_cents' => 500,
            'first_name' => 'Max',
            'last_name' => 'Mustermann',
        ];

        $this->settlementsRepository->method('findById')->with('settlement-1')->willReturn($settlementRow);
        $this->settlementsRepository->method('findItemsBySettlementId')->with('settlement-1')->willReturn([$itemRow]);

        $result = $this->service->getSettlement('settlement-1');

        $this->assertInstanceOf(SettlementDto::class, $result);
        $this->assertSame('settlement-1', $result->id);
        $this->assertCount(1, $result->items);
        $this->assertSame('tx-1', $result->items[0]->transactionId);
    }

    // ── listSettlements ──────────────────────────────────────────────

    public function test_listSettlements_passes_pagination_sort_and_date_filters_through(): void
    {
        $this->settlementsRepository
            ->expects($this->once())
            ->method('listPaginated')
            ->with(25, 50, 'active', 'created_at', 'asc', '2026-01-01', '2026-01-31')
            ->willReturn(['items' => [], 'total' => 0]);

        $result = $this->service->listSettlements(25, 50, 'active', 'created_at', 'asc', '2026-01-01', '2026-01-31');

        $this->assertInstanceOf(PaginatedResultDto::class, $result);
        $this->assertSame(25, $result->limit);
        $this->assertSame(50, $result->offset);
        $this->assertSame(0, $result->total);
    }

    public function test_listSettlements_maps_rows_to_settlement_array_shape(): void
    {
        $settlementRow = [
            'id' => 'settlement-1',
            'manual_reason' => null,
            'settlement_date' => '2026-01-01',
            'execution_date' => '2026-01-15',
            'period_start' => null,
            'period_end' => null,
            'sepa_message_id' => null,
            'total_amount_cents' => 500,
            'member_count' => 1,
            'is_cancelled' => 0,
            'cancelled_at' => null,
            'exported_at' => null,
            'notes' => null,
            'created_at' => '2026-01-01 10:00:00',
            'created_by_admin_id' => 'admin-1',
        ];

        $this->settlementsRepository->method('listPaginated')->willReturn(['items' => [$settlementRow], 'total' => 1]);

        $result = $this->service->listSettlements(10, 0);

        $this->assertSame(1, $result->total);
        $this->assertIsArray($result->items[0]);
        $this->assertSame('settlement-1', $result->items[0]['id']);
        $this->assertArrayHasKey('total_amount_eur', $result->items[0]);
    }

    // ── cancelSettlement ─────────────────────────────────────────────

    public function test_cancelSettlement_throws_notFoundException_when_missing(): void
    {
        $this->settlementsRepository->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);

        $this->service->cancelSettlement('missing-id', 'admin-1');
    }

    public function test_cancelSettlement_delegates_to_repository_and_writes_audit_entry(): void
    {
        $settlementRow = ['id' => 'settlement-1', 'is_cancelled' => 0];
        $this->settlementsRepository->method('findById')->willReturn($settlementRow);
        $this->settlementsRepository
            ->expects($this->once())
            ->method('cancelSettlement')
            ->with('settlement-1', 'admin-1')
            ->willReturn(true);

        $this->auditService
            ->expects($this->once())
            ->method('log')
            ->with(
                $this->equalTo(AuditAction::SETTLEMENT_CANCEL),
                $this->equalTo(EntityType::SETTLEMENT),
                'settlement-1',
                ['is_cancelled' => false],
                ['is_cancelled' => true, 'reason' => 'duplicate'],
                'admin-1',
            );

        $result = $this->service->cancelSettlement('settlement-1', 'admin-1', 'duplicate');

        $this->assertTrue($result);
    }

    public function test_cancelSettlement_does_not_guard_against_double_cancellation(): void
    {
        // NOTE: SettlementsService::cancelSettlement has no is_cancelled guard —
        // it looks the settlement up by id (regardless of its cancelled state)
        // and unconditionally calls the repository's cancelSettlement again,
        // which would re-run the DELETE/UPDATE. This documents the current
        // behaviour rather than an intended safeguard.
        $alreadyCancelledRow = ['id' => 'settlement-1', 'is_cancelled' => 1];
        $this->settlementsRepository->method('findById')->willReturn($alreadyCancelledRow);
        $this->settlementsRepository->expects($this->once())->method('cancelSettlement')->willReturn(true);

        $result = $this->service->cancelSettlement('settlement-1', 'admin-1');

        $this->assertTrue($result);
    }

    public function test_cancelSettlement_does_not_guard_against_cancelling_an_exported_settlement(): void
    {
        // NOTE: There is no check on exported_at either — an already-exported
        // settlement can still be "cancelled" through this service method.
        $exportedRow = ['id' => 'settlement-1', 'is_cancelled' => 0, 'exported_at' => '2026-01-02 09:00:00'];
        $this->settlementsRepository->method('findById')->willReturn($exportedRow);
        $this->settlementsRepository->expects($this->once())->method('cancelSettlement')->willReturn(true);

        $result = $this->service->cancelSettlement('settlement-1', 'admin-1');

        $this->assertTrue($result);
    }

    // ── markExported ─────────────────────────────────────────────────

    public function test_markExported_delegates_to_repository_and_writes_audit_entry(): void
    {
        $this->settlementsRepository
            ->expects($this->once())
            ->method('markExported')
            ->with('settlement-1')
            ->willReturn(true);

        $this->auditService
            ->expects($this->once())
            ->method('log')
            ->with(
                $this->equalTo(AuditAction::SETTLEMENT_EXPORT),
                $this->equalTo(EntityType::SETTLEMENT),
                'settlement-1',
                null,
                $this->callback(fn(array $newValues) => array_key_exists('exported_at', $newValues)),
                'admin-1',
            );

        $result = $this->service->markExported('settlement-1', 'admin-1');

        $this->assertTrue($result);
    }

    // ── getCsvData ───────────────────────────────────────────────────

    public function test_getCsvData_returns_empty_array_when_no_items(): void
    {
        $this->settlementsRepository->method('findItemsBySettlementId')->willReturn([]);

        $this->assertSame([], $this->service->getCsvData('settlement-1'));
    }

    public function test_getCsvData_aggregates_multiple_items_per_member_and_looks_up_member_including_deleted(): void
    {
        $items = [
            ['member_id' => 'member-a', 'first_name' => 'Max', 'last_name' => 'Mustermann', 'amount_cents' => 500],
            ['member_id' => 'member-a', 'first_name' => 'Max', 'last_name' => 'Mustermann', 'amount_cents' => 250],
            ['member_id' => 'member-b', 'first_name' => 'Erika', 'last_name' => 'Musterfrau', 'amount_cents' => 1000],
        ];
        $this->settlementsRepository->method('findItemsBySettlementId')->with('settlement-1')->willReturn($items);

        $this->membersRepository
            ->expects($this->exactly(2))
            ->method('findByIdIncludingDeleted')
            ->willReturnMap([
                ['member-a', ['id' => 'member-a', 'email' => 'max@example.com', 'iban' => 'DE89370400440532013000']],
                ['member-b', ['id' => 'member-b', 'email' => 'erika@example.com', 'iban' => 'DE02100100100006820101']],
            ]);

        $result = $this->service->getCsvData('settlement-1');

        $this->assertCount(2, $result);
        $this->assertSame('Max Mustermann', $result[0]['name']);
        $this->assertSame('max@example.com', $result[0]['email']);
        $this->assertSame('DE89370400440532013000', $result[0]['iban']);
        $this->assertSame(750, $result[0]['amount_cents']);
        $this->assertSame('Erika Musterfrau', $result[1]['name']);
        $this->assertSame(1000, $result[1]['amount_cents']);
    }

    public function test_getCsvData_handles_missing_member_gracefully(): void
    {
        $items = [
            ['member_id' => 'member-deleted', 'first_name' => 'Old', 'last_name' => 'Member', 'amount_cents' => 100],
        ];
        $this->settlementsRepository->method('findItemsBySettlementId')->willReturn($items);
        $this->membersRepository->method('findByIdIncludingDeleted')->willReturn(null);

        $result = $this->service->getCsvData('settlement-1');

        $this->assertCount(1, $result);
        $this->assertSame('', $result[0]['email']);
        $this->assertSame('', $result[0]['iban']);
        $this->assertSame(100, $result[0]['amount_cents']);
    }
}
