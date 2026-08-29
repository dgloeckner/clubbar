<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Services;

use App\Modules\Settlements\DTOs\ReversalCandidateDto;
use App\Modules\Settlements\DTOs\SettlementDto;
use App\Modules\Settlements\DTOs\SettlementReversalDto;
use App\Modules\Settlements\Domain\ReversalGate;
use App\Modules\Settlements\Domain\SettlementReference;
use App\Modules\Settlements\Enums\ReversalReason;
use App\Modules\Settlements\Repositories\CollectionHoldRepository;
use App\Modules\Settlements\Repositories\SettlementReversalsRepository;
use App\Modules\Settlements\Repositories\SettlementsRepository;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\BusinessRuleReason;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Services\AuditService;
use PDO;

/**
 * Undoing a collection the bank has already made (ruling #148, issue #196).
 *
 * #81 closed the double-debit path: a settlement that has moved money refuses
 * cancellation and tells the treasurer to reverse it. This is the thing to
 * reverse it *with* — without it, a bounced direct debit left the member's
 * items claimed, their Deckel reading €0, and the club out the money with no
 * record of it anywhere.
 *
 * **One mechanism, per-member granularity.** Whole-settlement undo is simply
 * every member; the trigger is an audit reason, not a separate code path. That
 * is why there is no `reverseSettlement()` beside `reverse()`: two entry points
 * would eventually disagree about what a reversal does.
 */
class SettlementReversalService
{
    public function __construct(
        private SettlementsRepository $settlementsRepository,
        private SettlementReversalsRepository $reversalsRepository,
        private CollectionHoldRepository $collectionHoldRepository,
        private AuditService $auditService,
        private PDO $db,
    ) {}

    /**
     * Record that these members' collections came back.
     *
     * @param list<string>|null $memberIds The members to reverse; null or empty
     *        means every member the settlement covers (the whole-settlement undo).
     * @return list<SettlementReversalDto> One per member, in the order given.
     *
     * @throws NotFoundException 404 when the settlement does not exist.
     * @throws BusinessRuleException 409 when no money moved, or a member is already reversed.
     * @throws ValidationException 422 when a named member is not part of the settlement.
     */
    public function reverse(
        string $settlementId,
        ?array $memberIds,
        ReversalReason $reason,
        ?string $bankReference,
        ?string $notes,
        string $adminUserId,
    ): array {
        $settlement = $this->settlementsRepository->findById($settlementId);
        if (!$settlement) {
            throw NotFoundException::forResource('Settlement', $settlementId);
        }

        // §7: a run that never reached the bank is cancelled, not reversed.
        $blocker = ReversalGate::blocker($settlement);
        if ($blocker !== null) {
            throw $blocker->toException();
        }

        $settledMemberIds = $this->settlementsRepository->findSettledMemberIds($settlementId);
        if ($settledMemberIds === []) {
            throw new BusinessRuleException(
                BusinessRuleReason::SETTLEMENT_HAS_NO_MEMBERS_TO_REVERSE,
                'This settlement covers no members, so there is nothing to reverse.',
            );
        }

        $targets = $this->resolveTargets($memberIds, $settledMemberIds);
        $this->refuseAlreadyReversed($settlementId, $targets);

        $amounts = $this->settlementsRepository->sumItemAmountsByMember($settlementId, $targets);
        $holdReason = $reason->placesCollectionHold() ? $this->holdReason($settlement, $bankReference) : null;

        $this->db->beginTransaction();
        try {
            // §1: free only these members' items. Everyone else stays settled.
            $this->settlementsRepository->releaseMemberClaims($settlementId, $targets);

            $reversals = [];
            foreach ($targets as $memberId) {
                $reversals[] = SettlementReversalDto::fromRow($this->reversalsRepository->create([
                    'settlement_id' => $settlementId,
                    'member_id' => $memberId,
                    'reason' => $reason->value,
                    'amount_cents' => $amounts[$memberId] ?? 0,
                    'bank_reference' => $bankReference,
                    'notes' => $notes,
                    'created_by_admin_id' => $adminUserId,
                ]));

                // §3: without the hold, the next run sweeps this member's whole
                // unsettled position — including what just bounced — and earns
                // a second return fee. A club error takes no hold: re-collecting
                // is the entire point of recording one.
                if ($holdReason !== null) {
                    $this->collectionHoldRepository->place($memberId, $holdReason, $adminUserId);
                    $this->auditService->log(
                        action: AuditAction::COLLECTION_HOLD_PLACED,
                        entityType: EntityType::MEMBER,
                        entityId: $memberId,
                        newValues: ['collection_hold' => true, 'reason' => $holdReason],
                        adminUserId: $adminUserId,
                    );
                }
            }

            $this->auditService->log(
                action: AuditAction::SETTLEMENT_REVERSE,
                entityType: EntityType::SETTLEMENT,
                entityId: $settlementId,
                newValues: [
                    'reason' => $reason->value,
                    'member_ids' => $targets,
                    'member_count' => count($targets),
                    'amount_cents' => array_sum(array_map(fn(SettlementReversalDto $r) => $r->amountCents, $reversals)),
                    'bank_reference' => $bankReference,
                    'collection_hold' => $holdReason !== null,
                ],
                adminUserId: $adminUserId,
            );

            // §8: the freed items and the event rows commit together or not at
            // all — a crash between them would leave the club with money back
            // in the pool and no record of why.
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $this->translateConstraintViolation($e, $settlementId);
        }

        return $reversals;
    }

