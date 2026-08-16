<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notifications;

use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailStatus;
use App\Modules\Notifications\Enums\StatementCadence;
use App\Modules\Notifications\Repositories\MailOutboxRepository;
use App\Modules\Notifications\Repositories\StatementRecipientsRepository;
use App\Modules\Notifications\Services\PeriodicEnqueueService;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Feature\DatabaseTestCase;

/**
 * The scheduled enqueue (ADR-0039 decision 1, #462 step 11.7).
 *
 * No sockets and no transport: this service only ever writes rows. Sending is
 * the drain's job, and P12 (#463) supplies the content that will render them.
 *
 * **Idempotency is asserted at the database, not in PHP.** Every test here runs
 * the pass and then counts rows, because a test that asserted the service
 * "checked first" would pass against exactly the lookup-then-insert race the
 * unique index exists to make impossible — and two cron ticks overlapping is
 * not a hypothetical on a host whose scheduler we do not control.
 */
class PeriodicEnqueueServiceTest extends DatabaseTestCase
{
    /** @var list<array{table:string,id:string}> */
    private array $created = [];

    private ?string $originalCadence = null;

    /** August 2026, mid-month — inside the period, as a real tick would be. */
    private const NOW = '2026-08-16 09:00:00';

    protected function tearDown(): void
    {
        // The scan covers every member in scope, which includes rows this
        // suite did not create — so the member cleanup below is not enough.
        // Left behind, these would show up in every other suite that counts the
        // queue.
        $this->db->exec("DELETE FROM mail_outbox WHERE kind = 'deckel_statement'");

        foreach (array_reverse($this->created) as $row) {
            $this->db->prepare("DELETE FROM {$row['table']} WHERE id = ?")->execute([$row['id']]);
        }
        $this->created = [];

        if ($this->originalCadence !== null) {
            $this->db->prepare('UPDATE mail_config SET statement_cadence = ? WHERE id = 1')
                ->execute([$this->originalCadence]);
            $this->originalCadence = null;
        }

        parent::tearDown();
    }

    /**
     * The acceptance criterion, stated as the database sees it: run the tick
     * twice, count one row per member.
     */
    public function test_two_ticks_in_one_period_leave_exactly_one_statement_per_member(): void
    {
        $this->cadence(StatementCadence::MONTHLY);
        $member = $this->member();

        $first = $this->service()->run($this->at(self::NOW));
        $second = $this->service()->run($this->at('2026-08-16 10:00:00'));

        $this->assertSame('2026-08', $first->period);
        $this->assertSame(1, $this->statementCount($member, '2026-08'));

        $this->assertSame(0, $second->queued, 'the second tick inserted nothing');
        $this->assertGreaterThanOrEqual(1, $second->alreadyQueued);
        $this->assertSame(
            1,
            $this->statementCount($member, '2026-08'),
            'a member has one August statement however often the cron fires'
        );
    }

    /** The queued row is what the drain will later need, and nothing more. */
    public function test_a_queued_statement_is_addressed_to_the_member_and_keyed_on_the_period(): void
    {
        $this->cadence(StatementCadence::MONTHLY);
        $member = $this->member(language: 'en');

        $this->service()->run($this->at(self::NOW));

        $row = $this->statementRow($member, '2026-08');

        $this->assertSame(MailKind::DECKEL_STATEMENT->value, $row['kind']);
        $this->assertSame($member, $row['subject_id'], 'the subject of a statement is the member');
        $this->assertSame($member, $row['member_id'], 'and the member column is what erasure keys on');
        $this->assertSame('2026-08', $row['dedup_key']);
        $this->assertSame($member . '@example.com', $row['recipient']);
        $this->assertSame('en', $row['language']);
        $this->assertSame(MailStatus::PENDING->value, $row['status']);
    }

    public function test_a_disabled_cadence_enqueues_nothing(): void
    {
        $this->cadence(StatementCadence::OFF);
        $member = $this->member();

        $result = $this->service()->run($this->at(self::NOW));

        $this->assertSame(0, $result->queued);
        $this->assertNull($result->period);
        $this->assertSame('cadence off', $result->reason);
        $this->assertSame(0, $this->statementCount($member, '2026-08'));
    }

    /**
     * What the cron prints, and what the log records.
     *
     * `bin/cron.php` runs in its own process, so nothing else measures these two
     * — and an unattended job whose one line of output is wrong is an unattended
     * job nobody can read.
     */
    public function test_the_pass_reports_itself_in_one_line(): void
    {
        $this->cadence(StatementCadence::MONTHLY);
        $this->member();

        $done = $this->service()->run($this->at(self::NOW));

        $this->assertStringContainsString('period=2026-08', $done->summary());
        $this->assertStringContainsString('queued=', $done->summary());
        $this->assertSame('2026-08', $done->toArray()['period']);

        $this->cadence(StatementCadence::OFF);

        $this->assertSame(
            'nothing due (cadence off)',
            $this->service()->run($this->at(self::NOW))->summary()
        );
    }

