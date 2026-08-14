<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Services;

use App\Modules\Settlements\DTOs\CreditBalanceDto;
use App\Modules\Settlements\DTOs\ExecutionDateInfoDto;
use App\Modules\Settlements\DTOs\MandateMissingDto;
use App\Modules\Settlements\DTOs\SettlementDto;
use App\Modules\Settlements\DTOs\SettlementItemDto;
use App\Modules\Settlements\DTOs\SettlementPreviewDto;
use App\Modules\Settlements\DTOs\SettlementReversalDto;
use App\Modules\Settlements\Domain\CancellationGate;
use App\Modules\Settlements\Domain\SettlementLeadTime;
use App\Modules\Settlements\Enums\SettlementMethod;
use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Modules\Settlements\Repositories\SettlementReversalsRepository;
use App\Modules\Settlements\Repositories\SettlementsRepository;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\ValidationException;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Notifications\Services\NotificationsService;
use App\Modules\Notifications\Services\SchedulerStatusService;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use App\Shared\Services\AuditService;
use PDO;

class SettlementsService
{
    /** Fixed SEPA lead time in calendar days (ADR-0009). */
    public const LEAD_TIME_DAYS = SettlementLeadTime::DAYS;

    public function __construct(
        private SettlementsRepository $settlementsRepository,
        private MembersRepository $membersRepository,
        private TransactionsRepository $transactionsRepository,
        private AuditService $auditService,
        private PDO $db,
        private SettlementReversalsRepository $reversalsRepository,
        private NotificationsService $notificationsService,
        private SchedulerStatusService $schedulerStatusService,
    ) {}

    /**
     * The earliest execution date an admin may choose (ADR-0009).
     *
     * TODAY + 7 calendar days, rolled forward to the next TARGET2 business day
     * so the resulting ReqdColltnDt is always a settlement day. Around Easter
     * the roll can add four days, since Good Friday through Easter Monday are
     * four consecutive closing days.
     *
     * @param string|null $today Injectable for tests; defaults to the current date.
     */
    public function getExecutionDateInfo(?string $today = null): ExecutionDateInfoDto
    {
        return new ExecutionDateInfoDto(
            minimumDate: SettlementLeadTime::earliestBusinessDay($today),
            today: SettlementLeadTime::today($today),
            leadTimeDays: self::LEAD_TIME_DAYS,
            rule: 'execution_date >= today + 7 calendar days, rolled to the next bank business day '
                . '(Mon-Fri, excluding TARGET2 closing days)',
        );
    }

