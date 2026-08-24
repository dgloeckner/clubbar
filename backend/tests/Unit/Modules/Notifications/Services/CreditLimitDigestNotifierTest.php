<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\Notifications\DTOs\CreditLimitDigestLineDto;
use App\Modules\Notifications\DTOs\CreditLimitDigestReportDto;
use App\Modules\Notifications\DTOs\EnqueueResultDto;
use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Enums\DigestCadence;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Services\AdminNotifier;
use App\Modules\Notifications\Services\CreditLimitDigestNotifier;
use App\Modules\Notifications\Services\CreditLimitDigestService;
use App\Modules\Notifications\Services\MailConfigService;
use App\Modules\CreditLimits\Domain\CreditLimitStatus;
use App\Shared\Logging\Logger;
use App\Shared\Time\ClubTimeZone;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * When the digest is queued, and — more often — when it is not.
 *
 * This scan runs on every scheduler tick, so most of what it must get right is
 * the refusals: an installation with the cadence off, one with no mail
 * configured, and the ordinary week in which nobody is near their ceiling. Each
 * of those must queue nothing and say why, because "no digest arrived" is
 * otherwise indistinguishable from a broken scheduler.
 */
class CreditLimitDigestNotifierTest extends TestCase
{
    private const BERLIN = 'Europe/Berlin';

    protected function setUp(): void
    {
        parent::setUp();
        putenv(ClubTimeZone::ENV_KEY . '=' . self::BERLIN);
        $_ENV[ClubTimeZone::ENV_KEY] = self::BERLIN;
    }

    protected function tearDown(): void
    {
        putenv(ClubTimeZone::ENV_KEY);
        unset($_ENV[ClubTimeZone::ENV_KEY]);
        parent::tearDown();
    }

    public function test_a_cadence_of_off_queues_nothing(): void
    {
        $adminNotifier = $this->createMock(AdminNotifier::class);
        $adminNotifier->expects($this->never())->method('warnAdmins');

        $result = $this->notifier(
            cadence: DigestCadence::OFF,
            canSend: true,
            report: $this->report(1),
            adminNotifier: $adminNotifier,
        )->run($this->now());

        $this->assertSame(0, $result->queued);
        $this->assertSame('cadence off', $result->reason);
    }

    /**
     * An installation with no mail configured behaves exactly as before this
     * feature existed. Without the gate, `NullTransport` records a *permanent
     * failure*, so every digest would show up in the Notifications page as a
     * red row on an installation whose owner never asked for mail.
     */
    public function test_an_installation_without_mail_queues_nothing(): void
    {
        $adminNotifier = $this->createMock(AdminNotifier::class);
        $adminNotifier->expects($this->never())->method('warnAdmins');

        $result = $this->notifier(
            cadence: DigestCadence::WEEKLY,
            canSend: false,
            report: $this->report(3),
            adminNotifier: $adminNotifier,
        )->run($this->now());

        $this->assertSame('mail not configured', $result->reason);
    }

    /**
     * The rule that keeps the mail worth opening: no names, no digest.
     *
     * A recipient who is sent "0 members near their limit" fifty times a year
     * has learned to file it unread by the time the fifty-first says eleven.
     */
    public function test_nobody_near_their_limit_queues_nothing(): void
    {
        $adminNotifier = $this->createMock(AdminNotifier::class);
        $adminNotifier->expects($this->never())->method('warnAdmins');

        $result = $this->notifier(
            cadence: DigestCadence::WEEKLY,
            canSend: true,
            report: $this->report(0),
            adminNotifier: $adminNotifier,
        )->run($this->now());

        $this->assertSame('nobody near their limit', $result->reason);
        $this->assertSame('2026-W35', $result->window, 'the window is still named, so a log line is diagnosable');
    }

    /** The occasion handed to the fan-out is the window, and the subject is the club's config. */
    public function test_the_digest_is_queued_against_the_current_window(): void
    {
        $adminNotifier = $this->createMock(AdminNotifier::class);
        $adminNotifier->expects($this->once())
            ->method('warnAdmins')
            ->with(
                MailKind::CREDIT_LIMIT_DIGEST,
                CreditLimitDigestNotifier::SUBJECT_ID,
                '2026-W35',
            )
            ->willReturn(new EnqueueResultDto(2, []));

        $result = $this->notifier(
            cadence: DigestCadence::WEEKLY,
            canSend: true,
            report: $this->report(3),
            adminNotifier: $adminNotifier,
        )->run($this->now());

        $this->assertSame('2026-W35', $result->window);
        $this->assertSame(2, $result->queued);
        $this->assertSame(3, $result->membersNearLimit);
    }

