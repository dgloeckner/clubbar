<?php

declare(strict_types=1);

namespace App\Shared\Repository;

/**
 * What "unsettled" means, in one place.
 *
 * The predicate and the ~40 lines of filter SQL around it were copied across
 * TransactionsRepository and SettlementsRepository — the subquery six times,
 * the `date_to . ' 23:59:59'` idiom five (issue #119). Each copy was a chance
 * for the definition of settled money to drift between the journal, the
 * settlement preview and the run itself.
 *
 * Every query here assumes the transactions table is aliased `t`; queries that
 * pass a search term must also join `members m` and `products p`.
 */
final class UnsettledTransactions
{
    /**
     * A transaction belongs to no live settlement.
     *
     * `active_transaction_id` is the live claim and nothing else: a settlement
     * that was cancelled keeps its items but releases them by nulling this
     * column (ruling #142 §3), and many NULLs coexist in the UNIQUE index.
     * That makes this one column both the definition of "settled" and the
     * database's guarantee that two runs cannot collect the same drink.
     */
    public const UNSETTLED =
        'NOT EXISTS (SELECT 1 FROM settlement_items si WHERE si.active_transaction_id = t.id)';

    public const SETTLED =
        'EXISTS (SELECT 1 FROM settlement_items si WHERE si.active_transaction_id = t.id)';

    /**
     * Widen a date to the last instant of that day.
     *
     * `occurred_at` is a datetime, so `<= '2026-01-31'` silently excludes
     * everything that happened on the 31st. A caller that already passed a
     * timestamp is left alone — appending to it produced an invalid datetime
     * that matched nothing.
     */
    public static function endOfDay(string $date): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
            ? $date . ' 23:59:59'
            : $date;
    }

    /**
     * WHERE fragments for "unsettled transactions matching these filters".
     *
     * Recognised keys: date_from, date_to, search, member_id. Anything else is
     * ignored — filters arrive straight from query strings.
     *
     * @param array<string, mixed> $filters
     * @param bool $supportsSearch False for queries that do not join members
     *        and products; the search term is then dropped rather than
     *        producing SQL that references tables it never joined.
     * @return array{0: list<string>, 1: list<mixed>}
     */
    public static function buildUnsettledWhere(array $filters, bool $supportsSearch = true): array
    {
        $where = [self::UNSETTLED];
        $params = [];

        if (isset($filters['date_from'])) {
            $where[] = 't.occurred_at >= ?';
            $params[] = $filters['date_from'];
        }
        if (isset($filters['date_to'])) {
            $where[] = 't.occurred_at <= ?';
            $params[] = self::endOfDay((string) $filters['date_to']);
        }
        if ($supportsSearch && isset($filters['search'])) {
            $escaped = SafeQuery::escapeLike((string) $filters['search']);
            $where[] = "(CONCAT(m.first_name, ' ', m.last_name) LIKE ? OR t.notes LIKE ? OR LOWER(p.names) LIKE ?)";
            $params[] = "%{$escaped}%";
            $params[] = "%{$escaped}%";
            $params[] = '%' . mb_strtolower($escaped) . '%';
        }
        if (isset($filters['member_id'])) {
            $where[] = 't.member_id = ?';
            $params[] = $filters['member_id'];
        }

        return [$where, $params];
    }
}
