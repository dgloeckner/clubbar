<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notifications;

use App\Modules\CreditLimits\Domain\CreditLimitPolicy;
use App\Modules\CreditLimits\Domain\CreditLimitStatus;
use App\Modules\CreditLimits\Services\CreditLimitConfigService;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Modules\Notifications\DTOs\DeckelStatementDataDto;
use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Repositories\DeckelStatementRepository;
use App\Modules\Notifications\Services\DeckelStatementService;
use App\Modules\Notifications\Services\MailConfigService;
use App\Shared\Mail\MailLayout;
use App\Shared\Repository\UnsettledTransactions;
use App\Shared\Security\IbanSealedBox;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Tests\Feature\DatabaseTestCase;

/**
 * What a Deckelauszug is made of (ADR-0039 decision 4, #463 step 12.1).
 *
 * Against real MariaDB, because the rules being pinned are about *which*
 * transactions belong to a statement dated 1 August, and that question is
 * answered by `UnsettledTransactions::unsettledAsOf()` in SQL. A test with a
 * hand-built array would assert the netting and quietly assume the predicate.
 *
 * Fixtures are **timelines rather than states**, as in #462: a drink on 20
 * July, a Storno on 22 July, a settlement on 3 August — then assert what an
 * August statement says. Every one of the rules below is invisible in a
 * snapshot and obvious in a timeline.
 *
 * The mail configuration is a stub. This test is about the numbers; the
 * branding is asserted in `DeckelStatementMailTest`, and pulling a real
 * `MailConfigService` in would drag the audit log and the transport factory
 * behind it for two fields nothing here reads.
 */
class DeckelStatementDataTest extends DatabaseTestCase
{
    private DeckelStatementService $service;

    /** @var list<array{table:string,column:string,id:string}> */
    private array $created = [];

    private ?string $categoryId = null;

    /** The period the statements below are dated at. */
    private const BOUNDARY = '2026-08-01 00:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $mailConfig = $this->createMock(MailConfigService::class);
        $mailConfig->method('getConfig')->willReturn(new MailConfigDto(
            senderName: 'Beispiel-Ruderverein e.V.',
            senderAddress: 'noreply@example.org',
            replyToAddress: 'kasse@example.org',
            headerStyle: MailLayout::HEADER_PAPER,
            footerOrgName: 'Beispiel-Ruderverein e.V.',
            footerAddressLine: null,
            websiteUrl: null,
            logoUrl: null,
        ));

        // The club's ceiling is configuration now (ADR-0047); the shipped
        // defaults are the constants the statement used to name.
        $creditLimits = $this->createMock(CreditLimitConfigService::class);
        $creditLimits->method('policy')->willReturn(CreditLimitPolicy::shipped());

