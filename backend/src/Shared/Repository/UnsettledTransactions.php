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
     * The same question, dated: was this transaction unsettled at `$t`?
     *
     * {@see UNSETTLED} keys on `active_transaction_id`, which is the *live*
     * claim and has no time dimension at all — it cannot answer "was this
     * unsettled on 1 August?". The Deckelauszug needs exactly that, because
     * ADR-0039 decision 2 fixes a statement's value at its period boundary
     * rather than at whichever delivery attempt happens to succeed: a greylisted
     * mail that leaves three days late must still say what it said on the 1st.
     *
     * **This is a second definition of settled money in the class whose whole
     * point is that there is one** (#119), and that cost is accepted rather than
     * hidden. Two things keep it honest: it lives here, beside the live
     * predicate, because #119's failure was a duplicated *answer* copy-pasted
     * into six queries and not a second named *question*; and
     * `UnsettledAsOfTest` pins the two to return the same transactions at
     * `$t = now`, so a change to one that does not fit the other fails.
     *
     * The history it reads exists because of #142, which split `transaction_id`
     * (every settlement that ever claimed this transaction, cancelled ones
     * included) from `active_transaction_id` (the one that claims it now). With
     * `settlements.created_at` and `cancelled_at` dating each of those claims,
     * the predicate is:
     *
     * > unsettled at `$t` ⟺ it had occurred by `$t`, and no settlement that
     * > existed at `$t` and was still live at `$t` had claimed it
     *
     * A claim is released two ways, and **both** have to be dated or this
     * disagrees with the live predicate:
     *
     * | Release | Live column | Dated by |
     * |---|---|---|
     * | The whole settlement is cancelled (#142 §3) | `active_transaction_id` nulled for every item | `settlements.cancelled_at` |
     * | One member's collection is reversed (#148 §1) | `active_transaction_id` nulled for that member's items only, settlement stays live | `settlement_reversals.created_at` |
     *
     * The second is the one that is easy to miss: `SettlementsRepository::releaseMemberClaims()`
     * frees a member's transactions without touching the settlement, so a
     * predicate that only knew about cancellation would report a reversed
     * member as still settled — and understate the Deckel of exactly the
     * member whose money came back. `settlement_reversals` is unique per
     * (settlement, member) and carries `created_at`, so it dates that release
     * precisely.
     *
     * `is_cancelled` is consulted alongside `cancelled_at` for the degenerate
     * row that is marked cancelled without a timestamp: treating it as live
     * would make this predicate disagree with the live one at `now`, which is
     * the one thing it may not do.
     *
     * Assumes the transactions table is aliased `t`, like everything else here.
     *
     * @return array{0:string,1:list<string>} SQL fragment and its bound values,
     *         in order — the caller appends both to its own WHERE.
     */
    public static function unsettledAsOf(\DateTimeImmutable $t): array
    {
        $at = $t->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $sql = 't.occurred_at <= ?'
            . ' AND NOT EXISTS ('
            . 'SELECT 1 FROM settlement_items si'
            . ' JOIN settlements s ON s.id = si.settlement_id'
            . ' WHERE si.transaction_id = t.id'
            . ' AND s.created_at <= ?'
            . ' AND (s.cancelled_at > ? OR (s.cancelled_at IS NULL AND s.is_cancelled = 0))'
            . ' AND NOT EXISTS ('
            . 'SELECT 1 FROM settlement_reversals sr'
            . ' WHERE sr.settlement_id = si.settlement_id'
            . ' AND sr.member_id = si.member_id'
            . ' AND sr.created_at <= ?'
            . ')'
            . ')';

        return [$sql, [$at, $at, $at, $at]];
    }

    /**
     * A member's Deckel as it stood at `$t` — the number a Deckelauszug states.
     *
     * The same expression `MembersRepository::BALANCE_EXPRESSION` evaluates,
     * with {@see unsettledAsOf()} in place of {@see UNSETTLED}, so the dated
     * figure and the live one are the same sum over a differently dated set
     * rather than two independently written aggregates.
     *
     * Expects the members table to be aliased `m`.
     *
     * @return array{0:string,1:list<string>} Scalar subquery and its bound values.
     */
    public static function balanceAsOf(\DateTimeImmutable $t): array
    {
        [$predicate, $params] = self::unsettledAsOf($t);

        return [
            '(SELECT COALESCE(SUM(t.amount_cents), 0) FROM transactions t'
                . ' WHERE t.member_id = m.id AND ' . $predicate . ')',
            $params,
        ];
    }

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
