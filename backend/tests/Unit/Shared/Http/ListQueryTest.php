<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Http;

use App\Shared\Exceptions\InvalidQueryParameterException;
use App\Shared\Http\ListQuery;
use PHPUnit\Framework\TestCase;

class ListQueryTest extends TestCase
{
    // ── Defaults ──────────────────────────────────────────────────────

    public function test_empty_params_yield_the_configured_defaults(): void
    {
        $query = ListQuery::fromParams([]);

        $this->assertSame(1, $query->page);
        $this->assertSame(ListQuery::DEFAULT_PER_PAGE, $query->perPage);
        $this->assertSame(0, $query->offset);
        $this->assertSame('created_at', $query->sortKey);
        $this->assertSame('desc', $query->sortOrder);
        $this->assertNull($query->search);
    }

    public function test_callers_can_override_the_defaults(): void
    {
        $query = ListQuery::fromParams([], defaultPerPage: 20, defaultSortKey: 'name', defaultSortOrder: 'asc');

        $this->assertSame(20, $query->perPage);
        $this->assertSame('name', $query->sortKey);
        $this->assertSame('asc', $query->sortOrder);
    }

    // ── Page / per_page dialect ───────────────────────────────────────

    public function test_page_and_per_page_are_translated_into_an_offset(): void
    {
        $query = ListQuery::fromParams(['page' => '3', 'per_page' => '25']);

        $this->assertSame(3, $query->page);
        $this->assertSame(25, $query->perPage);
        $this->assertSame(50, $query->offset);
    }

    public function test_limit_is_accepted_as_an_alias_of_per_page(): void
    {
        $query = ListQuery::fromParams(['limit' => '10']);

        $this->assertSame(10, $query->perPage);
    }

    public function test_per_page_wins_over_limit_when_both_are_given(): void
    {
        $query = ListQuery::fromParams(['per_page' => '10', 'limit' => '90']);

        $this->assertSame(10, $query->perPage);
    }

    public function test_an_explicit_offset_overrides_the_page_derived_one(): void
    {
        $query = ListQuery::fromParams(['per_page' => '10', 'offset' => '35']);

        $this->assertSame(35, $query->offset);
    }

    /**
     * The page number reported back to the client must describe the slice the
     * client actually receives, or the pagination footer lies.
     */
    public function test_an_explicit_offset_is_reflected_in_the_reported_page(): void
    {
        $query = ListQuery::fromParams(['per_page' => '10', 'offset' => '30']);

        $this->assertSame(4, $query->page);
    }

    // ── The cap ───────────────────────────────────────────────────────

    public function test_per_page_above_the_cap_is_rejected(): void
    {
        $this->expectException(InvalidQueryParameterException::class);

        ListQuery::fromParams(['per_page' => '500']);
    }

    public function test_the_rejection_names_the_parameter_the_caller_used(): void
    {
        try {
            ListQuery::fromParams(['limit' => '500']);
            $this->fail('expected InvalidQueryParameterException');
        } catch (InvalidQueryParameterException $e) {
            $this->assertSame(400, $e->getHttpStatusCode());
            $this->assertSame('invalid_request', $e->getErrorCode());
            $this->assertArrayHasKey('limit', $e->getMessages());
            $this->assertStringContainsString('100', $e->getMessages()['limit'][0]);
        }
    }

    public function test_the_cap_is_configurable(): void
    {
        $query = ListQuery::fromParams(['per_page' => '250'], maxPerPage: 500);

        $this->assertSame(250, $query->perPage);
    }

    public function test_per_page_exactly_at_the_cap_is_accepted(): void
    {
        $this->assertSame(100, ListQuery::fromParams(['per_page' => '100'])->perPage);
    }

    // ── Rejected input ────────────────────────────────────────────────

    public function test_non_numeric_per_page_is_rejected(): void
    {
        $this->expectException(InvalidQueryParameterException::class);

        ListQuery::fromParams(['per_page' => 'many']);
    }

    public function test_fractional_per_page_is_rejected(): void
    {
        $this->expectException(InvalidQueryParameterException::class);

        ListQuery::fromParams(['per_page' => '10.5']);
    }

    public function test_zero_per_page_is_rejected(): void
    {
        $this->expectException(InvalidQueryParameterException::class);

        ListQuery::fromParams(['per_page' => '0']);
    }

