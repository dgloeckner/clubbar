<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\Services;

use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Settlements\DTOs\SettlementDto;
use App\Modules\Settlements\DTOs\SettlementPreviewDto;
use App\Modules\Settlements\Enums\SettlementMethod;
use App\Modules\Settlements\Repositories\SettlementReversalsRepository;
use App\Modules\Settlements\Repositories\SettlementsRepository;
use App\Modules\Settlements\Services\SettlementsService;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

class SettlementsServiceTest extends TestCase
{
    private SettlementsRepository $settlementsRepository;
    private MembersRepository $membersRepository;
    private TransactionsRepository $transactionsRepository;
    private AuditService $auditService;
    private SettlementReversalsRepository $reversalsRepository;
    private \PDO $db;
    private SettlementsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settlementsRepository = $this->createMock(SettlementsRepository::class);
        $this->membersRepository = $this->createMock(MembersRepository::class);
        $this->transactionsRepository = $this->createMock(TransactionsRepository::class);
        $this->auditService = $this->createMock(AuditService::class);
        $this->reversalsRepository = $this->createMock(SettlementReversalsRepository::class);
        $this->db = $this->createMock(\PDO::class);

        $this->service = new SettlementsService(
            $this->settlementsRepository,
            $this->membersRepository,
            $this->transactionsRepository,
            $this->auditService,
            $this->db,
            $this->reversalsRepository,
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

    /**
     * Stub the collaborators the sweep needs: every member of $transactions
     * holds an active mandate, and their whole unsettled position is exactly
     * the transactions given.
     *
     * @param list<array{id: string, member_id: string, amount_cents: int}> $transactions
     */
    private function stubEligibleSweep(array $transactions): void
    {
        $positions = [];
        foreach ($transactions as $tx) {
            $positions[$tx['member_id']] = ($positions[$tx['member_id']] ?? 0) + $tx['amount_cents'];
        }

        $memberMap = [];
        foreach (array_keys($positions) as $memberId) {
            $memberMap[] = [$memberId, $this->member($memberId)];
        }

        $this->transactionsRepository->method('findUnsettledByMemberIds')->willReturn($transactions);
        $this->settlementsRepository->method('calculateUnsettledPositions')->willReturn($positions);
        $this->membersRepository->method('findById')->willReturnMap($memberMap);
    }

    /** A persisted settlement row, as the repository returns it. */
    private function settlementRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 'settlement-1',
            'method' => 'direct_debit',
            'settlement_date' => '2026-01-01',
            'execution_date' => '2026-01-15',
            'period_start' => null,
            'period_end' => null,
            'sepa_message_id' => 'SEPA-XYZ',
            'total_amount_cents' => 0,
            'member_count' => 1,
            'is_cancelled' => 0,
            'cancelled_at' => null,
            'exported_at' => null,
            'notes' => null,
            'created_at' => '2026-01-01 10:00:00',
            'created_by_admin_id' => 'admin-1',
        ], $overrides);
    }

    // ── previewSettlement ────────────────────────────────────────────

    public function test_previewSettlement_returns_empty_dto_when_nobody_participates(): void
    {
        $this->settlementsRepository->method('findParticipantMemberIds')->willReturn([]);

        $result = $this->service->previewSettlement('2026-01-01', '2026-01-31');

        $this->assertInstanceOf(SettlementPreviewDto::class, $result);
        $this->assertSame([], $result->eligibleMembers);
        $this->assertSame([], $result->ineligibleMembers);
        $this->assertSame([], $result->creditMembers);
        $this->assertSame(0, $result->eligibleTotal);
        $this->assertSame(0, $result->ineligibleTotal);
        $this->assertSame(0, $result->creditTotal);
        $this->assertSame(0, $result->memberCount);
        $this->assertSame([], $result->warnings);
    }

    public function test_previewSettlement_selects_participants_by_window_but_totals_by_whole_position(): void
    {
        $memberId = 'carry-forward-member';

        // The window picks who is in the run ...
        $this->settlementsRepository
            ->expects($this->once())
            ->method('findParticipantMemberIds')
            ->with('2026-02-01', '2026-02-28', null)
            ->willReturn([$memberId]);

        // ... and the balance tested is their whole unsettled position (#161 §1).
        $this->settlementsRepository
            ->expects($this->once())
            ->method('calculateUnsettledPositions')
            ->with([$memberId])
            ->willReturn([$memberId => -1500]);

        $this->membersRepository->method('findById')->willReturn($this->member($memberId));

        $result = $this->service->previewSettlement('2026-02-01', '2026-02-28');

        $this->assertSame([], $result->eligibleMembers);
        $this->assertCount(1, $result->creditMembers);
        $this->assertSame(-1500, $result->creditMembers[0]['balance_cents']);
    }

    public function test_previewSettlement_filters_by_memberId(): void
    {
        $memberIdA = 'member-a';

        $this->settlementsRepository
            ->expects($this->once())
            ->method('findParticipantMemberIds')
            ->with(null, null, $memberIdA)
            ->willReturn([$memberIdA]);
        $this->settlementsRepository->method('calculateUnsettledPositions')->willReturn([$memberIdA => 1000]);

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

        $this->settlementsRepository->method('findParticipantMemberIds')->willReturn([$eligibleId, $ineligibleId]);
        $this->settlementsRepository->method('calculateUnsettledPositions')->willReturn([
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
        $this->assertStringContainsString('no active SEPA mandate', $result->warnings[0]);
    }

    /**
     * Ruling #173 / #161: deactivation is temporary (a lost card), so it must
     * not strand debt the member genuinely owes.
     */
    public function test_previewSettlement_inactive_member_with_a_mandate_is_still_collected(): void
    {
        $memberId = 'inactive-member';
        $this->settlementsRepository->method('findParticipantMemberIds')->willReturn([$memberId]);
        $this->settlementsRepository->method('calculateUnsettledPositions')->willReturn([$memberId => 100]);
        $this->membersRepository->method('findById')->willReturn($this->member($memberId, ['is_active' => 0]));

        $result = $this->service->previewSettlement();

        $this->assertCount(1, $result->eligibleMembers);
        $this->assertCount(0, $result->ineligibleMembers);
    }

    /**
     * Eligibility is one question against the member's active mandate (#164),
     * not a conjunction of loose columns. An empty IBAN string used to count
     * as valid on the dashboard and invalid here.
     */
    public function test_previewSettlement_member_without_an_active_mandate_is_ineligible(): void
    {
        $memberId = 'no-mandate-member';
        $this->settlementsRepository->method('findParticipantMemberIds')->willReturn([$memberId]);
        $this->settlementsRepository->method('calculateUnsettledPositions')->willReturn([$memberId => 100]);
        $this->membersRepository->method('findById')->willReturn(
            $this->member($memberId, ['iban' => '', 'mandate_reference' => null])
        );

        $result = $this->service->previewSettlement();

        $this->assertCount(0, $result->eligibleMembers);
        $this->assertCount(1, $result->ineligibleMembers);
    }

    /**
     * Ruling #141: a member in credit is excluded entirely — from the
     * settlement and from the file — and reported in their own bucket, because
     * the remedy (pay them back) is the opposite of the ineligible bucket's
     * (chase the bank details).
     */
    public function test_previewSettlement_member_in_credit_is_excluded_into_its_own_bucket(): void
    {
        $creditId = 'credit-member';
        $owingId = 'owing-member';

        $this->settlementsRepository->method('findParticipantMemberIds')->willReturn([$creditId, $owingId]);
        $this->settlementsRepository->method('calculateUnsettledPositions')->willReturn([
            $creditId => -1500,
            $owingId => 500,
        ]);
        $this->membersRepository->method('findById')->willReturnMap([
            [$creditId, $this->member($creditId)],
            [$owingId, $this->member($owingId)],
        ]);

        $result = $this->service->previewSettlement();

        $this->assertSame([$owingId], array_column($result->eligibleMembers, 'member_id'));
        $this->assertSame([], $result->ineligibleMembers);
        $this->assertSame([$creditId], array_column($result->creditMembers, 'member_id'));
        $this->assertSame(-1500, $result->creditTotal);
        $this->assertSame(500, $result->eligibleTotal);
        $this->assertSame(2, $result->memberCount);
        $this->assertCount(1, $result->warnings);
        $this->assertStringContainsString('in credit', $result->warnings[0]);
    }

    /**
     * A member in credit is excluded whatever their mandate says: the club owes
     * them money, so "chase the bank details" is not the remedy.
     */
    public function test_previewSettlement_credit_beats_missing_mandate(): void
    {
        $memberId = 'credit-without-mandate';
        $this->settlementsRepository->method('findParticipantMemberIds')->willReturn([$memberId]);
        $this->settlementsRepository->method('calculateUnsettledPositions')->willReturn([$memberId => -200]);
        $this->membersRepository->method('findById')->willReturn(
            $this->member($memberId, ['iban' => null, 'mandate_reference' => null])
        );

        $result = $this->service->previewSettlement();

        $this->assertSame([], $result->ineligibleMembers);
        $this->assertCount(1, $result->creditMembers);
    }

    // ── The hold bucket (#196, ruling #148 §4) ────────────────────────

    public function test_previewSettlement_held_member_is_excluded_into_its_own_bucket_with_a_reason(): void
    {
        $heldId = 'held-member';
        $owingId = 'owing-member';

        $this->settlementsRepository->method('findParticipantMemberIds')->willReturn([$heldId, $owingId]);
        $this->settlementsRepository->method('calculateUnsettledPositions')->willReturn([
            $heldId => 1500,
            $owingId => 500,
        ]);
        $this->membersRepository->method('findById')->willReturnMap([
            [$heldId, $this->member($heldId, [
                'collection_hold' => 1,
                'collection_hold_reason' => 'Direct debit returned by the bank',
            ])],
            [$owingId, $this->member($owingId)],
        ]);

        $result = $this->service->previewSettlement();

        $this->assertSame([$owingId], array_column($result->eligibleMembers, 'member_id'));
        $this->assertSame([$heldId], array_column($result->heldMembers, 'member_id'));
        $this->assertSame(1500, $result->heldTotal);
        $this->assertSame(500, $result->eligibleTotal);
        $this->assertSame(2, $result->memberCount, 'a held member is still a participant of the run');
        $this->assertSame(
            'Direct debit returned by the bank',
            $result->heldMembers[0]['collection_hold_reason'],
            'the treasurer has to see why, or the hold is never resolved',
        );
        $this->assertCount(1, $result->warnings);
        $this->assertStringContainsString('collection hold', $result->warnings[0]);
    }

    /**
     * A hold outranks a missing mandate: this member\'s last collection came
     * back, and until somebody looks at that, their bank details are not the
     * question.
     */
    public function test_previewSettlement_a_hold_beats_a_missing_mandate(): void
    {
        $memberId = 'held-without-mandate';
        $this->settlementsRepository->method('findParticipantMemberIds')->willReturn([$memberId]);
        $this->settlementsRepository->method('calculateUnsettledPositions')->willReturn([$memberId => 900]);
        $this->membersRepository->method('findById')->willReturn($this->member($memberId, [
            'iban' => null,
            'mandate_reference' => null,
            'collection_hold' => 1,
            'collection_hold_reason' => 'Returned unpaid',
        ]));

        $result = $this->service->previewSettlement();

        $this->assertSame([], $result->ineligibleMembers);
        $this->assertCount(1, $result->heldMembers);
    }

    /**
     * Credit still comes first: the club owes this member money, so the hold —
     * which exists to stop the club taking money — is beside the point.
     */
    public function test_previewSettlement_credit_beats_a_hold(): void
    {
        $memberId = 'held-and-in-credit';
        $this->settlementsRepository->method('findParticipantMemberIds')->willReturn([$memberId]);
        $this->settlementsRepository->method('calculateUnsettledPositions')->willReturn([$memberId => -700]);
        $this->membersRepository->method('findById')->willReturn($this->member($memberId, [
            'collection_hold' => 1,
            'collection_hold_reason' => 'Returned unpaid',
        ]));

        $result = $this->service->previewSettlement();

        $this->assertSame([], $result->heldMembers);
        $this->assertCount(1, $result->creditMembers);
    }

    public function test_previewSettlement_a_cleared_hold_makes_the_member_collectable_again(): void
    {
        $memberId = 'cleared-member';
        $this->settlementsRepository->method('findParticipantMemberIds')->willReturn([$memberId]);
        $this->settlementsRepository->method('calculateUnsettledPositions')->willReturn([$memberId => 1500]);
        $this->membersRepository->method('findById')->willReturn($this->member($memberId, [
            'collection_hold' => 0,
            'collection_hold_reason' => 'Direct debit returned by the bank',
            'cleared_at' => '2026-08-08 12:00:00',
        ]));

        $result = $this->service->previewSettlement();

        $this->assertSame([], $result->heldMembers, 'the retained reason must not keep excluding a cleared member');
        $this->assertCount(1, $result->eligibleMembers);
    }

    /**
     * Ruling #141: a zero position settles — it closes the rows out — but gets
     * no line in the file. The GDPR zero-balance anonymisation check depends on
     * those rows actually closing.
     */
    public function test_previewSettlement_zero_position_is_eligible_and_closes_out(): void
    {
        $memberId = 'net-zero-member';
        $this->settlementsRepository->method('findParticipantMemberIds')->willReturn([$memberId]);
        $this->settlementsRepository->method('calculateUnsettledPositions')->willReturn([$memberId => 0]);
        $this->membersRepository->method('findById')->willReturn($this->member($memberId));

        $result = $this->service->previewSettlement();

        $this->assertCount(1, $result->eligibleMembers);
        $this->assertSame(0, $result->eligibleMembers[0]['balance_cents']);
        $this->assertSame([], $result->creditMembers);
        $this->assertSame([], $result->warnings);
    }

    public function test_previewSettlement_sepaEligibleOnly_drops_ineligible_members_and_warnings(): void
    {
        $eligibleId = 'eligible-member';
        $ineligibleId = 'ineligible-member';

        $this->settlementsRepository->method('findParticipantMemberIds')->willReturn([$eligibleId, $ineligibleId]);
        $this->settlementsRepository->method('calculateUnsettledPositions')->willReturn([
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

    /**
     * Credit exclusion is never silenced: sepa_eligible_only is about missing
     * bank details, and a credit member still has to be paid back.
     */
    public function test_previewSettlement_sepaEligibleOnly_still_reports_credit_members(): void
    {
        $creditId = 'credit-member';
        $this->settlementsRepository->method('findParticipantMemberIds')->willReturn([$creditId]);
        $this->settlementsRepository->method('calculateUnsettledPositions')->willReturn([$creditId => -900]);
        $this->membersRepository->method('findById')->willReturn($this->member($creditId));

        $result = $this->service->previewSettlement(sepaEligibleOnly: true);

        $this->assertCount(1, $result->creditMembers);
        $this->assertCount(1, $result->warnings);
    }

    public function test_previewSettlement_skips_members_that_no_longer_exist(): void
    {
        $missingId = 'missing-member';
        $this->settlementsRepository->method('findParticipantMemberIds')->willReturn([$missingId]);
        $this->settlementsRepository->method('calculateUnsettledPositions')->willReturn([$missingId => 100]);
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

        $this->service->createSettlement(['tx-1'], '2026-01-01', '2026-01-15', null, null, SettlementMethod::DIRECT_DEBIT, null, 'admin-1');
    }

    public function test_createSettlement_rejects_when_no_valid_unsettled_transactions_found(): void
    {
        $this->settlementsRepository->method('hasConflicts')->willReturn([]);
        $this->transactionsRepository->method('findUnsettledByIds')->willReturn([]);

        $this->db->expects($this->once())->method('rollBack');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('No valid unsettled transactions found');

        $this->service->createSettlement(['tx-1'], '2026-01-01', '2026-01-15', null, null, SettlementMethod::DIRECT_DEBIT, null, 'admin-1');
    }

    public function test_createSettlement_persists_items_and_writes_audit_entry(): void
    {
        $transactions = [
            ['id' => 'tx-1', 'member_id' => 'member-a', 'amount_cents' => 500],
            ['id' => 'tx-2', 'member_id' => 'member-b', 'amount_cents' => 750],
        ];
        $settlementRow = [
            'id' => 'settlement-1',
            'method' => 'direct_debit',
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
        $this->stubEligibleSweep($transactions);
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

        $result = $this->service->createSettlement(['tx-1', 'tx-2'], '2026-01-01', '2026-01-15', null, null, SettlementMethod::DIRECT_DEBIT, null, 'admin-1');

        $this->assertInstanceOf(SettlementDto::class, $result);
        $this->assertSame('settlement-1', $result->id);
        $this->assertSame(1250, $result->totalAmountCents);
        $this->assertSame(2, $result->memberCount);
    }

    public function test_createSettlement_passes_method_through_for_non_direct_debit_settlements(): void
    {
        // Ruling #163: method replaces the old manual_reason free string.
        // bank_transfer/write_off settlements are still persisted through the
        // same createSettlement() path — the service just enforces (below)
        // that they cover exactly one member.
        $transactions = [['id' => 'tx-1', 'member_id' => 'member-a', 'amount_cents' => 500]];
        $settlementRow = [
            'id' => 'settlement-1',
            'method' => 'bank_transfer',
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
        $this->stubEligibleSweep($transactions);
        $this->settlementsRepository->method('getNextSepaMessageId')->willReturn('SEPA-XYZ');

        $this->settlementsRepository
            ->expects($this->once())
            ->method('create')
            ->with($this->callback(fn(array $data) => $data['method'] === 'bank_transfer'))
            ->willReturn($settlementRow);

        $this->settlementsRepository->method('findItemsBySettlementId')->willReturn([]);

        $result = $this->service->createSettlement(['tx-1'], '2026-01-01', '2026-01-15', null, null, SettlementMethod::BANK_TRANSFER, null, 'admin-1');

        $this->assertSame(SettlementMethod::BANK_TRANSFER, $result->method);
    }

    public function test_createSettlement_rejects_non_direct_debit_settlement_covering_multiple_members(): void
    {
        // Ruling #163 + chk_settlements_manual_is_single_member: bank_transfer
        // and write_off settlements must cover exactly one member. The service
        // must reject this before it ever reaches the DB, so the caller gets a
        // 422 instead of a raw SQL CHECK violation.
        $transactions = [
            ['id' => 'tx-1', 'member_id' => 'member-a', 'amount_cents' => 500],
            ['id' => 'tx-2', 'member_id' => 'member-b', 'amount_cents' => 250],
        ];

        $this->settlementsRepository->method('hasConflicts')->willReturn([]);
        $this->transactionsRepository->method('findUnsettledByIds')->willReturn($transactions);
        $this->stubEligibleSweep($transactions);

        $this->settlementsRepository->expects($this->never())->method('create');
        $this->db->expects($this->once())->method('rollBack');
        $this->db->expects($this->never())->method('commit');

        $this->expectException(ValidationException::class);

        $this->service->createSettlement(['tx-1', 'tx-2'], '2026-01-01', '2026-01-15', null, null, SettlementMethod::WRITE_OFF, null, 'admin-1');
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
            'method' => 'direct_debit',
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
        $this->stubEligibleSweep($transactions);
        $this->settlementsRepository->method('getNextSepaMessageId')->willReturn('SEPA-XYZ');
        $this->settlementsRepository->method('create')->willReturn($settlementRow);
        $this->settlementsRepository->method('findItemsBySettlementId')->willReturn([]);

        $result = $this->service->createSettlement(['tx-1'], '2026-01-01', '2026-08-09', null, null, SettlementMethod::DIRECT_DEBIT, null, 'admin-1');

        $this->assertSame('2026-08-09', $result->executionDate);
    }

    public function test_createSettlement_rolls_back_and_rethrows_on_unexpected_failure(): void
    {
        $this->settlementsRepository->method('hasConflicts')->willReturn([]);
        $this->transactionsRepository->method('findUnsettledByIds')->willReturn([
            ['id' => 'tx-1', 'member_id' => 'member-a', 'amount_cents' => 500],
        ]);
        $this->stubEligibleSweep([['id' => 'tx-1', 'member_id' => 'member-a', 'amount_cents' => 500]]);
        $this->settlementsRepository->method('getNextSepaMessageId')->willReturn('SEPA-XYZ');
        $this->settlementsRepository->method('create')->willThrowException(new \RuntimeException('db exploded'));

        $this->db->expects($this->once())->method('rollBack');
        $this->db->expects($this->never())->method('commit');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('db exploded');

        $this->service->createSettlement(['tx-1'], '2026-01-01', '2026-01-15', null, null, SettlementMethod::DIRECT_DEBIT, null, 'admin-1');
    }

    /**
     * #161 §2: once a member is in the run, the run sweeps their whole
     * unsettled position. Settling only the posted slice would reintroduce the
     * very overpayment §1 exists to prevent.
     */
    public function test_createSettlement_sweeps_the_members_whole_unsettled_position(): void
    {
        $posted = [['id' => 'tx-feb', 'member_id' => 'member-a', 'amount_cents' => 500]];
        $wholePosition = [
            ['id' => 'tx-jan-storno', 'member_id' => 'member-a', 'amount_cents' => -200],
            ['id' => 'tx-feb', 'member_id' => 'member-a', 'amount_cents' => 500],
        ];

        $this->settlementsRepository->method('hasConflicts')->willReturn([]);
        $this->transactionsRepository->method('findUnsettledByIds')->willReturn($posted);
        $this->stubEligibleSweep($wholePosition);
        $this->settlementsRepository->method('getNextSepaMessageId')->willReturn('SEPA-XYZ');

        $this->settlementsRepository
            ->expects($this->once())
            ->method('create')
            ->with($this->callback(fn(array $data) => $data['total_amount_cents'] === 300 && $data['member_count'] === 1))
            ->willReturn($this->settlementRow(['total_amount_cents' => 300, 'member_count' => 1]));

        $settled = [];
        $this->settlementsRepository
            ->expects($this->exactly(2))
            ->method('createItem')
            ->willReturnCallback(function (array $data) use (&$settled): void { $settled[] = $data['transaction_id']; });

        $this->settlementsRepository->method('findItemsBySettlementId')->willReturn([]);

        $this->service->createSettlement(['tx-feb'], '2026-03-01', '2026-03-15', null, null, SettlementMethod::DIRECT_DEBIT, null, 'admin-1');

        $this->assertSame(['tx-jan-storno', 'tx-feb'], $settled, 'The January credit must be swept in with the February purchase');
    }

    /**
     * #161 §6: eligibility was tested at preview and nowhere else, so a client
     * could post ids the preview had flagged and settle them anyway. The
     * preview's verdict is now binding.
     */
    public function test_createSettlement_rejects_members_without_an_active_mandate(): void
    {
        $transactions = [['id' => 'tx-1', 'member_id' => 'member-a', 'amount_cents' => 500]];

        $this->settlementsRepository->method('hasConflicts')->willReturn([]);
        $this->transactionsRepository->method('findUnsettledByIds')->willReturn($transactions);
        $this->transactionsRepository->method('findUnsettledByMemberIds')->willReturn($transactions);
        $this->settlementsRepository->method('calculateUnsettledPositions')->willReturn(['member-a' => 500]);
        $this->membersRepository->method('findById')->willReturn(
            $this->member('member-a', ['iban' => null, 'mandate_reference' => null])
        );

        $this->settlementsRepository->expects($this->never())->method('create');
        $this->db->expects($this->once())->method('rollBack');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('no active SEPA mandate');

        $this->service->createSettlement(['tx-1'], '2026-01-01', '2026-01-15', null, null, SettlementMethod::DIRECT_DEBIT, null, 'admin-1');
    }

    /**
     * Ruling #141: a member whose total unsettled position is negative is
     * excluded entirely. Posting their transactions must not settle them.
     */
    public function test_createSettlement_rejects_members_in_credit(): void
    {
        $transactions = [['id' => 'tx-1', 'member_id' => 'member-a', 'amount_cents' => 500]];

        $this->settlementsRepository->method('hasConflicts')->willReturn([]);
        $this->transactionsRepository->method('findUnsettledByIds')->willReturn($transactions);
        $this->transactionsRepository->method('findUnsettledByMemberIds')->willReturn($transactions);
        $this->settlementsRepository->method('calculateUnsettledPositions')->willReturn(['member-a' => -1500]);
        $this->membersRepository->method('findById')->willReturn($this->member('member-a'));

        $this->settlementsRepository->expects($this->never())->method('create');
        $this->db->expects($this->once())->method('rollBack');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('in credit');

        $this->service->createSettlement(['tx-1'], '2026-01-01', '2026-01-15', null, null, SettlementMethod::DIRECT_DEBIT, null, 'admin-1');
    }

    /**
     * Ruling #148 §3: the hold is what stops the next run re-debiting exactly
     * what just bounced. It has to bind on creation too, or a client can post
     * the ids the preview put in the hold bucket and collect them anyway.
     */
    public function test_createSettlement_rejects_members_on_collection_hold(): void
    {
        $transactions = [['id' => 'tx-1', 'member_id' => 'member-a', 'amount_cents' => 500]];

        $this->settlementsRepository->method('hasConflicts')->willReturn([]);
        $this->transactionsRepository->method('findUnsettledByIds')->willReturn($transactions);
        $this->transactionsRepository->method('findUnsettledByMemberIds')->willReturn($transactions);
        $this->settlementsRepository->method('calculateUnsettledPositions')->willReturn(['member-a' => 500]);
        $this->membersRepository->method('findById')->willReturn(
            $this->member('member-a', ['collection_hold' => 1, 'collection_hold_reason' => 'Returned unpaid'])
        );

        $this->settlementsRepository->expects($this->never())->method('create');
        $this->db->expects($this->once())->method('rollBack');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('collection hold');

        $this->service->createSettlement(['tx-1'], '2026-01-01', '2026-01-15', null, null, SettlementMethod::DIRECT_DEBIT, null, 'admin-1');
    }

    /**
     * A hold stops collection, and only collection. A write-off gives up on
     * the money and a bank transfer is the member paying their own tab — both
     * are ways *out* of a hold, so neither may be blocked by one, or a returned
     * direct debit would strand the debt with no way to resolve it.
     */
    public function test_createSettlement_lets_a_held_member_be_written_off(): void
    {
        $transactions = [['id' => 'tx-1', 'member_id' => 'member-a', 'amount_cents' => 500]];

        $this->settlementsRepository->method('hasConflicts')->willReturn([]);
        $this->transactionsRepository->method('findUnsettledByIds')->willReturn($transactions);
        $this->transactionsRepository->method('findUnsettledByMemberIds')->willReturn($transactions);
        $this->settlementsRepository->method('calculateUnsettledPositions')->willReturn(['member-a' => 500]);
        $this->membersRepository->method('findById')->willReturn(
            $this->member('member-a', ['collection_hold' => 1, 'collection_hold_reason' => 'Returned unpaid'])
        );

        $this->settlementsRepository->expects($this->once())->method('create')->willReturn(
            $this->settlementRow(['method' => SettlementMethod::WRITE_OFF->value, 'total_amount_cents' => 500])
        );

        $result = $this->service->createSettlement(
            ['tx-1'], '2026-01-01', '2026-01-15', null, null, SettlementMethod::WRITE_OFF, null, 'admin-1',
        );

        $this->assertSame('settlement-1', $result->id);
    }

    /**
     * Ruling #141: zero settles. The rows close out — which the GDPR
     * zero-balance anonymisation check depends on — and the export writes no
     * line for it.
     */
    public function test_createSettlement_settles_a_net_zero_member(): void
    {
        $wholePosition = [
            ['id' => 'tx-purchase', 'member_id' => 'member-a', 'amount_cents' => 500],
            ['id' => 'tx-payout', 'member_id' => 'member-a', 'amount_cents' => -500],
        ];

        $this->settlementsRepository->method('hasConflicts')->willReturn([]);
        $this->transactionsRepository->method('findUnsettledByIds')->willReturn($wholePosition);
        $this->stubEligibleSweep($wholePosition);
        $this->settlementsRepository->method('getNextSepaMessageId')->willReturn('SEPA-XYZ');

        $this->settlementsRepository
            ->expects($this->once())
            ->method('create')
            ->with($this->callback(fn(array $data) => $data['total_amount_cents'] === 0 && $data['member_count'] === 1))
            ->willReturn($this->settlementRow(['total_amount_cents' => 0, 'member_count' => 1]));

        $this->settlementsRepository->expects($this->exactly(2))->method('createItem');
        $this->settlementsRepository->method('findItemsBySettlementId')->willReturn([]);

        $result = $this->service->createSettlement(
            ['tx-purchase', 'tx-payout'], '2026-01-01', '2026-01-15', null, null, SettlementMethod::DIRECT_DEBIT, null, 'admin-1'
        );

        $this->assertSame(0, $result->totalAmountCents);
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
        $this->stubEligibleSweep([
            ['id' => 'tx-1', 'member_id' => 'member-a', 'amount_cents' => 500],
            ['id' => 'tx-2', 'member_id' => 'member-b', 'amount_cents' => 750],
        ]);
        $this->settlementsRepository->method('hasConflicts')->willReturn([]);
        $this->settlementsRepository->method('getNextSepaMessageId')->willReturn('SEPA-XYZ');
        $this->settlementsRepository->method('create')->willReturn([
            'id' => 'settlement-1',
            'method' => 'direct_debit',
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
        $this->stubEligibleSweep([['id' => 'tx-1', 'member_id' => 'member-a', 'amount_cents' => 500]]);
        $this->settlementsRepository->method('hasConflicts')->willReturn([]);
        $this->settlementsRepository->method('getNextSepaMessageId')->willReturn('SEPA-XYZ');

        $this->settlementsRepository
            ->expects($this->once())
            ->method('create')
            ->with($this->callback(fn(array $data) => $data['period_start'] === '2026-02-01' && $data['period_end'] === '2026-02-28'))
            ->willReturn([
                'id' => 'settlement-1',
                'method' => 'direct_debit',
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


    /**
     * The filter path is a sweep, not a hand-picked list, so an excluded
     * member is dropped and the rest of the run proceeds — exclude-and-flag,
     * rather than failing the whole run (ruling #141).
     */
    public function test_createSettlementByFilters_drops_credit_and_unmandated_members(): void
    {
        $filters = ['date_from' => '2026-02-01', 'date_to' => '2026-02-28'];
        $matched = [
            ['id' => 'tx-owing', 'member_id' => 'member-owing', 'amount_cents' => 500],
            ['id' => 'tx-credit', 'member_id' => 'member-credit', 'amount_cents' => 100],
            ['id' => 'tx-nomandate', 'member_id' => 'member-nomandate', 'amount_cents' => 300],
        ];

        $this->transactionsRepository->method('findAllUnsettledByFilters')->willReturn(array_column($matched, 'id'));
        // Arg-aware: the sweep narrows the id list before re-fetching it.
        $this->transactionsRepository->method('findUnsettledByIds')->willReturnCallback(
            fn(array $ids) => array_values(array_filter($matched, fn(array $tx) => in_array($tx['id'], $ids, true)))
        );
        $this->settlementsRepository->method('hasConflicts')->willReturn([]);
        $this->settlementsRepository->method('calculateUnsettledPositions')->willReturn([
            'member-owing' => 500,
            'member-credit' => -900,
            'member-nomandate' => 300,
        ]);
        $this->membersRepository->method('findById')->willReturnMap([
            ['member-owing', $this->member('member-owing')],
            ['member-credit', $this->member('member-credit')],
            ['member-nomandate', $this->member('member-nomandate', ['iban' => null, 'mandate_reference' => null])],
        ]);

        $this->transactionsRepository
            ->expects($this->once())
            ->method('findUnsettledByMemberIds')
            ->with(['member-owing'])
            ->willReturn([['id' => 'tx-owing', 'member_id' => 'member-owing', 'amount_cents' => 500]]);

        $this->settlementsRepository->method('getNextSepaMessageId')->willReturn('SEPA-XYZ');
        $this->settlementsRepository
            ->expects($this->once())
            ->method('create')
            ->with($this->callback(fn(array $data) => $data['total_amount_cents'] === 500 && $data['member_count'] === 1))
            ->willReturn($this->settlementRow(['total_amount_cents' => 500]));
        $this->settlementsRepository->method('findItemsBySettlementId')->willReturn([]);

        $result = $this->service->createSettlementByFilters($filters, '2026-02-01', '2026-02-15', 'admin-1');

        $this->assertSame(500, $result->totalAmountCents);
    }

    public function test_createSettlementByFilters_throws_when_every_matched_member_is_excluded(): void
    {
        $filters = ['date_from' => '2026-02-01', 'date_to' => '2026-02-28'];
        $matched = [['id' => 'tx-credit', 'member_id' => 'member-credit', 'amount_cents' => 100]];

        $this->transactionsRepository->method('findAllUnsettledByFilters')->willReturn(['tx-credit']);
        $this->transactionsRepository->method('findUnsettledByIds')->willReturn($matched);
        $this->settlementsRepository->method('calculateUnsettledPositions')->willReturn(['member-credit' => -900]);
        $this->membersRepository->method('findById')->willReturn($this->member('member-credit'));

        $this->settlementsRepository->expects($this->never())->method('create');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('No collectable members');

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
            'method' => 'direct_debit',
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
            'method' => 'direct_debit',
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

    /**
     * A settlement row as the gate sees it: a direct debit, not cancelled, not
     * submitted, execution date comfortably ahead — i.e. cancellable.
     */
    private function cancellableRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 'settlement-1',
            'method' => SettlementMethod::DIRECT_DEBIT->value,
            'is_cancelled' => 0,
            'exported_at' => null,
            'submitted_at' => null,
            'execution_date' => (new \DateTimeImmutable('today'))->modify('+7 days')->format('Y-m-d'),
        ], $overrides);
    }

    public function test_cancelSettlement_delegates_to_repository_and_writes_audit_entry(): void
    {
        $settlementRow = $this->cancellableRow();
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

    // ── the cancellability gate (#81, ruling #142 as generalised by #163) ──
    //
    // A settlement may be cancelled while no money has moved. Everything below
    // is one rule seen from a different side.

    public function test_cancelSettlement_refuses_an_already_cancelled_settlement(): void
    {
        // Cancelling twice is a conflict, not a no-op to paper over: the second
        // call must not re-run the release of the items.
        $this->settlementsRepository->method('findById')->willReturn($this->cancellableRow(['is_cancelled' => 1]));
        $this->settlementsRepository->expects($this->never())->method('cancelSettlement');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/already been cancelled/i');

        $this->service->cancelSettlement('settlement-1', 'admin-1');
    }

    public function test_cancelSettlement_allows_an_exported_settlement_whose_execution_date_is_still_ahead(): void
    {
        // Generating the file is not sending it. Locking here would strand the
        // ordinary case of downloading a file, spotting a wrong amount, and
        // never submitting it.
        $this->settlementsRepository->method('findById')->willReturn(
            $this->cancellableRow(['exported_at' => '2026-01-02 09:00:00'])
        );
        $this->settlementsRepository->expects($this->once())->method('cancelSettlement')->willReturn(true);

        $this->assertTrue($this->service->cancelSettlement('settlement-1', 'admin-1'));
    }

    public function test_cancelSettlement_refuses_a_settlement_submitted_to_the_bank(): void
    {
        // This is the double-debit path of #81: the bank has the file, so
        // returning its transactions to the unsettled pool would collect them
        // a second time.
        $this->settlementsRepository->method('findById')->willReturn(
            $this->cancellableRow(['exported_at' => '2026-01-02 09:00:00', 'submitted_at' => '2026-01-02 10:00:00'])
        );
        $this->settlementsRepository->expects($this->never())->method('cancelSettlement');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/submitted to the bank.*revers/is');

        $this->service->cancelSettlement('settlement-1', 'admin-1');
    }

    public function test_cancelSettlement_refuses_a_settlement_whose_execution_date_has_passed(): void
    {
        // The backstop against a forgotten "mark submitted" click: by the
        // execution date the money has almost certainly moved anyway.
        $this->settlementsRepository->method('findById')->willReturn(
            $this->cancellableRow(['execution_date' => (new \DateTimeImmutable('today'))->modify('-1 day')->format('Y-m-d')])
        );
        $this->settlementsRepository->expects($this->never())->method('cancelSettlement');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/execution date.*revers/is');

        $this->service->cancelSettlement('settlement-1', 'admin-1');
    }

    public function test_cancelSettlement_allows_a_settlement_executing_today(): void
    {
        // The gate is execution_date >= today, so the day itself is still open.
        $this->settlementsRepository->method('findById')->willReturn(
            $this->cancellableRow(['execution_date' => (new \DateTimeImmutable('today'))->format('Y-m-d')])
        );
        $this->settlementsRepository->expects($this->once())->method('cancelSettlement')->willReturn(true);

        $this->assertTrue($this->service->cancelSettlement('settlement-1', 'admin-1'));
    }

    public function test_cancelSettlement_refuses_a_bank_transfer_settlement(): void
    {
        // Ruling #163: the money a bank transfer records has already arrived,
        // so there is nothing to un-move — reversal is the only remedy.
        $this->settlementsRepository->method('findById')->willReturn(
            $this->cancellableRow(['method' => SettlementMethod::BANK_TRANSFER->value])
        );
        $this->settlementsRepository->expects($this->never())->method('cancelSettlement');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/already transferred|bank transfer/i');

        $this->service->cancelSettlement('settlement-1', 'admin-1');
    }

    public function test_cancelSettlement_allows_a_write_off_after_its_execution_date(): void
    {
        // A write-off never moves money, so neither submission nor the
        // execution date can lock it.
        $this->settlementsRepository->method('findById')->willReturn($this->cancellableRow([
            'method' => SettlementMethod::WRITE_OFF->value,
            'execution_date' => '2020-01-01',
        ]));
        $this->settlementsRepository->expects($this->once())->method('cancelSettlement')->willReturn(true);

        $this->assertTrue($this->service->cancelSettlement('settlement-1', 'admin-1'));
    }

    public function test_cancelSettlement_rolls_back_when_releasing_the_items_fails(): void
    {
        // #86: releasing the items and flagging the settlement are two writes.
        // A crash between them would leave a live settlement with no claims,
        // whose transactions silently count as unsettled.
        $this->settlementsRepository->method('findById')->willReturn($this->cancellableRow());
        $this->settlementsRepository->method('cancelSettlement')
            ->willThrowException(new \RuntimeException('database went away'));

        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->never())->method('commit');
        $this->db->expects($this->once())->method('rollBack');

        $this->expectException(\RuntimeException::class);

        $this->service->cancelSettlement('settlement-1', 'admin-1');
    }

    // ── markSubmitted ────────────────────────────────────────────────

    public function test_markSubmitted_records_who_submitted_and_when(): void
    {
        $this->settlementsRepository->method('findById')->willReturn(
            $this->cancellableRow(['exported_at' => '2026-01-02 09:00:00'])
        );
        $this->settlementsRepository
            ->expects($this->once())
            ->method('markSubmitted')
            ->with('settlement-1', 'admin-1')
            ->willReturn(true);

        $this->auditService->expects($this->once())->method('log');

        $this->assertTrue($this->service->markSubmitted('settlement-1', 'admin-1'));
    }

    public function test_markSubmitted_refuses_a_settlement_that_was_never_exported(): void
    {
        // Submission means "the file I generated is now with the bank"; there
        // is no file until the export ran.
        $this->settlementsRepository->method('findById')->willReturn($this->cancellableRow());
        $this->settlementsRepository->expects($this->never())->method('markSubmitted');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/export/i');

        $this->service->markSubmitted('settlement-1', 'admin-1');
    }

    public function test_markSubmitted_refuses_a_settlement_already_submitted(): void
    {
        $this->settlementsRepository->method('findById')->willReturn($this->cancellableRow([
            'exported_at' => '2026-01-02 09:00:00',
            'submitted_at' => '2026-01-02 10:00:00',
        ]));
        $this->settlementsRepository->expects($this->never())->method('markSubmitted');

        $this->expectException(BusinessRuleException::class);

        $this->service->markSubmitted('settlement-1', 'admin-1');
    }

    public function test_markSubmitted_refuses_a_cancelled_settlement(): void
    {
        $this->settlementsRepository->method('findById')->willReturn($this->cancellableRow([
            'exported_at' => '2026-01-02 09:00:00',
            'is_cancelled' => 1,
        ]));
        $this->settlementsRepository->expects($this->never())->method('markSubmitted');

        $this->expectException(BusinessRuleException::class);

        $this->service->markSubmitted('settlement-1', 'admin-1');
    }

    public function test_markSubmitted_refuses_a_non_direct_debit_settlement(): void
    {
        // Only a direct debit is ever handed to a bank; a bank transfer or a
        // write-off has no file to submit (#163).
        $this->settlementsRepository->method('findById')->willReturn($this->cancellableRow([
            'method' => SettlementMethod::WRITE_OFF->value,
            'exported_at' => '2026-01-02 09:00:00',
        ]));
        $this->settlementsRepository->expects($this->never())->method('markSubmitted');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/direct-debit/i');

        $this->service->markSubmitted('settlement-1', 'admin-1');
    }

    public function test_markSubmitted_throws_notFoundException_when_missing(): void
    {
        $this->settlementsRepository->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);

        $this->service->markSubmitted('missing-id', 'admin-1');
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

    public function test_markExported_refuses_a_cancelled_settlement(): void
    {
        // A cancelled run collects nothing; stamping it exported would claim a
        // file went to the bank for it (#114, #142 §5).
        $this->settlementsRepository->method('findById')->willReturn($this->cancellableRow(['is_cancelled' => 1]));
        $this->settlementsRepository->expects($this->never())->method('markExported');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/cancelled/i');

        $this->service->markExported('settlement-1', 'admin-1');
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

    // ------------------------------------------------------------------
    // listCreditBalances (#161 work item 3)
    // ------------------------------------------------------------------

    public function test_listCreditBalances_maps_rows_to_dtos_and_sums_the_total(): void
    {
        $rows = [
            ['member_id' => 'member-a', 'first_name' => 'Max', 'last_name' => 'Mustermann', 'balance_cents' => -1500],
            ['member_id' => 'member-b', 'first_name' => 'Erika', 'last_name' => 'Musterfrau', 'balance_cents' => -500],
        ];
        $this->settlementsRepository
            ->expects($this->once())
            ->method('findMembersInCredit')
            ->willReturn($rows);

        $result = $this->service->listCreditBalances();

        $this->assertSame(-2000, $result['total_credit_cents']);
        $this->assertCount(2, $result['items']);
        $this->assertSame('member-a', $result['items'][0]->memberId);
        $this->assertSame('Max', $result['items'][0]->firstName);
        $this->assertSame('Mustermann', $result['items'][0]->lastName);
        $this->assertSame(-1500, $result['items'][0]->balanceCents);
        $this->assertSame('member-b', $result['items'][1]->memberId);
        $this->assertSame(-500, $result['items'][1]->balanceCents);
    }

    public function test_listCreditBalances_returns_empty_list_and_zero_total_when_nobody_is_in_credit(): void
    {
        $this->settlementsRepository->method('findMembersInCredit')->willReturn([]);

        $result = $this->service->listCreditBalances();

        $this->assertSame([], $result['items']);
        $this->assertSame(0, $result['total_credit_cents']);
    }
}
