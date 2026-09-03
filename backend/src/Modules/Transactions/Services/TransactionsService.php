<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Services;

use App\Shared\Utils\Uuid;
use App\Modules\Transactions\DTOs\TransactionBatchResultDto;
use App\Modules\Transactions\Exceptions\CannotStornoAStornoException;
use App\Modules\Transactions\Exceptions\TransactionAlreadyStornoedException;
use App\Modules\Transactions\Exceptions\TransactionNotStorableException;
use App\Shared\DTOs\PaginatedResultDto;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use App\Modules\Transactions\Sync\TerminalTransactionValidator;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Services\AdminNotifier;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Exceptions\NotFoundException;
use App\Modules\Members\Domain\MandateCompleteness;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Products\Repositories\ProductsRepository;
use App\Shared\Logging\Logger;
use App\Shared\Services\AuditService;
use App\Shared\Utils\Age;
use App\Shared\Utils\DateFormatter;

class TransactionsService
{
    public function __construct(
        private TransactionsRepository $transactionsRepository,
        private MembersRepository $membersRepository,
        private ProductsRepository $productsRepository,
        private AuditService $auditService,
        private Logger $logger,
        private AdminNotifier $adminNotifier,
    ) {}

    /**
     * @param list<string> $requestedMemberIds Members whose balance the caller
     *        wants reported even though this batch does not touch them (#191).
     *        A terminal after a settlement has nothing to upload, so without
     *        this it could never learn that the tab is now zero — it would keep
     *        showing the pre-settlement Deckel until the member's next purchase.
     */
    public function processBatch(array $transactions, array $requestedMemberIds = []): TransactionBatchResultDto
    {
        $acceptedIds = [];
        $errors = [];
        $affectedMemberIds = [];
        // The product row per id, looked up at most once per batch. A 100-row
        // batch is frequently 100 rows for a handful of products, and two flags
        // now read from it — the price and the Jugendschutz age. Local rather
        // than a property: this service is memoised as a singleton, and a cache
        // that outlived the batch would judge later sales against a price, or a
        // legal age, that has since changed.
        $products = [];

        foreach ($requestedMemberIds as $memberId) {
            // Unknown ids are skipped, not reported as 0. A phantom zero would
            // read to the terminal as "this member owes nothing" and overwrite a
            // real cached balance; an absent key leaves the cache alone, which
            // is the same graceful degradation an offline scan already gets.
            if ($this->membersRepository->findById($memberId)) {
                $affectedMemberIds[$memberId] = true;
            }
        }

        foreach ($transactions as $tx) {
            // #259, ruling #143 §2: judged per row, not per batch. This used to
            // be a batch-wide 422 in SyncController — one unstorable row and
            // nothing at all was written, which the terminal then retried
            // unchanged forever, so every good sale behind it went uncollected.
            $rejection = TerminalTransactionValidator::rejectionFor($tx);
            if ($rejection !== null) {
                $errors[] = $rejection;
                continue;
            }

            $member = $this->membersRepository->findById($tx['member_id']);
            if (!$member) {
                $errors[] = ['error' => 'not_found', 'transaction_id' => $tx['id'], 'message' => 'Member not found'];
                continue;
            }

            // #162, ruling #143 §1: a missing mandate is NOT a rejection. The
            // drink was served against the terminal's last synced state; by the
            // time the batch arrives the sale is a historical fact and the only
            // question is whether the row can be stored. Refusing it here would
            // destroy the record of a sale that happened, bill nobody, and show
            // the loss on no report. ADR-0020 keeps the preventive half at the
            // terminal (it blocks at card scan); the server is the backstop and
            // stores-and-flags. The flag is derived, not stored: the member now
            // carries an unsettled balance without an active mandate, which is
            // exactly what puts them in the settlement preview's
            // `ineligible_members` bucket (#161 §3) for the treasurer.
            if (!$this->hasActiveMandate($member)) {
                $this->logger->warning('Transaction stored for member without an active SEPA mandate', [
                    'member_id' => $tx['member_id'],
                    'transaction_id' => $tx['id'] ?? null,
                ]);
            }

            $this->flagImplausibleSaleTime($tx);

            try {
                // A null return means the id was already stored — the terminal
                // is replaying the batch, which ADR-0004 makes safe. Either way
                // the transaction is on the server, so either way it is accepted.
                $stored = $this->transactionsRepository->insertTransaction($tx);
            } catch (TransactionNotStorableException $e) {
                // #82: a row the database refused. Never report it as accepted —
                // the terminal would purge a served drink from its offline queue
                // and the sale would exist nowhere. Only this row is rejected;
                // the rest of the batch still lands. Transient failures are not
                // caught here: they propagate so the terminal retries the batch.
                $errors[] = [
                    'error' => 'unstorable',
                    'transaction_id' => $tx['id'] ?? null,
                    'message' => 'Transaction could not be stored and was not accepted',
                ];
                continue;
            }

            // Only for a row this call actually inserted. The two flags above
            // run before the insert; this one runs after, and deliberately.
            // A row the database refused is then never named in an append-only
            // table it does not appear in; a replayed batch does not write the
            // entry twice; and an audit write that fails (the classic cause
            // being migration 040 unapplied, so the ENUM truncates) cannot wedge
            // the batch permanently — the retry finds the booking already
            // stored, gets null back, and skips straight past this.
            if ($stored !== null) {
                $this->flagPriceDivergence($tx, $products);
                $this->flagJugendschutzViolation($tx, $member, $products);
            }

            $acceptedIds[] = $tx['id'];
            $affectedMemberIds[$tx['member_id']] = true;
        }

        $memberBalances = [];
        foreach (array_keys($affectedMemberIds) as $memberId) {
            $memberBalances[$memberId] = $this->transactionsRepository->getUnsettledMemberBalanceCents($memberId);
        }

        return new TransactionBatchResultDto(
            acceptedIds: $acceptedIds,
            rejectedCount: count($errors),
            errors: $errors,
            memberBalances: $memberBalances,
        );
    }