    /**
     * The second tick of the same window is the ordinary case, not an error:
     * the unique index refuses the insert and the scan reports it as already
     * queued rather than as nothing to do.
     */
    public function test_a_second_tick_in_the_same_window_reports_already_queued(): void
    {
        $adminNotifier = $this->createMock(AdminNotifier::class);
        $adminNotifier->method('warnAdmins')->willReturn(new EnqueueResultDto(0, [], alreadyQueued: 2));

        $result = $this->notifier(
            cadence: DigestCadence::WEEKLY,
            canSend: true,
            report: $this->report(3),
            adminNotifier: $adminNotifier,
        )->run($this->now());

        $this->assertSame(0, $result->queued);
        $this->assertSame(2, $result->alreadyQueued);
        $this->assertNull($result->reason, 'already queued is a result, not a refusal');
    }

    /** Members the cap dropped are still counted in what the scan reports. */
    public function test_the_scan_counts_the_members_the_cap_left_out(): void
    {
        $adminNotifier = $this->createMock(AdminNotifier::class);
        $adminNotifier->method('warnAdmins')->willReturn(new EnqueueResultDto(1, []));

        $result = $this->notifier(
            cadence: DigestCadence::DAILY,
            canSend: true,
            report: $this->report(2, omitted: 5),
            adminNotifier: $adminNotifier,
        )->run($this->now());

        $this->assertSame(7, $result->membersNearLimit);
    }

    /**
     * The caller is the cron tick, whose other job is draining the queue. A
     * scan that could not read a table must not stop the club's announcements
     * from going out.
     */
    public function test_a_failure_is_reported_rather_than_thrown(): void
    {
        $digestService = $this->createMock(CreditLimitDigestService::class);
        $digestService->method('collect')->willThrowException(new \RuntimeException('table is gone'));

        $notifier = new CreditLimitDigestNotifier(
            $digestService,
            $this->createMock(AdminNotifier::class),
            $this->mailConfigService(DigestCadence::WEEKLY, true),
            $this->createMock(Logger::class),
        );

        $result = $notifier->run($this->now());

        $this->assertStringContainsString('table is gone', (string) $result->reason);
        $this->assertSame(0, $result->queued);
    }

    private function notifier(
        DigestCadence $cadence,
        bool $canSend,
        CreditLimitDigestReportDto $report,
        AdminNotifier $adminNotifier,
    ): CreditLimitDigestNotifier {
        $digestService = $this->createMock(CreditLimitDigestService::class);
        $digestService->method('collect')->willReturn($report);

        return new CreditLimitDigestNotifier(
            $digestService,
            $adminNotifier,
            $this->mailConfigService($cadence, $canSend),
            $this->createMock(Logger::class),
        );
    }

    private function mailConfigService(DigestCadence $cadence, bool $canSend): MailConfigService
    {
        $service = $this->createMock(MailConfigService::class);
        $service->method('getConfig')->willReturn(new MailConfigDto(
            senderName: 'Club',
            senderAddress: 'bar@example.org',
            replyToAddress: null,
            headerStyle: 'paper',
            footerOrgName: 'Club',
            footerAddressLine: null,
            websiteUrl: null,
            logoUrl: null,
            creditLimitDigestCadence: $cadence,
        ));
        $service->method('canSend')->willReturn($canSend);

        return $service;
    }

    private function report(int $lines, int $omitted = 0): CreditLimitDigestReportDto
    {
        $rows = [];
        for ($i = 0; $i < $lines; $i++) {
            $rows[] = new CreditLimitDigestLineDto(
                memberId: 'm' . $i,
                name: 'Member ' . $i,
                balanceCents: 9_000,
                limitCents: 10_000,
                percentOfLimit: 90,
                status: CreditLimitStatus::APPROACHING,
            );
        }

        return new CreditLimitDigestReportDto(
            lines: $rows,
            clubDefaultLimitCents: 10_000,
            warnThresholdPercent: 80,
            totalOwedCents: 9_000 * $lines,
            exceededCount: 0,
            omitted: $omitted,
        );
    }

    /** A Monday in ISO week 35 of 2026. */
    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-24 09:00:00', new DateTimeZone('UTC'));
    }
}