    /**
     * Which collections a bank reference points at (ADR-0032 §8, epic #433).
     *
     * The return-entry UI is a lookup rather than a form: the treasurer pastes
     * whatever the statement quotes and confirms who it resolved to. Matching
     * is forgiving about *shape* — whitespace, case, and the `E2E-` prefix some
     * statements carry are all stripped, and a partial reference matches — but
     * not about *what* it matches. Only the two reference columns are searched;
     * a member name resolves nothing, by design.
     *
     * Candidates the reverse endpoint would refuse come back too, carrying its
     * refusal. Filtering them out is the one outcome worth avoiding: a
     * reference that genuinely exists returning "no match" makes the treasurer
     * doubt the statement and record the return against something else.
     *
     * @return list<ReversalCandidateDto> Most recent execution date first.
     *
     * @throws ValidationException 422 when the reference is too short to be one.
     */
    public function findCandidates(string $reference, int $limit = 25): array
    {
        $needle = self::normaliseReference($reference);

        // A one- or two-character substring matches nearly every reference the
        // club has ever issued, which is a list, not a lookup.
        if (mb_strlen($needle) < self::MIN_REFERENCE_LENGTH) {
            throw new ValidationException(
                'Enter at least ' . self::MIN_REFERENCE_LENGTH . ' characters of the reference.',
                ['reference' => ['Enter at least ' . self::MIN_REFERENCE_LENGTH . ' characters of the reference.']],
            );
        }

        $rows = $this->settlementsRepository->findCollectionsByReference(
            $needle,
            // The already-prefix-stripped needle, not the raw input — an
            // `EREF+`/`E2E-` prefix left in place would never match a
            // settlement id. Inner spaces go too, because a Verwendungszweck
            // that wrapped in the bank's UI pastes back with them; that is safe
            // here and only here, since the eref and mref arms still receive
            // $needle untouched and a mandate reference may legitimately
            // contain a space.
            SettlementReference::normalise($needle),
            $limit,
        );

        /** @var array<string, SettlementDto> */
        $settlements = [];
        /** @var array<string, array<string, array<string, mixed>>> settlement id => member id => reversal row */
        $reversals = [];

        $candidates = [];
        foreach ($rows as $row) {
            $settlementId = $row['settlement_id'];

            if (!isset($settlements[$settlementId])) {
                $settlementRow = $this->settlementsRepository->findById($settlementId);
                if (!$settlementRow) {
                    continue;
                }
                // Built from the row alone: this needs the settlement's status
                // and its gate answer, not its items. Deriving either here
                // would be the second rule set #81 was.
                $settlements[$settlementId] = SettlementDto::fromRow($settlementRow);

                $reversals[$settlementId] = [];
                foreach ($this->reversalsRepository->findBySettlementId($settlementId) as $reversalRow) {
                    $reversals[$settlementId][$reversalRow['member_id']] = $reversalRow;
                }
            }

            $settlement = $settlements[$settlementId];
            $reversal = $reversals[$settlementId][$row['member_id']] ?? null;

            $candidates[] = new ReversalCandidateDto(
                settlementId: $settlementId,
                memberId: $row['member_id'],
                memberName: trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: null,
                amountCents: (int) $row['amount_cents'],
                endToEndId: $row['end_to_end_id'] ?? null,
                mandateReference: $row['mandate_reference'] ?? null,
                executionDate: $settlement->executionDate,
                settlementDate: $settlement->settlementDate,
                status: $settlement->status,
                isReversible: $settlement->isReversible,
                reversalBlockedReason: $settlement->reversalBlockedReason,
                alreadyReversed: $reversal !== null,
                reversedAt: $reversal['created_at'] ?? null,
                reversedByAdminName: $reversal['admin_display_name'] ?? null,
                reversedReason: isset($reversal['reason']) ? ReversalReason::from($reversal['reason']) : null,
            );
        }

        return $candidates;
    }

