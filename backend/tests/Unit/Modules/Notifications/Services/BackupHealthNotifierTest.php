<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\Backups\Domain\BackupRetention;
use App\Modules\Backups\Services\BackupSchedule;
use App\Modules\Backups\Services\BackupStatusCheck;
use App\Modules\Notifications\DTOs\EnqueueResultDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Services\AdminNotifier;
use App\Modules\Notifications\Services\BackupHealthNotifier;
use App\Modules\Notifications\Services\MailConfigService;
use App\Shared\Logging\Logger;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempTree;

/**
 * The alarm a club without an external monitor actually has (#693, ADR-0049).
 *
 * `backup.heartbeat_url` (#712) is better where somebody has set one up, and it
 * is optional; most clubs will not have one. So this scan is the mechanism that
 * assumes nothing beyond an address — and the properties worth pinning are less
 * about *what it says* than about **when it stays quiet**.
 *
 * Part of #693, epic #686.
 */
class BackupHealthNotifierTest extends TestCase
{
    use TempTree;

    private const KEYS = 'admin:bb637d8ec1cb92bca0467e59faa6d61f6b7f8088103e5b89d7afdc01f1efa45c';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = self::makeTempTree('backup-health');
    }

    protected function tearDown(): void
    {
        self::removeTempTree($this->dir);
    }

    /**
     * **The failure this whole milestone exists for.** A cron never added to
     * the hosting panel, or dropped in a tariff migration, produces no error
     * anybody sees — and until something says so it is indistinguishable from a
     * job running fine every night.
     */
    public function test_a_backup_that_never_ran_reaches_the_admin(): void
    {
        $notifier = $this->notifier($this->expecting(queued: 2));

        $result = $notifier->run(new DateTimeImmutable('2026-08-27 03:00:00'));

        $this->assertSame(1, $result->failingRows);
        $this->assertSame(2, $result->queued);
    }

    /**
     * **Nothing on success**, which is the property the channel's usefulness
     * rests on. A recipient who receives "backups are fine" fifty times has
     * learned to file the fifty-first unread, and the fifty-first is the one
     * that matters. Silence has to mean the condition does not hold.
     */
    public function test_a_healthy_backup_queues_nothing_at_all(): void
    {
        $this->archive(hoursAgo: 3);

        $notifier = $this->notifier($this->expectingNoCall());

        $result = $notifier->run(new DateTimeImmutable('2026-08-27 03:00:00'));

        $this->assertSame(0, $result->failingRows);
        $this->assertSame(0, $result->queued);
        $this->assertNull($result->reason, 'a healthy scan ran; it simply had nothing to say');
    }

    /**
     * Backups off is a legitimate choice (ADR-0049 decision 2), not a fault.
     * A club that never generated a keypair has decided; nagging them daily
     * forever is how a warning channel earns a filter rule.
     */
    public function test_backups_switched_off_are_not_a_fault(): void
    {
        $notifier = $this->notifier($this->expectingNoCall(), keys: '');

        $result = $notifier->run(new DateTimeImmutable('2026-08-27 03:00:00'));

        $this->assertSame('backups not configured', $result->reason);
    }

    /**
     * The same gate the credential scan uses, for the same reason:
     * `NullTransport` records a **permanent failure**, so every warning queued
     * on an installation with no mail configured would land in the
     * Notifications page as a red row on an installation whose owner never
     * asked for mail.
     */
    public function test_an_installation_with_no_mail_queues_nothing(): void
    {
        $notifier = $this->notifier($this->expectingNoCall(), canSend: false);

        $result = $notifier->run(new DateTimeImmutable('2026-08-27 03:00:00'));

        $this->assertSame('mail not configured', $result->reason);
    }

    /**
     * **At most one a day.** The tick is every fifteen minutes and a broken
     * backup stays broken until somebody acts, so the day is what the dedup key
     * carries — anything finer would be ninety-six mails about one missing cron
     * entry.
     */
    public function test_the_dedup_occasion_is_the_day_and_says_so(): void
    {
        $occasion = BackupHealthNotifier::occasion(new DateTimeImmutable('2026-08-27 23:59:59'));

        $this->assertSame('stale:2026-08-27', $occasion);

        // The budget that makes this fit: warnAdmins() builds
        // `occasion:adminUserId` into a VARCHAR(64), and an admin id is 36.
        $this->assertLessThanOrEqual(27, strlen($occasion));
    }

    /** A problem still there tomorrow is worth saying again — the first mail may have landed on a Friday. */
    public function test_a_new_day_is_a_new_occasion(): void
    {
        $this->assertNotSame(
            BackupHealthNotifier::occasion(new DateTimeImmutable('2026-08-27 12:00:00')),
            BackupHealthNotifier::occasion(new DateTimeImmutable('2026-08-28 12:00:00')),
        );
    }

    /**
     * **`fail` only, never `unknown`.** The row that can be unknown is the
     * off-site copy, and it is unknown precisely when the journal's bounded
     * window has rolled past the last upload on a long-running installation.
     * Mailing about that would send a club chasing a transport that works.
     */
    public function test_an_unknown_row_is_not_a_reason_to_write_to_anybody(): void
    {
        // A fresh archive and no journal: `backup_last_upload` is UNKNOWN,
        // everything else passes.
        $this->archive(hoursAgo: 1);

        $findings = (new BackupStatusCheck($this->dir, self::KEYS, BackupRetention::defaults()))->findings();
        $unknown = array_filter($findings, static fn ($f): bool => $f->status === 'unknown');
        $this->assertNotSame([], $unknown, 'the fixture must actually produce an unknown row');

        $result = $this->notifier($this->expectingNoCall())->run(new DateTimeImmutable('2026-08-27 03:00:00'));

        $this->assertSame(0, $result->queued);
    }

    /**
     * The caller is the cron tick whose real job is draining the queue. A scan
     * that could not read a directory must not stop the club's announcements
     * from going out.
     */
    public function test_a_scan_that_throws_is_reported_and_swallowed(): void
    {
        $notifier = new BackupHealthNotifier(
            new BackupStatusCheck($this->dir, self::KEYS, BackupRetention::defaults()),
            $this->schedule(self::KEYS),
            $this->throwingNotifier(),
            $this->mailConfig(true),
            $this->createMock(Logger::class),
        );

        $result = $notifier->run(new DateTimeImmutable('2026-08-27 03:00:00'));

        $this->assertStringStartsWith('scan failed:', (string) $result->reason);
    }

    // ---------------------------------------------------------------- helpers

    private function notifier(
        AdminNotifier $adminNotifier,
        string $keys = self::KEYS,
        bool $canSend = true,
    ): BackupHealthNotifier {
        return new BackupHealthNotifier(
            new BackupStatusCheck($this->dir, $keys, BackupRetention::defaults()),
            $this->schedule($keys),
            $adminNotifier,
            $this->mailConfig($canSend),
            $this->createMock(Logger::class),
        );
    }

    private function schedule(string $keys): BackupSchedule
    {
        return new BackupSchedule('/srv/htdocs', 'https://verein.example', $this->dir, $keys);
    }

    private function mailConfig(bool $canSend): MailConfigService
    {
        $mailConfig = $this->createMock(MailConfigService::class);
        $mailConfig->method('canSend')->willReturn($canSend);

        return $mailConfig;
    }

    private function expecting(int $queued): AdminNotifier
    {
        $notifier = $this->createMock(AdminNotifier::class);
        $notifier->expects($this->once())
            ->method('warnAdmins')
            ->with(
                MailKind::BACKUP_HEALTH_WARNING,
                // The installation itself: there is no backup row to point at,
                // and ADR-0049 decision 8 is why there never will be.
                '1',
                'stale:2026-08-27',
            )
            ->willReturn(new EnqueueResultDto(queued: $queued));

        return $notifier;
    }

    private function expectingNoCall(): AdminNotifier
    {
        $notifier = $this->createMock(AdminNotifier::class);
        $notifier->expects($this->never())->method('warnAdmins');

        return $notifier;
    }

    private function throwingNotifier(): AdminNotifier
    {
        $notifier = $this->createMock(AdminNotifier::class);
        $notifier->method('warnAdmins')->willThrowException(new \RuntimeException('the database went away'));

        return $notifier;
    }

    private function archive(int $hoursAgo): void
    {
        $at = (new DateTimeImmutable('2026-08-27 03:00:00'))->getTimestamp() - $hoursAgo * 3600;

        file_put_contents(
            $this->dir . '/clubbar-' . gmdate('Ymd-His', $at) . '-1a2b3c4d.cbb',
            'sealed'
        );
    }
}
