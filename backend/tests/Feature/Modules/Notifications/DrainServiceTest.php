<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notifications;

use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Enums\DrainSource;
use App\Modules\Notifications\DTOs\MailRequestDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Enums\MailStatus;
use App\Modules\Notifications\Repositories\CronHeartbeatRepository;
use App\Modules\Notifications\Repositories\MailOutboxRepository;
use App\Modules\Notifications\Enums\CronInterval;
use App\Modules\Notifications\Services\DrainService;
use App\Modules\Notifications\Services\HeartbeatPinger;
use App\Modules\Notifications\Services\RetrySchedule;
use App\Modules\Notifications\Services\MailConfigService;
use App\Modules\Notifications\Services\MailRetention;
use App\Modules\Notifications\Services\NotificationsService;
use App\Modules\Settlements\Repositories\SettlementAnnouncementsRepository;
use App\Modules\Notifications\Contracts\MailContentBuilder;
use App\Modules\Notifications\Services\MailContentRegistry;
use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Members\Repositories\MembersRepository;
use App\Shared\Config\PhpRuntime;
use App\Shared\Http\OutboundHttpClient;
use App\Shared\Logging\Logger;
use App\Shared\Mail\MailDsn;
use App\Shared\Mail\MailLayout;
use App\Shared\Mail\MailMessage;
use App\Shared\Mail\MailSender;
use App\Shared\Mail\MailSendResult;
use App\Shared\Mail\MailTransport;
use App\Shared\Mail\MailTransportFactory;
use App\Shared\Mail\MailTransportStatus;
use App\Shared\Services\AuditService;
use Tests\Feature\DatabaseTestCase;

/**
 * The drain against a real MariaDB (ADR-0038, #403).
 *
 * The queue, the claim and the retry arithmetic are real here; the *content* is
 * not — the builder is stood in for, because what a pre-notification says has
 * its own tests (#404) and repeating them through a settlement fixture would
 * only make this file slower and less clear about what it is checking.
 *
 * The transport is a counter. That is the whole point of the concurrency test:
 * two drains over one queue must produce exactly N sends, and a count is the
 * only evidence that means anything about "never N+1".
 */
class DrainServiceTest extends DatabaseTestCase
{
    private MailOutboxRepository $outbox;
    private CronHeartbeatRepository $heartbeat;

    /** @var list<string> */
    private array $settlementIds = [];
    /** @var list<string> */
    private array $memberIds = [];
    /** @var list<string> */
    private array $adminIds = [];

