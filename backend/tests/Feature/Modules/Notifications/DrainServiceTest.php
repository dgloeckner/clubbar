<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notifications;

use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Enums\DrainSource;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailStatus;
use App\Modules\Notifications\Repositories\CronHeartbeatRepository;
use App\Modules\Notifications\Repositories\MailOutboxRepository;
use App\Modules\Notifications\Services\DrainService;
use App\Modules\Notifications\Services\MailConfigService;
use App\Modules\Notifications\Services\NotificationsService;
use App\Modules\Notifications\Services\SettlementMailBuilder;
use App\Modules\Members\Repositories\MembersRepository;
use App\Shared\Config\PhpRuntime;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->outbox = new MailOutboxRepository($this->db, $this->logger);
        $this->heartbeat = new CronHeartbeatRepository($this->db);
    }

    protected function tearDown(): void
    {
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

        $this->db->prepare('UPDATE mail_outbox SET claimed_at = DATE_SUB(NOW(), INTERVAL 30 MINUTE) WHERE settlement_id = ?')
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
     * attempt on purpose. Three attempts, then the reason is put in front of
     * the Kassenwart and nothing chases it further (ADR-0038 rule 6).
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

        // Attempt 2
        $this->fastForward($settlementId);
        $this->assertSame(1, $service->run(DrainSource::CLI)->retrying);
        $this->assertSame(2, (int) $this->rowFor($settlementId, $memberIds[0])['attempts']);

        // Attempt 3 — the cap
        $this->fastForward($settlementId);
        $this->assertSame(1, $service->run(DrainSource::CLI)->failed);

        $row = $this->rowFor($settlementId, $memberIds[0]);
        $this->assertSame(MailStatus::FAILED->value, $row['status']);
        $this->assertSame(NotificationsService::MAX_ATTEMPTS, (int) $row['attempts']);

        // And it stops: a failed message is not claimed again, however many
        // ticks go by.
        $this->fastForward($settlementId);
        $this->assertSame(0, $service->run(DrainSource::CLI)->claimed);
        $this->assertSame(3, $transport->sends, 'exactly MAX_ATTEMPTS attempts were made');
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
            'SELECT COUNT(*) FROM mail_outbox WHERE settlement_id = ? AND status = ? AND claim_token IS NULL'
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
    // Fixtures
    // -------------------------------------------------------------------

    private function service(
        MailTransport $transport,
        int $batchSize = DrainService::DEFAULT_BATCH_SIZE,
    ): DrainService {
        $notifications = new NotificationsService(
            new MailOutboxRepository($this->db, $this->logger),
            // Only the enqueue path reads members; a drain never does.
            $this->createMock(MembersRepository::class),
            $this->createMock(AuditService::class),
        );

        $builder = $this->createMock(SettlementMailBuilder::class);
        $builder->method('build')->willReturnCallback(static fn (array $row): MailMessage => new MailMessage(
            to: (string) $row['recipient'],
            subject: 'Vorabankündigung',
            html: '<p>Vorabankündigung</p>',
            text: 'Vorabankuendigung',
        ));

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
        ));

        return new DrainService(
            $notifications,
            $builder,
            $transportFactory,
            $mailConfig,
            $this->heartbeat,
            $this->createMock(Logger::class),
            $batchSize,
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
            $this->outbox->enqueue(
                MailKind::SEPA_PRENOTIFICATION,
                $settlementId,
                $memberId,
                $memberId . '@example.com',
                'de',
            );
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
        $this->db->prepare('UPDATE mail_outbox SET next_attempt_at = NOW() WHERE settlement_id = ?')
            ->execute([$settlementId]);
    }

    private function countWithStatus(string $settlementId, MailStatus $status): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM mail_outbox WHERE settlement_id = ? AND status = ?');
        $stmt->execute([$settlementId, $status->value]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function rowFor(string $settlementId, string $memberId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM mail_outbox WHERE settlement_id = ? AND member_id = ?');
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
