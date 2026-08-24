<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\Services;

use App\Modules\Settlements\Enums\ReversalReason;
use App\Modules\Settlements\Domain\SettlementReference;
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
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
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
        // The hold reason used to quote a random `SEPA-<12 hex>` that named
        // nothing the treasurer could look up. It now carries the canonical
        // reference — the same string in the file, the mail and the UI.
        $this->assertStringContainsString(
            SettlementReference::of(self::SETTLEMENT_ID),
            $held[self::ALICE],
        );
    }

    public function test_a_club_error_holds_nobody(): void
    {
        $this->stubSubmittedSettlement([self::ALICE], [self::ALICE => 1500]);

        $this->collectionHoldRepository->expects($this->never())->method('place');

        $this->service->reverse(
            self::SETTLEMENT_ID, [self::ALICE], ReversalReason::CLUB_ERROR, null, 'Charged twice', self::ADMIN,
        );
    }

    /**
     * This used to guard the `?? $settlement['id']` fallback for a settlement
     * with no `sepa_message_id`. There is no fallback any more and no column to
     * be missing: the reference is derived from the primary key, so a hold
     * reason can no longer come out anonymous. The test stays, pointed at the
     * property that replaced the fallback.
     */
    public function test_the_hold_reason_always_names_the_settlement(): void
    {
        $this->stubSubmittedSettlement([self::ALICE], [self::ALICE => 1500]);

        $this->collectionHoldRepository->expects($this->once())
            ->method('place')
            ->with(
                self::ALICE,
                $this->stringContains(SettlementReference::of(self::SETTLEMENT_ID)),
                self::ADMIN,
            );

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

    // ── Resolving a bank reference (epic #433 §3, ADR-0032 §8) ─────────

    public function test_a_reference_resolves_to_a_candidate_carrying_the_settlements_own_gate_answer(): void
    {
        // Nothing about the candidate is re-derived: status and the gate come
        // from the same settlement row the reverse call throws from, so the
        // list and the endpoint cannot disagree about what is possible.
        $this->stubLookup([$this->collectionRow(self::ALICE, 2550)]);

        [$candidate] = $this->service->findCandidates('E2E-abc123abc123');

        $this->assertSame(self::SETTLEMENT_ID, $candidate->settlementId);
        $this->assertSame(self::ALICE, $candidate->memberId);
        $this->assertSame('Alice Member', $candidate->memberName);
        $this->assertSame(2550, $candidate->amountCents);
        $this->assertTrue($candidate->isReversible);
        $this->assertTrue($candidate->isActionable());
        $this->assertFalse($candidate->alreadyReversed);
    }

    public function test_a_member_already_reversed_is_returned_carrying_who_recorded_it_and_when(): void
    {
        // Dropping the row would tell a treasurer holding a real reference that
        // it matches nothing, so they retype it, doubt the statement, and
        // eventually record the return against the wrong thing.
        $this->stubLookup([$this->collectionRow(self::ALICE, 2550)], [
            $this->reversalRow(self::ALICE, 2550) + ['admin_display_name' => 'The Treasurer'],
        ]);

        [$candidate] = $this->service->findCandidates('E2E-abc123abc123');

        $this->assertTrue($candidate->alreadyReversed);
        $this->assertFalse($candidate->isActionable());
        $this->assertSame('The Treasurer', $candidate->reversedByAdminName);
        $this->assertSame(ReversalReason::BANK_RETURN, $candidate->reversedReason);
    }

    public function test_a_run_that_never_moved_money_is_returned_with_the_gates_own_refusal(): void
    {
        $this->stubLookup([$this->collectionRow(self::ALICE, 2550)], [], submittedAt: null);

        [$candidate] = $this->service->findCandidates('E2E-abc123abc123');

        $this->assertFalse($candidate->isReversible);
        $this->assertFalse($candidate->isActionable());
        $this->assertStringContainsString('Cancel it instead', (string) $candidate->reversalBlockedReason);
    }

    public function test_the_settlement_is_read_once_however_many_of_its_members_match(): void
    {
        // A mandate reference resolves every collection of one member, and an
        // EREF prefix can match several rows of one run; re-reading the
        // settlement per row would make a two-member match cost twice as much
        // for an answer that cannot differ.
        $this->stubLookup(
            [$this->collectionRow(self::ALICE, 2550), $this->collectionRow(self::BOB, 1500)],
            perSettlementReads: 1,
        );

        $this->assertCount(2, $this->service->findCandidates('E2E-abc123abc123'));
    }

    /**
     * @dataProvider imperfectReferences
     */
    public function test_an_imperfect_reference_is_normalised_before_it_is_matched(string $typed, string $expected): void
    {
        // The treasurer is copying a value off a statement under time pressure
        // and should not have to classify it first, nor reproduce it perfectly.
        $this->settlementsRepository->expects($this->once())
            ->method('findCollectionsByReference')
            ->with($expected)
            ->willReturn([]);

        $this->service->findCandidates($typed);
    }

    /** @return array<string, array{string, string}> */
    public static function imperfectReferences(): array
    {
        return [
            'surrounding whitespace' => ['  E2E-ABC123  ', 'abc123'],
            'upper case' => ['E2E-ABC123', 'abc123'],
            'no prefix at all' => ['abc123', 'abc123'],
            'the EREF+E2E- pair a statement actually prints' => ['EREF+E2E-ABC123', 'abc123'],
            'the MREF+ label' => ['MREF+MND-alice', 'mnd-alice'],
        ];
    }

    public function test_a_reference_too_short_to_identify_anything_is_refused(): void
    {
        // A two-character substring matches nearly every identifier the club
        // has ever issued, which is a list, not a lookup.
        $this->settlementsRepository->expects($this->never())->method('findCollectionsByReference');

        $this->expectException(ValidationException::class);
        $this->service->findCandidates('ab');
    }

    public function test_a_reference_that_is_only_a_prefix_label_is_refused_too(): void
    {
        // "E2E-" normalises to nothing, and a lookup over the empty string
        // would return the whole table.
        $this->settlementsRepository->expects($this->never())->method('findCollectionsByReference');

        $this->expectException(ValidationException::class);
        $this->service->findCandidates('E2E-');
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /**
     * A lookup that matched $rows, all belonging to one submitted settlement.
     *
     * @param list<array<string, mixed>> $rows Collection rows as the repository returns them.
     * @param list<array<string, mixed>> $reversals Reversal rows already on that settlement.
     * @param int|null $perSettlementReads How often the settlement behind these
     *        rows may be read — the caching assertion. A count rather than a
     *        matcher because PHPUnit's invocation rules are stateful: one
     *        `once()` shared by two mocked methods counts both and fails on the
     *        second, which looks exactly like the bug it is meant to catch.
     */
    private function stubLookup(
        array $rows,
        array $reversals = [],
        ?string $submittedAt = '2026-08-07 11:00:00',
        ?int $perSettlementReads = null,
    ): void {
        $reads = fn(): InvocationOrder => $perSettlementReads === null
            ? $this->any()
            : $this->exactly($perSettlementReads);

        $this->settlementsRepository->method('findCollectionsByReference')->willReturn($rows);
        $this->settlementsRepository->expects($reads())->method('findById')->willReturn([
            'id' => self::SETTLEMENT_ID,
            'method' => SettlementMethod::DIRECT_DEBIT->value,
            'is_cancelled' => 0,
            'execution_date' => date('Y-m-d', strtotime('+14 days')),
            'submitted_at' => $submittedAt,
            'settlement_date' => '2026-08-13',
            'total_amount_cents' => 4050,
            'member_count' => 2,
            'created_at' => '2026-08-13 09:00:00',
        ]);
        $this->reversalsRepository->expects($reads())->method('findBySettlementId')->willReturn($reversals);
    }

    /** @return array<string, mixed> A collection row as findCollectionsByReference() returns it. */
    private function collectionRow(string $memberId, int $amountCents): array
    {
        return [
            'settlement_id' => self::SETTLEMENT_ID,
            'member_id' => $memberId,
            'amount_cents' => $amountCents,
            'end_to_end_id' => 'E2E-abc123abc123-' . substr($memberId, 0, 12),
            'first_name' => $memberId === self::ALICE ? 'Alice' : 'Bob',
            'last_name' => 'Member',
            'mandate_reference' => 'MND-' . $memberId,
        ];
    }

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
        array $alreadyReversed = [],
    ): void {
        $this->settlementsRepository->method('findById')->willReturn([
            'id' => self::SETTLEMENT_ID,
            'method' => SettlementMethod::DIRECT_DEBIT->value,
            'is_cancelled' => 0,
            'execution_date' => date('Y-m-d', strtotime('+14 days')),
            'submitted_at' => '2026-08-07 11:00:00',
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
