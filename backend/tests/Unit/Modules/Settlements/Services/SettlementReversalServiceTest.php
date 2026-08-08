<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\Services;

use App\Modules\Settlements\Enums\ReversalReason;
use App\Modules\Settlements\Enums\SettlementMethod;
use App\Modules\Settlements\Repositories\CollectionHoldRepository;
use App\Modules\Settlements\Repositories\SettlementReversalsRepository;
use App\Modules\Settlements\Repositories\SettlementsRepository;
use App\Modules\Settlements\Services\SettlementReversalService;
use App\Shared\Enums\AuditAction;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

/**
 * The reversal service's decisions, isolated from the database (#196).
 *
 * What the database proves is covered in the Feature suite; what is pinned
 * here is everything the service decides *before* it writes — who is reversed,
 * whether a hold follows, and which of the three refusals applies — plus the
 * one thing no database test can force conveniently: that a failed write rolls
 * back rather than being left half-applied.
 */
class SettlementReversalServiceTest extends TestCase
{
    private SettlementsRepository $settlementsRepository;
    private SettlementReversalsRepository $reversalsRepository;
    private CollectionHoldRepository $collectionHoldRepository;
    private AuditService $auditService;
    private \PDO $db;
    private SettlementReversalService $service;

    private const SETTLEMENT_ID = 'settlement-1';
    private const ALICE = 'member-alice';
    private const BOB = 'member-bob';
    private const ADMIN = 'admin-1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->settlementsRepository = $this->createMock(SettlementsRepository::class);
        $this->reversalsRepository = $this->createMock(SettlementReversalsRepository::class);
        $this->collectionHoldRepository = $this->createMock(CollectionHoldRepository::class);
        $this->auditService = $this->createMock(AuditService::class);
        $this->db = $this->createMock(\PDO::class);

