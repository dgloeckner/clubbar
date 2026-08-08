<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Repository;

use App\Shared\Repository\SafeQuery;
use App\Shared\Repository\UnsettledTransactions;
use PHPUnit\Framework\TestCase;

class UnsettledTransactionsTest extends TestCase
{
    // ── The predicate ─────────────────────────────────────────────────

    public function test_the_unsettled_predicate_keys_off_the_live_claim_column(): void
    {
        $this->assertSame(
            'NOT EXISTS (SELECT 1 FROM settlement_items si WHERE si.active_transaction_id = t.id)',
            UnsettledTransactions::UNSETTLED
        );
    }

    public function test_the_settled_predicate_is_the_exact_negation(): void
    {
        $this->assertSame(
            str_replace('NOT EXISTS', 'EXISTS', UnsettledTransactions::UNSETTLED),
            UnsettledTransactions::SETTLED
        );
    }

    // ── End of day ────────────────────────────────────────────────────

    public function test_a_bare_date_is_widened_to_the_end_of_that_day(): void
    {
        $this->assertSame('2026-01-31 23:59:59', UnsettledTransactions::endOfDay('2026-01-31'));
    }

    /**
     * `date_to=2026-01-31 12:00:00` used to become
     * `2026-01-31 12:00:00 23:59:59`, which MySQL reads as an invalid datetime
     * and silently treats as matching nothing.
     */
    public function test_a_timestamp_is_left_alone(): void
    {
        $this->assertSame('2026-01-31 12:00:00', UnsettledTransactions::endOfDay('2026-01-31 12:00:00'));
    }

    // ── The filter builder ────────────────────────────────────────────

    public function test_the_unsettled_predicate_always_leads_the_where_list(): void
    {
        [$where, $params] = UnsettledTransactions::buildUnsettledWhere([]);

        $this->assertSame([UnsettledTransactions::UNSETTLED], $where);
        $this->assertSame([], $params);
    }

    public function test_date_from_is_bound_as_a_lower_bound(): void
    {
        [$where, $params] = UnsettledTransactions::buildUnsettledWhere(['date_from' => '2026-01-01']);

        $this->assertContains('t.occurred_at >= ?', $where);
        $this->assertSame(['2026-01-01'], $params);
    }

    public function test_date_to_is_bound_to_the_end_of_the_day(): void
    {
        [$where, $params] = UnsettledTransactions::buildUnsettledWhere(['date_to' => '2026-01-31']);

        $this->assertContains('t.occurred_at <= ?', $where);
        $this->assertSame(['2026-01-31 23:59:59'], $params);
    }

    public function test_member_id_is_bound(): void
    {
        [$where, $params] = UnsettledTransactions::buildUnsettledWhere(['member_id' => 'm-1']);

        $this->assertContains('t.member_id = ?', $where);
        $this->assertSame(['m-1'], $params);
    }

    public function test_search_spans_member_name_notes_and_product_names(): void
    {
        [$where, $params] = UnsettledTransactions::buildUnsettledWhere(['search' => 'Meier']);

        $this->assertContains(
            "(CONCAT(m.first_name, ' ', m.last_name) LIKE ? OR t.notes LIKE ? OR LOWER(p.names) LIKE ?)",
            $where
        );
        $this->assertSame(['%Meier%', '%Meier%', '%meier%'], $params);
    }

    public function test_search_escapes_like_wildcards(): void
    {
        [, $params] = UnsettledTransactions::buildUnsettledWhere(['search' => '100%']);

        // A bare % in the search term must not survive as a wildcard; the
        // escaping is SafeQuery's, so this asserts the delegation rather than
        // restating its output.
        $this->assertSame('%' . SafeQuery::escapeLike('100%') . '%', $params[0]);
        $this->assertStringContainsString('\\%', $params[0]);
    }

    public function test_placeholders_and_parameters_stay_in_step(): void
    {
        [$where, $params] = UnsettledTransactions::buildUnsettledWhere([
            'date_from' => '2026-01-01',
            'date_to'   => '2026-01-31',
            'search'    => 'bier',
            'member_id' => 'm-1',
        ]);

        $this->assertSame(
            substr_count(implode(' ', $where), '?'),
            count($params),
            'every placeholder must have exactly one bound parameter'
        );
        $this->assertSame(
            ['2026-01-01', '2026-01-31 23:59:59', '%bier%', '%bier%', '%bier%', 'm-1'],
            $params
        );
    }

    public function test_unknown_filter_keys_are_ignored(): void
    {
        [$where, $params] = UnsettledTransactions::buildUnsettledWhere(['drop_table' => 'members']);

        $this->assertSame([UnsettledTransactions::UNSETTLED], $where);
        $this->assertSame([], $params);
    }

    /**
     * The settlement participant query has no product or member join, so it
     * cannot carry the search clause — it opts out rather than producing SQL
     * that references tables it never joined.
     */
    public function test_the_search_clause_can_be_switched_off(): void
    {
        [$where, $params] = UnsettledTransactions::buildUnsettledWhere(
            ['search' => 'bier', 'member_id' => 'm-1'],
            supportsSearch: false
        );

        $this->assertSame([UnsettledTransactions::UNSETTLED, 't.member_id = ?'], $where);
        $this->assertSame(['m-1'], $params);
    }
}
