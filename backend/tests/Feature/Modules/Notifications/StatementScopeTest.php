<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notifications;

use App\Modules\Notifications\Repositories\StatementRecipientsRepository;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Feature\DatabaseTestCase;

/**
 * Who gets a Deckelauszug (ADR-0039 decision 3, #462 step 11.6).
 *
 * One case per ruling, and the two that matter most are the ones that look like
 * mistakes: a member who owes nothing is **in**, and a member who has been
 * deactivated but still owes is **in**. The first is what stops a statement
 * from becoming a nudge — if it only ever arrives when you owe something, its
 * arrival *is* the message. The second is the one case where silence is wrong:
 * deactivating somebody does not cancel what they owe, and the club will still
 * collect it.
 */
class StatementScopeTest extends DatabaseTestCase
{
    private StatementRecipientsRepository $recipients;

    /** @var list<array{table:string,id:string}> */
    private array $created = [];

    /** Far enough back that no other suite's fixtures land inside it. */
    private const BOUNDARY = '2026-08-01 00:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->recipients = new StatementRecipientsRepository($this->db);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $row) {
            $this->db->prepare("DELETE FROM {$row['table']} WHERE id = ?")->execute([$row['id']]);
        }
        $this->created = [];

        parent::tearDown();
    }

    public function test_an_active_member_who_owes_nothing_still_receives_one(): void
    {
        $member = $this->member(active: true);

        $this->assertContains($member, $this->inScope(), 'a zero balance is a statement, not a reason to skip');
    }

    public function test_an_active_member_in_credit_receives_one(): void
    {
        $member = $this->member(active: true);
        $this->transaction($member, -1500);

        $this->assertContains($member, $this->inScope(), 'a credit is stated, not suppressed');
    }

    public function test_an_active_member_who_owes_receives_one(): void
    {
        $member = $this->member(active: true);
        $this->transaction($member, 4200);

        $this->assertContains($member, $this->inScope());
    }

    public function test_an_inactive_member_with_a_zero_tab_is_skipped(): void
    {
        $member = $this->member(active: false);

        $this->assertNotContains($member, $this->inScope());
    }

    /**
     * The ruling that is easy to get backwards.
     *
     * Deactivating a member does not cancel their Deckel; the club still means
     * to collect it. Going quiet on somebody you are still going to bill is the
     * one case where not sending a statement is the wrong answer.
     */
    public function test_an_inactive_member_who_still_owes_receives_one(): void
    {
        $member = $this->member(active: false);
        $this->transaction($member, 2500);

        $this->assertContains($member, $this->inScope());
    }

    /** A soft-deleted member is treated as inactive, not as a separate case. */
    public function test_a_soft_deleted_member_who_still_owes_receives_one(): void
    {
        $member = $this->member(active: true, deleted: true);
        $this->transaction($member, 900);

        $this->assertContains($member, $this->inScope());
    }

    public function test_a_soft_deleted_member_with_a_zero_tab_is_skipped(): void
    {
        $member = $this->member(active: true, deleted: true);

        $this->assertNotContains($member, $this->inScope());
    }

    /**
     * No address, no statement, and **no exception** — in practice this is an
     * anonymised member (ADR-0029 clears `email`). There is nobody to write to
     * and nothing an admin could act on, so the skip is silent: no log line, no
     * counter, no self-check finding.
     */
    public function test_a_member_with_no_address_is_skipped_without_complaint(): void
    {
        $withNull = $this->member(active: true, email: null);
        $withBlank = $this->member(active: true, email: '   ');
        $this->transaction($withNull, 1000);
        $this->transaction($withBlank, 1000);

        $scope = $this->inScope();

        $this->assertNotContains($withNull, $scope);
        $this->assertNotContains($withBlank, $scope);
    }

    /**
     * The scope is dated, like the number it will state.
     *
     * A drink bought after the boundary cannot bring an inactive member into
     * scope for the period before it — otherwise a statement would go out
     * itemising nothing, which is exactly the mismatch ADR-0039 decision 2
     * exists to prevent.
     */
    public function test_the_tab_is_judged_at_the_boundary_and_not_at_now(): void
    {
        $member = $this->member(active: false);
        $this->transaction($member, 3000, '2026-08-20 18:00:00');

        $this->assertNotContains(
            $member,
            $this->inScope(),
            'on 1 August this member owed nothing; the drink is on the September statement'
        );

        $this->assertContains(
            $member,
            $this->inScope('2026-09-01 00:00:00'),
            'by 1 September it is on their tab, and an inactive member who owes is in scope'
        );
    }

    /** The row carries what the enqueue needs, and nothing it does not. */
    public function test_a_recipient_carries_the_address_and_the_language(): void
    {
        $member = $this->member(active: true, language: 'en');

        $row = $this->rowFor($member);

        $this->assertSame($member . '@example.com', $row['email']);
        $this->assertSame('en', $row['preferred_language']);
    }

    // ── helpers ───────────────────────────────────────────────────────

    /** @return list<string> Member ids in scope at the boundary. */
    private function inScope(string $boundary = self::BOUNDARY): array
    {
        return array_column($this->scope($boundary), 'id');
    }

    /** @return list<array<string,mixed>> */
    private function scope(string $boundary = self::BOUNDARY): array
    {
        return $this->recipients->findForBoundary(new DateTimeImmutable($boundary, new DateTimeZone('UTC')));
    }

    /** @return array<string,mixed> */
    private function rowFor(string $memberId): array
    {
        foreach ($this->scope() as $row) {
            if ($row['id'] === $memberId) {
                return $row;
            }
        }

        $this->fail("member {$memberId} is not in scope");
    }

    private function member(
        bool $active,
        bool $deleted = false,
        ?string $email = 'default',
        string $language = 'de',
    ): string {
        $id = $this->track('members', $this->generateUuid());
        $address = $email === 'default' ? $id . '@example.com' : $email;

        $this->db->prepare(
            'INSERT INTO members (id, first_name, last_name, email, preferred_language, is_active, deleted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            'Scope',
            'Member',
            $address,
            $language,
            $active ? 1 : 0,
            $deleted ? '2026-07-15 10:00:00' : null,
        ]);

        return $id;
    }

    private function transaction(string $memberId, int $cents, string $occurredAt = '2026-07-20 19:00:00'): void
    {
        $id = $this->track('transactions', $this->generateUuid());
        $this->db->prepare(
            'INSERT INTO transactions (id, member_id, amount_cents, transaction_type, occurred_at, received_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$id, $memberId, $cents, 'purchase', $occurredAt, $occurredAt]);
    }

    private function track(string $table, string $id): string
    {
        $this->created[] = ['table' => $table, 'id' => $id];

        return $id;
    }
}