    /** Below this a substring stops identifying anything in particular. */
    private const MIN_REFERENCE_LENGTH = 3;

    /**
     * What the treasurer typed, reduced to what can be matched.
     *
     * They are copying a value off a bank statement under time pressure and
     * should not have to classify it first, nor reproduce it perfectly. The
     * labels are dropped because a statement prints them *around* the
     * identifier rather than as part of it — and stripped repeatedly, because
     * the value a German bank quotes is literally `EREF+E2E-…`: one label
     * introducing an identifier whose own prefix is another (#149).
     */
    private static function normaliseReference(string $reference): string
    {
        $needle = trim($reference);

        while (($stripped = preg_replace('/^(e2e|eref|mref)[-+: ]+/i', '', $needle)) !== null && $stripped !== $needle) {
            $needle = $stripped;
        }

        return mb_strtolower(trim($needle));
    }

    /**
     * Which members this reversal is for: the ones named, or all of them.
     *
     * @param list<string>|null $memberIds
     * @param list<string> $settledMemberIds
     * @return list<string>
     */
    private function resolveTargets(?array $memberIds, array $settledMemberIds): array
    {
        if ($memberIds === null || $memberIds === []) {
            return $settledMemberIds;
        }

        $targets = array_values(array_unique(array_map(strval(...), $memberIds)));

        $strangers = array_values(array_diff($targets, $settledMemberIds));
        if ($strangers !== []) {
            throw new ValidationException(
                'Some members are not part of this settlement: ' . implode(', ', $strangers),
                ['member_ids' => ['These members are not part of this settlement: ' . implode(', ', $strangers)]],
            );
        }

        return $targets;
    }

    /**
     * §7 / test contract 7: a member is reversed once per settlement.
     *
     * The `UNIQUE (settlement_id, member_id)` constraint is what actually
     * guarantees it — this check only buys a named 409 in the ordinary case.
     *
     * @param list<string> $targets
     */
    private function refuseAlreadyReversed(string $settlementId, array $targets): void
    {
        $already = $this->reversalsRepository->findReversedMemberIds($settlementId, $targets);
        if ($already !== []) {
            throw new BusinessRuleException(
                BusinessRuleReason::MEMBERS_ALREADY_REVERSED,
                'These members have already been reversed on this settlement: ' . implode(', ', $already),
                ['member_ids' => implode(', ', $already), 'member_count' => count($already)],
            );
        }
    }

    /**
     * What the treasurer sees next to the held member in the preview.
     *
     * The bank reference is included when there is one, because it is the only
     * thread back to the return booking — the original Verwendungszweck does
     * not come back (DK replaces `SVWZ` with the constant RETURN/REFUND).
     *
     * @param array<string, mixed> $settlement
     */
    private function holdReason(array $settlement, ?string $bankReference): string
    {
        $reason = 'Direct debit returned by the bank for settlement '
            . SettlementReference::of($settlement['id']);

        if ($bankReference !== null && $bankReference !== '') {
            $reason .= ' (bank reference ' . $bankReference . ')';
        }

        return $reason;
    }

    /**
     * A concurrent second reversal loses to the unique constraint rather than
     * to the check above, and must still read as a conflict and not a 500.
     */
    private function translateConstraintViolation(\Throwable $e, string $settlementId): \Throwable
    {
        if ($e instanceof \PDOException && ($e->errorInfo[0] ?? null) === '23000') {
            return new BusinessRuleException(
                BusinessRuleReason::MEMBERS_ALREADY_REVERSED,
                'A member of settlement ' . $settlementId . ' has already been reversed.',
                previous: $e,
            );
        }

        return $e;
    }
}