    /**
     * What a collection run would contain, and who it would leave out and why
     * (ruling #141, issue #161).
     *
     * The date window selects the run's *participants* — the members with
     * unsettled activity in it. It does not bound the amounts: each
     * participant's whole unsettled position is what gets tested and, on
     * inclusion, what gets swept.
     *
     * Participants can be named three ways, in this order of precedence:
     *
     * - `$memberIds` — what the New Settlement screen sends, because the unit
     *   of selection is the member (ADR-0030).
     * - `$transactionIds` — the compatibility path, resolved to members exactly
     *   as `createSettlement()` resolves them, so a caller holding transactions
     *   is told what its post will actually do rather than what its selection
     *   adds up to (#128).
     * - the date window, which selects who has unsettled activity in it.
     *
     * None of the three bound the amounts. Whichever names the participants,
     * each one's whole unsettled position is what gets tested and swept.
     *
     * @param list<string>|null $transactionIds
     * @param list<string>|null $memberIds
     */
    public function previewSettlement(?string $fromDate = null, ?string $toDate = null, ?string $memberId = null, bool $sepaEligibleOnly = false, ?array $transactionIds = null, ?array $memberIds = null): SettlementPreviewDto
    {
        if ($memberIds !== null) {
            $participantIds = array_values(array_unique($memberIds));
        } elseif ($transactionIds !== null) {
            $posted = $this->transactionsRepository->findUnsettledByIds($transactionIds);
            $participantIds = array_values(array_unique(array_column($posted, 'member_id')));
        } else {
            $participantIds = $this->settlementsRepository->findParticipantMemberIds($fromDate, $toDate, $memberId);
        }

        if (empty($participantIds)) {
            return new SettlementPreviewDto([], [], 0, 0, 0, []);
        }

        $memberIds = $participantIds;
        $positions = $this->settlementsRepository->calculateUnsettledPositions($memberIds);

        // Counted in SQL. The New Settlement screen previews with no filter, so
        // "every participant" is every member with an open position — hydrating
        // their rows here made one page load read the whole journal.
        $unsettledCounts = $this->transactionsRepository->countUnsettledByMemberIds($memberIds);

        $eligible = [];
        $ineligible = [];
        $credit = [];
        $held = [];
        // A subset of $eligible, not a fifth bucket: these members are
        // collected from like everybody else (#405).
        $withoutEmail = [];
        $warnings = [];

        foreach ($memberIds as $mid) {
            // NOTE: one query per participant. Since ADR-0030 this path runs
            // over every member with an open position, so it wants batching.
            // Left for its own change rather than folded into this one — see
            // the follow-up in the plan.
            $member = $this->membersRepository->findById($mid);
            if (!$member) continue;

            $balance = $positions[$mid] ?? 0;
            $entry = [
                'member_id' => $mid,
                'first_name' => $member['first_name'],
                'last_name' => $member['last_name'],
                'balance_cents' => $balance,
                // How many rows this member would contribute to the run — the
                // number their row on the New Settlement screen shows.
                'transaction_count' => $unsettledCounts[$mid] ?? 0,
                // Last four characters only — the preview identifies the
                // account, it never needs to debit it (ADR-0036).
                'iban_last4' => $member['iban_last4'] ?? null,
                'mandate_reference' => $member['mandate_reference'] ?? null,
            ];

            // Credit is tested first and reported even under sepa_eligible_only:
            // the club owes this member money (§ 812 BGB), so "chase the bank
            // details" is not the remedy and the exclusion must never be silent.
            if ($balance < 0) {
                $credit[] = $entry;
                $warnings[] = "Member {$member['first_name']} {$member['last_name']} is in credit and is excluded from collection";
                continue;
            }

            // A hold outranks a missing mandate: the member's last collection
            // came back, and until somebody looks at that, their bank details
            // are not the question (ruling #148 §4).
            if (!empty($member['collection_hold'])) {
                $entry['collection_hold_reason'] = $member['collection_hold_reason'] ?? null;
                $held[] = $entry;
                $warnings[] = "Member {$member['first_name']} {$member['last_name']} is on collection hold and is excluded from collection"
                    . (empty($member['collection_hold_reason']) ? '' : ": {$member['collection_hold_reason']}");
                continue;
            }

            if ($this->hasActiveMandate($member)) {
                // Zero settles too — it closes the rows out, and only the
                // export decides not to write a file line for it. What it
                // cannot do is make a run on its own: a settlement whose whole
                // total is zero is refused at creation (#372), so a screen
                // showing only zero-balance members has nothing to post.
                $eligible[] = $entry;

                // #405: collected from, and unreachable. This never excludes
                // anybody — the member owes the money and the run collects it
                // — it only says that one of the people being collected from
                // will not be told. #362 makes an address required at
                // application level; this stays as defense-in-depth for legacy
                // rows until they are backfilled, and disappears on its own
                // once they are.
                //
                // Tested on a positive balance rather than on membership of
                // this bucket: a member closing out at 0.00 is settled but not
                // collected from, so there is no announcement to miss.
                if ($balance > 0 && trim((string) ($member['email'] ?? '')) === '') {
                    $withoutEmail[] = $entry;
                    $warnings[] = "Member {$member['first_name']} {$member['last_name']} has no email address "
                        . 'and cannot be sent the pre-notification for this collection';
                }
            } elseif (!$sepaEligibleOnly) {
                $ineligible[] = $entry;
                $warnings[] = "Member {$member['first_name']} {$member['last_name']} has no active SEPA mandate";
            }
        }

        // Counted over the eligible members' *whole* positions, because that is
        // what createSettlement() sweeps once the partition check passes.
        $transactionCount = (int) array_sum(array_column($eligible, 'transaction_count'));

        return new SettlementPreviewDto(
            eligibleMembers: $eligible,
            ineligibleMembers: $ineligible,
            eligibleTotal: array_sum(array_column($eligible, 'balance_cents')),
            ineligibleTotal: array_sum(array_column($ineligible, 'balance_cents')),
            memberCount: count($eligible) + count($ineligible) + count($credit) + count($held),
            warnings: $warnings,
            creditMembers: $credit,
            creditTotal: array_sum(array_column($credit, 'balance_cents')),
            heldMembers: $held,
            heldTotal: array_sum(array_column($held, 'balance_cents')),
            transactionCount: $transactionCount,
            membersWithoutEmail: $withoutEmail,
        );
    }

