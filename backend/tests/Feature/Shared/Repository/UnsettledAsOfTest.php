<?php

declare(strict_types=1);

namespace Tests\Feature\Shared\Repository;

use App\Shared\Repository\UnsettledTransactions;
use DateTimeImmutable;
use Tests\Feature\DatabaseTestCase;

/**
 * The dated half of "unsettled" (ADR-0039 decision 2, #462 step 11.2).
 *
 * A Deckelauszug states its member's tab **as of its period boundary**, not as
 * of whichever delivery attempt succeeded, so the question the queue asks is
 * dated: *was this transaction unsettled on 1 August?* The live predicate
 * cannot answer it — `active_transaction_id` is the claim as it stands right
 * now and carries no time at all.
 *
 * Two things are tested, and the first matters more than the second.
 *
 * **Equivalence.** ADR-0039 accepts a second definition of settled money in the
 * class whose docblock reads *"what 'unsettled' means, in one place"* on the
 * condition that a test pins the two together at `t = now`. That is
 * {@see test_the_dated_predicate_agrees_with_the_live_one_at_now}, and it runs
 * over the whole table rather than over this test's own fixtures: the drift
 * #119 was about happened in queries nobody was looking at, so the assertion
 * has to cover rows nobody wrote for it.
 *
 * **The timeline.** The fixtures state a story rather than a state — a
 * transaction occurs, a settlement claims it, that settlement is cancelled —
 * and the same predicate is asked at three instants inside it.
 */