    /**
     * The catch-up cap. A scheduler that was off since spring comes back and
     * queues August, not April through August.
     */
    public function test_a_period_that_has_passed_enqueues_nothing(): void
    {
        $this->cadence(StatementCadence::MONTHLY);
        $member = $this->member();

        $result = $this->service()->run($this->at(self::NOW), '2026-04');

        $this->assertSame(0, $result->queued);
        $this->assertSame('period is not current', $result->reason);
        $this->assertSame(0, $this->statementCount($member, '2026-04'));
    }

    public function test_a_period_that_has_not_arrived_enqueues_nothing_either(): void
    {
        $this->cadence(StatementCadence::MONTHLY);
        $member = $this->member();

        $this->service()->run($this->at(self::NOW), '2026-09');

        $this->assertSame(0, $this->statementCount($member, '2026-09'));
    }

    /** A typo at the command line is reported, not enqueued under a nonsense key. */
    public function test_an_unreadable_period_enqueues_nothing(): void
    {
        $this->cadence(StatementCadence::MONTHLY);
        $this->member();

        $result = $this->service()->run($this->at(self::NOW), '2026-Q3');

        $this->assertSame(0, $result->queued);
        $this->assertStringContainsString('unreadable period', (string) $result->reason);
    }

    /** Naming the current period explicitly is the same pass, run deliberately. */
    public function test_naming_the_current_period_queues_it_once(): void
    {
        $this->cadence(StatementCadence::MONTHLY);
        $member = $this->member();

        $this->service()->run($this->at(self::NOW));
        $rerun = $this->service()->run($this->at(self::NOW), '2026-08');

        $this->assertSame(0, $rerun->queued, 're-running the current period is a no-op, by the index');
        $this->assertSame(1, $this->statementCount($member, '2026-08'));
    }

    public function test_a_quarterly_cadence_queues_one_statement_for_the_quarter(): void
    {
        $this->cadence(StatementCadence::QUARTERLY);
        $member = $this->member();

        $result = $this->service()->run($this->at(self::NOW));

        $this->assertSame('2026-Q3', $result->period);
        $this->assertSame(1, $this->statementCount($member, '2026-Q3'));
        $this->assertSame(0, $this->statementCount($member, '2026-08'));
    }

    /** Scope is the recipients query's ruling, and the enqueue does not second-guess it. */
    public function test_a_member_with_no_address_is_skipped_silently(): void
    {
        $this->cadence(StatementCadence::MONTHLY);
        $unreachable = $this->member(email: null);

        $result = $this->service()->run($this->at(self::NOW));

        $this->assertSame(0, $this->statementCount($unreachable, '2026-08'));
        $this->assertSame(0, $result->skipped, 'no address is not a skipped row; it never entered the scan');
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function service(): PeriodicEnqueueService
    {
        return new PeriodicEnqueueService(
            // The base class's real one, over this connection: the cadence
            // this service branches on is a column, and a double would let a
            // schema mistake pass unnoticed in a test that exists to touch it.
            $this->mailConfigService(),
            new StatementRecipientsRepository($this->db),
            new MailOutboxRepository($this->db, $this->logger),
            $this->logger,
        );
    }

    private function at(string $instant): DateTimeImmutable
    {
        return new DateTimeImmutable($instant, new DateTimeZone('UTC'));
    }

    private function cadence(StatementCadence $cadence): void
    {
        $this->originalCadence ??= (string) $this->db
            ->query('SELECT statement_cadence FROM mail_config WHERE id = 1')
            ->fetchColumn();

        $this->db->prepare('UPDATE mail_config SET statement_cadence = ? WHERE id = 1')
            ->execute([$cadence->value]);
    }

    private function member(?string $email = 'default', string $language = 'de'): string
    {
        $id = $this->generateUuid();
        $this->created[] = ['table' => 'members', 'id' => $id];

        $this->db->prepare(
            'INSERT INTO members (id, first_name, last_name, email, preferred_language, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([$id, 'Periodic', 'Enqueue', $email === 'default' ? $id . '@example.com' : $email, $language]);

        return $id;
    }

    private function statementCount(string $memberId, string $period): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM mail_outbox WHERE kind = ? AND subject_id = ? AND dedup_key = ?'
        );
        $stmt->execute([MailKind::DECKEL_STATEMENT->value, $memberId, $period]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function statementRow(string $memberId, string $period): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM mail_outbox WHERE kind = ? AND subject_id = ? AND dedup_key = ?'
        );
        $stmt->execute([MailKind::DECKEL_STATEMENT->value, $memberId, $period]);
        $row = $stmt->fetch();

        $this->assertIsArray($row, "no {$period} statement for {$memberId}");

        return $row;
    }
}