        $this->service = new DeckelStatementService(
            new DeckelStatementRepository($this->db),
            new MembersRepository(
                $this->db,
                $this->logger,
                new IbanSealedBox(str_repeat('0', 63) . '2', 'test'),
                new EncryptionKeysRepository($this->db, $this->logger),
            ),
            $mailConfig,
            $creditLimits,
        );
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $row) {
            $this->db->prepare("DELETE FROM {$row['table']} WHERE {$row['column']} = ?")->execute([$row['id']]);
        }
        $this->created = [];
        $this->categoryId = null;

        parent::tearDown();
    }

    /**
     * The rule that makes a statement readable: a purchase and its Storno
     * cancel, and **both** lines disappear.
     *
     * Not "the Storno is shown as a negative next to it" — a member who
     * corrected one drink would then see three lines about a round they
     * remember as one, and the two that net to nothing are the two they cannot
     * act on.
     */
    public function test_a_netted_pair_disappears_from_both_the_lines_and_the_total(): void
    {
        $member = $this->member();
        $kept = $this->purchase($member, 250, '2026-07-20 19:00:00', 'Bier');
        $cancelled = $this->purchase($member, 180, '2026-07-20 19:05:00', 'Schorle');
        $this->storno($member, $cancelled, -180, '2026-07-22 11:00:00');

        $statement = $this->statementFor($member);

        $this->assertSame(['Bier'], $this->labels($statement));
        $this->assertSame(250, $statement->totalCents);
        $this->assertNotNull($kept);
    }

    /**
     * The line a member is glad to see: a Storno whose original was collected
     * in an earlier settlement has nothing to net against, so it stands alone
     * as a negative — money in their favour, and the label says where it comes
     * from.
     */
    public function test_an_orphaned_storno_stands_alone_and_names_the_settlement_it_came_from(): void
    {
        $member = $this->member();
        $original = $this->purchase($member, 250, '2026-06-14 20:00:00', 'Bier');
        $this->settle($member, $original, 250, '2026-07-03 09:00:00', '2026-07-03');
        $this->storno($member, $original, -250, '2026-07-20 19:00:00');

        $statement = $this->statementFor($member);

        $this->assertCount(1, $statement->lines);
        $this->assertSame(-250, $statement->totalCents);

        $label = $statement->lines[0]->label;
        $this->assertStringContainsString('Storno', $label);
        $this->assertStringContainsString('Bier', $label, 'the line names what it reverses');
        $this->assertStringContainsString('07/2026', $label, 'and which settlement collected it');
    }

    /**
     * The other half of the same rule: a Storno booked *after* the boundary has
     * not happened yet as far as this statement is concerned, so its original
     * stands.
     */
    public function test_a_storno_after_the_boundary_does_not_net_against_the_period_being_stated(): void
    {
        $member = $this->member();
        $purchase = $this->purchase($member, 250, '2026-07-20 19:00:00', 'Bier');
        $this->storno($member, $purchase, -250, '2026-08-04 18:00:00');

        $august = $this->statementFor($member);
        $this->assertSame(250, $august->totalCents, 'on 1 August the drink was simply bought');
        $this->assertCount(1, $august->lines);

        $september = $this->statementFor($member, '2026-09-01 00:00:00');
        $this->assertSame(0, $september->totalCents, 'by 1 September the pair nets out');
        $this->assertSame([], $september->lines);
    }

    /**
     * The cap, and the invariant that makes it safe: 150 bookings print 100
     * lines, report 50 omitted, and total over all 150.
     */
    public function test_the_cap_prints_a_hundred_lines_and_totals_over_all_of_them(): void
    {
        $member = $this->member();
        for ($i = 0; $i < 150; $i++) {
            $this->purchase($member, 100, sprintf('2026-07-%02d 19:%02d:00', ($i % 28) + 1, $i % 60), 'Bier');
        }

        $statement = $this->statementFor($member);

        $this->assertCount(DeckelStatementDataDto::MAX_LINES, $statement->lines);
        $this->assertSame(50, $statement->omittedLines);
        $this->assertSame(15_000, $statement->totalCents, 'the total is over all 150, never over the printed 100');
    }

    /** A cleared tab has no lines and nothing to apologise for. */
    public function test_a_zero_tab_produces_no_lines(): void
    {
        $member = $this->member();
        $purchase = $this->purchase($member, 250, '2026-06-14 20:00:00', 'Bier');
        $this->settle($member, $purchase, 250, '2026-07-03 09:00:00', '2026-07-03');

        $statement = $this->statementFor($member);

        $this->assertSame([], $statement->lines);
        $this->assertSame(0, $statement->totalCents);
        $this->assertSame(0, $statement->omittedLines);
        $this->assertSame(CreditLimitStatus::OK, $statement->creditStatus);
    }

    /** A member in credit gets a negative total, stated as such by the renderer. */
    public function test_a_member_in_credit_gets_a_negative_total(): void
    {
        $member = $this->member();
        $this->payout($member, -1_500, '2026-07-10 12:00:00', 'Auslage erstattet');

        $statement = $this->statementFor($member);

        $this->assertSame(-1_500, $statement->totalCents);
        $this->assertSame(CreditLimitStatus::OK, $statement->creditStatus, 'credit never uses up a limit');
    }

    /**
     * The invariant the whole design rests on, asserted directly: the sum of
     * the netted lines is the member's Deckel at that instant, as
     * `balanceAsOf()` computes it in SQL.
     *
     * Two independently written aggregates over the same money, pinned to
     * agree. If netting ever dropped a line it should not, this is what fails —
     * and it fails for every case above at once rather than for the one
     * somebody remembered to write a test for.
     */
    public function test_the_total_equals_the_dated_balance_the_scope_query_uses(): void
    {
        $member = $this->member();
        $collected = $this->purchase($member, 250, '2026-06-14 20:00:00', 'Bier');
        $this->settle($member, $collected, 250, '2026-07-03 09:00:00', '2026-07-03');
        $this->storno($member, $collected, -250, '2026-07-20 19:00:00');
        $netted = $this->purchase($member, 180, '2026-07-21 19:00:00', 'Schorle');
        $this->storno($member, $netted, -180, '2026-07-21 19:30:00');
        $this->purchase($member, 990, '2026-07-25 21:00:00', 'Bier');

        $statement = $this->statementFor($member);

        $this->assertSame($this->balanceAsOf($member), $statement->totalCents);
    }

    /** The Stand is the boundary, never today — the field the mail leads with. */
    public function test_the_statement_is_dated_at_the_boundary(): void
    {
        $statement = $this->statementFor($this->member());

        $this->assertSame('2026-08-01', $statement->boundaryDate);
    }

    /** The address is the snapshot the caller passes, never re-read from the member. */
    public function test_the_recipient_address_is_the_snapshot_and_not_the_member_row(): void
    {
        $member = $this->member();

        $statement = $this->statementFor($member, self::BOUNDARY, 'snapshot@example.org');

        $this->assertSame('snapshot@example.org', $statement->recipientAddress);
        $this->assertSame('Deckel Member', $statement->recipientName);
        $this->assertSame('Deckel', $statement->salutationName);
    }

    /** Lines are chronological, so a member can walk them against their own memory. */
    public function test_lines_are_in_the_order_the_bookings_happened(): void
    {
        $member = $this->member();
        $this->purchase($member, 250, '2026-07-25 21:00:00', 'Spaet');
        $this->purchase($member, 250, '2026-07-02 18:00:00', 'Frueh');
        $this->purchase($member, 250, '2026-07-10 20:00:00', 'Mitte');

        $this->assertSame(['Frueh', 'Mitte', 'Spaet'], $this->labels($this->statementFor($member)));
    }

    /** The band the mail warns in is computed from the stated tab, not from today's. */
    public function test_the_credit_limit_band_is_computed_from_the_stated_tab(): void
    {
        $member = $this->member();
        $this->purchase($member, 12_500, '2026-07-20 19:00:00', 'Grosse Runde');

        $statement = $this->statementFor($member);

        $this->assertSame(CreditLimitStatus::EXCEEDED, $statement->creditStatus);
        $this->assertSame(CreditLimitPolicy::DEFAULT_LIMIT_CENTS, $statement->creditLimitCents);
    }

    /**
     * A member with a ceiling of their own is told about **theirs** (ADR-0047).
     * A statement naming the club default while the terminal enforces something
     * else tells the member a number nobody is using.
     */
    public function test_a_statement_names_the_members_own_ceiling(): void
    {
        $member = $this->member();
        $this->db->prepare('UPDATE members SET credit_limit_cents = 5000 WHERE id = ?')->execute([$member]);
        $this->purchase($member, 4_800, '2026-07-20 19:00:00', 'Runde');

        $statement = $this->statementFor($member);

        $this->assertSame(5_000, $statement->creditLimitCents);
        // 4,800 is inside a 5,000 ceiling but past its 80 % band; against the
        // club's 10,000 it would have been silent.
        $this->assertSame(CreditLimitStatus::APPROACHING, $statement->creditStatus);
    }

    /**
     * `0` is the member with no ceiling, and the mail drops its limit sentence
     * on exactly that value — so the statement must carry the zero rather than
     * quietly substituting the club default.
     */
    public function test_an_uncapped_member_gets_no_ceiling_to_state(): void
    {
        $member = $this->member();
        $this->db->prepare('UPDATE members SET credit_limit_cents = 0 WHERE id = ?')->execute([$member]);
        $this->purchase($member, 50_000, '2026-07-20 19:00:00', 'Grosse Runde');

        $statement = $this->statementFor($member);

        $this->assertSame(0, $statement->creditLimitCents);
        $this->assertSame(CreditLimitStatus::OK, $statement->creditStatus);
    }

    /** A product with no translation for the member's language falls back rather than blanking. */
    public function test_a_line_is_labelled_in_the_members_language(): void
    {
        $member = $this->member();
        $this->purchase($member, 250, '2026-07-20 19:00:00', 'Bier', 'Beer');

        $this->assertSame(['Beer'], $this->labels($this->statementFor($member, self::BOUNDARY, null, MailLanguage::English)));
        $this->assertSame(['Bier'], $this->labels($this->statementFor($member)));
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function statementFor(
        string $memberId,
        string $boundary = self::BOUNDARY,
        ?string $recipient = null,
        MailLanguage $language = MailLanguage::German,
    ): DeckelStatementDataDto {
        return $this->service->forMember(
            $memberId,
            new DateTimeImmutable($boundary, new DateTimeZone('UTC')),
            $language,
            $recipient ?? ($memberId . '@example.com'),
        );
    }

    /** @return list<string> */
    private function labels(DeckelStatementDataDto $statement): array
    {
        return array_map(static fn ($line): string => $line->label, $statement->lines);
    }

    private function balanceAsOf(string $memberId, string $boundary = self::BOUNDARY): int
    {
        [$expression, $params] = UnsettledTransactions::balanceAsOf(
            new DateTimeImmutable($boundary, new DateTimeZone('UTC'))
        );

        $stmt = $this->db->prepare('SELECT ' . $expression . ' AS balance FROM members m WHERE m.id = ?');
        $stmt->execute([...$params, $memberId]);

        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['balance'];
    }

    private function member(): string
    {
        $id = $this->track('members', 'id', $this->generateUuid());
        $this->db->prepare(
            'INSERT INTO members (id, first_name, last_name, email, preferred_language, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([$id, 'Deckel', 'Member', $id . '@example.com', 'de']);

        return $id;
    }

    private function product(string $nameDe, ?string $nameEn = null): string
    {
        // The category is tracked first, so the reversed teardown deletes the
        // product before the category it points at.
        $categoryId = $this->category();
        $id = $this->track('products', 'id', $this->generateUuid());
        $this->db->prepare(
            'INSERT INTO products (id, category_id, names, price_cents, is_active) VALUES (?, ?, ?, ?, 1)'
        )->execute([
            $id,
            $categoryId,
            json_encode(['de' => $nameDe, 'en' => $nameEn ?? $nameDe], JSON_UNESCAPED_UNICODE),
            250,
        ]);

        return $id;
    }

    /** One per test — products need a category and nothing here asserts on it. */
    private function category(): string
    {
        if ($this->categoryId === null) {
            $this->categoryId = $this->track('categories', 'id', $this->generateUuid());
            $this->db->prepare('INSERT INTO categories (id, names, is_active) VALUES (?, ?, 1)')
                ->execute([$this->categoryId, json_encode(['de' => 'Deckelauszug', 'en' => 'Statement'])]);
        }

        return $this->categoryId;
    }

    private function purchase(
        string $memberId,
        int $cents,
        string $occurredAt,
        string $productName,
        ?string $productNameEn = null,
    ): string {
        return $this->transaction($memberId, $cents, $occurredAt, 'purchase', [
            'product_id' => $this->product($productName, $productNameEn),
        ]);
    }

    private function payout(string $memberId, int $cents, string $occurredAt, string $note): string
    {
        return $this->transaction($memberId, $cents, $occurredAt, 'payout', ['notes' => $note]);
    }

    private function storno(string $memberId, string $originalId, int $cents, string $occurredAt): string
    {
        return $this->transaction($memberId, $cents, $occurredAt, 'storno', [
            'related_transaction_id' => $originalId,
            'notes' => 'Falsch gebucht',
        ]);
    }

    /** @param array<string,mixed> $extra */
    private function transaction(
        string $memberId,
        int $cents,
        string $occurredAt,
        string $type,
        array $extra = [],
    ): string {
        $id = $this->track('transactions', 'id', $this->generateUuid());
        $this->db->prepare(
            'INSERT INTO transactions
                (id, member_id, product_id, amount_cents, transaction_type, notes,
                 related_transaction_id, occurred_at, received_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            $memberId,
            $extra['product_id'] ?? null,
            $cents,
            $type,
            $extra['notes'] ?? null,
            $extra['related_transaction_id'] ?? null,
            $occurredAt,
            $occurredAt,
        ]);

        return $id;
    }

    /** A live settlement created at `$createdAt` that claims one transaction. */
    private function settle(
        string $memberId,
        string $transactionId,
        int $cents,
        string $createdAt,
        string $settlementDate,
    ): string {
        $adminId = $this->track('admin_users', 'id', $this->generateUuid());
        $this->db->prepare('INSERT INTO admin_users (id, email, password_hash, is_active) VALUES (?, ?, ?, 1)')
            ->execute([$adminId, $adminId . '@example.com', password_hash('test123', PASSWORD_BCRYPT)]);

        $settlementId = $this->track('settlements', 'id', $this->generateUuid());
        $this->db->prepare(
            'INSERT INTO settlements
                (id, settlement_date, execution_date, total_amount_cents, member_count, created_by_admin_id, created_at)
             VALUES (?, ?, ?, ?, 1, ?, ?)'
        )->execute([$settlementId, $settlementDate, $settlementDate, $cents, $adminId, $createdAt]);

        $this->track('settlement_items', 'settlement_id', $settlementId);
        $this->db->prepare(
            'INSERT INTO settlement_items (settlement_id, transaction_id, active_transaction_id, member_id, amount_cents)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$settlementId, $transactionId, $transactionId, $memberId, $cents]);

        return $settlementId;
    }

    private function track(string $table, string $column, string $id): string
    {
        $this->created[] = ['table' => $table, 'column' => $column, 'id' => $id];

        return $id;
    }
}
