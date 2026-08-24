<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditLimits\Repositories;

use App\Modules\CreditLimits\Repositories\NearLimitRepository;
use Tests\Feature\DatabaseTestCase;

/**
 * Who is near their Deckel ceiling, asked of a real database (#385, ADR-0047).
 *
 * These tests arrived here from `DashboardRepositoryTest` together with the
 * query, when the near-limit digest became its second consumer. They moved
 * rather than being copied, and that is the point: the dashboard panel and the
 * digest must never name different members, so there is one query and one suite
 * holding it to its word.
 *
 * None of them can be written against a double. The band boundary is decided by
 * `DIV` — MariaDB's integer division, matching `intdiv()` in
 * `CreditLimit::warnAtCents()` — and every member this query returns sits at
 * that boundary by definition. A decimal division would move the boundary cent
 * and name a member the terminal has not warned yet, which is the one row this
 * query must never produce.
 *
 * Fixtures are dated March 2019 so nothing else in the database can appear in
 * the answer; the unbounded counts are measured as a delta.
 */
class NearLimitRepositoryTest extends DatabaseTestCase
{
    private NearLimitRepository $repository;

    /** @var list<string> */
    private array $testMemberIds = [];
    /** @var list<string> */
    private array $testTransactionIds = [];
    /** @var list<string> */
    private array $testSettlementIds = [];
    /** @var list<string> */
    private array $testAdminUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new NearLimitRepository($this->db);
    }

    protected function tearDown(): void
    {
        // Settlement items first: they hold the claim that makes a transaction
        // settled, and the foreign key points this way.
        if ($this->testSettlementIds !== []) {
            $placeholders = implode(',', array_fill(0, count($this->testSettlementIds), '?'));
            $this->db->prepare("DELETE FROM settlement_items WHERE settlement_id IN ({$placeholders})")
                ->execute($this->testSettlementIds);
        }
        $this->cleanupTestData('settlements', $this->testSettlementIds);
        $this->cleanupTestData('transactions', $this->testTransactionIds);
        $this->cleanupTestData('members', $this->testMemberIds);
        $this->cleanupTestData('admin_users', $this->testAdminUserIds);

        $this->testSettlementIds = [];
        $this->testTransactionIds = [];
        $this->testMemberIds = [];
        $this->testAdminUserIds = [];

        parent::tearDown();
    }

    public function test_findNearLimit_lists_only_tabs_that_reached_the_threshold(): void
    {
        $near = $this->createMember('Near', 'Limit');
        $this->createTransaction($near, 8_000, '2019-03-05 20:00:00');
        $far = $this->createMember('Far', 'Below');
        $this->createTransaction($far, 7_999, '2019-03-05 20:00:00');

        $ids = $this->idsOf($this->repository->findNearLimit(10_000, 80, 50));

        $this->assertContains($near, $ids, 'the threshold is inclusive');
        $this->assertNotContains($far, $ids);
    }

    public function test_findNearLimit_sums_the_whole_tab_not_the_single_purchase(): void
    {
        $member = $this->createMember('Many', 'Rounds');
        foreach (range(1, 4) as $i) {
            $this->createTransaction($member, 2_100, "2019-03-0{$i} 20:00:00");
        }

        $row = $this->rowFor($member, $this->repository->findNearLimit(10_000, 80, 50));

        $this->assertNotNull($row);
        $this->assertSame(8_400, (int) $row['balance_cents']);
        $this->assertSame('Many Rounds', $row['name']);
    }

    public function test_findNearLimit_lets_a_storno_pull_a_member_back_off_the_list(): void
    {
        // A storno is a row of its own, not a retraction, so the only thing
        // that can take a member off this list is the sum (ADR-0004).
        $member = $this->createMember('Stornoed', 'Back');
        $purchase = $this->createTransaction($member, 9_000, '2019-03-05 20:00:00');
        $this->createStorno($member, $purchase, 2_000, '2019-03-06 20:00:00');

        $ids = $this->idsOf($this->repository->findNearLimit(10_000, 80, 50));

        $this->assertNotContains($member, $ids);
    }

    public function test_findNearLimit_forgets_a_tab_that_has_been_settled(): void
    {
        $member = $this->createMember('Settled', 'Up');
        $transaction = $this->createTransaction($member, 9_500, '2019-03-05 20:00:00');
        $this->assertContains($member, $this->idsOf($this->repository->findNearLimit(10_000, 80, 50)));

        $this->settle($member, $transaction, 9_500);

        $this->assertNotContains($member, $this->idsOf($this->repository->findNearLimit(10_000, 80, 50)));
    }

    public function test_findNearLimit_leaves_out_members_the_terminal_no_longer_serves(): void
    {
        $inactive = $this->createMember('Inactive', 'Debtor');
        $this->createTransaction($inactive, 9_000, '2019-03-05 20:00:00');
        $this->db->prepare('UPDATE members SET is_active = 0 WHERE id = ?')->execute([$inactive]);

        $deleted = $this->createMember('Deleted', 'Debtor');
        $this->createTransaction($deleted, 9_000, '2019-03-05 20:00:00');
        $this->db->prepare('UPDATE members SET deleted_at = NOW() WHERE id = ?')->execute([$deleted]);

        $ids = $this->idsOf($this->repository->findNearLimit(10_000, 80, 50));

        $this->assertNotContains($inactive, $ids);
        $this->assertNotContains($deleted, $ids);
    }

    /**
     * Ordering is asserted between *this test's own* two members rather than by
     * absolute position, and the reason is worth writing down.
     *
     * This query has no date window — it answers "who owes a lot right now?"
     * across the whole database — so any member with a bigger tab takes the
     * first row. One reliably exists: the API suite's
     * `POST /api/sync/transactions accepts max batch size` books a hundred
     * transactions against a fresh member, and ADR-0004 makes transactions
     * append-only, so that tab cannot be cleaned up afterwards. Running the API
     * suite before this one against a shared database used to turn this test
     * red, which read as "the dashboard query broke".
     *
     * Comparing positions instead is the same rule as E2E pattern 003: assert
     * on the data you created, never on where it happens to land.
     */
    public function test_findNearLimit_puts_the_biggest_tab_first_and_honours_the_limit(): void
    {
        $small = $this->createMember('Small', 'Tab');
        $this->createTransaction($small, 8_100, '2019-03-05 20:00:00');
        $big = $this->createMember('Big', 'Tab');
        $this->createTransaction($big, 20_000, '2019-03-05 20:00:00');

        // The limit is absolute whatever else is in the table.
        $this->assertCount(1, $this->repository->findNearLimit(20_000, 100, 1));

        $ids = $this->idsOf($this->repository->findNearLimit(10_000, 80, 500));
        $this->assertContains($big, $ids);
        $this->assertContains($small, $ids);

        $this->assertLessThan(
            array_search($small, $ids, true),
            array_search($big, $ids, true),
            'the bigger tab has to come first',
        );
    }

    public function test_countNearLimit_counts_what_the_list_would_have_shown(): void
    {
        $before = $this->repository->countNearLimit(10_000, 80);

        $this->createTransaction($this->createMember('First', 'Debtor'), 8_000, '2019-03-05 20:00:00');
        $this->createTransaction($this->createMember('Second', 'Debtor'), 12_000, '2019-03-05 20:00:00');
        $this->createTransaction($this->createMember('Third', 'Saint'), 500, '2019-03-05 20:00:00');

        $this->assertSame($before + 2, $this->repository->countNearLimit(10_000, 80));
    }

    public function test_countNearLimit_counts_a_member_once_however_many_rounds_they_bought(): void
    {
        $before = $this->repository->countNearLimit(10_000, 80);

        $member = $this->createMember('Regular', 'Guest');
        foreach (range(1, 5) as $i) {
            $this->createTransaction($member, 2_000, "2019-03-0{$i} 20:00:00");
        }

        $this->assertSame($before + 1, $this->repository->countNearLimit(10_000, 80));
    }

    // ── Per-member ceilings (ADR-0047, #559) ────────────────────────────────
    //
    // The threshold used to be one number for the whole club. It is now one per
    // row, and every case below is a member the old query would have got wrong.

    public function test_a_raised_override_takes_a_member_off_the_list(): void
    {
        $member = $this->createMember('Raised', 'Ceiling');
        $this->createTransaction($member, 9_000, '2019-03-05 20:00:00');
        $this->assertContains($member, $this->idsOf($this->repository->findNearLimit(10_000, 80, 500)));

        // 9,000 is 45 % of 20,000 — nowhere near the band, and the terminal is
        // still serving them.
        $this->setOverride($member, 20_000);

        $this->assertNotContains($member, $this->idsOf($this->repository->findNearLimit(10_000, 80, 500)));
    }

    public function test_a_lowered_override_puts_a_member_on_it(): void
    {
        $member = $this->createMember('Lowered', 'Ceiling');
        $this->createTransaction($member, 3_000, '2019-03-05 20:00:00');
        $this->assertNotContains($member, $this->idsOf($this->repository->findNearLimit(10_000, 80, 500)));

        // 3,000 against a 3,000 ceiling is a member who cannot buy the next
        // round, invisible under a club-wide threshold of 8,000.
        $this->setOverride($member, 3_000);

        $this->assertContains($member, $this->idsOf($this->repository->findNearLimit(10_000, 80, 500)));
    }

    public function test_a_member_with_no_ceiling_never_appears_however_large_the_tab(): void
    {
        $member = $this->createMember('Unlimited', 'Founder');
        $this->createTransaction($member, 500_000, '2019-03-05 20:00:00');
        $this->setOverride($member, 0);

        $this->assertNotContains($member, $this->idsOf($this->repository->findNearLimit(10_000, 80, 500)));
        $this->assertNotContains($member, $this->idsOf($this->repository->findNearLimit(0, 80, 500)));
    }

    /**
     * The club switching enforcement off does not switch it off for a member
     * who was deliberately given a ceiling — they are still being refused at
     * the bar, so the panel must still say so.
     */
    public function test_an_override_still_counts_when_the_club_default_is_off(): void
    {
        $member = $this->createMember('Capped', 'Anyway');
        $this->createTransaction($member, 4_500, '2019-03-05 20:00:00');
        $this->setOverride($member, 5_000);

        $ids = $this->idsOf($this->repository->findNearLimit(0, 80, 500));

        $this->assertContains($member, $ids);
    }

    /**
     * The boundary cent, and why it is `DIV` and not `/`: 70 % of 3,333 is
     * 2,333.1, and `intdiv()` at the terminal makes the band start at 2,333.
     * A decimal here would move it to 2,334 and put a member on this panel the
     * terminal has not warned yet — the one figure this list must never
     * produce.
     */
    public function test_the_band_boundary_is_the_same_whole_cent_the_terminal_uses(): void
    {
        $inside = $this->createMember('On', 'Boundary');
        $this->createTransaction($inside, 2_333, '2019-03-05 20:00:00');
        $this->setOverride($inside, 3_333);

        $outside = $this->createMember('One', 'Cent Short');
        $this->createTransaction($outside, 2_332, '2019-03-05 20:00:00');
        $this->setOverride($outside, 3_333);

        $ids = $this->idsOf($this->repository->findNearLimit(10_000, 70, 500));

        $this->assertContains($inside, $ids, '2333 is inside the band');
        $this->assertNotContains($outside, $ids, '2332 is not');
    }

    /**
     * Ranking by share, not by amount. A member €10 past a €50 ceiling is
     * already being refused; one at €170 of €200 is merely being warned, and
     * ordering by raw amount would put the second one first.
     */
    public function test_the_most_pressing_member_sorts_first_even_with_the_smaller_tab(): void
    {
        $pressing = $this->createMember('Small', 'Ceiling');
        $this->createTransaction($pressing, 6_000, '2019-03-05 20:00:00');
        $this->setOverride($pressing, 5_000); // 120 %

        $comfortable = $this->createMember('Large', 'Ceiling');
        $this->createTransaction($comfortable, 16_800, '2019-03-05 20:00:00');
        $this->setOverride($comfortable, 20_000); // 84 %

        $ids = $this->idsOf($this->repository->findNearLimit(10_000, 80, 500));

        $this->assertLessThan(
            array_search($comfortable, $ids, true),
            array_search($pressing, $ids, true),
            'the member who cannot buy a drink comes before the one who is merely close',
        );
    }

    public function test_each_row_carries_the_ceiling_it_was_measured_against(): void
    {
        $inheriting = $this->createMember('Club', 'Default');
        $this->createTransaction($inheriting, 8_500, '2019-03-05 20:00:00');

        $overridden = $this->createMember('Own', 'Ceiling');
        $this->createTransaction($overridden, 4_500, '2019-03-05 20:00:00');
        $this->setOverride($overridden, 5_000);

        $rows = $this->repository->findNearLimit(10_000, 80, 500);

        $this->assertSame(10_000, (int) $this->rowFor($inheriting, $rows)['limit_cents']);
        $this->assertSame(5_000, (int) $this->rowFor($overridden, $rows)['limit_cents']);
    }

    public function test_the_count_agrees_with_the_list_about_per_member_ceilings(): void
    {
        $before = $this->repository->countNearLimit(10_000, 80);

        $onlyByOverride = $this->createMember('Counted', 'ByOverride');
        $this->createTransaction($onlyByOverride, 3_000, '2019-03-05 20:00:00');
        $this->setOverride($onlyByOverride, 3_000);

        $liftedOut = $this->createMember('Uncounted', 'ByOverride');
        $this->createTransaction($liftedOut, 9_000, '2019-03-05 20:00:00');
        $this->setOverride($liftedOut, 50_000);

        $this->assertSame($before + 1, $this->repository->countNearLimit(10_000, 80));
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function setOverride(string $memberId, ?int $cents): void
    {
        $this->db->prepare('UPDATE members SET credit_limit_cents = ? WHERE id = ?')->execute([$cents, $memberId]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function idsOf(array $rows): array
    {
        return array_map(static fn(array $row): string => (string) $row['id'], $rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function rowFor(string $memberId, array $rows): ?array
    {
        foreach ($rows as $row) {
            if ($row['id'] === $memberId) {
                return $row;
            }
        }

        return null;
    }

    /** Claim a transaction for a settlement run, the way a real run does. */
    private function settle(string $memberId, string $transactionId, int $amountCents): void
    {
        $adminId = $this->generateUuid();
        $this->testAdminUserIds[] = $adminId;
        $this->db->prepare(
            'INSERT INTO admin_users (id, email, password_hash, display_name, is_active) VALUES (?, ?, ?, ?, 1)'
        )->execute([$adminId, "dash-{$adminId}@example.com", password_hash('test123', PASSWORD_BCRYPT), 'Test Admin']);

        $settlementId = $this->generateUuid();
        $this->testSettlementIds[] = $settlementId;
        $this->db->prepare(
            'INSERT INTO settlements (id, settlement_date, execution_date, total_amount_cents, member_count, created_by_admin_id)
             VALUES (?, ?, ?, ?, 1, ?)'
        )->execute([$settlementId, '2019-03-31', '2019-04-05', $amountCents, $adminId]);

        $this->db->prepare(
            'INSERT INTO settlement_items (settlement_id, transaction_id, active_transaction_id, member_id, amount_cents)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$settlementId, $transactionId, $transactionId, $memberId, $amountCents]);
    }

    private function createMember(string $firstName = 'Dash', string $lastName = 'Board'): string
    {
        $id = $this->generateUuid();
        $this->testMemberIds[] = $id;

        $this->db->prepare(
            'INSERT INTO members (id, first_name, last_name, email, preferred_language, is_active) VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([$id, $firstName, $lastName, "dash-{$id}@example.com", 'de']);

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
