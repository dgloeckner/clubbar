<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Dashboard\Repositories;

use App\Modules\Dashboard\Repositories\DashboardRepository;
use Tests\Feature\DatabaseTestCase;

/**
 * The dashboard queries against a real database (#118).
 *
 * These used to be inline in a controller, where the only thing that ever ran
 * them was a request. They are the half of the module a mock cannot check:
 * whether the SQL says what the service thinks it says.
 *
 * Window-scoped queries use March 2019 so nothing else in the database can
 * appear in the answer; the unbounded ones are measured as a delta.
 */
class DashboardRepositoryTest extends DatabaseTestCase
{
    private const WINDOW_START = '2019-03-01';
    private const WINDOW_END = '2019-03-31';

    private DashboardRepository $repository;

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
    /** @var list<string> */
    private array $testMandateIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new DashboardRepository($this->db);
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData('transactions', $this->testTransactionIds);
        $this->cleanupTestData('mandates', $this->testMandateIds);
        $this->cleanupTestData('products', $this->testProductIds);
        $this->cleanupTestData('categories', $this->testCategoryIds);
        $this->cleanupTestData('terminals', $this->testTerminalIds);
        $this->cleanupTestData('members', $this->testMemberIds);
        parent::tearDown();
    }

    // ── Revenue ─────────────────────────────────────────────────────────────

    /**
     * The date names a **club** calendar day, and the column holds UTC.
     *
     * Berlin is +01:00 in March, so the club day 2019-03-10 begins at 23:00Z on
     * the 9th. A sale a second earlier was rung up at 23:59:59 on the 9th and is
     * last night's takings; a sale a second later was rung up at 00:00 on the
     * 10th and is today's. Reading the date as a UTC day put that boundary an
     * hour late, which is how "Heute" on the dashboard lost every sale a bar
     * serving past midnight made after 00:00 local (#365).
     */
    public function test_sumRevenueSince_counts_from_the_start_of_the_club_day(): void
    {
        $member = $this->createMember();
        $before = $this->repository->sumRevenueSince('2019-03-10');

        $this->createTransaction($member, 500, '2019-03-09 22:59:59');
        $this->createTransaction($member, 700, '2019-03-09 23:00:00');
        $this->createTransaction($member, 300, '2019-03-20 12:00:00');

        $this->assertSame($before + 1000, $this->repository->sumRevenueSince('2019-03-10'));
    }

    /**
     * Both edges of the window are club days, and the last one here is 23 hours
     * long: Berlin enters summer time on 2019-03-31, so that day starts at
     * 23:00Z on the 30th and ends at 22:00Z rather than 23:00Z. A fixed offset
     * gets exactly one of those two edges wrong, which is why the boundaries
     * are constructed in the zone.
     */
    public function test_sumRevenueBetween_covers_the_club_days_edge_to_edge(): void
    {
        $member = $this->createMember();
        $this->createTransaction($member, 400, '2019-02-28 22:59:59'); // 23:59:59 on 28 Feb
        $this->createTransaction($member, 100, '2019-02-28 23:00:00'); // 00:00:00 on 1 Mar
        $this->createTransaction($member, 200, '2019-03-31 21:59:59'); // 23:59:59 on 31 Mar
        $this->createTransaction($member, 999, '2019-03-31 22:00:00'); // 00:00:00 on 1 Apr

        $this->assertSame(300, $this->repository->sumRevenueBetween(self::WINDOW_START, self::WINDOW_END));
    }

    public function test_sumRevenueBetween_is_zero_rather_than_null_for_an_empty_range(): void
    {
        $this->assertSame(0, $this->repository->sumRevenueBetween('2019-05-01', '2019-05-31'));
    }

    public function test_sumRevenueSince_counts_purchases_only(): void
    {
        $member = $this->createMember();
        $before = $this->repository->sumRevenueSince('2019-03-01');

        $purchase = $this->createTransaction($member, 5000, '2019-03-05 10:00:00');
        $this->createStorno($member, $purchase, 5000, '2019-03-05 10:05:00');
        $this->createTransaction($member, -700, '2019-03-06 10:00:00', 'payout');

        // Netting the storno and the payout off would leave -700.
        $this->assertSame($before + 5000, $this->repository->sumRevenueSince('2019-03-01'));
    }

    public function test_sumRevenueBetween_counts_purchases_only(): void
    {
        $member = $this->createMember();
        $purchase = $this->createTransaction($member, 5000, '2019-03-05 10:00:00');
        $this->createStorno($member, $purchase, 5000, '2019-03-05 10:05:00');
        $this->createTransaction($member, 2500, '2019-03-06 10:00:00');

        $this->assertSame(7500, $this->repository->sumRevenueBetween(self::WINDOW_START, self::WINDOW_END));
    }

    /**
     * A bar on the chart is a day the *club* had.
     *
     * A sale at 23:30Z on the 5th was rung up at 00:30 on the 6th in Berlin
     * (+01:00 in early March), so it belongs to the 6th. Bucketing on the
     * stored UTC put a Saturday evening's late takings under Sunday —
     * invisible for a lunchtime club and systematic for an evening one (#365).
     */
    public function test_findDailyRevenue_buckets_by_the_club_day(): void
    {
        $member = $this->createMember();
        $this->createTransaction($member, 1000, '2019-03-05 12:00:00'); // 13:00 on the 5th
        $this->createTransaction($member, 2000, '2019-03-05 23:30:00'); // 00:30 on the 6th

        $days = $this->repository->findDailyRevenue('2019-03-05', '2019-03-06');
        $byDate = [];
        foreach ($days as $day) {
            $byDate[(string) $day['date']] = (int) $day['revenue_cents'];
        }

        $this->assertSame(['2019-03-05' => 1000, '2019-03-06' => 2000], $byDate);
    }

    /**
     * The offset is not a constant, so a range spanning a daylight-saving
     * transition needs a different one on each side of it. Berlin goes back to
     * +01:00 at 01:00Z on 2019-10-27: 22:30Z on the 26th is still 00:30 on the
     * 27th, while 23:30Z on the 27th is 00:30 on the *28th*. A single fixed
     * offset gets one of these two wrong whichever value it picks — which is
     * what `ClubLocalSql` builds a CASE for, `CONVERT_TZ` being unavailable on
     * a stock MariaDB.
     */
    public function test_findDailyRevenue_stays_correct_across_a_dst_transition(): void
    {
        $member = $this->createMember();
        $this->createTransaction($member, 1000, '2019-10-26 22:30:00'); // 00:30 on the 27th, +02:00
        $this->createTransaction($member, 2000, '2019-10-27 23:30:00'); // 00:30 on the 28th, +01:00

        $days = $this->repository->findDailyRevenue('2019-10-26', '2019-10-28');
        $byDate = [];
        foreach ($days as $day) {
            $byDate[(string) $day['date']] = (int) $day['revenue_cents'];
        }

        $this->assertSame(['2019-10-27' => 1000, '2019-10-28' => 2000], $byDate);
    }

    public function test_findDailyRevenue_counts_purchases_only(): void
    {
        $member = $this->createMember();
        $purchase = $this->createTransaction($member, 5000, '2019-03-05 10:00:00');
        $this->createStorno($member, $purchase, 5000, '2019-03-05 10:05:00');
        $this->createTransaction($member, 2500, '2019-03-06 10:00:00');

        $days = $this->repository->findDailyRevenue(self::WINDOW_START, self::WINDOW_END);

        // The 5th holds a purchase and its storno, and is one sold item worth
        // 5000 — not two transactions worth nothing.
        $this->assertCount(2, $days);
        $this->assertSame(5000, (int) $days[0]['revenue_cents']);
        $this->assertSame(1, (int) $days[0]['transaction_count']);

        // The breakdown and the headline agree, which is the point of the
        // shared filter (#116).
        $this->assertSame(
            $this->repository->sumRevenueBetween(self::WINDOW_START, self::WINDOW_END),
            array_sum(array_map(static fn(array $d): int => (int) $d['revenue_cents'], $days)),
        );
        $this->assertSame(
            $this->repository->countPurchasesBetween(self::WINDOW_START, self::WINDOW_END),
            array_sum(array_map(static fn(array $d): int => (int) $d['transaction_count'], $days)),
        );
    }

    public function test_countPurchasesBetween_ignores_other_transaction_types(): void
    {
        $member = $this->createMember();
        $this->createTransaction($member, 100, '2019-03-05 10:00:00');
        $this->createTransaction($member, 200, '2019-03-06 10:00:00');
        $this->createTransaction($member, -50, '2019-03-07 10:00:00', 'payout');

        $this->assertSame(2, $this->repository->countPurchasesBetween(self::WINDOW_START, self::WINDOW_END));
    }

    public function test_findDailyRevenue_groups_by_calendar_day_in_order(): void
    {
        $member = $this->createMember();
        $this->createTransaction($member, 100, '2019-03-05 10:00:00');
        $this->createTransaction($member, 250, '2019-03-05 18:30:00');
        $this->createTransaction($member, 400, '2019-03-07 09:00:00');

        $days = $this->repository->findDailyRevenue(self::WINDOW_START, self::WINDOW_END);

        $this->assertCount(2, $days);
        $this->assertSame('2019-03-05', $days[0]['date']);
        $this->assertSame(350, (int) $days[0]['revenue_cents']);
        $this->assertSame(2, (int) $days[0]['transaction_count']);
        $this->assertSame('2019-03-07', $days[1]['date']);
        $this->assertSame(400, (int) $days[1]['revenue_cents']);
    }

    // ── Top lists ───────────────────────────────────────────────────────────

    public function test_findTopProductsByRevenue_ranks_by_money_and_honours_the_limit(): void
    {
        $member = $this->createMember();
        $category = $this->createCategory();
        $cheapButPopular = $this->createProduct($category, 'Wasser', 100);
        $dearButRare = $this->createProduct($category, 'Whisky', 900);

        $this->createTransaction($member, 100, '2019-03-05 10:00:00', 'purchase', $cheapButPopular);
        $this->createTransaction($member, 100, '2019-03-05 11:00:00', 'purchase', $cheapButPopular);
        $this->createTransaction($member, 900, '2019-03-05 12:00:00', 'purchase', $dearButRare);

        $top = $this->repository->findTopProductsByRevenue(self::WINDOW_START, self::WINDOW_END, 10);

        $this->assertCount(2, $top);
        $this->assertSame($dearButRare, $top[0]['id']);
        $this->assertSame(900, (int) $top[0]['revenue_cents']);
        $this->assertSame(1, (int) $top[0]['sold_count']);

        $this->assertCount(1, $this->repository->findTopProductsByRevenue(self::WINDOW_START, self::WINDOW_END, 1));
    }

    public function test_findTopProductsBySoldCount_ranks_by_units_not_by_money(): void
    {
        $member = $this->createMember();
        $category = $this->createCategory();
        $cheapButPopular = $this->createProduct($category, 'Wasser', 100);
        $dearButRare = $this->createProduct($category, 'Whisky', 900);

        $this->createTransaction($member, 100, '2019-03-05 10:00:00', 'purchase', $cheapButPopular);
        $this->createTransaction($member, 100, '2019-03-05 11:00:00', 'purchase', $cheapButPopular);
        $this->createTransaction($member, 900, '2019-03-05 12:00:00', 'purchase', $dearButRare);

        $top = $this->repository->findTopProductsBySoldCount(self::WINDOW_START, self::WINDOW_END, 1);

        $this->assertCount(1, $top);
        $this->assertSame($cheapButPopular, $top[0]['id']);
        $this->assertSame(2, (int) $top[0]['sold_count']);
    }

    public function test_findTopMembers_ranks_spenders_and_names_them(): void
    {
        $big = $this->createMember('Bigga', 'Spender');
        $small = $this->createMember('Smalla', 'Spender');
        $this->createTransaction($big, 900, '2019-03-05 10:00:00');
        $this->createTransaction($big, 100, '2019-03-06 10:00:00');
        $this->createTransaction($small, 200, '2019-03-05 10:00:00');

        $top = $this->repository->findTopMembers(self::WINDOW_START, self::WINDOW_END, 10);

        $this->assertCount(2, $top);
        $this->assertSame($big, $top[0]['id']);
        $this->assertSame('Bigga Spender', $top[0]['name']);
        $this->assertSame(1000, (int) $top[0]['revenue_cents']);
        $this->assertSame(2, (int) $top[0]['purchase_count']);
    }

    // ── Recent transactions ─────────────────────────────────────────────────

    public function test_findRecentTransactions_returns_the_newest_first_with_its_context(): void
    {
        $member = $this->createMember('Anna', 'Meier');
        $category = $this->createCategory();
        $product = $this->createProduct($category, 'Bier', 350);
        $terminal = $this->createTerminal('Tresen');

        // Dated far enough ahead to outrank anything else in the database, but
        // inside the TIMESTAMP range.
        $this->createTransaction($member, 350, '2037-01-01 10:00:00', 'purchase', $product, $terminal);
        $newest = $this->createTransaction($member, 700, '2037-01-02 10:00:00', 'purchase', $product, $terminal);

        $rows = $this->repository->findRecentTransactions(10);

        $this->assertSame($newest, $rows[0]['id']);
        $this->assertSame('Anna Meier', $rows[0]['member_name']);
        $this->assertSame('Tresen', $rows[0]['terminal_name']);
        $this->assertSame('purchase', $rows[0]['type']);
        $this->assertStringContainsString('Bier', (string) $rows[0]['product_names']);
    }

    public function test_findRecentTransactions_honours_the_limit(): void
    {
        $member = $this->createMember();
        $this->createTransaction($member, 100, '2037-01-01 10:00:00');
        $this->createTransaction($member, 100, '2037-01-02 10:00:00');

        $this->assertCount(1, $this->repository->findRecentTransactions(1));
    }

    // ── SEPA alert input ────────────────────────────────────────────────────

    public function test_countMembersWithoutMandate_counts_a_member_with_no_mandate(): void
    {
        $before = $this->repository->countMembersWithoutMandate();

        $this->createMember();

        $this->assertSame($before + 1, $this->repository->countMembersWithoutMandate());
    }

    public function test_countMembersWithoutMandate_does_not_count_a_member_holding_one(): void
    {
        $before = $this->repository->countMembersWithoutMandate();

        $member = $this->createMember();
        $this->createMandate($member);

        $this->assertSame($before, $this->repository->countMembersWithoutMandate());
    }

    public function test_countMembersWithoutMandate_ignores_deleted_members(): void
    {
        $before = $this->repository->countMembersWithoutMandate();

        $member = $this->createMember();
        $this->db->prepare('UPDATE members SET deleted_at = NOW() WHERE id = ?')->execute([$member]);

        $this->assertSame($before, $this->repository->countMembersWithoutMandate());
    }

    // ── Members near their credit limit ─────────────────────────────────────


    // ── Fixtures ────────────────────────────────────────────────────────────





    private function createMember(string $firstName = 'Dash', string $lastName = 'Board'): string
    {
        $id = $this->generateUuid();
        $this->testMemberIds[] = $id;

        $this->db->prepare(
            'INSERT INTO members (id, first_name, last_name, email, preferred_language, is_active) VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([$id, $firstName, $lastName, "dash-{$id}@example.com", 'de']);

        return $id;
    }

    private function createMandate(string $memberId): string
    {
        // A sealed mandate names the key generation it was sealed under, and
        // the FK insists that key exists. CI applies migrations without the
        // seed, so nothing else puts it there (ADR-0036).
        $this->ensureActiveEncryptionKey();

        $id = $this->generateUuid();
        $this->testMandateIds[] = $id;

        $this->db->prepare(
            'INSERT INTO mandates (id, member_id, active_member_id, reference, iban_ciphertext, iban_last4, encryption_key_id) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            $memberId,
            $memberId,
            substr(str_replace('-', '', $id), 0, 35),
            $this->sealIban('DE89370400440532013000'),
            '3000',
            self::DEV_KEY_ID,
        ]);

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

    /**
     * The exact negation of a booking. A storno must name what it reverses —
     * `chk_transactions_storno_is_linked` refuses the row otherwise — so this
     * cannot go through createTransaction().
     */
    private function createStorno(
        string $memberId,
        string $originalId,
        int $amountCents,
        string $occurredAt,
    ): string {
        $id = $this->generateUuid();
        $this->testTransactionIds[] = $id;

        $this->db->prepare(
            'INSERT INTO transactions (id, member_id, amount_cents, transaction_type, occurred_at, related_transaction_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$id, $memberId, -$amountCents, 'storno', $occurredAt, $originalId]);

        return $id;
    }
}