    /** @var list<array{id: string, next_attempt_at: string}> */
    private array $parked = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->outbox = new MailOutboxRepository($this->db, $this->logger);
        $this->heartbeat = new CronHeartbeatRepository($this->db);
        $this->parkForeignMessages();
    }

    /**
     * Give this class a queue of its own — the same measure
     * {@see MailOutboxRepositoryTest} takes, and for the same reason.
     *
     * A drain claims whatever is due; that is the whole point of it. So an
     * assertion like "this run failed exactly one message" only means anything
     * when nothing else is waiting — and something else routinely is, because
     * the API suite creates settlements against this same database and every
     * one of them queues an announcement that nothing drains.
     *
     * Anything already due is pushed a day out for the duration and put back
     * exactly as it was afterwards. Deleting would be simpler and wrong: those
     * rows are somebody else's fixture.
     */
    private function parkForeignMessages(): void
    {
        $due = $this->db
            ->query("SELECT id, next_attempt_at FROM mail_outbox WHERE status = 'pending'")
            ->fetchAll();

        foreach ($due as $row) {
            $this->parked[] = ['id' => (string) $row['id'], 'next_attempt_at' => (string) $row['next_attempt_at']];
        }

        if ($this->parked !== []) {
            $this->db->exec(
                "UPDATE mail_outbox SET next_attempt_at = DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE status = 'pending'"
            );
        }
    }

    private function unparkForeignMessages(): void
    {
        $stmt = $this->db->prepare('UPDATE mail_outbox SET next_attempt_at = ? WHERE id = ?');
        foreach ($this->parked as $row) {
            $stmt->execute([$row['next_attempt_at'], $row['id']]);
        }
        $this->parked = [];
    }

    protected function tearDown(): void
    {
        $this->unparkForeignMessages();

        foreach ($this->settlementIds as $id) {
            $this->db->prepare('DELETE FROM settlements WHERE id = ?')->execute([$id]);
        }
        foreach ($this->memberIds as $id) {
            $this->db->prepare('DELETE FROM members WHERE id = ?')->execute([$id]);
        }
        foreach ($this->adminIds as $id) {
            $this->db->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
        }

        parent::tearDown();
    }

    // -------------------------------------------------------------------
    // Concurrency
    // -------------------------------------------------------------------

    /**
     * Two drains over the same queue send exactly N mails, never N+1.
     *
     * The interleaving is the one that actually happens on a host: a run is
     * still working through its claimed batch when the next cron tick starts a
     * second one. The second must find nothing, and when the first finishes the
     * total must be N — not N plus the messages the second one duplicated.
     */
    public function test_two_concurrent_drains_send_each_message_exactly_once(): void
    {
        [$settlementId] = $this->queue(6);

        $counter = new CountingTransport();
        $first = $this->service($counter, batchSize: 6);
        $second = $this->service($counter, batchSize: 6);

        $firstResult = $first->run(DrainSource::CLI);
        $secondResult = $second->run(DrainSource::URL);

        $this->assertSame(6, $firstResult->sent);
        $this->assertSame(0, $secondResult->sent, 'the second run may not re-send a drained queue');
        $this->assertSame(6, $counter->sends, 'exactly N, never N+1');
        $this->assertSame(6, $this->countWithStatus($settlementId, MailStatus::SENT));
    }

    public function test_a_run_that_starts_mid_flight_cannot_take_claimed_messages(): void
    {
        [$settlementId] = $this->queue(4);

        // A drain that has claimed its batch and is somewhere inside the SMTP
        // conversation. Nothing is marked yet.
        $inFlight = $this->outbox->claimBatch(4);
        $this->assertCount(4, $inFlight);

        $counter = new CountingTransport();
        $result = $this->service($counter)->run(DrainSource::URL);

        $this->assertSame(0, $result->claimed);
        $this->assertSame(0, $counter->sends);
        $this->assertSame(4, $this->countWithStatus($settlementId, MailStatus::PENDING));
    }

    /**
     * A killed run must not strand its rows. After the stale window a fresh
     * drain picks them up — otherwise a host that reaps a long-running cron
     * would leave a settlement permanently half-announced.
     */
    public function test_a_stale_claim_from_a_killed_run_is_drained_by_the_next_one(): void
    {
        [$settlementId] = $this->queue(2);

        $abandoned = $this->outbox->claimBatch(2);
        $this->assertCount(2, $abandoned);

        $this->db->prepare('UPDATE mail_outbox SET claimed_at = DATE_SUB(NOW(), INTERVAL 30 MINUTE) WHERE subject_id = ?')
            ->execute([$settlementId]);

        $counter = new CountingTransport();
        $result = $this->service($counter)->run(DrainSource::CLI);

        $this->assertSame(2, $result->sent);
        $this->assertSame(2, $this->countWithStatus($settlementId, MailStatus::SENT));
    }

    // -------------------------------------------------------------------
    // Retry and the cap
    // -------------------------------------------------------------------

    /**
     * A transient failure schedules a retry; the cap flips the row to `failed`
     * and stops.
     *
     * Greylisting is why this exists — the receiving MTA rejects the first
     * attempt on purpose. Four attempts on a ladder measured in scheduler ticks
     * (ADR-0039 decision 5), then the reason is put in front of the Kassenwart
     * and nothing chases it further (ADR-0038 rule 6).
     */
    public function test_a_transient_failure_retries_until_the_cap_then_fails(): void
    {
        [$settlementId, $memberIds] = $this->queue(1);

        $transport = new CountingTransport(MailSendResult::transientFailure('451 greylisted, try again'));
        $service = $this->service($transport);

        // Attempt 1
        $this->assertSame(1, $service->run(DrainSource::CLI)->retrying);
        $row = $this->rowFor($settlementId, $memberIds[0]);
        $this->assertSame(MailStatus::PENDING->value, $row['status']);
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertStringContainsString('greylisted', (string) $row['last_error']);

        // The backoff is real: the same run started again finds nothing due.
        $this->assertSame(0, $service->run(DrainSource::CLI)->claimed, 'the backoff must hold the message back');

        // Attempts 2 and 3, each after its rung of the ladder has elapsed.
        for ($attempt = 2; $attempt < RetrySchedule::MAX_ATTEMPTS; $attempt++) {
            $this->fastForward($settlementId);
            $this->assertSame(1, $service->run(DrainSource::CLI)->retrying);
            $this->assertSame($attempt, (int) $this->rowFor($settlementId, $memberIds[0])['attempts']);
        }

        // The last attempt — no rung after it.
        $this->fastForward($settlementId);
        $this->assertSame(1, $service->run(DrainSource::CLI)->failed);

        $row = $this->rowFor($settlementId, $memberIds[0]);
        $this->assertSame(MailStatus::FAILED->value, $row['status']);
        $this->assertSame(RetrySchedule::MAX_ATTEMPTS, (int) $row['attempts']);

        // And it stops: a failed message is not claimed again, however many
        // ticks go by.
        $this->fastForward($settlementId);
        $this->assertSame(0, $service->run(DrainSource::CLI)->claimed);
        $this->assertSame(
            RetrySchedule::MAX_ATTEMPTS,
            $transport->sends,
            'exactly MAX_ATTEMPTS attempts were made'
        );
    }

    public function test_a_permanent_refusal_does_not_get_a_second_attempt(): void
    {
        [$settlementId, $memberIds] = $this->queue(1);

        $transport = new CountingTransport(MailSendResult::permanentFailure('550 no such mailbox'));
        $result = $this->service($transport)->run(DrainSource::CLI);

        $this->assertSame(1, $result->failed);
        $row = $this->rowFor($settlementId, $memberIds[0]);
        $this->assertSame(MailStatus::FAILED->value, $row['status']);
        $this->assertStringContainsString('550', (string) $row['last_error']);
    }

    // -------------------------------------------------------------------
    // The budget
    // -------------------------------------------------------------------

    public function test_messages_the_budget_cut_short_are_due_again_immediately(): void
    {
        [$settlementId] = $this->queue(3);

        $transport = new CountingTransport(sleepMicroseconds: 1_200_000);
        $result = $this->service($transport)->run(DrainSource::CLI, budgetSeconds: 1);

        $this->assertTrue($result->budgetExhausted);
        $this->assertSame(1, $transport->sends);

        // Released, not left claimed: nothing was attempted on them, so nothing
        // needs backing off and the next tick takes them straight away.
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM mail_outbox WHERE subject_id = ? AND status = ? AND claim_token IS NULL'
        );
        $stmt->execute([$settlementId, MailStatus::PENDING->value]);
        $this->assertSame(2, (int) $stmt->fetchColumn());

        $next = $this->service(new CountingTransport())->run(DrainSource::CLI);
        $this->assertSame(2, $next->sent);
    }

    // -------------------------------------------------------------------
    // The heartbeat
    // -------------------------------------------------------------------

    public function test_a_run_stamps_the_heartbeat_with_its_source_and_interpreter(): void
    {
        [$settlementId] = $this->queue(2);

        $this->service(new CountingTransport())->run(DrainSource::CLI);

        $row = $this->heartbeat->get();
        $this->assertNotNull($row);
        $this->assertNotNull($row['last_run_at']);
        $this->assertSame(DrainSource::CLI->value, $row['source']);
        $this->assertSame(2, (int) $row['sent']);
        $this->assertSame(0, (int) $row['failed']);
        $this->assertSame(PhpRuntime::version(), $row['php_version']);
        // '' means checked and complete; NULL would mean never checked.
        $this->assertSame(PhpRuntime::missingExtensionsSummary(), (string) $row['missing_extensions']);
        $this->assertTrue($this->heartbeat->hasEverRun());
    }

    public function test_an_idle_run_still_records_a_heartbeat(): void
    {
        // Eleven months of the year this is the only evidence the scheduler
        // works, so it has to count as evidence.
        $this->db->prepare('UPDATE cron_heartbeat SET source = ?, last_run_at = NULL WHERE id = 1')
            ->execute([DrainSource::URL->value]);

        $this->service(new CountingTransport())->run(DrainSource::CLI);

        $row = $this->heartbeat->get();
        $this->assertNotNull($row['last_run_at']);
        $this->assertSame(DrainSource::CLI->value, $row['source']);
    }


    // -------------------------------------------------------------------
    // The record that outlives the queue (#408)
    // -------------------------------------------------------------------

    /**
     * A delivered announcement leaves a settlement-side row carrying the
     * queue's own timestamp — the fact the retention tier keeps once the queue
     * row, and the address on it, have been pruned.
     */
    public function test_a_delivered_announcement_is_recorded_on_the_settlement(): void
    {
        [$settlementId, $memberIds] = $this->queue(2);

        $this->service(new CountingTransport())->run(DrainSource::CLI);

        $recorded = (new SettlementAnnouncementsRepository($this->db, $this->logger))
            ->findBySettlementId($settlementId);

        $this->assertCount(2, $recorded);
        // Compared as a set: both rows are written inside the same second, so
        // any ordering the query imposes on them is arbitrary (Pattern 003).
        $recordedMembers = array_map(static fn(array $r): string => $r['member_id'], $recorded);
        sort($recordedMembers);
        $expected = $memberIds;
        sort($expected);
        $this->assertSame($expected, $recordedMembers);

        // Copied from the queue row, not read from a second clock — while both
        // exist they must not be able to disagree.
        foreach ($recorded as $row) {
            $this->assertSame(MailKind::SEPA_PRENOTIFICATION->value, $row['kind']);

            $stmt = $this->db->prepare(
                'SELECT sent_at FROM mail_outbox WHERE subject_id = ? AND member_id = ? AND kind = ?'
            );
            $stmt->execute([$settlementId, $row['member_id'], MailKind::SEPA_PRENOTIFICATION->value]);
            $this->assertSame((string) $stmt->fetchColumn(), $row['sent_at']);
        }
    }

    /**
     * A message that never left records nothing. The row means "this member was
     * announced to", and its whole value is that it is absent when that is not
     * true.
     */
    public function test_a_failed_send_records_no_announcement(): void
    {
        [$settlementId] = $this->queue(1);

        $this->service(new CountingTransport(MailSendResult::permanentFailure('550 no such mailbox')))
            ->run(DrainSource::CLI);

        $this->assertSame(
            [],
            (new SettlementAnnouncementsRepository($this->db, $this->logger))->findBySettlementId($settlementId),
        );
    }

    /**
     * The scheduler is the only unattended process this system has, so it is
     * where ADR-0029's pruning happens — and what it takes is delivered rows
     * past their window, leaving the settlement-side record behind.
     */
    public function test_a_run_prunes_delivered_rows_and_keeps_the_settlement_record(): void
    {
        [$settlementId, $memberIds] = $this->queue(1);

        $this->service(new CountingTransport())->run(DrainSource::CLI);

        // Age the delivered row past its window, then run again. Written
        // directly rather than waited for — no test owns a clock.
        $this->db->prepare(
            'UPDATE mail_outbox SET sent_at = DATE_SUB(NOW(), INTERVAL ? DAY) WHERE subject_id = ?'
        )->execute([MailRetention::sentDaysFor(MailKind::SEPA_PRENOTIFICATION) + 1, $settlementId]);

        $result = $this->service(new CountingTransport())->run(DrainSource::CLI);

        $this->assertGreaterThanOrEqual(1, $result->pruned);
        $this->assertSame([], $this->outbox->findBySubjectId($settlementId), 'the queue row is gone');

        $recorded = (new SettlementAnnouncementsRepository($this->db, $this->logger))
            ->findBySettlementId($settlementId);
        $this->assertCount(1, $recorded, 'the proof that the member was announced to is not');
        $this->assertSame($memberIds[0], $recorded[0]['member_id']);
    }

    /**
     * A Deckelauszug is delivered, and leaves **no** settlement record — which
     * is the whole of the regression, because getting this wrong did not
     * produce a wrong row, it stopped the queue (#463, ADR-0039).
     *
     * `settlement_announcements` is the durable proof that a member was
     * announced to about a *settlement*. A statement's `subject_id` is the
     * member, so writing one there put a member id in `settlement_id` under a
     * `kind` the enum does not have — and MariaDB's truncation warning came
     * back as a `PDOException` that `DrainService::run()` caught as an aborted
     * run. The message had already gone out; the other 199 rows in the batch
     * stayed claimed and unprocessed, and the run reported `claimed=0 sent=0`.
     * From the outside that is indistinguishable from an idle tick.
     *
     * So the assertions are in that order deliberately: the mail leaves, the
     * run reports what it did, and the record is absent. A test that only
     * checked the absent record would have passed against a drain that aborted.
     */
    public function test_a_delivered_statement_leaves_no_settlement_record_and_does_not_abort_the_run(): void
    {
        $memberId = $this->member();
        $this->outbox->enqueue(MailRequestDto::forStatement(
            memberId: $memberId,
            recipient: $memberId . '@example.com',
            language: MailLanguage::German,
            period: '2026-08',
        ));

        // Strict, and it is what makes this a regression test rather than a
        // description: not writing the record and *failing* to write it leave
        // an identical database, so the absence of the error is the assertion.
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->never())->method('error');

        $transport = new CountingTransport();
        $result = $this->service($transport, DrainService::DEFAULT_BATCH_SIZE, $logger)->run(DrainSource::CLI);

        $this->assertSame(1, $transport->sends, 'the statement is delivered like any other message');
        $this->assertSame(1, $result->sent, 'an aborted run reports zero, and that is what this guards');
        $this->assertSame(MailStatus::SENT->value, $this->rowFor($memberId, $memberId)['status']);

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM settlement_announcements WHERE settlement_id = ?');
        $stmt->execute([$memberId]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'a statement announces nothing and proves nothing');
    }

    /**
     * The batch survives one message's bookkeeping going wrong.
     *
     * The promise `recordAnnouncement()` already made for a missing `sent_at` —
     * *"failing the drain over a lost bookkeeping row would turn one missing
     * record into a batch that never went out"* — now covers the write itself.
     * Simulated the only way it can be from here: the announcements table is
     * dropped for the length of the run, so the INSERT fails for real.
     */
    public function test_a_refused_announcement_record_does_not_stop_the_batch(): void
    {
        [$settlementId, $memberIds] = $this->queue(3);

        $this->db->exec('RENAME TABLE settlement_announcements TO settlement_announcements_hidden');

        try {
            $transport = new CountingTransport();
            $result = $this->service($transport)->run(DrainSource::CLI);
        } finally {
            $this->db->exec('RENAME TABLE settlement_announcements_hidden TO settlement_announcements');
        }

        $this->assertSame(3, $transport->sends, 'every message in the batch still went out');
        $this->assertSame(3, $result->sent);
        $this->assertSame(3, $this->countWithStatus($settlementId, MailStatus::SENT));
        $this->assertCount(3, $memberIds);
    }

    /**
     * Retention does not wait for the mail server.
     *
     * Pruning deletes what has already been delivered, so a run that cannot
     * send anything is still a run that can do it — and an installation whose
     * SMTP has been wrong for a month must not also have stopped clearing
     * addresses it no longer needs.
     */
    public function test_a_run_with_no_transport_still_prunes(): void
    {
        [$settlementId] = $this->queue(1);
        $this->db->prepare(
            "UPDATE mail_outbox SET status = 'sent', sent_at = DATE_SUB(NOW(), INTERVAL ? DAY) WHERE subject_id = ?"
        )->execute([MailRetention::sentDaysFor(MailKind::SEPA_PRENOTIFICATION) + 1, $settlementId]);

        $result = $this->serviceWithoutTransport()->run(DrainSource::CLI);

        $this->assertSame(0, $result->claimed, 'an unsendable configuration claims nothing');
        $this->assertGreaterThanOrEqual(1, $result->pruned);
        $this->assertSame([], $this->outbox->findBySubjectId($settlementId));
    }

    // -------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------

    private function service(
        MailTransport $transport,
        int $batchSize = DrainService::DEFAULT_BATCH_SIZE,
        ?Logger $bookkeepingLogger = null,
    ): DrainService {
        // The queue's own logger is normally the suite's silent mock. One test
        // hands in a strict one instead: "the drain recorded nothing" and "the
        // drain tried, failed, and swallowed it" leave the same database, and
        // the log line is the only thing that tells them apart.
        $queueLogger = $bookkeepingLogger ?? $this->logger;

        $notifications = new NotificationsService(
            new MailOutboxRepository($this->db, $queueLogger),
            // Only the enqueue path reads members and admins; a drain never does.
            $this->createMock(MembersRepository::class),
            $this->createMock(AuditService::class),
            $this->createMock(AdminUsersRepository::class),
            // A delivered announcement leaves a durable settlement-side record
            // (#408); this suite drains real rows, so it gets the real one.
            new SettlementAnnouncementsRepository($this->db, $queueLogger),
            $queueLogger,
        );

        // A real registry with a stub builder: the registry is final, and the
        // dispatch is worth exercising rather than mocking away.
        $content = new MailContentRegistry(new FixedContentBuilder());

        $transportFactory = $this->createMock(MailTransportFactory::class);
        $transportFactory->method('status')
            ->willReturn(MailTransportStatus::ok(MailDsn::parse('smtp://user:pass@mail.example.org:587')));
        $transportFactory->method('create')->willReturn($transport);

        $mailConfig = $this->createMock(MailConfigService::class);
        $mailConfig->method('getConfig')->willReturn(new MailConfigDto(
            senderName: 'Testverein',
            senderAddress: 'kasse@example.org',
            replyToAddress: null,
            headerStyle: MailLayout::DEFAULT_HEADER_STYLE,
            footerOrgName: 'Testverein e.V.',
            footerAddressLine: null,
            websiteUrl: null,
            logoUrl: null,
            cronInterval: CronInterval::HOURLY,
        ));

        return new DrainService(
            $notifications,
            $content,
            $transportFactory,
            $mailConfig,
            $this->heartbeat,
            new HeartbeatPinger($this->createMock(OutboundHttpClient::class), $this->createMock(Logger::class), null),
            $this->createMock(Logger::class),
            $batchSize,
        );
    }

    /**
     * A drain on an installation whose mail DSN is unset — the state in which
     * the run claims nothing and still has retention to carry out.
     */
    private function serviceWithoutTransport(): DrainService
    {
        $transportFactory = $this->createMock(MailTransportFactory::class);
        // `status()` unconfigured is what the drain reads; `create()` is never
        // reached, and its signature does not permit null anyway.
        $transportFactory->method('status')->willReturn(MailTransportStatus::unconfigured());

        $mailConfig = $this->createMock(MailConfigService::class);
        $mailConfig->method('getConfig')->willReturn(new MailConfigDto(
            senderName: 'Testverein',
            senderAddress: 'kasse@example.org',
            replyToAddress: null,
            headerStyle: MailLayout::DEFAULT_HEADER_STYLE,
            footerOrgName: 'Testverein e.V.',
            footerAddressLine: null,
            websiteUrl: null,
            logoUrl: null,
            cronInterval: CronInterval::HOURLY,
        ));

        return new DrainService(
            new NotificationsService(
                new MailOutboxRepository($this->db, $this->logger),
                $this->createMock(MembersRepository::class),
                $this->createMock(AuditService::class),
                $this->createMock(AdminUsersRepository::class),
                new SettlementAnnouncementsRepository($this->db, $this->logger),
                $this->logger,
            ),
            new MailContentRegistry(new FixedContentBuilder()),
            $transportFactory,
            $mailConfig,
            $this->heartbeat,
            new HeartbeatPinger($this->createMock(OutboundHttpClient::class), $this->createMock(Logger::class), null),
            $this->createMock(Logger::class),
        );
    }

    /** @return array{0: string, 1: list<string>} settlement id and its member ids */
    private function queue(int $count): array
    {
        $settlementId = $this->settlement();
        $memberIds = [];

        for ($i = 0; $i < $count; $i++) {
            $memberId = $this->member();
            $memberIds[] = $memberId;
            $this->outbox->enqueue(MailRequestDto::forMember(
                kind: MailKind::SEPA_PRENOTIFICATION,
                settlementId: $settlementId,
                memberId: $memberId,
                recipient: $memberId . '@example.com',
                language: MailLanguage::German,
            ));
        }

        return [$settlementId, $memberIds];
    }

    private function member(): string
    {
        $id = $this->generateUuid();
        $this->memberIds[] = $id;

        $this->db->prepare(
            'INSERT INTO members (id, first_name, last_name, email, preferred_language, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([$id, 'Test', 'Drain', $id . '@example.com', 'de']);

        return $id;
    }

    private function settlement(): string
    {
        $adminId = $this->generateUuid();
        $this->adminIds[] = $adminId;
        $this->db->prepare('INSERT INTO admin_users (id, email, password_hash, is_active) VALUES (?, ?, ?, 1)')
            ->execute([$adminId, $adminId . '@example.com', password_hash('test123', PASSWORD_BCRYPT)]);

        $id = $this->generateUuid();
        $this->settlementIds[] = $id;
        $this->db->prepare(
            'INSERT INTO settlements (id, settlement_date, execution_date, total_amount_cents, member_count, created_by_admin_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$id, '2026-08-01', '2026-08-21', 350, 1, $adminId]);

        return $id;
    }

    /** Pull a backed-off message forward, as fifteen minutes of real time would. */
    private function fastForward(string $settlementId): void
    {
        $this->db->prepare('UPDATE mail_outbox SET next_attempt_at = NOW() WHERE subject_id = ?')
            ->execute([$settlementId]);
    }

    private function countWithStatus(string $settlementId, MailStatus $status): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM mail_outbox WHERE subject_id = ? AND status = ?');
        $stmt->execute([$settlementId, $status->value]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function rowFor(string $settlementId, string $memberId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM mail_outbox WHERE subject_id = ? AND member_id = ?');
        $stmt->execute([$settlementId, $memberId]);

        return $stmt->fetch() ?: [];
    }
}

/**
 * A transport that counts instead of connecting.
 *
 * "Never N+1" is a statement about how many times `send()` was reached, and no
 * assertion about database rows can substitute for counting the calls.
 */
final class CountingTransport implements MailTransport
{
    public int $sends = 0;

    /** @var list<string> */
    public array $recipients = [];

    public function __construct(
        private ?MailSendResult $answer = null,
        private int $sleepMicroseconds = 0,
    ) {}

    public function send(MailMessage $message, MailSender $sender): MailSendResult
    {
        $this->sends++;
        $this->recipients[] = $message->to;

        if ($this->sleepMicroseconds > 0) {
            usleep($this->sleepMicroseconds);
        }

        return $this->answer ?? MailSendResult::sent('<' . $this->sends . '@test.invalid>');
    }

    public function describe(): string
    {
        return 'counting transport (test)';
    }
}

/**
 * Renders a minimal message for any kind, addressed to the row's recipient.
 *
 * The content itself has its own tests (#404); what this file is about is the
 * queue underneath it.
 */
final class FixedContentBuilder implements MailContentBuilder
{
    public function supports(MailKind $kind): bool
    {
        return true;
    }

    public function build(array $outboxRow): MailMessage
    {
        return new MailMessage(
            to: (string) $outboxRow['recipient'],
            subject: 'Vorabankündigung',
            html: '<p>Vorabankündigung</p>',
            text: 'Vorabankuendigung',
        );
    }
}