        $this->service = new SettlementReversalService(
            $this->settlementsRepository,
            $this->reversalsRepository,
            $this->collectionHoldRepository,
            $this->auditService,
            $this->db,
        );
    }

    // ── Who gets reversed ──────────────────────────────────────────────

    public function test_a_bank_return_frees_only_the_named_members_claims(): void
    {
        $this->stubSubmittedSettlement([self::ALICE, self::BOB], [self::ALICE => 1500, self::BOB => 2500]);

        $this->settlementsRepository->expects($this->once())
            ->method('releaseMemberClaims')
            ->with(self::SETTLEMENT_ID, [self::ALICE]);

        $reversals = $this->service->reverse(
            self::SETTLEMENT_ID, [self::ALICE], ReversalReason::BANK_RETURN, 'RET-1', null, self::ADMIN,
        );

        $this->assertCount(1, $reversals);
        $this->assertSame(self::ALICE, $reversals[0]->memberId);
        $this->assertSame(1500, $reversals[0]->amountCents, 'the amount comes from the items, never from the caller');
    }

    public function test_omitting_the_member_list_means_every_member_of_the_settlement(): void
    {
        // "Whole-settlement undo is simply every member" — a shorthand for the
        // list, not a second code path.
        $this->stubSubmittedSettlement([self::ALICE, self::BOB], [self::ALICE => 1500, self::BOB => 2500]);

        $this->settlementsRepository->expects($this->once())
            ->method('releaseMemberClaims')
            ->with(self::SETTLEMENT_ID, [self::ALICE, self::BOB]);

        $reversals = $this->service->reverse(
            self::SETTLEMENT_ID, null, ReversalReason::CLUB_ERROR, null, null, self::ADMIN,
        );

        $this->assertCount(2, $reversals);
    }

    public function test_the_same_member_named_twice_is_reversed_once(): void
    {
        $this->stubSubmittedSettlement([self::ALICE], [self::ALICE => 1500]);

        $this->settlementsRepository->expects($this->once())
            ->method('releaseMemberClaims')
            ->with(self::SETTLEMENT_ID, [self::ALICE]);

        $reversals = $this->service->reverse(
            self::SETTLEMENT_ID, [self::ALICE, self::ALICE], ReversalReason::CLUB_ERROR, null, null, self::ADMIN,
        );

        $this->assertCount(1, $reversals);
    }

    // ── Whether a hold follows ─────────────────────────────────────────

    public function test_a_bank_return_holds_every_reversed_member(): void
    {
        $this->stubSubmittedSettlement([self::ALICE, self::BOB], [self::ALICE => 1500, self::BOB => 2500]);

        $held = [];
        $this->collectionHoldRepository->method('place')
            ->willReturnCallback(function (string $memberId, string $reason) use (&$held): void {
                $held[$memberId] = $reason;
            });

        $this->service->reverse(
            self::SETTLEMENT_ID, null, ReversalReason::BANK_RETURN, 'RET-42', null, self::ADMIN,
        );

        $this->assertSame([self::ALICE, self::BOB], array_keys($held));
        $this->assertStringContainsString('RET-42', $held[self::ALICE], 'the bank reference is the only thread back to the return');
        $this->assertStringContainsString('SEPA-MSG-1', $held[self::ALICE]);
    }

    public function test_a_club_error_holds_nobody(): void
    {
        $this->stubSubmittedSettlement([self::ALICE], [self::ALICE => 1500]);

        $this->collectionHoldRepository->expects($this->never())->method('place');

        $this->service->reverse(
            self::SETTLEMENT_ID, [self::ALICE], ReversalReason::CLUB_ERROR, null, 'Charged twice', self::ADMIN,
        );
    }

    public function test_the_hold_reason_survives_a_settlement_with_no_sepa_message_id(): void
    {
        $this->stubSubmittedSettlement([self::ALICE], [self::ALICE => 1500], sepaMessageId: null);

        $this->collectionHoldRepository->expects($this->once())
            ->method('place')
            ->with(self::ALICE, $this->stringContains(self::SETTLEMENT_ID), self::ADMIN);

        $this->service->reverse(
            self::SETTLEMENT_ID, [self::ALICE], ReversalReason::BANK_RETURN, null, null, self::ADMIN,
        );
    }

    // ── The refusals ───────────────────────────────────────────────────

    public function test_an_unknown_settlement_is_a_404(): void
    {
        $this->settlementsRepository->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->service->reverse('nope', null, ReversalReason::BANK_RETURN, null, null, self::ADMIN);
    }

    public function test_a_settlement_that_never_moved_money_is_refused(): void
    {
        $this->settlementsRepository->method('findById')->willReturn([
            'id' => self::SETTLEMENT_ID,
            'method' => SettlementMethod::DIRECT_DEBIT->value,
            'is_cancelled' => 0,
            'execution_date' => date('Y-m-d', strtotime('+14 days')),
            'submitted_at' => null,
        ]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Cancel it instead');
        $this->service->reverse(self::SETTLEMENT_ID, null, ReversalReason::BANK_RETURN, null, null, self::ADMIN);
    }

    public function test_a_settlement_covering_no_members_is_refused(): void
    {
        $this->stubSubmittedSettlement([], []);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('nothing to reverse');
        $this->service->reverse(self::SETTLEMENT_ID, null, ReversalReason::BANK_RETURN, null, null, self::ADMIN);
    }

    public function test_a_member_outside_the_settlement_is_a_422_naming_them(): void
    {
        $this->stubSubmittedSettlement([self::ALICE], [self::ALICE => 1500]);

        try {
            $this->service->reverse(
                self::SETTLEMENT_ID, [self::BOB], ReversalReason::BANK_RETURN, null, null, self::ADMIN,
            );
            $this->fail('a member who was never collected from cannot be reversed');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('member_ids', $e->getErrors());
            $this->assertStringContainsString(self::BOB, $e->getErrors()['member_ids'][0]);
        }
    }

    public function test_a_member_already_reversed_is_a_409_naming_them(): void
    {
        $this->stubSubmittedSettlement([self::ALICE, self::BOB], [self::ALICE => 1500], alreadyReversed: [self::ALICE]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage(self::ALICE);
        $this->service->reverse(
            self::SETTLEMENT_ID, [self::ALICE], ReversalReason::BANK_RETURN, null, null, self::ADMIN,
        );
    }

    // ── Atomicity and the audit trail ──────────────────────────────────

    public function test_a_failed_write_rolls_back_and_the_failure_surfaces(): void
    {
        $this->stubSubmittedSettlement([self::ALICE], [self::ALICE => 1500]);
        $this->reversalsRepository->method('create')->willThrowException(new \RuntimeException('disk on fire'));

        $this->db->expects($this->once())->method('rollBack');

        $this->expectException(\RuntimeException::class);
        $this->service->reverse(
            self::SETTLEMENT_ID, [self::ALICE], ReversalReason::BANK_RETURN, null, null, self::ADMIN,
        );
    }

    public function test_a_constraint_violation_reads_as_a_conflict_not_a_crash(): void
    {
        // The pre-check lost a race: a concurrent reversal got there first, and
        // the unique constraint is what caught it. The caller must still see a
        // 409 rather than a 500.
        $this->stubSubmittedSettlement([self::ALICE], [self::ALICE => 1500]);

        $pdoException = new \PDOException('Integrity constraint violation');
        $pdoException->errorInfo = ['23000', 1062, 'Duplicate entry'];
        $this->reversalsRepository->method('create')->willThrowException($pdoException);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('already been reversed');
        $this->service->reverse(
            self::SETTLEMENT_ID, [self::ALICE], ReversalReason::BANK_RETURN, null, null, self::ADMIN,
        );
    }

    public function test_the_reversal_is_audited_with_who_what_and_why(): void
    {
        $this->stubSubmittedSettlement([self::ALICE, self::BOB], [self::ALICE => 1500, self::BOB => 2500]);

        $logged = [];
        $this->auditService->method('log')
            ->willReturnCallback(function (AuditAction $action, $entityType, string $entityId, ?array $old = null, ?array $new = null, ?string $adminUserId = null) use (&$logged): void {
                $logged[] = ['action' => $action, 'entity_id' => $entityId, 'new' => $new, 'admin' => $adminUserId];
            });

        $this->service->reverse(
            self::SETTLEMENT_ID, null, ReversalReason::BANK_RETURN, 'RET-9', 'Returned unpaid', self::ADMIN,
        );

        $actions = array_column($logged, 'action');
        $this->assertContains(AuditAction::SETTLEMENT_REVERSE, $actions);
        $this->assertSame(
            2,
            count(array_filter($actions, static fn(AuditAction $a): bool => $a === AuditAction::COLLECTION_HOLD_PLACED)),
            'each held member gets their own entry — a hold outlives the reversal that caused it',
        );

        $reversalEntry = end($logged);
        $this->assertSame(self::SETTLEMENT_ID, $reversalEntry['entity_id']);
        $this->assertSame(self::ADMIN, $reversalEntry['admin']);
        $this->assertSame(4000, $reversalEntry['new']['amount_cents']);
        $this->assertSame('bank_return', $reversalEntry['new']['reason']);
        $this->assertSame('RET-9', $reversalEntry['new']['bank_reference']);
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /**
     * A submitted direct debit covering $settledMemberIds, whose items add up
     * to $amounts, and whose reversal writes all succeed.
     *
     * @param list<string> $settledMemberIds
     * @param array<string, int> $amounts
     * @param list<string> $alreadyReversed Members that already carry a reversal row.
     */
    private function stubSubmittedSettlement(
        array $settledMemberIds,
        array $amounts,
        ?string $sepaMessageId = 'SEPA-MSG-1',
        array $alreadyReversed = [],
    ): void {
        $this->settlementsRepository->method('findById')->willReturn([
            'id' => self::SETTLEMENT_ID,
            'method' => SettlementMethod::DIRECT_DEBIT->value,
            'is_cancelled' => 0,
            'execution_date' => date('Y-m-d', strtotime('+14 days')),
            'submitted_at' => '2026-08-07 11:00:00',
            'sepa_message_id' => $sepaMessageId,
        ]);
        $this->settlementsRepository->method('findSettledMemberIds')->willReturn($settledMemberIds);
        $this->settlementsRepository->method('sumItemAmountsByMember')->willReturn($amounts);
        $this->reversalsRepository->method('findReversedMemberIds')->willReturn($alreadyReversed);
        $this->reversalsRepository->method('create')
            ->willReturnCallback(fn(array $data): array => $this->reversalRow(
                $data['member_id'],
                (int) $data['amount_cents'],
                $data['reason'],
                $data['bank_reference'] ?? null,
            ));
    }

    /** @return array<string, mixed> A `settlement_reversals` row as the repository returns it. */
    private function reversalRow(string $memberId, int $amountCents, string $reason = 'bank_return', ?string $bankReference = null): array
    {
        return [
            'id' => 'reversal-' . $memberId,
            'settlement_id' => self::SETTLEMENT_ID,
            'member_id' => $memberId,
            'reason' => $reason,
            'amount_cents' => $amountCents,
            'bank_reference' => $bankReference,
            'notes' => null,
            'created_by_admin_id' => self::ADMIN,
            'created_at' => '2026-08-08 12:00:00',
        ];
    }
}