    /**
     * SEPA validity is one question — does the member hold an active mandate
     * (#164) — asked of the mandate record the member row joins in, not a
     * conjunction of loosely-maintained columns. `is_active` is deliberately
     * not part of it: deactivation is temporary (a lost card), and must not
     * strand debt the member genuinely owes (ruling #173).
     */
    private function hasActiveMandate(array $member): bool
    {
        return !empty($member['mandate_reference']) && !empty($member['has_iban']);
    }

    /**
     * Return lightweight aggregate preview for all unsettled transactions
     * matching the given journal filters.
     *
     * @param array{ date_from?: string, date_to?: string, search?: string, member_id?: string } $filters
     * @return array{ transaction_count: int, member_count: int, total_amount_cents: int }
     */
    public function previewByFilters(array $filters): array
    {
        return $this->transactionsRepository->summarizeUnsettledByFilters($filters);
    }

    /**
     * `$postedMemberIds` is what the New Settlement screen sends: the run's
     * participants named directly, since the unit of selection is the member
     * (ADR-0030). `$transactionIds` is the compatibility path — it says *who*
     * is being settled and is then discarded, because either way the run
     * covers each named member in full (#161 §2).
     *
     * There is no `$settlementDate` parameter: the settlement's date is the day
     * the server created it (issue #113). It was a request field once, and
     * being one made it the anchor of the lead-time rule — a caller could
     * backdate it and collect tomorrow. Nothing reads it as a rule input now,
     * and no layer can be handed a wrong one.
     *
     * @param list<string>|null $postedMemberIds
     */
    public function createSettlement(array $transactionIds, string $executionDate, ?string $periodStart, ?string $periodEnd, SettlementMethod $method, ?string $notes, string $adminUserId, ?array $postedMemberIds = null): SettlementDto
    {
        // #405: a direct debit is announced, and the drain is the only thing
        // that sends the announcement. On an installation where no scheduled
        // run has ever been observed, that announcement would be queued and
        // never leave — so the collection is refused instead of being made
        // unannounced, which is what makes the scheduler mandatory rather than
        // recommended.
        //
        // Scoped to the methods that enqueue: a `write_off` moves no money and
        // announces nothing, and blocking it would refuse an operation whose
        // promise this installation *can* keep. `bank_transfer` joins this
        // branch when #410 gives it a payment request.
        //
        // Outside the transaction and before any write, because nothing about
        // this settlement is wrong — there is simply nothing to roll back.
        if ($method->isSepaExportable()) {
            $this->schedulerStatusService->assertVerified();
        }

        $this->db->beginTransaction();
        try {
            if ($postedMemberIds !== null) {
                $memberIds = array_values(array_unique($postedMemberIds));
                if (empty($memberIds)) {
                    throw new BusinessRuleException('No members named');
                }
            } else {
                // Validate no conflicts
                $conflicts = $this->settlementsRepository->hasConflicts($transactionIds);
                if (!empty($conflicts)) {
                    throw new BusinessRuleException('Some transactions are already settled');
                }

                // Fetch transactions
                $posted = $this->transactionsRepository->findUnsettledByIds($transactionIds);
                if (empty($posted)) {
                    throw new BusinessRuleException('No valid unsettled transactions found');
                }

                $memberIds = array_values(array_unique(array_column($posted, 'member_id')));
            }

            // #161 §6: the preview's verdict is binding. Posting ids the
            // preview flagged used to settle them regardless, because creation
            // validated nothing at all.
            $partition = $this->partitionByCollectability($memberIds, $method);
            if (!empty($partition['ineligible']) || !empty($partition['credit']) || !empty($partition['held'])) {
                $messages = [];
                if (!empty($partition['ineligible'])) {
                    $messages['ineligible_member_ids'] =
                        ['These members have no active SEPA mandate: ' . implode(', ', $partition['ineligible'])];
                }
                if (!empty($partition['credit'])) {
                    $messages['credit_member_ids'] =
                        ['These members are in credit and are excluded from collection: ' . implode(', ', $partition['credit'])];
                }
                if (!empty($partition['held'])) {
                    $messages['held_member_ids'] =
                        ['These members are on collection hold after a returned direct debit: ' . implode(', ', $partition['held'])];
                }

                throw new ValidationException(
                    'Some members cannot be settled — ' . implode('; ', array_map(fn(array $m) => $m[0], $messages)),
                    $messages,
                );
            }

            $transactions = $this->transactionsRepository->findUnsettledByMemberIds($memberIds);
            if (empty($transactions)) {
                // Reachable from the member path, where nothing was resolved
                // through an unsettled row on the way in: an unknown id, or a
                // member whose position another run swept while this screen was
                // open. An empty settlement is not a settlement.
                throw new BusinessRuleException('No valid unsettled transactions found');
            }

            $totalAmount = array_sum(array_column($transactions, 'amount_cents'));

            // #372: a run that collects nothing is not a settlement. It gets
            // here when every named member's open rows net out — the ordinary
            // case being a storno cancelling each of their sales — and what it
            // would produce is a pain.008 whose PmtInf carries no direct debit
            // at all, which is not a valid file and which no bank accepts.
            //
            // This does not walk back ruling #141 §5: a member closing out at
            // 0.00 still settles, and still rides along in a run that collects
            // from somebody else. It is the run's own total that may not be
            // zero, because that total is what goes to the bank.
            if ($totalAmount <= 0) {
                throw new BusinessRuleException(sprintf(
                    'A settlement has to collect something, and this one totals %s EUR — the selected members\' '
                    . 'open transactions cancel each other out. Nothing is owed, so there is nothing to collect '
                    . 'and no SEPA file to send.',
                    number_format($totalAmount / 100, 2, '.', ''),
                ));
            }

            // Ruling #163: bank_transfer/write_off settlements cover exactly one
            // member (also enforced in the DB by chk_settlements_manual_is_single_member).
            // Reject here so the caller gets a 422 instead of a raw SQL CHECK violation.
            if (!$method->isSepaExportable() && count($memberIds) !== 1) {
                throw new ValidationException(
                    'Non-direct-debit settlements must cover exactly one member',
                    ['method' => ['bank_transfer and write_off settlements must cover exactly one member']],
                );
            }

            $sepaMessageId = $this->settlementsRepository->getNextSepaMessageId();

            $settlement = $this->settlementsRepository->create([
                'method' => $method->value,
                // The server's clock, never the caller's (issue #113).
                'settlement_date' => SettlementLeadTime::today(),
                'execution_date' => $executionDate,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'sepa_message_id' => $sepaMessageId,
                'total_amount_cents' => $totalAmount,
                'member_count' => count($memberIds),
                'notes' => $notes,
                'created_by_admin_id' => $adminUserId,
            ]);

            foreach ($transactions as $tx) {
                $this->settlementsRepository->createItem([
                    'settlement_id' => $settlement['id'],
                    'transaction_id' => $tx['id'],
                    'member_id' => $tx['member_id'],
                    'amount_cents' => $tx['amount_cents'],
                ]);
            }

            // The pre-notification is enqueued here, inside this transaction,
            // and no network call happens (ADR-0038 rule 1). Two things follow.
            //
            // First, there is no half-finalize: the settlement and the promise
            // to announce it commit together, or neither exists. A finalize
            // that sent mail in a loop could be cut off by the host's gateway
            // timeout with an unknowable number of members already told, and
            // that state is not repairable.
            //
            // Second, this is the point where the seven-day distance is
            // guaranteed by construction — $executionDate is already at least
            // today + 7 (SettlementLeadTime), so whenever the drain gets to it,
            // the announcement cannot be late by Nutzungsordnung § 7 Abs. 3.
            //
            // Only a direct debit is announced. A bank transfer needs a payment
            // request instead (different content, no mandate reference) and a
            // write-off moves no money at all — #410.
            if ($method->isSepaExportable()) {
                $this->notificationsService->enqueueForSettlement(
                    settlementId: $settlement['id'],
                    amountsByMember: self::sumByMember($transactions),
                    adminUserId: $adminUserId,
                );
            }

            $this->auditService->log(
                action: AuditAction::SETTLEMENT_CREATE,
                entityType: EntityType::SETTLEMENT,
                entityId: $settlement['id'],
                newValues: ['total_amount_cents' => $totalAmount, 'member_count' => count($memberIds), 'transaction_count' => count($transactions)],
                adminUserId: $adminUserId,
            );

            $this->db->commit();

            $items = $this->settlementsRepository->findItemsBySettlementId($settlement['id']);
            $itemDtos = array_map(fn($row) => SettlementItemDto::fromRow($row), $items);

            return SettlementDto::fromRow($settlement, $itemDtos);

        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * What this run collects from each member — the figure the announcement
     * quotes, and the one that decides whether there is anything to announce.
     *
     * It is a sum over the settled rows rather than the member's position,
     * because a storno inside the same run nets against the sale: a member
     * whose sales are all reversed settles at 0.00, is told nothing, and is
     * still correctly part of the run (ruling #141 §5).
     *
     * @param list<array{member_id: string, amount_cents: int|string}> $transactions
     * @return array<string,int>
     */
    private static function sumByMember(array $transactions): array
    {
        $totals = [];
        foreach ($transactions as $tx) {
            $memberId = (string) $tx['member_id'];
            $totals[$memberId] = ($totals[$memberId] ?? 0) + (int) $tx['amount_cents'];
        }

        return $totals;
    }

    /**
     * Split a run's participants into those it can collect from and those it
     * must leave out, with the reason kept distinct (ruling #141, #161 §3,
     * ruling #148 §4).
     *
     * @param list<string> $memberIds
     * @return array{collectable: list<string>, ineligible: list<string>, credit: list<string>, held: list<string>}
     */
    private function partitionByCollectability(array $memberIds, SettlementMethod $method): array
    {
        $positions = $this->settlementsRepository->calculateUnsettledPositions($memberIds);

        $collectable = [];
        $ineligible = [];
        $credit = [];
        $held = [];

        foreach ($memberIds as $memberId) {
            // A negative position means the club owes this member money, so no
            // settlement may touch them whatever their bank details say.
            if (($positions[$memberId] ?? 0) < 0) {
                $credit[] = $memberId;
                continue;
            }

            $member = $this->membersRepository->findById($memberId);

            // A hold stops *collection*, and only collection. A bank transfer
            // is the member paying their own tab and a write-off gives up on
            // the money — both are ways out of a hold, so neither may be
            // blocked by one, or a returned direct debit would strand the debt
            // with no way to resolve it.
            if ($method->isSepaExportable() && $member !== null && !empty($member['collection_hold'])) {
                $held[] = $memberId;
                continue;
            }

            // Only a direct debit needs a mandate: a bank transfer is the
            // member paying their own tab, and a write-off collects nothing.
            if ($method->isSepaExportable() && ($member === null || !$this->hasActiveMandate($member))) {
                $ineligible[] = $memberId;
                continue;
            }

            $collectable[] = $memberId;
        }

        return ['collectable' => $collectable, 'ineligible' => $ineligible, 'credit' => $credit, 'held' => $held];
    }

    /**
     * Create a settlement for all unsettled transactions matching the given filters.
     *
     * Unlike createSettlement(), which is handed a list the admin picked and
     * refuses it if anything in it cannot be collected, this path is a sweep:
     * members it cannot collect from are excluded and the run proceeds
     * (exclude-and-flag, ruling #141).
     *
     * @param array{ date_from?: string, date_to?: string, search?: string, member_id?: string } $filters
     */
    public function createSettlementByFilters(
        array $filters,
        string $executionDate,
        string $adminUserId,
        ?string $notes = null,
        SettlementMethod $method = SettlementMethod::DIRECT_DEBIT,
    ): SettlementDto {
        $transactionIds = $this->transactionsRepository->findAllUnsettledByFilters($filters);
        if (empty($transactionIds)) {
            throw new BusinessRuleException('No unsettled transactions found for the given filters');
        }

        $matched = $this->transactionsRepository->findUnsettledByIds($transactionIds);
        $memberIds = array_values(array_unique(array_column($matched, 'member_id')));
        $collectable = $this->partitionByCollectability($memberIds, $method)['collectable'];
        if (empty($collectable)) {
            throw new BusinessRuleException(
                'No collectable members matched the given filters — every match is in credit, on collection hold, '
                . 'or has no active SEPA mandate'
            );
        }

        $collectableIds = [];
        foreach ($matched as $transaction) {
            if (in_array($transaction['member_id'], $collectable, true)) {
                $collectableIds[] = $transaction['id'];
            }
        }

        return $this->createSettlement(
            transactionIds: $collectableIds,
            executionDate: $executionDate,
            periodStart: $filters['date_from'] ?? null,
            periodEnd: $filters['date_to'] ?? null,
            method: $method,
            notes: $notes,
            adminUserId: $adminUserId,
        );
    }

    public function getSettlement(string $settlementId): ?SettlementDto
    {
        $settlement = $this->settlementsRepository->findById($settlementId);
        if (!$settlement) return null;

        $items = $this->settlementsRepository->findItemsBySettlementId($settlementId);
        $itemDtos = array_map(fn($row) => SettlementItemDto::fromRow($row), $items);

        // The reversal events ride along on the single-settlement read: they
        // are what the derived status is computed from (ruling #148 §6), so
        // showing the status without them would leave the admin panel unable
        // to say *why* a settlement reads "partly reversed".
        $reversals = array_map(
            static fn(array $row) => SettlementReversalDto::fromRow($row),
            $this->reversalsRepository->findBySettlementId($settlementId),
        );

        return SettlementDto::fromRow($settlement, $itemDtos, $reversals);
    }

    public function listSettlements(int $limit, int $offset, ?string $status = null, string $sortKey = 'created_at', string $sortOrder = 'desc', ?string $dateFrom = null, ?string $dateTo = null): PaginatedResultDto
    {
        $result = $this->settlementsRepository->listPaginated($limit, $offset, $status, $sortKey, $sortOrder, $dateFrom, $dateTo);
        $items = array_map(fn($row) => SettlementDto::fromRow($row)->toArray(), $result['items']);

        return new PaginatedResultDto(items: $items, total: $result['total'], limit: $limit, offset: $offset);
    }

    /**
     * Undo a settlement that has not yet moved any money (#81, ruling #142).
     *
     * The gate is CancellationGate's to answer; this method's own job is to
     * make the undo atomic. Releasing the items and flagging the settlement are
     * two writes, and a crash between them used to leave a live settlement with
     * no claims — whose transactions then silently counted as unsettled and
     * were collected a second time (#86).
     *
     * @throws BusinessRuleException 409 when money has already moved.
     */
    public function cancelSettlement(string $settlementId, string $adminUserId, ?string $reason = null): bool
    {
        $settlement = $this->settlementsRepository->findById($settlementId);
        if (!$settlement) throw NotFoundException::forResource('Settlement', $settlementId);

        $blocker = CancellationGate::blocker($settlement);
        if ($blocker !== null) {
            throw new BusinessRuleException($blocker);
        }

        $this->db->beginTransaction();
        try {
            $result = $this->settlementsRepository->cancelSettlement($settlementId, $adminUserId);

            // What the member already knows decides what happens to their
            // announcement (ADR-0038). One that never left the host is
            // superseded — there is nothing to retract. One that went out earns
            // a "Einzug entfällt". The queue is the only record of which is
            // which, so this has to happen here, in the cancellation's own
            // transaction, and not in a later pass that could find the queue
            // already drained.
            $this->notificationsService->cancelSettlementNotifications($settlementId, $adminUserId);

            $this->auditService->log(
                action: AuditAction::SETTLEMENT_CANCEL,
                entityType: EntityType::SETTLEMENT,
                entityId: $settlementId,
                oldValues: ['is_cancelled' => false],
                newValues: ['is_cancelled' => true, 'reason' => $reason],
                adminUserId: $adminUserId,
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $result;
    }

    /**
     * Record that the exported file is now with the bank — the real point of no
     * return, and the moment cancellation stops being available (ruling #142 §1).
     *
     * `exported_at` cannot carry this meaning: it is set when the treasurer
     * *generates* the file, which says nothing about whether it was sent.
     *
     * @throws BusinessRuleException 409 when there is no submitted file to record.
     */
    public function markSubmitted(string $settlementId, string $adminUserId): bool
    {
        $settlement = $this->settlementsRepository->findById($settlementId);
        if (!$settlement) throw NotFoundException::forResource('Settlement', $settlementId);

        if (!empty($settlement['is_cancelled'])) {
            throw new BusinessRuleException('This settlement was cancelled and cannot be submitted to the bank.');
        }

        if (!empty($settlement['submitted_at'])) {
            throw new BusinessRuleException('This settlement has already been marked as submitted.');
        }

        $method = SettlementMethod::tryFrom((string) ($settlement['method'] ?? '')) ?? SettlementMethod::DIRECT_DEBIT;
        if (!$method->isSepaExportable()) {
            throw new BusinessRuleException(
                'Only a direct-debit settlement is submitted to a bank; a ' . $method->label() . ' is not.'
            );
        }

        if (empty($settlement['exported_at'])) {
            throw new BusinessRuleException(
                'Export the SEPA file before marking this settlement as submitted — there is nothing with the bank yet.'
            );
        }

        $result = $this->settlementsRepository->markSubmitted($settlementId, $adminUserId);

        $this->auditService->log(
            action: AuditAction::SETTLEMENT_SUBMIT,
            entityType: EntityType::SETTLEMENT,
            entityId: $settlementId,
            newValues: ['submitted_at' => date('Y-m-d H:i:s'), 'submitted_by_admin_id' => $adminUserId],
            adminUserId: $adminUserId,
        );

        return $result;
    }

    public function getCsvData(string $settlementId): array
    {
        $items = $this->settlementsRepository->findItemsBySettlementId($settlementId);
        $memberData = [];
        foreach ($items as $item) {
            $mid = $item['member_id'];
            if (!isset($memberData[$mid])) {
                $member = $this->membersRepository->findByIdIncludingDeleted($mid);
                $memberData[$mid] = [
                    'name' => trim(($item['first_name'] ?? '') . ' ' . ($item['last_name'] ?? '')),
                    'email' => $member['email'] ?? '',
                    // ****3000 — the CSV is a reconciliation aid, not a debit
                    // instruction; last4 identifies the account (ADR-0036).
                    'iban' => isset($member['iban_last4']) && $member['iban_last4'] !== null
                        ? '****' . $member['iban_last4'] : '',
                    'amount_cents' => 0,
                ];
            }
            $memberData[$mid]['amount_cents'] += (int) $item['amount_cents'];
        }
        return array_values($memberData);
    }

    /**
     * Record that a pain.008 file was generated for this settlement.
     *
     * @param array<string, mixed> $exportSummary What the file actually
     *        collects and whom it left out — {@see SepaExportResultDto::toAuditSummary()}.
     *        It goes into the audit entry because the HTTP response carrying
     *        it is a file download the browser saves and forgets, and #114 is
     *        precisely the story of an omission with nowhere to be read later.
     *
     * @throws NotFoundException 404 when the settlement does not exist.
     * @throws BusinessRuleException 409 when it was cancelled.
     */
    public function markExported(string $settlementId, string $adminUserId, array $exportSummary = []): bool
    {
        // An unknown id used to write an audit entry claiming an export
        // happened, for a settlement that was never there — the UPDATE matched
        // no row and said nothing, because PDO::execute() reports the statement
        // ran, not that it changed anything (#114).
        $settlement = $this->settlementsRepository->findById($settlementId);
        if ($settlement === null) {
            throw NotFoundException::forResource('Settlement', $settlementId);
        }

        // Stamping a cancelled settlement as exported would make it look like a
        // file went out for a run that collects nothing (#114, #142 §5).
        if (!empty($settlement['is_cancelled'])) {
            throw new BusinessRuleException(
                sprintf('Settlement %s was cancelled and cannot be exported to SEPA', $settlementId)
            );
        }

        $result = $this->settlementsRepository->markExported($settlementId);

        $this->auditService->log(
            action: AuditAction::SETTLEMENT_EXPORT,
            entityType: EntityType::SETTLEMENT,
            entityId: $settlementId,
            newValues: ['exported_at' => date('Y-m-d H:i:s')] + $exportSummary,
            adminUserId: $adminUserId,
        );

        return $result;
    }

    /**
     * Standing "credit balances outstanding" listing under Members (#161 work
     * item 3, non-blocking) — every member the club currently owes money,
     * most-negative first, with the sum of what is owed.
     *
     * @return array{items: list<CreditBalanceDto>, total_credit_cents: int}
     */
    public function listCreditBalances(): array
    {
        $rows = $this->settlementsRepository->findMembersInCredit();
        $items = array_map(fn(array $row) => CreditBalanceDto::fromRow($row), $rows);

        return [
            'items' => $items,
            'total_credit_cents' => array_sum(array_column($rows, 'balance_cents')),
        ];
    }

    /**
     * Standing "no usable mandate" listing under Members (#258) — the members
     * the next run cannot collect from at all, most owed first.
     *
     * The total is a third kind of number, and the surface labels it as one:
     * credit is money owed *out*, a hold is money owed *in* and temporarily
     * skipped, and this is money owed in that no run can currently reach.
     *
     * @return array{items: list<MandateMissingDto>, total_uncollectable_cents: int}
     */
    public function listMembersWithoutMandate(): array
    {
        $rows = $this->settlementsRepository->findMembersWithoutUsableMandate();
        $items = array_map(fn(array $row) => MandateMissingDto::fromRow($row), $rows);

        return [
            'items' => $items,
            'total_uncollectable_cents' => array_sum(array_column($rows, 'balance_cents')),
        ];
    }
}