    public function test_negative_per_page_is_rejected(): void
    {
        $this->expectException(InvalidQueryParameterException::class);

        ListQuery::fromParams(['per_page' => '-5']);
    }

    public function test_page_below_one_is_rejected(): void
    {
        $this->expectException(InvalidQueryParameterException::class);

        ListQuery::fromParams(['page' => '0']);
    }

    public function test_non_numeric_page_is_rejected(): void
    {
        $this->expectException(InvalidQueryParameterException::class);

        ListQuery::fromParams(['page' => 'first']);
    }

    public function test_negative_offset_is_rejected(): void
    {
        $this->expectException(InvalidQueryParameterException::class);

        ListQuery::fromParams(['offset' => '-1']);
    }

    public function test_an_empty_per_page_string_falls_back_to_the_default(): void
    {
        $this->assertSame(
            ListQuery::DEFAULT_PER_PAGE,
            ListQuery::fromParams(['per_page' => ''])->perPage
        );
    }

    // ── Sorting ───────────────────────────────────────────────────────

    public function test_sort_and_order_are_accepted(): void
    {
        $query = ListQuery::fromParams(['sort' => 'amount', 'order' => 'asc']);

        $this->assertSame('amount', $query->sortKey);
        $this->assertSame('asc', $query->sortOrder);
    }

    public function test_sort_key_and_sort_order_are_accepted_as_aliases(): void
    {
        $query = ListQuery::fromParams(['sort_key' => 'amount', 'sort_order' => 'asc']);

        $this->assertSame('amount', $query->sortKey);
        $this->assertSame('asc', $query->sortOrder);
    }

    public function test_sort_wins_over_sort_key_when_both_are_given(): void
    {
        $query = ListQuery::fromParams(['sort' => 'amount', 'sort_key' => 'name']);

        $this->assertSame('amount', $query->sortKey);
    }

    public function test_sort_order_is_normalised_to_lowercase(): void
    {
        $this->assertSame('asc', ListQuery::fromParams(['order' => 'ASC'])->sortOrder);
    }

    /**
     * An unrecognised direction must not reach the query builder; it falls
     * back to the endpoint's default rather than being rejected, because a
     * stray `order=` is not worth a 400 to the caller.
     */
    public function test_an_unknown_sort_order_falls_back_to_the_default(): void
    {
        $this->assertSame('desc', ListQuery::fromParams(['order' => 'sideways'])->sortOrder);
    }

    // ── The combined sort_by dialect ──────────────────────────────────

    /**
     * The admin frontend has always sent `sort_by=name_asc`. Only Products
     * understood it, so sorting the member and settlement lists silently did
     * nothing (issue #119) — the parameter went out and was dropped on the
     * floor. Every endpoint speaks all three spellings now.
     */
    public function test_the_combined_sort_by_dialect_is_split_into_key_and_order(): void
    {
        $query = ListQuery::fromParams(['sort_by' => 'name_asc']);

        $this->assertSame('name', $query->sortKey);
        $this->assertSame('asc', $query->sortOrder);
    }

    public function test_a_multi_word_sort_by_keeps_its_underscores(): void
    {
        $query = ListQuery::fromParams(['sort_by' => 'created_at_desc']);

        $this->assertSame('created_at', $query->sortKey);
        $this->assertSame('desc', $query->sortOrder);
    }

    public function test_a_sort_by_without_a_direction_suffix_is_taken_as_the_key(): void
    {
        $query = ListQuery::fromParams(['sort_by' => 'name'], defaultSortOrder: 'desc');

        $this->assertSame('name', $query->sortKey);
        $this->assertSame('desc', $query->sortOrder);
    }

    public function test_an_explicit_sort_wins_over_the_combined_dialect(): void
    {
        $query = ListQuery::fromParams(['sort_by' => 'name_asc', 'sort' => 'amount', 'order' => 'desc']);

        $this->assertSame('amount', $query->sortKey);
        $this->assertSame('desc', $query->sortOrder);
    }

    // ── Search ────────────────────────────────────────────────────────

    public function test_search_is_carried_through(): void
    {
        $this->assertSame('meier', ListQuery::fromParams(['search' => 'meier'])->search);
    }

    public function test_a_blank_search_is_treated_as_absent(): void
    {
        $this->assertNull(ListQuery::fromParams(['search' => '   '])->search);
    }
}
