<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Enums\DrainSource;
use App\Modules\Notifications\Enums\MailStatus;
use App\Modules\Notifications\Repositories\CronHeartbeatRepository;
use App\Modules\Notifications\Services\DrainService;
use App\Modules\Notifications\Services\MailConfigService;
use App\Modules\Notifications\Services\NotificationsService;
use App\Modules\Notifications\Contracts\MailContentBuilder;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Services\MailContentRegistry;
use App\Shared\Config\PhpRuntime;
use App\Shared\Logging\Logger;
use App\Shared\Mail\InvalidMailDsnException;
use App\Shared\Mail\MailDsn;
use App\Shared\Mail\MailMessage;
use App\Shared\Mail\MailSendResult;
use App\Shared\Mail\MailTransport;
use App\Shared\Mail\MailTransportFactory;
use App\Shared\Mail\MailTransportStatus;
use App\Shared\Mail\MailLayout;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The drain loop, with the queue and the transport stood in for (#403).
 *
 * What is under test here is the *decision-making* of one run: what it does
 * with each outcome the transport reports, what it refuses to start on, and
 * what it hands back when it runs out of time. Whether the SQL claims safely is
 * a database question and lives in the Feature suite against a real MariaDB.
 */
class DrainServiceTest extends TestCase
{
    private NotificationsService&MockObject $notifications;
    private MailTransportFactory&MockObject $transportFactory;
    private MailConfigService&MockObject $mailConfig;
    private CronHeartbeatRepository&MockObject $heartbeat;
    private MailTransport&MockObject $transport;

    /**
     * What the stubbed builder does with the next row.
     *
     * A real {@see MailContentRegistry} holding a stub builder rather than a
     * mocked registry: the registry is final, and dispatching through the real
     * one is the more useful test anyway — it is the piece #410 and #438 will
     * add to.
     *
     * @var callable(array<string,mixed>): MailMessage
     */
    private $render;

    protected function setUp(): void
    {
        parent::setUp();

        $this->notifications = $this->createMock(NotificationsService::class);
        $this->transportFactory = $this->createMock(MailTransportFactory::class);
        $this->mailConfig = $this->createMock(MailConfigService::class);
        $this->heartbeat = $this->createMock(CronHeartbeatRepository::class);
        $this->transport = $this->createMock(MailTransport::class);

        $this->transport->method('describe')->willReturn('smtp://mail.example.org:587');
        $this->render = fn (array $row): MailMessage => $this->message();

        // Configured and complete unless a test says otherwise.
        $this->transportFactory->method('status')
            ->willReturn(MailTransportStatus::ok(MailDsn::parse('smtp://user:pass@mail.example.org:587')));
        $this->transportFactory->method('create')->willReturn($this->transport);
        $this->mailConfig->method('getConfig')->willReturn($this->config());
    }

    // -------------------------------------------------------------------
    // The ordinary run
    // -------------------------------------------------------------------

    public function test_sends_every_claimed_message_and_stops_when_the_queue_is_empty(): void
    {
        $this->claims([$this->row('a'), $this->row('b')], []);

                $this->transport->expects($this->exactly(2))
            ->method('send')
            ->willReturn(MailSendResult::sent('<id@example.org>'));

        $this->notifications->method('recordResult')->willReturn(MailStatus::SENT);

        $result = $this->service()->run(DrainSource::CLI);

        $this->assertSame(2, $result->claimed);
        $this->assertSame(2, $result->sent);
        $this->assertSame(0, $result->failed);
        $this->assertSame(0, $result->retrying);
        $this->assertFalse($result->budgetExhausted);
    }

    public function test_keeps_claiming_until_a_round_comes_back_empty(): void
    {
        // Three rounds: two full batches then nothing. A run that stopped after
        // the first would leave a settlement half-announced until the next tick.
        $this->claims([$this->row('a')], [$this->row('b')], []);

                $this->transport->method('send')->willReturn(MailSendResult::sent());
        $this->notifications->method('recordResult')->willReturn(MailStatus::SENT);

        $this->assertSame(2, $this->service()->run(DrainSource::CLI)->sent);
    }

    // -------------------------------------------------------------------
    // Failure classification
    // -------------------------------------------------------------------

    public function test_a_transient_failure_is_counted_as_retrying_not_failed(): void
    {
        // Greylisting: the receiving MTA refused on purpose and expects another
        // go in a quarter of an hour. Reporting it as a failure would have the
        // Kassenwart chasing ordinary operation.
        $this->claims([$this->row('a')], []);

                $this->transport->method('send')->willReturn(MailSendResult::transientFailure('450 greylisted'));
        $this->notifications->method('recordResult')->willReturn(MailStatus::PENDING);

        $result = $this->service()->run(DrainSource::CLI);

        $this->assertSame(1, $result->retrying);
        $this->assertSame(0, $result->failed);
        $this->assertSame(0, $result->sent);
    }

    public function test_an_exhausted_message_is_counted_as_failed(): void
    {
        $this->claims([$this->row('a')], []);

                $this->transport->method('send')->willReturn(MailSendResult::permanentFailure('550 no such mailbox'));
        $this->notifications->method('recordResult')->willReturn(MailStatus::FAILED);

        $result = $this->service()->run(DrainSource::CLI);

        $this->assertSame(1, $result->failed);
        $this->assertSame(0, $result->retrying);
    }

    public function test_a_message_that_cannot_be_rendered_fails_permanently(): void
    {
        // The settlement behind the row is gone, or the kind has no content
        // yet. Neither becomes true later, so retrying it three times only
        // delays the moment somebody is told.
        $this->claims([$this->row('a')], []);

        $this->render = static function (): MailMessage {
            throw new \RuntimeException('settlement vanished');
        };

        $recorded = null;
        $this->notifications->method('recordResult')
            ->willReturnCallback(function (string $id, MailSendResult $result) use (&$recorded): MailStatus {
                $recorded = $result;
                return MailStatus::FAILED;
            });

        $this->transport->expects($this->never())->method('send');

        $result = $this->service()->run(DrainSource::CLI);

        $this->assertSame(1, $result->skipped);
        $this->assertNotNull($recorded);
        $this->assertFalse($recorded->transient, 'An unrenderable message must not buy itself another attempt');
        $this->assertStringContainsString('settlement vanished', (string) $recorded->error);
    }

    public function test_a_transport_that_throws_does_not_take_the_batch_with_it(): void
    {
        $this->claims([$this->row('a'), $this->row('b')], []);
        
        $calls = 0;
        $this->transport->method('send')->willReturnCallback(function () use (&$calls): MailSendResult {
            $calls++;
            if ($calls === 1) {
                throw new \RuntimeException('socket exploded');
            }
            return MailSendResult::sent();
        });

        $statuses = [];
        $this->notifications->method('recordResult')
            ->willReturnCallback(function (string $id, MailSendResult $r) use (&$statuses): MailStatus {
                $statuses[] = $r;
                return $r->sent ? MailStatus::SENT : MailStatus::PENDING;
            });

        $result = $this->service()->run(DrainSource::CLI);

        $this->assertSame(2, $result->claimed);
        $this->assertSame(1, $result->sent, 'The second message must still be attempted');
        $this->assertTrue($statuses[0]->transient, 'An unexplained throw is a reason to try again, not to give up');
    }

    // -------------------------------------------------------------------
    // What the run refuses to start on
    // -------------------------------------------------------------------

    public function test_claims_nothing_when_no_transport_is_configured(): void
    {
        // Claiming here would burn an attempt on every queued message and, three
        // ticks later, mark the whole queue failed for a missing line in
        // config.php. The queue has to survive a misconfiguration.
        $factory = $this->createMock(MailTransportFactory::class);
        $factory->method('status')->willReturn(MailTransportStatus::unconfigured());
        $factory->expects($this->never())->method('create');

        $this->notifications->expects($this->never())->method('claimBatch');
        $this->heartbeat->expects($this->once())->method('record');

        $result = $this->service(transportFactory: $factory)->run(DrainSource::CLI);

        $this->assertSame(0, $result->claimed);
    }

    public function test_claims_nothing_when_the_dsn_is_unusable(): void
    {
        $factory = $this->createMock(MailTransportFactory::class);
        $factory->method('status')
            ->willReturn(MailTransportStatus::ok(MailDsn::parse('smtp://user:pass@mail.example.org:587')));
        $factory->method('create')->willThrowException(new InvalidMailDsnException('unsupported scheme'));

        $this->notifications->expects($this->never())->method('claimBatch');

        $this->assertSame(0, $this->service(transportFactory: $factory)->run(DrainSource::CLI)->claimed);
    }

    public function test_claims_nothing_when_there_is_no_sender_address(): void
    {
        $mailConfig = $this->createMock(MailConfigService::class);
        $mailConfig->method('getConfig')->willReturn($this->config(senderAddress: ''));

        $this->notifications->expects($this->never())->method('claimBatch');

        $this->assertSame(0, $this->service(mailConfig: $mailConfig)->run(DrainSource::CLI)->claimed);
    }

    // -------------------------------------------------------------------
    // The wall-clock budget
    // -------------------------------------------------------------------

    public function test_the_budget_stops_the_run_and_hands_back_what_it_did_not_reach(): void
    {
        // The rows a killed run leaves claimed are stuck until the stale window
        // expires. Nothing was attempted on them, so nothing needs backing off:
        // they go straight back in the queue for the next tick.
        $this->claims([$this->row('a'), $this->row('b'), $this->row('c')], []);

                $this->transport->method('send')->willReturnCallback(function (): MailSendResult {
            usleep(1_200_000);
            return MailSendResult::sent();
        });
        $this->notifications->method('recordResult')->willReturn(MailStatus::SENT);

        $released = [];
        $this->notifications->method('releaseClaim')
            ->willReturnCallback(function (string $id) use (&$released): void {
                $released[] = $id;
            });

        $result = $this->service()->run(DrainSource::CLI, budgetSeconds: 1);

        $this->assertTrue($result->budgetExhausted);
        $this->assertSame(1, $result->sent);
        $this->assertSame(1, $result->claimed, 'Only the message actually worked on counts as claimed');
        $this->assertSame(['b', 'c'], $released);
    }

    // -------------------------------------------------------------------
    // The heartbeat
    // -------------------------------------------------------------------

    public function test_every_run_records_a_heartbeat_with_this_interpreters_details(): void
    {
        // The panel's CLI PHP is frequently not the web PHP. A queue that will
        // not move is explained by that difference often enough that it belongs
        // in the row somebody is already reading.
        $this->claims([]);

        $this->heartbeat->expects($this->once())
            ->method('record')
            ->with(
                DrainSource::URL,
                0,
                0,
                PhpRuntime::version(),
                PhpRuntime::missingExtensionsSummary(),
            );

        $this->service()->run(DrainSource::URL);
    }

    public function test_a_heartbeat_that_cannot_be_written_does_not_lose_the_run(): void
    {
        $this->claims([$this->row('a')], []);
                $this->transport->method('send')->willReturn(MailSendResult::sent());
        $this->notifications->method('recordResult')->willReturn(MailStatus::SENT);

        $this->heartbeat->method('record')->willThrowException(new \RuntimeException('table is gone'));

        $this->assertSame(1, $this->service()->run(DrainSource::CLI)->sent);
    }

    public function test_the_source_is_recorded_and_never_branched_on(): void
    {
        $this->claims([$this->row('a')], []);
                $this->transport->expects($this->once())->method('send')->willReturn(MailSendResult::sent());
        $this->notifications->method('recordResult')->willReturn(MailStatus::SENT);

        $result = $this->service()->run(DrainSource::URL);

        $this->assertSame(DrainSource::URL, $result->source);
        $this->assertSame(1, $result->sent);
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    /** Successive `claimBatch()` answers, in order. */
    private function claims(array ...$rounds): void
    {
        $this->notifications->method('claimBatch')->willReturnOnConsecutiveCalls(...$rounds);
    }

    /** @return array<string,mixed> A row shaped like `claimBatch()` returns. */
    private function row(string $id): array
    {
        return [
            'id' => $id,
            'kind' => 'sepa_prenotification',
            'subject_id' => 'settlement-1',
            'dedup_key' => 'member-' . $id,
            'member_id' => 'member-' . $id,
            'recipient' => $id . '@example.org',
            'language' => 'de',
        ];
    }

    private function message(): MailMessage
    {
        return new MailMessage(
            to: 'member@example.org',
            subject: 'Vorabankündigung',
            html: '<p>Vorabankündigung</p>',
            text: 'Vorabankuendigung',
        );
    }

    private function config(string $senderAddress = 'kasse@example.org'): MailConfigDto
    {
        return new MailConfigDto(
            senderName: 'Beispielverein',
            senderAddress: $senderAddress,
            replyToAddress: 'kassenwart@example.org',
            headerStyle: MailLayout::DEFAULT_HEADER_STYLE,
            footerOrgName: 'Beispielverein e.V.',
            footerAddressLine: null,
            websiteUrl: null,
            logoUrl: null,
        );
    }

    private function service(
        ?MailTransportFactory $transportFactory = null,
        ?MailConfigService $mailConfig = null,
    ): DrainService {
        return new DrainService(
            $this->notifications,
            new MailContentRegistry(new StubContentBuilder(fn (array $row): MailMessage => ($this->render)($row))),
            $transportFactory ?? $this->transportFactory,
            $mailConfig ?? $this->mailConfig,
            $this->heartbeat,
            $this->createMock(Logger::class),
        );
    }
}

/**
 * A builder that renders whatever the test told it to.
 *
 * Claims every kind: which builder answers is `MailContentRegistry`'s own test,
 * and here the point is what the drain does with what comes back.
 */
final class StubContentBuilder implements MailContentBuilder
{
    /** @var callable(array<string,mixed>): MailMessage */
    private $render;

    public function __construct(callable $render)
    {
        $this->render = $render;
    }

    public function supports(MailKind $kind): bool
    {
        return true;
    }

    public function build(array $outboxRow): MailMessage
    {
        return ($this->render)($outboxRow);
    }
}