class UnsettledAsOfTest extends DatabaseTestCase
{
    /** @var list<array{table:string,id:string}> Removed in reverse order. */
    private array $created = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $row) {
            $stmt = $this->db->prepare("DELETE FROM {$row['table']} WHERE id = ?");
            $stmt->execute([$row['id']]);
        }
        $this->created = [];

        parent::tearDown();
    }

    /**
     * The condition ADR-0039 attaches to having two definitions at all.
     *
     * Whole-table, both directions: every transaction the live predicate calls
     * unsettled the dated one must call unsettled at `now`, and vice versa. A
     * release mechanism that only one of them knows about — the per-member
     * reversal of #148 is the one that nearly slipped through — shows up here
     * as a difference of exactly the affected rows.
     *
     * **Restricted to transactions that have actually occurred**, which is the
     * whole domain over which the two are claimed to be equivalent. The dated
     * predicate reads *"it had occurred by `$t`, and no live claim"*; the live
     * one is only the second half and has no time dimension at all. A sale
     * timed in the future therefore separates them by construction, and this
     * assertion would read that as the drift it exists to catch.
     *
     * Such a sale is not corruption to be cleaned up: a terminal is offline and
     * owns its own clock (ADR-0033), sync stores `created_at` **as sent**, and
     * `tests/api/transactions.spec.ts` pins that a far-future sale time is
     * accepted and stored unchanged. So the row is legitimate, and it is the
     * comparison that has to be honest about its domain rather than the data.
     *
     * The restriction costs nothing this test was buying. It still runs over
     * every row in the table rather than over its own fixtures — which is the
     * point, since #119's drift happened in queries nobody was looking at — and
     * a release mechanism only one predicate knows about still shows up as a
     * difference, because a released claim has nothing to do with when the sale
     * occurred.
     */
    public function test_the_dated_predicate_agrees_with_the_live_one_at_now(): void
    {
        // A settlement, a cancelled settlement and a reversed member, so the
        // comparison has something to be wrong about even on a bare database.
        $this->buildTimeline();
        $this->buildReversedCollection();

        // `now` from the database, not from PHP: the fixtures above were
        // written with the server's clock and a few seconds of skew on a shared
        // host would silently exclude the newest of them. Read once and used
        // for both halves, so the two questions are asked at the same instant.
        $now = (string) $this->db->query('SELECT NOW()')->fetchColumn();

        // `occurred_at <= ?` is the dated predicate's own first clause, applied
        // here so the live side is asked about the same rows. See the docblock:
        // a future-dated sale is legitimate and would otherwise read as drift.
        $stmt = $this->db->prepare(
            'SELECT t.id FROM transactions t '
            . 'WHERE ' . UnsettledTransactions::UNSETTLED . ' AND t.occurred_at <= ? ORDER BY t.id'
        );
        $stmt->execute([$now]);
        $live = $stmt->fetchAll(\PDO::FETCH_COLUMN, 0);

        $dated = $this->unsettledAt(new DateTimeImmutable($now));

        // The fixtures above guarantee rows on a bare database, so an empty
        // comparison means the restriction added for future-dated sales has
        // swallowed the table — a vacuous pass, which is the failure mode a
        // whole-table assertion is most easily reduced to.
        $this->assertNotEmpty($live, 'the equivalence must be asserted over actual rows, not an empty set');

        $this->assertSame(
            $live,
            $dated,
            'unsettledAsOf(now) and UNSETTLED are the same question asked two ways (ADR-0039 decision 2). '
            . 'A difference here means one of them learned about a way a claim is released and the other did not.'
        );
    }

    /**
     * T1 occurs 20 Jul. Settlement S is created 3 Aug and claims it. S is
     * cancelled 10 Aug. The tab therefore contains T1, then does not, then does
     * again — and a statement for each of those months has to say so.
     */
    public function test_a_claim_that_was_made_and_released_moves_the_tab_at_both_dates(): void
    {
        $fixture = $this->buildTimeline();

        $this->assertContains(
            $fixture['transaction'],
            $this->unsettledAt(new DateTimeImmutable('2026-08-01 00:00:00')),
            'on 1 August the settlement that claims this transaction did not exist yet'
        );

        $this->assertNotContains(
            $fixture['transaction'],
            $this->unsettledAt(new DateTimeImmutable('2026-08-05 00:00:00')),
            'on 5 August the settlement had been created and had not been cancelled — the drink was claimed'
        );

        $this->assertContains(
            $fixture['transaction'],
            $this->unsettledAt(new DateTimeImmutable('2026-08-15 00:00:00')),
            'on 15 August the cancellation had released the claim, and the drink is back on the Deckel'
        );
    }

    /** A transaction that has not happened yet is on nobody's statement. */
    public function test_a_transaction_is_absent_from_a_boundary_it_had_not_occurred_by(): void
    {
        $fixture = $this->buildTimeline();

        $this->assertNotContains(
            $fixture['transaction'],
            $this->unsettledAt(new DateTimeImmutable('2026-07-01 00:00:00')),
            'the July statement is dated 1 July, and this drink was bought on the 20th'
        );
    }

    /**
     * The release the whole-table comparison exists to catch.
     *
     * `releaseMemberClaims()` frees one member's transactions and leaves the
     * settlement live, so a dated predicate that only knew about
     * `settlements.cancelled_at` would keep reporting this member as collected
     * from — understating the Deckel of precisely the member whose money came
     * back after a bank return.
     */
    public function test_a_reversed_member_is_unsettled_again_from_the_reversal_onwards(): void
    {
        $fixture = $this->buildReversedCollection();

        $this->assertNotContains(
            $fixture['transaction'],
            $this->unsettledAt(new DateTimeImmutable('2026-08-05 00:00:00')),
            'before the reversal the collection stood'
        );

        $this->assertContains(
            $fixture['transaction'],
            $this->unsettledAt(new DateTimeImmutable('2026-08-15 00:00:00')),
            'the reversal put the money back on this member\'s tab, and a statement dated after it must say so'
        );
    }

    /** The dated balance is the dated set, summed — not a second aggregate. */
    public function test_the_dated_balance_is_the_sum_of_the_dated_set(): void
    {
        $fixture = $this->buildTimeline();

        $this->assertSame(350, $this->balanceAt($fixture['member'], new DateTimeImmutable('2026-08-01 00:00:00')));
        $this->assertSame(0, $this->balanceAt($fixture['member'], new DateTimeImmutable('2026-08-05 00:00:00')));
        $this->assertSame(350, $this->balanceAt($fixture['member'], new DateTimeImmutable('2026-08-15 00:00:00')));
    }

    // ── helpers ───────────────────────────────────────────────────────

    /** @return list<string> Transaction ids unsettled at $t. */
    private function unsettledAt(DateTimeImmutable $t): array
    {
        [$predicate, $params] = UnsettledTransactions::unsettledAsOf($t);

        $stmt = $this->db->prepare('SELECT t.id FROM transactions t WHERE ' . $predicate . ' ORDER BY t.id');
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN, 0);
    }

    private function balanceAt(string $memberId, DateTimeImmutable $t): int
    {
        [$expression, $params] = UnsettledTransactions::balanceAsOf($t);

        $stmt = $this->db->prepare('SELECT ' . $expression . ' FROM members m WHERE m.id = ?');
        $stmt->execute([...$params, $memberId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The story in the ADR: bought 20 Jul, collected 3 Aug, cancelled 10 Aug.
     *
     * @return array{member:string,transaction:string,settlement:string}
     */
    private function buildTimeline(): array
    {
        $admin = $this->insertAdmin();
        $member = $this->insertMember();
        $transaction = $this->insertTransaction($member, 350, '2026-07-20 19:30:00');
        $settlement = $this->insertSettlement($admin, '2026-08-03 09:00:00', '2026-08-10 11:00:00');
        $this->insertItem($settlement, $transaction, $member, 350, active: false);

        return ['member' => $member, 'transaction' => $transaction, 'settlement' => $settlement];
    }

    /**
     * A live settlement whose claim on one member was reversed on 10 Aug.
     *
     * @return array{member:string,transaction:string,settlement:string}
     */
    private function buildReversedCollection(): array
    {
        $admin = $this->insertAdmin();
        $member = $this->insertMember();
        $transaction = $this->insertTransaction($member, 500, '2026-07-22 20:00:00');
        $settlement = $this->insertSettlement($admin, '2026-08-03 09:00:00', null);
        $this->insertItem($settlement, $transaction, $member, 500, active: false);
        $this->insertReversal($settlement, $member, $admin, 500, '2026-08-10 11:00:00');

        return ['member' => $member, 'transaction' => $transaction, 'settlement' => $settlement];
    }

    private function insertAdmin(): string
    {
        $id = $this->track('admin_users', $this->generateUuid());
        $this->db->prepare('INSERT INTO admin_users (id, email, password_hash, is_active) VALUES (?, ?, ?, 1)')
            ->execute([$id, $id . '@example.com', password_hash('test123', PASSWORD_BCRYPT)]);

        return $id;
    }

    private function insertMember(): string
    {
        $id = $this->track('members', $this->generateUuid());
        $this->db->prepare(
            'INSERT INTO members (id, first_name, last_name, email, preferred_language, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([$id, 'As', 'Of', $id . '@example.com', 'de']);

        return $id;
    }

    private function insertTransaction(string $memberId, int $cents, string $occurredAt): string
    {
        $id = $this->track('transactions', $this->generateUuid());
        $this->db->prepare(
            'INSERT INTO transactions (id, member_id, amount_cents, transaction_type, occurred_at, received_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$id, $memberId, $cents, 'purchase', $occurredAt, $occurredAt]);

        return $id;
    }

    private function insertSettlement(string $adminId, string $createdAt, ?string $cancelledAt): string
    {
        $id = $this->track('settlements', $this->generateUuid());
        $this->db->prepare(
            'INSERT INTO settlements
                (id, settlement_date, execution_date, total_amount_cents, member_count,
                 created_by_admin_id, created_at, is_cancelled, cancelled_at, cancelled_by_admin_id)
             VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            substr($createdAt, 0, 10),
            substr($createdAt, 0, 10),
            350,
            $adminId,
            $createdAt,
            $cancelledAt === null ? 0 : 1,
            $cancelledAt,
            $cancelledAt === null ? null : $adminId,
        ]);

        return $id;
    }

    /**
     * @param bool $active Whether the claim still stands *now*. The timeline
     *        fixtures are all released by now, which is what makes them usable
     *        in the whole-table equivalence assertion above.
     */
    private function insertItem(
        string $settlementId,
        string $transactionId,
        string $memberId,
        int $cents,
        bool $active,
    ): void {
        $this->db->prepare(
            'INSERT INTO settlement_items (settlement_id, transaction_id, active_transaction_id, member_id, amount_cents)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$settlementId, $transactionId, $active ? $transactionId : null, $memberId, $cents]);
    }

    private function insertReversal(
        string $settlementId,
        string $memberId,
        string $adminId,
        int $cents,
        string $createdAt,
    ): void {
        $id = $this->track('settlement_reversals', $this->generateUuid());
        $this->db->prepare(
            'INSERT INTO settlement_reversals
                (id, settlement_id, member_id, reason, amount_cents, created_by_admin_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $settlementId, $memberId, 'bank_return', $cents, $adminId, $createdAt]);
    }

    private function track(string $table, string $id): string
    {
        $this->created[] = ['table' => $table, 'id' => $id];

        return $id;
    }
}
