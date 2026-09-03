<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Reports\Repositories;

use App\Modules\Reports\Domain\ReportFilters;
use App\Modules\Reports\Repositories\ReportsRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\DatabaseTestCase;

/**
 * The reporting queries against a real database (#118).
 *
 * ReportsService used to build these inline from a raw PDO, so the grouping
 * dialect — the JSON extraction for a category name, the ISO week spelling,
 * the derived-table count — was only ever exercised by an end-to-end run.
 *
 * Every query here is date-bounded to March 2019, which nothing else in the
 * database occupies, so the assertions can be exact.
 */
class ReportsRepositoryTest extends DatabaseTestCase
{
    private const WINDOW_START = '2019-03-01';
    private const WINDOW_END = '2019-03-31';

    private ReportsRepository $repository;

    /** @var list<string> */
    private array $testMemberIds = [];
    /** @var list<string> */
    private array $testProductIds = [];
    /** @var list<string> */
    private array $testCategoryIds = [];
    /** @var list<string> */
    private array $testTransactionIds = [];
    /** @var list<string> */
    private array $testTerminalIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new ReportsRepository($this->db);
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData('transactions', $this->testTransactionIds);
        $this->cleanupTestData('products', $this->testProductIds);
        $this->cleanupTestData('categories', $this->testCategoryIds);
        $this->cleanupTestData('terminals', $this->testTerminalIds);
        $this->cleanupTestData('members', $this->testMemberIds);
        parent::tearDown();
    }

    private function window(?string $categoryIds = null, ?string $productIds = null, ?string $terminalId = null): ReportFilters
    {
        return ReportFilters::fromRequest(self::WINDOW_START, self::WINDOW_END, $categoryIds, $productIds, $terminalId);
    }

    // ── Summary ─────────────────────────────────────────────────────────────

    public function test_summary_totals_purchases_and_counts_distinct_members(): void
    {
        $anna = $this->createMember('Anna', 'Meier');
        $ben = $this->createMember('Ben', 'Schulz');
        $this->createTransaction($anna, 500, '2019-03-05 10:00:00');
        $this->createTransaction($anna, 300, '2019-03-06 10:00:00');
        $this->createTransaction($ben, 200, '2019-03-07 10:00:00');

        $summary = $this->repository->summary($this->window());

        $this->assertSame(1000, $summary['total_revenue_cents']);
        $this->assertSame(3, $summary['transaction_count']);
        $this->assertSame(2, $summary['unique_member_count']);
    }

    public function test_summary_ignores_transactions_that_are_not_purchases(): void
    {
        $member = $this->createMember();
        $this->createTransaction($member, 500, '2019-03-05 10:00:00');
        $this->createTransaction($member, -500, '2019-03-06 10:00:00', 'payout');

        $this->assertSame(500, $this->repository->summary($this->window())['total_revenue_cents']);
    }

    public function test_summary_of_an_empty_window_is_zero_rather_than_null(): void
    {
        $summary = $this->repository->summary(ReportFilters::fromRequest('2019-05-01', '2019-05-31'));

        $this->assertSame(0, $summary['total_revenue_cents']);
        $this->assertSame(0, $summary['transaction_count']);
        $this->assertSame(0, $summary['unique_member_count']);
    }

    public function test_summary_narrows_to_the_requested_categories(): void
    {
        $member = $this->createMember();
        $drinks = $this->createCategory('Getränke');
        $food = $this->createCategory('Essen');
        $beer = $this->createProduct($drinks, 'Bier', 350);
        $pizza = $this->createProduct($food, 'Pizza', 850);

        $this->createTransaction($member, 350, '2019-03-05 10:00:00', 'purchase', $beer);
        $this->createTransaction($member, 850, '2019-03-05 11:00:00', 'purchase', $pizza);

        $this->assertSame(350, $this->repository->summary($this->window($drinks))['total_revenue_cents']);
        $this->assertSame(1200, $this->repository->summary($this->window("{$drinks},{$food}"))['total_revenue_cents']);
    }

    public function test_summary_narrows_to_the_requested_products(): void
    {
        $member = $this->createMember();
        $category = $this->createCategory();
        $beer = $this->createProduct($category, 'Bier', 350);
        $wine = $this->createProduct($category, 'Wein', 500);

        $this->createTransaction($member, 350, '2019-03-05 10:00:00', 'purchase', $beer);
        $this->createTransaction($member, 500, '2019-03-05 11:00:00', 'purchase', $wine);

        $this->assertSame(500, $this->repository->summary($this->window(null, $wine))['total_revenue_cents']);
    }

    // ── Grouping ────────────────────────────────────────────────────────────

    /**
     * @return list<array{string, string}>
     */
    public static function groupingProvider(): array
    {
        return [
            'by category' => ['category', 'Getränke'],
            'by product' => ['product', 'Bier'],
            'by member' => ['member', 'Anna Meier'],
            'by day' => ['day', '2019-03-05'],
            'by week' => ['week', '2019-W10'],
            'by month' => ['month', '2019-03'],
            'by year' => ['year', '2019'],
        ];
    }

    #[DataProvider('groupingProvider')]
    public function test_every_offered_grouping_names_its_dimension(string $groupBy, string $expectedDimension): void
    {
        $member = $this->createMember('Anna', 'Meier');
        $category = $this->createCategory('Getränke');
        $product = $this->createProduct($category, 'Bier', 350);
        $this->createTransaction($member, 350, '2019-03-05 10:00:00', 'purchase', $product);

        $rows = $this->repository->fetchGrouped($this->window(), $groupBy, 'revenue', 25, 0);

        $this->assertCount(1, $rows);
        $this->assertSame($expectedDimension, $rows[0]['dimension']);
        $this->assertSame(350, $rows[0]['revenue_cents']);
        $this->assertSame(1, $rows[0]['count']);
    }

    /**
     * @return list<array{string, ?string}>
     */
    public static function dimensionIdProvider(): array
    {
        return [
            'by category' => ['category', 'category'],
            'by product' => ['product', 'product'],
            'by member' => ['member', 'member'],
            'by day' => ['day', null],
            'by week' => ['week', null],
            'by month' => ['month', null],
            'by year' => ['year', null],
        ];
    }

    /**
     * The entity groupings identify themselves; the date ones have no entity to
     * identify. The id is what lets the panel tell two nameless rows apart.
     */
    #[DataProvider('dimensionIdProvider')]
    public function test_a_grouping_reports_the_id_it_keys_on(string $groupBy, ?string $expected): void
    {
        $member = $this->createMember('Anna', 'Meier');
        $category = $this->createCategory('Getränke');
        $product = $this->createProduct($category, 'Bier', 350);
        $this->createTransaction($member, 350, '2019-03-05 10:00:00', 'purchase', $product);

        $rows = $this->repository->fetchGrouped($this->window(), $groupBy, 'revenue', 25, 0);

        $ids = ['category' => $category, 'product' => $product, 'member' => $member];
        $this->assertSame($expected === null ? null : $ids[$expected], $rows[0]['dimension_id']);
    }

    // ── Sales whose product no longer resolves (ADR-0033 §1) ────────────────

    /**
     * The bug this whole group exists for.
     *
     * A terminal may upload a sale naming a `product_id` the server has never
     * seen — the drink was poured against a cached catalogue, and ADR-0033 §1
     * stores the row rather than losing the record of a sale that happened.
     * Grouping on `p.id` then folded *every* such sale into a single NULL
     * group, whatever product it named: one fabricated row that summed
     * unrelated products and, being the sum of many, sorted to the top of the
     * chart. Keying on `t.product_id` keeps them apart.
     */
    public function test_sales_naming_different_missing_products_stay_different_rows(): void
    {
        $member = $this->createMember();
        $gone = $this->generateUuid();
        $alsoGone = $this->generateUuid();

        $this->createTransaction($member, 500, '2019-03-05 10:00:00', 'purchase', $gone);
        $this->createTransaction($member, 300, '2019-03-05 11:00:00', 'purchase', $alsoGone);

        $rows = $this->repository->fetchGrouped($this->window(), 'product', 'revenue', 25, 0);

        $this->assertCount(2, $rows);
        $this->assertSame([500, 300], array_column($rows, 'revenue_cents'));
        $this->assertSame([$gone, $alsoGone], array_column($rows, 'dimension_id'));
        $this->assertSame(2, $this->repository->countGroups($this->window(), 'product'));
    }

    /**
     * The other half of the same bug: the group used to be *named* — with the
     * English literal 'Unknown', which a German panel printed untranslated
     * between its own product names. There is no name to give, so the query
     * says so and leaves the labelling to the client.
     */
    public function test_a_sale_whose_product_is_missing_has_no_name(): void
    {
        $member = $this->createMember();
        $gone = $this->generateUuid();
        $this->createTransaction($member, 500, '2019-03-05 10:00:00', 'purchase', $gone);

        $rows = $this->repository->fetchGrouped($this->window(), 'product', 'revenue', 25, 0);

        $this->assertNull($rows[0]['dimension']);
        $this->assertSame($gone, $rows[0]['dimension_id']);
    }

    public function test_a_sale_carrying_no_product_at_all_has_neither_name_nor_id(): void
    {
        $member = $this->createMember();
        $this->createTransaction($member, 500, '2019-03-05 10:00:00');

        $rows = $this->repository->fetchGrouped($this->window(), 'product', 'revenue', 25, 0);

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['dimension']);
        $this->assertNull($rows[0]['dimension_id']);
    }

    /**
     * A category is reached *through* the product, so a missing product takes
     * the category with it. One nameless bucket is right here — unlike the
     * product grouping, there is no id left to tell the sales apart by.
     */
    public function test_a_missing_product_leaves_the_category_grouping_nameless(): void
    {
        $member = $this->createMember();
        $category = $this->createCategory('Getränke');
        $beer = $this->createProduct($category, 'Bier', 350);
        $this->createTransaction($member, 350, '2019-03-05 10:00:00', 'purchase', $beer);
        $this->createTransaction($member, 500, '2019-03-05 11:00:00', 'purchase', $this->generateUuid());

        $rows = $this->repository->fetchGrouped($this->window(), 'category', 'revenue', 25, 0);

        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]['dimension']);
        $this->assertNull($rows[0]['dimension_id']);
        $this->assertSame('Getränke', $rows[1]['dimension']);
        $this->assertSame($category, $rows[1]['dimension_id']);
    }

    /**
     * A deleted product is not a missing one: the delete is soft precisely so
     * the sales that reference it keep resolving their name (UC-A43).
     */
    public function test_a_soft_deleted_product_still_names_its_sales(): void
    {
        $member = $this->createMember();
        $category = $this->createCategory();
        $product = $this->createProduct($category, 'Bier', 350);
        $this->createTransaction($member, 350, '2019-03-05 10:00:00', 'purchase', $product);
        $this->db->prepare('UPDATE products SET deleted_at = NOW() WHERE id = ?')->execute([$product]);

        $rows = $this->repository->fetchGrouped($this->window(), 'product', 'revenue', 25, 0);

        $this->assertSame('Bier', $rows[0]['dimension']);
        $this->assertSame($product, $rows[0]['dimension_id']);
    }

    public function test_grouping_by_revenue_puts_the_biggest_first(): void
    {
        $member = $this->createMember();
        $category = $this->createCategory();
        $this->createTransaction($member, 100, '2019-03-05 10:00:00', 'purchase', $this->createProduct($category, 'Wasser', 100));
        $this->createTransaction($member, 100, '2019-03-05 11:00:00', 'purchase', $this->createProduct($category, 'Saft', 100));
        $this->createTransaction($member, 900, '2019-03-05 12:00:00', 'purchase', $this->createProduct($category, 'Whisky', 900));

        $rows = $this->repository->fetchGrouped($this->window(), 'product', 'revenue', 25, 0);

        $this->assertSame('Whisky', $rows[0]['dimension']);
    }

    public function test_grouping_by_count_puts_the_most_popular_first(): void
    {
        $member = $this->createMember();
        $category = $this->createCategory();
        $water = $this->createProduct($category, 'Wasser', 100);
        $this->createTransaction($member, 100, '2019-03-05 10:00:00', 'purchase', $water);
        $this->createTransaction($member, 100, '2019-03-05 11:00:00', 'purchase', $water);
        $this->createTransaction($member, 900, '2019-03-05 12:00:00', 'purchase', $this->createProduct($category, 'Whisky', 900));

        $rows = $this->repository->fetchGrouped($this->window(), 'product', 'count', 25, 0);

        $this->assertSame('Wasser', $rows[0]['dimension']);
        $this->assertSame(2, $rows[0]['count']);
    }

    public function test_countGroups_counts_groups_not_transactions(): void
    {
        $member = $this->createMember();
        $this->createTransaction($member, 100, '2019-03-05 10:00:00');
        $this->createTransaction($member, 100, '2019-03-05 11:00:00');
        $this->createTransaction($member, 100, '2019-03-06 10:00:00');

        $this->assertSame(2, $this->repository->countGroups($this->window(), 'day'));
        $this->assertSame(1, $this->repository->countGroups($this->window(), 'month'));
    }

    public function test_a_page_of_groups_skips_the_ones_before_it(): void
    {
        $member = $this->createMember();
        foreach ([5, 6, 7] as $index => $day) {
            $this->createTransaction($member, ($index + 1) * 100, "2019-03-0{$day} 10:00:00");
        }

        $page = $this->repository->fetchGrouped($this->window(), 'day', 'revenue', 1, 1);

        $this->assertCount(1, $page);
        $this->assertSame('2019-03-06', $page[0]['dimension']);
    }

    public function test_an_unknown_grouping_is_refused_rather_than_silently_ignored(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->repository->countGroups($this->window(), 'phase-of-the-moon');
    }

    public function test_a_product_with_no_german_name_falls_back_to_english(): void
    {
        $member = $this->createMember();
        $category = $this->createCategory();
        $id = $this->generateUuid();
        $this->testProductIds[] = $id;
        $this->db->prepare(
            'INSERT INTO products (id, category_id, names, price_cents, icon_name, is_active) VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([$id, $category, json_encode(['en' => 'Cider']), 400, 'glass']);

        $this->createTransaction($member, 400, '2019-03-05 10:00:00', 'purchase', $id);

        $rows = $this->repository->fetchGrouped($this->window(), 'product', 'revenue', 25, 0);

        $this->assertSame('Cider', $rows[0]['dimension']);
    }

    // ── Terminal activity ───────────────────────────────────────────────────

    public function test_transactionsForActivity_returns_every_type_in_time_order(): void
    {
        $member = $this->createMember();
        $this->createTransaction($member, 200, '2019-03-05 12:00:00');
        $this->createTransaction($member, 100, '2019-03-05 10:00:00');
        $this->createTransaction($member, -50, '2019-03-05 11:00:00', 'payout');

        $rows = $this->repository->transactionsForActivity($this->window());

        $this->assertSame([100, -50, 200], array_column($rows, 'amount_cents'));
    }

    public function test_transactionsForActivity_narrows_to_one_terminal(): void
    {
        $member = $this->createMember();
        $mine = $this->createTerminal('Tresen');
        $other = $this->createTerminal('Küche');
        $this->createTransaction($member, 100, '2019-03-05 10:00:00', 'purchase', null, $mine);
        $this->createTransaction($member, 200, '2019-03-05 11:00:00', 'purchase', null, $other);

        $rows = $this->repository->transactionsForActivity($this->window(null, null, $mine));

        $this->assertCount(1, $rows);
        $this->assertSame(100, $rows[0]['amount_cents']);
    }

    /**
     * The hour the *club* was in. Berlin is +01:00 in March, so sales stored at
     * 19:00Z and 21:00Z were rung up at 20:00 and 22:00 by everyone who was
     * standing at the till.
     *
     * This is the worst of the shifted figures to leave wrong, because it is
     * the only one with no second surface to disagree with it: an hour-of-day
     * histogram is a smooth, believable curve with a clear peak whichever way
     * it has been moved, so nobody can catch it by reading the screen (#365).
     */
    public function test_hourlyDistribution_buckets_by_the_hour_the_club_was_in(): void
    {
        $member = $this->createMember();
        $this->createTransaction($member, 100, '2019-03-05 19:00:00');
        $this->createTransaction($member, 100, '2019-03-05 19:45:00');
        $this->createTransaction($member, 100, '2019-03-06 21:00:00');

        $byHour = $this->repository->hourlyDistribution($this->window());

        $this->assertSame([20 => 2, 22 => 1], $byHour);
    }

    public function test_terminalSummary_counts_per_terminal_busiest_first(): void
    {
        $member = $this->createMember();
        $busy = $this->createTerminal('Tresen');
        $quiet = $this->createTerminal('Küche');
        $this->createTransaction($member, 100, '2019-03-05 10:00:00', 'purchase', null, $busy);
        $this->createTransaction($member, 100, '2019-03-05 11:00:00', 'purchase', null, $busy);
        $this->createTransaction($member, 100, '2019-03-05 12:00:00', 'purchase', null, $quiet);

        $summary = $this->repository->terminalSummary($this->window());

        $this->assertCount(2, $summary);
        $this->assertSame($busy, $summary[0]['id']);
        $this->assertSame('Tresen', $summary[0]['name']);
        $this->assertSame(2, $summary[0]['transaction_count']);
        $this->assertSame(1, $summary[1]['transaction_count']);
    }

    public function test_terminalSummary_leaves_out_transactions_with_no_terminal(): void
    {
        $member = $this->createMember();
        $this->createTransaction($member, 100, '2019-03-05 10:00:00');

        $this->assertSame([], $this->repository->terminalSummary($this->window()));
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function createMember(string $firstName = 'Report', string $lastName = 'Reader'): string
    {
        $id = $this->generateUuid();
        $this->testMemberIds[] = $id;

        $this->db->prepare(
            'INSERT INTO members (id, first_name, last_name, email, preferred_language, is_active) VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([$id, $firstName, $lastName, "report-{$id}@example.com", 'de']);

        return $id;
    }

    private function createCategory(string $name = 'Getränke'): string
    {
        $id = $this->generateUuid();
        $this->testCategoryIds[] = $id;

        $this->db->prepare('INSERT INTO categories (id, names, is_active) VALUES (?, ?, 1)')
            ->execute([$id, json_encode(['de' => $name, 'en' => $name])]);

        return $id;
    }

    private function createProduct(string $categoryId, string $name, int $priceCents): string
    {
        $id = $this->generateUuid();
        $this->testProductIds[] = $id;

        $this->db->prepare(
            'INSERT INTO products (id, category_id, names, price_cents, icon_name, is_active) VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([$id, $categoryId, json_encode(['de' => $name, 'en' => $name]), $priceCents, 'glass']);

        return $id;
    }

    private function createTerminal(string $name): string
    {
        $id = $this->generateUuid();
        $this->testTerminalIds[] = $id;

        $this->db->prepare('INSERT INTO terminals (id, name, device_id, is_active) VALUES (?, ?, ?, 1)')
            ->execute([$id, $name, "device-{$id}"]);

        return $id;
    }

    private function createTransaction(
        string $memberId,
        int $amountCents,
        string $occurredAt,
        string $type = 'purchase',
        ?string $productId = null,
        ?string $terminalId = null,
    ): string {
        $id = $this->generateUuid();
        $this->testTransactionIds[] = $id;

        $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, created_by_terminal_id, amount_cents, transaction_type, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $memberId, $productId, $terminalId, $amountCents, $type, $occurredAt]);

        return $id;
    }
}