    /**
     * Storno a transaction — reverse one specific booking, in full.
     *
     * The transaction is the subject of the operation, not a parameter of it
     * (UC-A23, #169). Everything the storno needs is read from the target:
     *
     * - the **amount** is the exact negation of the original, and cannot be
     *   supplied by the caller. This is what deletes the sign-convention and
     *   zero-amount error classes rather than validating them away;
     * - the **member** comes from the original too, so a storno can never be
     *   booked against somebody else's tab.
     *
     * There is no SEPA mandate gate. A storno *reduces* what the member owes,
     * and gating a debt reduction on the ability to collect is inverted — it
     * blocked the § 812 BGB remedy for exactly the members who could not be
     * billed (#158 §1, ADR-0028 §1).
     *
     * @throws NotFoundException when no such transaction exists
     * @throws CannotStornoAStornoException when the target is itself a storno
     * @throws TransactionAlreadyStornoedException when the target already has a storno
     */
    public function storno(string $transactionId, string $reason, ?string $adminId = null): array
    {
        $original = $this->transactionsRepository->findById($transactionId);
        if (!$original) {
            throw NotFoundException::forResource('Transaction', $transactionId);
        }

        if (($original['transaction_type'] ?? null) === 'storno') {
            throw new CannotStornoAStornoException(
                'A storno cannot itself be stornoed. Book a new purchase instead.',
            );
        }

        // A courtesy check so the common case reports cleanly. It is not the
        // guarantee — the unique index on related_transaction_id is, and
        // insertStorno() surfaces its refusal (see there).
        if ($this->transactionsRepository->findStornoFor($transactionId) !== null) {
            throw new TransactionAlreadyStornoedException('This transaction has already been stornoed');
        }

        $memberId = $original['member_id'];
        $stored = $this->transactionsRepository->insertStorno([
            'id' => Uuid::v4(),
            'member_id' => $memberId,
            'product_id' => null,
            'amount_cents' => -((int) $original['amount_cents']),
            'transaction_type' => 'storno',
            'notes' => $reason,
            'created_by_admin_id' => $adminId,
            'related_transaction_id' => $transactionId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->auditService->log(
            action: AuditAction::TRANSACTION_STORNO,
            entityType: EntityType::TRANSACTION,
            entityId: $stored['id'],
            newValues: [
                'related_transaction_id' => $transactionId,
                'member_id' => $memberId,
                'amount_cents' => (int) $stored['amount_cents'],
                'reason' => $reason,
            ],
            adminUserId: $adminId,
        );

        return [
            'transaction' => $this->formatTransactionTimestamps($stored),
            'new_balance_cents' => $this->transactionsRepository->getUnsettledMemberBalanceCents($memberId),
        ];
    }

    public function getTransactions(int $limit, int $offset, array $filters = [], string $sortKey = 'created_at', string $sortOrder = 'desc'): PaginatedResultDto
    {
        $result = $this->transactionsRepository->listPaginated($limit, $offset, $filters, $sortKey, $sortOrder);

        $items = array_map(fn(array $row) => $this->formatTransactionTimestamps($row), $result['items']);

        return new PaginatedResultDto(
            items: $items,
            total: $result['total'],
            limit: $limit,
            offset: $offset,
        );
    }

    public function getMemberTransactionHistory(string $memberId, ?string $type = null): array
    {
        $balance = $this->transactionsRepository->getUnsettledMemberBalanceCents($memberId);
        $transactions = $this->transactionsRepository->findByMemberId($memberId, 1000, 0, $type);
        $transactions = array_map(fn(array $row) => $this->formatTransactionTimestamps($row), $transactions);

        return [
            'member_id' => $memberId,
            'current_balance_cents' => $balance,
            'transactions' => $transactions,
        ];
    }

    public function getRecentTransactions(string $memberId, int $limit = 50, int $offset = 0, ?string $since = null): array
    {
        return $this->transactionsRepository->findByMemberId($memberId, $limit, $offset, null, $since);
    }

    /**
     * Fetch recent transactions for a member with member existence check.
     * Adds translated product_name and normalized type field.
     *
     * @throws NotFoundException when member does not exist
     */
    public function getRecentTransactionsForMember(string $memberId, int $limit = 50, int $offset = 0, ?string $since = null): array
    {
        $member = $this->membersRepository->findById($memberId);
        if (!$member) {
            throw NotFoundException::forResource('Member', $memberId);
        }

        $language = $member['preferred_language'] ?? 'de';
        $rows = $this->transactionsRepository->findByMemberId($memberId, $limit, $offset, null, $since);

        foreach ($rows as &$row) {
            // Format timestamps to ISO 8601 UTC
            $row = $this->formatTransactionTimestamps($row);

            // Normalize type field
            $row['type'] = $row['transaction_type'] ?? null;

            // Translate product_name from JSON names column
            $productNames = $row['product_names'] ?? null;
            if ($productNames !== null) {
                $names = is_array($productNames) ? $productNames : (json_decode($productNames, true) ?? []);
                $row['product_name'] = $names[$language] ?? $names['de'] ?? $names['en'] ?? reset($names) ?: null;
            } else {
                // No product (a storno or a payout): use notes, fallback to type label
                $typeLabel = match ($row['transaction_type'] ?? '') {
                    'storno' => 'Storno',
                    'payout' => 'Payout',
                    default  => 'Transaction',
                };
                $row['product_name'] = $row['notes'] ?: $typeLabel;
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * Format DATETIME fields on a raw transaction row to ISO 8601 UTC.
     * Leaves DATE-only fields (settlement_date) unchanged.
     */
    private function formatTransactionTimestamps(array $row): array
    {
        if (isset($row['created_at'])) {
            $row['created_at'] = DateFormatter::toUtcIso($row['created_at']);
        }
        if (isset($row['updated_at'])) {
            $row['updated_at'] = DateFormatter::toUtcIso($row['updated_at']);
        }
        // settlement_date is DATE-only — no timezone conversion needed
        return $row;
    }

    /**
     * Mirrors SettlementsService: SEPA validity is the single question "does
     * this member hold an active mandate" (#164). Kept identical so the sync
     * flag and the settlement-preview `ineligible_members` bucket never
     * disagree about who needs the treasurer's attention.
     */
    /**
     * How far ahead of the server's clock a sale time may sit before it stops
     * being terminal clock skew and starts being a claim about the future.
     */
    private const FUTURE_SALE_TOLERANCE_SECONDS = 3600;

    /**
     * How far back a sale time may sit before it stops being an offline stretch
     * (ADR-0012 permits days) and starts being a dead clock battery or a
     * backdating attempt. Generous on purpose: the flag must stay rare enough
     * to be worth reading.
     */
    private const STALE_SALE_TOLERANCE_SECONDS = 90 * 86400;

    /**
     * Flag a sale time that cannot be true, and store it anyway (#79, ruling
     * #144 §2).
     *
     * The value is never rewritten and never rejected. Clamping would erase the
     * evidence — a terminal with a flat clock battery and an attacker probing
     * the ledger look identical once the value is corrected in place — and
     * silently editing a recorded fact sits badly with ADR-0004's append-only
     * ledger. Rejecting would refuse an evening's real revenue over a hardware
     * fault, which is exactly what ruling #143 forbids. `received_at`, stamped
     * by the database, is the anchor that makes the claim checkable later.
     *
     * The backdating attack this originally guarded is separately defused:
     * settlement sweeps a member's entire unsettled position regardless of date
     * (#141), so a backdated sale is still collected.
     */
    private function flagImplausibleSaleTime(array $tx): void
    {
        $occurredAt = $tx['created_at'] ?? null;
        if (!is_string($occurredAt) || $occurredAt === '') {
            return;
        }

        try {
            $soldAt = new \DateTimeImmutable($occurredAt);
        } catch (\Exception) {
            // Nothing to judge. The database refuses the row and #82 reports it
            // as unstorable — a rejection, not a flag.
            return;
        }

        $skewSeconds = $soldAt->getTimestamp() - time();

        $direction = match (true) {
            $skewSeconds > self::FUTURE_SALE_TOLERANCE_SECONDS => 'future',
            -$skewSeconds > self::STALE_SALE_TOLERANCE_SECONDS => 'stale',
            default => null,
        };

        if ($direction === null) {
            return;
        }

        $this->logger->warning('Transaction stored with an implausible sale time', [
            'transaction_id' => $tx['id'] ?? null,
            'member_id' => $tx['member_id'] ?? null,
            'terminal_id' => $tx['created_by_terminal_id'] ?? null,
            'occurred_at' => $occurredAt,
            'skew_seconds' => $skewSeconds,
            'direction' => $direction,
        ]);
    }

    /**
     * Record a stored sale whose claimed amount disagrees with what the product
     * costs today (#204, ruling #144 §3, the last item of its field-authority
     * table).
     *
     * The amount is **not** corrected. The terminal charged a price the member
     * saw and accepted, possibly weeks earlier while offline (ADR-0012);
     * recomputing it from the current catalogue would silently bill something
     * other than the tile showed at the bar, and would have nothing to compute
     * from once the product is deleted. A compromised terminal can lie about
     * amounts, but it can equally invent whole transactions, so this is not the
     * weak link — the entry is for accounting honesty, not access control. The
     * likelier trigger is a terminal running a stale product cache after the
     * catalogue changed, and this is how that gets noticed.
     *
     * An audit entry rather than the `logger->warning` its two siblings above
     * use: a divergence is a financial fact a treasurer should be able to find,
     * filter and keep, which is what ADR-0013's table is for and what a daily
     * JSON log file is not. Like them, it never alters the row and never
     * rejects it.
     *
     * @param array<string, mixed> $tx
     * @param array<string, int|null> $cache Per-batch product price lookups.
     */
    private function flagPriceDivergence(array $tx, array &$cache): void
    {
        $productId = $tx['product_id'] ?? null;
        if (!is_string($productId) || $productId === '') {
            return;
        }

        $currentCents = $this->currentPriceCents($productId, $cache);
        if ($currentCents === null) {
            // A product that no longer exists, or never did, is not a
            // divergence — there is nothing to compare against. Not a rejection
            // either: the sale is real (ruling #143).
            return;
        }

        $claimedCents = (int) $tx['amount_cents'];
        if ($claimedCents === $currentCents) {
            return;
        }

        // No actor: a terminal synced this, no admin acted. `admin_user_id` is
        // nullable and every terminal-originated entry relies on that. The
        // payload carries ids only and never member PII — it is filed under the
        // transaction, so the anonymization scrub, which keys on the member's
        // own entity_id, cannot reach inside it (ADR-0013 principle 8).
        $this->auditService->log(
            action: AuditAction::TRANSACTION_PRICE_DIVERGENCE,
            entityType: EntityType::TRANSACTION,
            entityId: $tx['id'],
            newValues: [
                'member_id' => $tx['member_id'] ?? null,
                'product_id' => $productId,
                'terminal_id' => $tx['created_by_terminal_id'] ?? null,
                'amount_cents' => $claimedCents,
                'current_price_cents' => $currentCents,
            ],
        );
    }

    /**
     * @param array<string, array<string, mixed>|null> $cache
     * @return array<string, mixed>|null The live product, or null when no live
     *         product carries that id — `findById` already excludes tombstones.
     */
    private function product(string $productId, array &$cache): ?array
    {
        // array_key_exists, not isset: a cached "no such product" is null, and
        // isset would re-query it for every remaining row of the batch.
        if (!array_key_exists($productId, $cache)) {
            $cache[$productId] = $this->productsRepository->findById($productId);
        }

        return $cache[$productId];
    }

    /**
     * @param array<string, array<string, mixed>|null> $cache
     */
    private function currentPriceCents(string $productId, array &$cache): ?int
    {
        $product = $this->product($productId, $cache);

        return $product === null ? null : (int) $product['price_cents'];
    }

    /**
     * Record a stored sale the terminal should have refused on legal grounds
     * (ADR-0045 §3, JuSchG § 9).
     *
     * Runs after the insert and only for a row this call actually inserted, for
     * the same reasons `flagPriceDivergence` does: a replayed batch must not
     * write the record twice, and a row the database refused must not be named
     * in an append-only table it does not appear in.
     *
     * **The age is recomputed at the transaction's own timestamp**, never at
     * ingest (rule 2). A terminal may sync a fortnight after the sale, and the
     * question is whether the drink was lawful when it was handed over — not
     * whether the member has since had a birthday. Judging it at ingest would
     * make a late-syncing till quietly launder the incident.
     *
     * Three cases return without recording, and each is a decision rather than
     * an oversight:
     *
     * - **No live product.** Deleted since the sale, so there is no `min_age`
     *   left to have breached. The price flag treats a tombstoned product the
     *   same way.
     * - **`min_age` is NULL.** The drink carries no legal age. A minor buying
     *   an Apfelschorle is not an incident; blocking terminal *access* on age is
     *   an explicit non-goal.
     * - **The age cannot be established** — an absent or unreadable birth date,
     *   which in practice means an anonymized member. Nothing is asserted,
     *   because a violation invented out of missing data is a legal finding the
     *   server cannot support. This is not the terminal's rule 3 inverted: there
     *   the absence of a date means *refuse*, and refusing is a safe default
     *   because nothing has happened yet. Here the sale is already a fact, and
     *   the only question is what can honestly be recorded about it.
     *
     * @param array<string, mixed> $member
     * @param array<string, array<string, mixed>|null> $products
     */
    private function flagJugendschutzViolation(array $tx, array $member, array &$products): void
    {
        $productId = $tx['product_id'] ?? null;
        if (!is_string($productId) || $productId === '') {
            return;
        }

        $product = $this->product($productId, $products);
        if ($product === null || ($product['min_age'] ?? null) === null) {
            return;
        }

        $minAge = (int) $product['min_age'];

        $occurredAt = $tx['created_at'] ?? null;
        if (!is_string($occurredAt) || $occurredAt === '') {
            return;
        }

        try {
            $soldAt = new \DateTimeImmutable($occurredAt);
        } catch (\Exception) {
            // Unparseable: the database refuses the row and #82 reports it as
            // unstorable, so this is unreachable for a stored sale. Guarded
            // anyway rather than letting an exception escape a flag.
            return;
        }

        $ageAtSale = Age::yearsAt($member['date_of_birth'] ?? null, $soldAt);
        if ($ageAtSale === null) {
            $this->logger->warning('Age-restricted product sold to a member whose age cannot be established', [
                'transaction_id' => $tx['id'] ?? null,
                'member_id' => $tx['member_id'] ?? null,
                'product_id' => $productId,
                'min_age' => $minAge,
            ]);

            return;
        }

        if ($ageAtSale >= $minAge) {
            return;
        }

        $this->logger->warning('Age-restricted product sold to an underage member', [
            'transaction_id' => $tx['id'] ?? null,
            'member_id' => $tx['member_id'] ?? null,
            'product_id' => $productId,
            'terminal_id' => $tx['created_by_terminal_id'] ?? null,
            'min_age' => $minAge,
            'age_at_sale' => $ageAtSale,
        ]);

        // No actor: a terminal synced this, no admin acted. The payload carries
        // ids and two numbers — never the member's name and never their birth
        // date. It is filed under the *transaction*, so the anonymization
        // scrub, which keys on the member's own entity_id, cannot reach inside
        // it (ADR-0013 principle 8); recording the date of birth here would
        // leave it behind after an erasure that is supposed to remove it.
        //
        // `min_age` is stored as it stood at the sale rather than looked up
        // later, which is what makes the record survive the drink being
        // un-restricted afterwards (invariant 4).
        $this->auditService->log(
            action: AuditAction::JUGENDSCHUTZ_VIOLATION,
            entityType: EntityType::TRANSACTION,
            entityId: $tx['id'],
            newValues: [
                'member_id' => $tx['member_id'] ?? null,
                'product_id' => $productId,
                'terminal_id' => $tx['created_by_terminal_id'] ?? null,
                'min_age' => $minAge,
                'age_at_sale' => $ageAtSale,
                'occurred_at' => $occurredAt,
            ],
        );

        $this->notify((string) $tx['id'], $minAge);
    }

    /**
     * Tell a human, without letting the telling endanger the record (#622).
     *
     * The audit entry above is written by the time this runs and the sale is
     * already stored, so a queue that cannot take the message must cost the
     * notification and nothing else. Letting it propagate would reject a batch
     * of good sales because a notification failed — the mistake ruling #143
     * forbids, reached from a different direction. `TerminalAnomalyDetector`
     * guards its own `mail()` the same way and for the same reason.
     *
     * The occasion is the age the drink required. `subject_id` is already the
     * transaction and M7 flags once per inserted row, so nothing about *this*
     * sale needs distinguishing — what the occasion buys instead is that the
     * message keeps saying 18 after somebody clears the limit, which is
     * invariant 4 applied to the notice as well as to the record.
     */
    private function notify(string $transactionId, int $minAge): void
    {
        try {
            $this->adminNotifier->warnAdmins(
                MailKind::JUGENDSCHUTZ_VIOLATION,
                $transactionId,
                'age:' . $minAge,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Could not queue a Jugendschutz violation notice', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * One definition, shared (ADR-0020, #164) — including the signature date,
     * which this used to leave out. {@see MandateCompleteness}.
     */
    private function hasActiveMandate(array $member): bool
    {
        return MandateCompleteness::isComplete($member);
    }
}
