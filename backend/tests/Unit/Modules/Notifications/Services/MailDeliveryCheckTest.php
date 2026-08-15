<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\DTOs\SchedulerStatusDto;
use App\Modules\Notifications\Enums\CronInterval;
use App\Modules\Notifications\Services\MailConfigService;
use App\Modules\Notifications\Services\MailDeliveryCheck;
use App\Modules\Notifications\Services\NotificationsService;
use App\Modules\Notifications\Services\SchedulerStatusService;
use App\Shared\Mail\MailDsn;
use App\Shared\Mail\MailTransportStatus;
use App\Shared\Security\SecurityFinding;
use App\Shared\Security\SecuritySelfCheck;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The diagnosis surface an alarm sends somebody to (#406).
 *
 * The rule every row obeys is the one ADR-0031 decision 3 puts on the whole
 * self-check: report what was **observed**, and never a pass for something that
 * could not be measured. So the assertions here are about what each row *says*
 * given a state, not about whether the state is one the application intended.
 */
class MailDeliveryCheckTest extends TestCase
{
    private const NOW = '2026-08-15 12:00:00';

    /**
     * @param array<string,int> $counts
     */
    private function check(
        ?string $lastRunAt = self::NOW,
        ?string $oldestDueAt = null,
        array $counts = [],
        CronInterval $declared = CronInterval::HOURLY,
        ?string $observedInterval = null,
        bool $disagrees = false,
        bool $transportConfigured = true,
        string $senderAddress = 'bar@example.org',
    ): MailDeliveryCheck {
        $mailConfig = $this->createMock(MailConfigService::class);
        $mailConfig->method('getConfig')->willReturn(new MailConfigDto(
            senderName: 'Club',
            senderAddress: $senderAddress,
            replyToAddress: null,
            headerStyle: 'paper',
            footerOrgName: 'Club',
            footerAddressLine: null,
            websiteUrl: null,
            logoUrl: null,
            cronInterval: $declared,
        ));
        $mailConfig->method('transportStatus')->willReturn(
            $transportConfigured
                ? MailTransportStatus::ok(MailDsn::parse('smtp://user:pass@mail.example.org:587'))
                : MailTransportStatus::unconfigured()
        );

        $scheduler = $this->createMock(SchedulerStatusService::class);
        $scheduler->method('status')->willReturn(new SchedulerStatusDto(
            verified: $lastRunAt !== null,
            lastRunAt: $lastRunAt,
            source: 'cli',
            lastSent: 0,
            lastFailed: 0,
            phpVersion: '8.3.0',
            missingExtensions: [],
            cliCommand: 'php /srv/backend/bin/cron.php',
            drainUrl: null,
            recommendedIntervalMinutes: 15,
            declaredInterval: $declared->value,
            observedInterval: $observedInterval,
            intervalDisagrees: $disagrees,
        ));

        $notifications = $this->createMock(NotificationsService::class);
        $notifications->method('queueCounts')->willReturn($counts + [
            'pending' => 0, 'sent' => 0, 'failed' => 0, 'superseded' => 0,
        ]);
        $notifications->method('oldestDueAt')->willReturn($oldestDueAt);

        return new MailDeliveryCheck($mailConfig, $scheduler, $notifications);
    }

    /** @return array<string,SecurityFinding> */
    private function rows(MailDeliveryCheck $check): array
    {
        $rows = [];
        foreach ($check->findings(new DateTimeImmutable(self::NOW)) as $finding) {
            $rows[$finding->id] = $finding;
        }

        return $rows;
    }

    public function test_it_reports_all_five_rows_in_the_delivery_category(): void
    {
        $rows = $this->rows($this->check());

        $this->assertSame(
            ['mail_transport', 'mail_scheduler_run', 'mail_scheduler_interval', 'mail_queue_backlog', 'mail_queue_failed'],
            array_keys($rows)
        );

        foreach ($rows as $row) {
            $this->assertSame(SecuritySelfCheck::CATEGORY_DELIVERY, $row->category);
        }
    }

    public function test_a_configured_transport_with_a_sender_passes(): void
    {
        $this->assertTrue($this->rows($this->check())['mail_transport']->isPass());
    }

    public function test_a_missing_dsn_is_reported_with_what_it_costs(): void
    {
        $row = $this->rows($this->check(transportConfigured: false))['mail_transport'];

        $this->assertSame(SecurityFinding::WARN, $row->status);
        $this->assertStringContainsString('§ 7 Abs. 3', (string) $row->remedy);
    }

    /**
     * A transport with no From address cannot send, and nothing can invent a
     * mailbox the club owns — so the two halves are one row.
     */
    public function test_a_transport_without_a_sender_address_is_not_a_pass(): void
    {
        $row = $this->rows($this->check(senderAddress: ''))['mail_transport'];

        $this->assertSame(SecurityFinding::WARN, $row->status);
        $this->assertStringContainsString('no sender address', $row->observed);
    }

    public function test_an_installation_that_never_ran_the_drain_says_so(): void
    {
        $row = $this->rows($this->check(lastRunAt: null))['mail_scheduler_run'];

        $this->assertSame(SecurityFinding::WARN, $row->status);
        $this->assertStringContainsString('no run has ever been observed', $row->observed);
    }

    public function test_a_scheduler_silent_for_more_than_two_ticks_is_reported(): void
    {
        $row = $this->rows($this->check(lastRunAt: '2026-08-15 08:00:00'))['mail_scheduler_run'];

        $this->assertSame(SecurityFinding::WARN, $row->status);
        $this->assertStringContainsString('4 hours ago', $row->observed);
    }

    /**
     * The gate lifts permanently after the first run (#405). A scheduler that
     * dies later warns; it does not lock the treasurer out of a collection, and
     * the remedy text has to say so or somebody will assume the worst.
     */
    public function test_a_stopped_scheduler_does_not_claim_settlements_are_blocked(): void
    {
        $row = $this->rows($this->check(lastRunAt: '2026-08-15 08:00:00'))['mail_scheduler_run'];

        $this->assertStringContainsString('Settlements are still allowed', (string) $row->remedy);
    }

    public function test_the_same_gap_on_a_daily_scheduler_is_healthy(): void
    {
        $row = $this->rows($this->check(
            lastRunAt: '2026-08-15 08:00:00',
            declared: CronInterval::DAILY,
        ))['mail_scheduler_run'];

        $this->assertTrue($row->isPass(), 'four hours is an ordinary morning on a daily cron');
    }

    public function test_one_run_is_not_enough_to_judge_the_interval(): void
    {
        $row = $this->rows($this->check())['mail_scheduler_interval'];

        $this->assertSame(SecurityFinding::UNKNOWN, $row->status, 'a check that cannot be run is never a pass');
    }

    public function test_a_declaration_the_scheduler_contradicts_is_reported(): void
    {
        $row = $this->rows($this->check(observedInterval: 'daily', disagrees: true))['mail_scheduler_interval'];

        $this->assertSame(SecurityFinding::WARN, $row->status);
        $this->assertStringContainsString('declared hourly, observed daily', $row->observed);
        $this->assertStringContainsString('never overridden automatically', (string) $row->remedy);
    }

    public function test_an_empty_queue_passes(): void
    {
        $this->assertTrue($this->rows($this->check())['mail_queue_backlog']->isPass());
    }

    public function test_a_message_overdue_by_three_ticks_is_reported(): void
    {
        $row = $this->rows($this->check(
            oldestDueAt: '2026-08-15 08:00:00',
            counts: ['pending' => 2],
        ))['mail_queue_backlog'];

        $this->assertSame(SecurityFinding::WARN, $row->status);
        $this->assertStringContainsString('2 pending', $row->observed);
    }

    /**
     * The regression the whole predicate exists for: a row waiting out its
     * backoff is due in the *future* and is not a backlog, however old it is.
     */
    public function test_a_message_inside_its_backoff_is_not_a_backlog(): void
    {
        $row = $this->rows($this->check(
            oldestDueAt: '2026-08-15 16:00:00',
            counts: ['pending' => 1],
        ))['mail_queue_backlog'];

        $this->assertTrue($row->isPass());
        $this->assertStringContainsString('none overdue', $row->observed);
    }

    public function test_given_up_messages_are_counted_and_named_as_a_human_task(): void
    {
        $row = $this->rows($this->check(counts: ['failed' => 3]))['mail_queue_failed'];

        $this->assertSame(SecurityFinding::WARN, $row->status);
        $this->assertSame('3 failed', $row->observed);
        $this->assertStringContainsString('address', (string) $row->remedy);
    }

    /**
     * `FAIL` means "exposes credentials or member data" everywhere else in this
     * report. A stopped scheduler is serious and is not that — and warnings
     * that cannot be told apart from leaks are warnings people skim.
     */
    public function test_no_delivery_row_is_ever_a_fail(): void
    {
        $checks = [
            $this->check(lastRunAt: null, transportConfigured: false),
            $this->check(lastRunAt: '2026-08-01 00:00:00', oldestDueAt: '2026-08-01 00:00:00', counts: ['pending' => 9, 'failed' => 4]),
        ];

        foreach ($checks as $check) {
            foreach ($this->rows($check) as $row) {
                $this->assertNotSame(SecurityFinding::FAIL, $row->status, $row->id . ' must not be a fail');
            }
        }
    }
}
