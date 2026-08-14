<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Repositories;

use App\Shared\Utils\Uuid;
use PDO;
use App\Modules\Settlements\Domain\SettlementStatusFilter;
use App\Modules\Settlements\Enums\SettlementMethod;
use App\Shared\Logging\Logger;
use App\Shared\Repository\SafeQuery;
use App\Shared\Repository\UnsettledTransactions;

class SettlementsRepository
{
    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    /**
     * The two counts every settlement row carries so its status can be derived
     * rather than stored (ruling #148 §6) — how many of its members have been
     * reversed, and how many it covers at all.
     *
     * Selected as correlated subqueries rather than joined so the counts cannot
     * multiply each other, and so a settlement with neither still returns 0.
     */
    private const STATUS_COUNTS =
        '(SELECT COUNT(*) FROM settlement_reversals sr WHERE sr.settlement_id = s.id) AS reversal_member_count,
         (SELECT COUNT(DISTINCT si.member_id) FROM settlement_items si WHERE si.settlement_id = s.id) AS settled_member_count';

    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT s.*, a.display_name as admin_display_name, ' . self::STATUS_COUNTS
            . ' FROM settlements s LEFT JOIN admin_users a ON s.created_by_admin_id = a.id WHERE s.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get the most recent settlement (non-cancelled)
     */
    public function getLatest(): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT s.*, a.display_name as admin_display_name
             FROM settlements s
             LEFT JOIN admin_users a ON s.created_by_admin_id = a.id
             WHERE s.is_cancelled = 0
             ORDER BY s.created_at DESC
             LIMIT 1'
        );
        $stmt->execute();
        return $stmt->fetch() ?: null;
    }

    public function findItemsBySettlementId(string $settlementId): array
    {
        $stmt = $this->db->prepare(
            'SELECT si.*, m.first_name, m.last_name, t.transaction_type, t.notes AS transaction_notes, t.product_id, t.occurred_at AS transaction_created_at, p.names AS product_names, p.price_cents AS product_price_cents
             FROM settlement_items si
             LEFT JOIN members m ON si.member_id = m.id
             LEFT JOIN transactions t ON si.transaction_id = t.id
             LEFT JOIN products p ON t.product_id = p.id
             WHERE si.settlement_id = ?
             ORDER BY m.last_name ASC, t.occurred_at ASC'
        );
        $stmt->execute([$settlementId]);
        return $stmt->fetchAll();
    }

    /**
     * What "unsettled" means lives in UnsettledTransactions — this repository
     * and TransactionsRepository each used to spell it out for themselves.
     */
    private const UNSETTLED = UnsettledTransactions::UNSETTLED;

    /**
     * Every member with unsettled activity inside the run's window — the
     * participants of the run, and nothing more (#161, ruling #141).
     *
     * The window says *which* run this is; it does not bound what the run
     * collects. Once a member participates, the whole of their unsettled
     * position is swept and tested — see calculateUnsettledPositions().
     *
     * @return list<string>
     */
    public function findParticipantMemberIds(?string $fromDate = null, ?string $toDate = null, ?string $memberId = null): array
    {
        // No members/products join here, so the search clause is not available.
        [$where, $params] = UnsettledTransactions::buildUnsettledWhere(
            array_filter([
                'date_from' => $fromDate,
                'date_to'   => $toDate,
                'member_id' => $memberId,
            ]),
            supportsSearch: false,
        );

        $whereClause = 'WHERE ' . implode(' AND ', $where);
        $stmt = $this->db->prepare("SELECT DISTINCT t.member_id FROM transactions t {$whereClause}");
        $stmt->execute($params);

        return array_map(static fn(array $row): string => $row['member_id'], $stmt->fetchAll());
    }

    /**
     * The signed total each member currently owes (positive) or is owed
     * (negative), over *all* unsettled transactions regardless of when they
     * occurred (#161 §1, ruling #141).
     *
     * Date-bounding this sum is the bug the issue describes: a €20 credit in
     * January and a €5 drink in February netted to +5 for a February run,
     * debiting money the member does not owe and stranding the credit.
     *
     * @param list<string> $memberIds Restrict to these members; empty means all.
     * @return array<string, int>
     */
    public function calculateUnsettledPositions(array $memberIds = []): array
    {
        $where = [self::UNSETTLED];
        $params = [];

        if (!empty($memberIds)) {
            [$placeholders, $inParams] = SafeQuery::inClause(array_values($memberIds), 'string');
            $where[] = "t.member_id IN ({$placeholders})";
            $params = $inParams;
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);
        $stmt = $this->db->prepare("SELECT t.member_id, SUM(t.amount_cents) as total FROM transactions t {$whereClause} GROUP BY t.member_id");
        $stmt->execute($params);

        $positions = [];
        foreach ($stmt->fetchAll() as $row) {
            $positions[$row['member_id']] = (int) $row['total'];
        }
        return $positions;
    }

    /**
     * Members whose total unsettled position is negative — the club owes
     * them money (#161 work item 3). Same UNSETTLED predicate as
     * calculateUnsettledPositions(), summed and filtered in SQL so sorting
     * and the member join stay the database's job.
     *
     * Soft-deleted members are excluded: nothing actionable comes from
     * surfacing a credit owed to a member record that no longer exists.
     *
     * @return list<array{member_id: string, first_name: string, last_name: string, balance_cents: int}>
     */
    public function findMembersInCredit(): array
    {
        $stmt = $this->db->query(
            'SELECT t.member_id, m.first_name, m.last_name, SUM(t.amount_cents) as balance_cents
             FROM transactions t
             JOIN members m ON m.id = t.member_id
             WHERE ' . self::UNSETTLED . ' AND m.deleted_at IS NULL
             GROUP BY t.member_id, m.first_name, m.last_name
             HAVING SUM(t.amount_cents) < 0
             ORDER BY balance_cents ASC'
        );

        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['balance_cents'] = (int) $row['balance_cents'];
        }
        return $rows;
    }

    /**
     * Members the next run cannot collect from because they have no usable
     * mandate (#258) — the third standing exclusion, beside credit and holds.
     *
     * The three buckets must stay mutually exclusive, or the surface puts
     * contradictory remedies in front of the treasurer for one member. So this
     * repeats the precedence `previewSettlement()` applies (ruling #148 §4):
     * credit is tested first and wins, a hold outranks a missing mandate, and
     * only what is left lands here. Expressed as SQL that means a non-negative
     * position (negative is credit) and `collection_hold = 0`.
     *
     * "No usable mandate" is the absence of an active `mandates` row. Since
     * #164 the IBAN and the reference live there together and arrive as a
     * pair, so `hasActiveMandate()` inverted is simply "nothing joined":
     * `active_member_id` is the column that marks the one live mandate, and it
     * is nulled when a mandate is revoked or replaced.
     *
     * Participants only — a member with no open position is not something the
     * next run would leave behind, and listing every mandate-less member at a
     * club that collects mandates late produces a list long enough to ignore.
     *
     * @return list<array{member_id: string, first_name: string, last_name: string, balance_cents: int}>
     */
    public function findMembersWithoutUsableMandate(): array
    {
        $stmt = $this->db->query(
            'SELECT t.member_id, m.first_name, m.last_name,
                    SUM(t.amount_cents) as balance_cents
             FROM transactions t
             JOIN members m ON m.id = t.member_id
             LEFT JOIN mandates md ON md.active_member_id = m.id
             WHERE ' . self::UNSETTLED . ' AND m.deleted_at IS NULL
               AND m.collection_hold = 0
               AND md.active_member_id IS NULL
             GROUP BY t.member_id, m.first_name, m.last_name
             HAVING SUM(t.amount_cents) >= 0
             ORDER BY balance_cents DESC'
        );

        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['balance_cents'] = (int) $row['balance_cents'];
        }
        return $rows;
    }

    public function create(array $data): array
    {
        $id = $data['id'] ?? Uuid::v4();
        $now = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            'INSERT INTO settlements (id, method, settlement_date, execution_date, period_start, period_end, sepa_message_id, total_amount_cents, member_count, is_cancelled, notes, created_by_admin_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            // A settlement is a direct debit unless the caller says otherwise —
            // the same default the column carries (#163).
            $data['method'] ?? SettlementMethod::DIRECT_DEBIT->value,
            $data['settlement_date'],
            $data['execution_date'],
            $data['period_start'] ?? null,
            $data['period_end'] ?? null,
            $data['sepa_message_id'] ?? null,
            (int) $data['total_amount_cents'],
            (int) $data['member_count'],
            0,
            $data['notes'] ?? null,
            $data['created_by_admin_id'] ?? null,
            $now,
            $now,
        ]);

        $this->logger->info('Settlement created', ['id' => $id]);
        return $this->findById($id);
    }

    public function createItem(array $data): void
    {
        // active_transaction_id mirrors transaction_id: a freshly created
        // settlement's claim on the transaction is live (#163). It is the
        // column later cleared/repointed by correction handling, since
        // transaction_id itself is no longer unique.
        $stmt = $this->db->prepare(
            'INSERT INTO settlement_items (settlement_id, transaction_id, active_transaction_id, member_id, amount_cents, end_to_end_id) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['settlement_id'],
            $data['transaction_id'],
            $data['transaction_id'],
            $data['member_id'],
            (int) $data['amount_cents'],
            $data['end_to_end_id'] ?? null,
        ]);
    }

    /**
     * Record the EndToEndId each collection line was sent under (#150).
     *
     * One line covers all of a member's items in the run, so every one of that
     * member's rows carries the same value — an `EREF+` quoted back on a return
     * then resolves to exactly the set of items that collection was for.
     *
     * Members the export left out (no mandate, or in credit under ruling #141)
     * are simply absent from the map: nothing was sent for them, so there is
     * nothing for a bank to return and nothing to write down.
     *
     * The identifier is derived, not allocated, so re-exporting rewrites each
     * row with the value it already held. That is the point — the identifier
     * already at the bank must survive a second export.
     *
     * @param array<string, string> $endToEndIdsByMemberId member id => identifier
     */
    public function storeEndToEndIds(string $settlementId, array $endToEndIdsByMemberId): void
    {
        if ($endToEndIdsByMemberId === []) {
            return;
        }

        $stmt = $this->db->prepare(
            'UPDATE settlement_items SET end_to_end_id = ? WHERE settlement_id = ? AND member_id = ?'
        );
        foreach ($endToEndIdsByMemberId as $memberId => $endToEndId) {
            $stmt->execute([$endToEndId, $settlementId, $memberId]);
        }

        $this->logger->info('Settlement collection identifiers recorded', [
            'settlement_id' => $settlementId,
            'collections' => count($endToEndIdsByMemberId),
        ]);
    }

    /**
     * Release a settlement's claim on its transactions without erasing what it
     * contained (ruling #142 §3).
     *
     * This used to `DELETE` the items, which is why a cancelled settlement's
     * CSV export came back empty and why the `is_cancelled` guards elsewhere
     * were dead code. Nulling `active_transaction_id` instead frees the
     * transactions — many NULLs coexist in a UNIQUE column, which is how a
     * transaction becomes settleable again — while the rows survive as history.
     *
     * The caller wraps both writes in a transaction; on its own this method is
     * not atomic (#86).
     */
    public function cancelSettlement(string $id, string $adminUserId): bool
    {
        $now = date('Y-m-d H:i:s');

        $this->db->prepare('UPDATE settlement_items SET active_transaction_id = NULL WHERE settlement_id = ?')
            ->execute([$id]);

        $stmt = $this->db->prepare('UPDATE settlements SET is_cancelled = 1, cancelled_at = ?, cancelled_by_admin_id = ?, updated_at = ? WHERE id = ?');
        $result = $stmt->execute([$now, $adminUserId, $now, $id]);

        $this->logger->info('Settlement cancelled', ['id' => $id]);
        return $result;
    }

    /**
     * Release one settlement's claim on *some* of its members' transactions
     * (ruling #148 §1) — the per-member half of what cancelSettlement() does
     * to the whole run.
     *
     * Same mechanism, deliberately: nulling `active_transaction_id` frees the
     * transactions to be collected again while the rows survive, so the
     * settlement still exports a CSV saying what it originally contained.
     * Members not named here keep their claims and stay settled.
     *
     * The caller wraps this and the reversal rows in one DB transaction; on
     * its own it is not atomic (ruling #148 §8).
     *
     * @param list<string> $memberIds
     */
    public function releaseMemberClaims(string $settlementId, array $memberIds): void
    {
        if ($memberIds === []) {
            return;
        }

        [$placeholders, $params] = SafeQuery::inClause(array_values($memberIds), 'string');
        $stmt = $this->db->prepare(
            "UPDATE settlement_items SET active_transaction_id = NULL
              WHERE settlement_id = ? AND member_id IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$settlementId], $params));

        $this->logger->info('Settlement claims released for members', [
            'settlement_id' => $settlementId,
            'members' => count($memberIds),
        ]);
    }

    /**
     * What each named member's items in this settlement add up to — the amount
     * the reversal records as having come back.
     *
     * Derived from the items rather than taken from the caller: the reversal
     * must say what was actually collected from that member, and a freely typed
     * amount is exactly how that stops being true.
     *
     * @param list<string> $memberIds Restrict to these members; empty means all.
     * @return array<string, int>
     */
    public function sumItemAmountsByMember(string $settlementId, array $memberIds = []): array
    {
        $sql = 'SELECT member_id, SUM(amount_cents) AS total FROM settlement_items WHERE settlement_id = ?';
        $params = [$settlementId];

        if ($memberIds !== []) {
            [$placeholders, $inParams] = SafeQuery::inClause(array_values($memberIds), 'string');
            $sql .= " AND member_id IN ({$placeholders})";
            $params = array_merge($params, $inParams);
        }

        $stmt = $this->db->prepare($sql . ' GROUP BY member_id');
        $stmt->execute($params);

        $totals = [];
        foreach ($stmt->fetchAll() as $row) {
            $totals[$row['member_id']] = (int) $row['total'];
        }
        return $totals;
    }

    /**
     * Every member this settlement covers, whether or not their claim is still
     * live. Reversed members stay in the list — their rows were retained.
     *
     * @return list<string>
     */
    public function findSettledMemberIds(string $settlementId): array
    {
        $stmt = $this->db->prepare(
            'SELECT DISTINCT member_id FROM settlement_items WHERE settlement_id = ?'
        );
        $stmt->execute([$settlementId]);

        return array_map(static fn(array $row): string => $row['member_id'], $stmt->fetchAll());
    }

    /**
     * The collections a bank reference points at (ADR-0032 §8, epic #433).
     *
     * A treasurer holding a return booking knows one thing for certain: the
     * reference the bank quoted. German banks echo our own `EndToEndId` back as
     * `EREF+`, and quote the mandate as `MREF+` — so both columns are searched,
     * and nothing else is. Matching member names or amounts would turn a lookup
     * into the free-hand picker §8 exists to forbid, and picking the wrong
     * member reverses the wrong person's debt irreversibly.
     *
     * One row per member per settlement, because that is the granularity a
     * reversal is recorded at: the amount is the sum of that member's items in
     * that run, which is exactly what the bank collected under that identifier.
     *
     * Rows whose settlement cannot be reversed are deliberately *not* filtered
     * out here. A reference the treasurer genuinely read off a statement must
     * never come back as "no match" — the caller shows the row with the gate's
     * own refusal instead.
     *
     * @param string $needle Already normalised (trimmed, lower-cased, `E2E-`
     *        stripped); this method only escapes it for LIKE.
     * @return list<array<string, mixed>>
     */
    public function findCollectionsByReference(string $needle, int $limit = 25): array
    {
        // `\`, `%` and `_` are LIKE metacharacters; a reference containing one
        // must match itself rather than act as a wildcard.
        $escaped = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle) . '%';

        // Two named parameters holding the same value: prepares are not
        // emulated on this connection, so a placeholder cannot be reused.
        $stmt = $this->db->prepare(
            "SELECT si.settlement_id,
                    si.member_id,
                    SUM(si.amount_cents) AS amount_cents,
                    MAX(si.end_to_end_id) AS end_to_end_id,
                    MAX(m.first_name) AS first_name,
                    MAX(m.last_name) AS last_name,
                    (SELECT md.reference
                       FROM mandates md
                      WHERE md.member_id = si.member_id
                      ORDER BY md.active_member_id IS NULL, md.created_at DESC
                      LIMIT 1) AS mandate_reference
               FROM settlement_items si
               JOIN settlements s ON s.id = si.settlement_id
               JOIN members m ON m.id = si.member_id
              WHERE (si.end_to_end_id IS NOT NULL AND LOWER(si.end_to_end_id) LIKE :eref ESCAPE '\\\\')
                 OR EXISTS (SELECT 1
                              FROM mandates mref
                             WHERE mref.member_id = si.member_id
                               AND LOWER(mref.reference) LIKE :mref ESCAPE '\\\\')
              GROUP BY si.settlement_id, si.member_id
              ORDER BY MAX(s.execution_date) DESC, MAX(s.created_at) DESC, MAX(m.last_name) ASC
              LIMIT " . max(1, $limit)
        );
        $stmt->execute(['eref' => $escaped, 'mref' => $escaped]);

        return $stmt->fetchAll();
    }

    public function markExported(string $id): bool
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare('UPDATE settlements SET exported_at = ?, updated_at = ? WHERE id = ?');
        return $stmt->execute([$now, $now, $id]);
    }

    /** The moment the exported file reached the bank — see SettlementsService::markSubmitted(). */
    public function markSubmitted(string $id, string $adminUserId): bool
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'UPDATE settlements SET submitted_at = ?, submitted_by_admin_id = ?, updated_at = ? WHERE id = ?'
        );
        $result = $stmt->execute([$now, $adminUserId, $now, $id]);

        $this->logger->info('Settlement submitted to the bank', ['id' => $id]);
        return $result;
    }

    public function listPaginated(int $limit, int $offset, ?string $status = null, string $sortKey = 'created_at', string $sortOrder = 'desc', ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $where = [];
        $params = [];

        // The ladder lives in SettlementStatusFilter, which walks it in the
        // same order SettlementStatus::derive() does — the filter and the
        // badge cannot disagree about a row (#377). This used to understand
        // two values, `active` and `cancelled`, so the four rungs between them
        // were unaskable.
        $statusFilter = SettlementStatusFilter::sql($status);
        if ($statusFilter !== null) {
            $where[] = $statusFilter;
        }

        if ($dateFrom) {
            $where[] = 's.created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo) {
            $where[] = 's.created_at <= ?';
            $params[] = UnsettledTransactions::endOfDay($dateTo);
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $dir = SafeQuery::direction($sortOrder);
        // Both keys the settlements table offers as sortable headers. Until
        // #120 this was a ternary whose two branches were the same column, so
        // clicking "Created by" re-sorted by date and the arrow moved onto a
        // heading the order had nothing to do with.
        // Both keys need a tiebreaker. `display_name` repeats for every
        // settlement one admin created, and two settlements can share a
        // `created_at` second — and row order *within* a tie is undefined, so
        // LIMIT/OFFSET over it can hand the same row to two pages and never
        // hand over another at all. The id is unique, so it makes paging
        // deterministic; for the name sort, date orders the group underneath it.
        $orderBy = $sortKey === 'created_by'
            ? "a.display_name {$dir}, s.created_at DESC, s.id ASC"
            : "s.created_at {$dir}, s.id ASC";

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM settlements s {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $dataParams = array_merge($params, [$limit, $offset]);
        $stmt = $this->db->prepare(
            "SELECT s.*, a.display_name as admin_display_name, " . self::STATUS_COUNTS . ",
                (SELECT COUNT(*) FROM settlement_items si WHERE si.settlement_id = s.id) as transaction_count,
                (SELECT MIN(t.occurred_at) FROM settlement_items si JOIN transactions t ON si.transaction_id = t.id WHERE si.settlement_id = s.id) as transaction_date_min,
                (SELECT MAX(t.occurred_at) FROM settlement_items si JOIN transactions t ON si.transaction_id = t.id WHERE si.settlement_id = s.id) as transaction_date_max
             FROM settlements s LEFT JOIN admin_users a ON s.created_by_admin_id = a.id {$whereClause} ORDER BY {$orderBy} LIMIT ? OFFSET ?"
        );
        $stmt->execute($dataParams);

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    public function getNextSepaMessageId(): string
    {
        $uuid = Uuid::v4();
        return 'SEPA-' . substr(str_replace('-', '', $uuid), 0, 12);
    }

    public function hasConflicts(array $transactionIds): array
    {
        if (empty($transactionIds)) return [];

        [$placeholders, $params] = SafeQuery::inClause($transactionIds, 'string');
        $stmt = $this->db->prepare(
            "SELECT si.transaction_id, s.settlement_date FROM settlement_items si JOIN settlements s ON si.settlement_id = s.id WHERE si.active_transaction_id IN ({$placeholders})"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function countPending(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM settlements WHERE is_cancelled = 0 AND exported_at IS NULL')->fetchColumn();
    }
}
