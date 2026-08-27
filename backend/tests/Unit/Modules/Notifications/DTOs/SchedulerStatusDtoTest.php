<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\DTOs;

use App\Modules\Backups\Domain\BackupScheduleStatus;
use App\Modules\Notifications\DTOs\SchedulerStatusDto;
use PHPUnit\Framework\TestCase;

/**
 * The payload split of #677.
 *
 * `GET /api/admin/scheduler` is granted to the Kassenwart because the gate it
 * reports on refuses *their* button — finalizing a direct debit is blocked
 * until a drain run has been observed. The grant widened; the payload did not,
 * and this is the file that holds it to that.
 *
 * The load-bearing assertion is the exact-key one: it is what makes a field
 * added to this DTO next year admin-only until a human deliberately writes it
 * into `toOfficeArray()`. A test that only listed the fields that must *not*
 * leak would pass for every field nobody thought of.
 */
class SchedulerStatusDtoTest extends TestCase
{
    private function dto(bool $verified = false, ?BackupScheduleStatus $backup = null): SchedulerStatusDto
    {
        return new SchedulerStatusDto(
            verified: $verified,
            lastRunAt: $verified ? '2026-08-14 09:15:00' : null,
            source: $verified ? 'cli' : null,
            lastSent: 2,
            lastFailed: 0,
            phpVersion: '8.3.33',
            missingExtensions: [],
            cliCommand: 'php /var/www/vhosts/example.org/httpdocs/backend/bin/cron.php',
            drainUrl: 'https://bar.example.org/api/cron/drain',
            recommendedIntervalMinutes: 15,
            declaredInterval: 'hourly',
            observedInterval: 'daily',
            intervalDisagrees: true,
            backup: $backup ?? self::backup(),
        );
    }

    private static function backup(bool $configured = true, bool $verified = false): BackupScheduleStatus
    {
        return new BackupScheduleStatus(
            configured: $configured,
            verified: $verified,
            cliCommand: 'php /var/www/vhosts/example.org/httpdocs/backend/bin/backup.php',
            triggerUrl: 'https://bar.example.org/api/cron/backup',
        );
    }

    /** The operator's view is unchanged by the split. */
    public function test_the_admin_array_carries_the_whole_status(): void
    {
        $array = $this->dto()->toArray();

        $this->assertFalse($array['verified']);
        $this->assertSame('8.3.33', $array['php_version']);
        $this->assertSame([], $array['missing_extensions']);
        $this->assertSame('hourly', $array['declared_interval']);
        $this->assertSame('daily', $array['observed_interval']);
        $this->assertTrue($array['interval_disagrees']);
        $this->assertStringEndsWith('/backend/bin/cron.php', $array['setup']['cli_command']);
        $this->assertSame('https://bar.example.org/api/cron/drain', $array['setup']['drain_url']);
        $this->assertSame(15, $array['setup']['recommended_interval_minutes']);
        $this->assertTrue($array['backup']['configured']);
        $this->assertFalse($array['backup']['verified']);
        $this->assertStringEndsWith('/backend/bin/backup.php', $array['backup']['cli_command']);
        $this->assertSame('https://bar.example.org/api/cron/backup', $array['backup']['trigger_url']);
    }

    /**
     * **The second job carries no schedule.** Triggering is external — a
     * hosting panel fires the backup and the application never reads a cadence.
     * A field here would read as configuration the application honours, and the
     * recommendation is the same on every installation anyway, so the panel's
     * own strings carry it.
     */
    public function test_the_backup_section_ships_exactly_four_fields_and_no_schedule(): void
    {
        $backup = $this->dto()->toArray()['backup'];

        $this->assertSame(['configured', 'verified', 'cli_command', 'trigger_url'], array_keys($backup));
        $this->assertStringNotContainsString('* * *', json_encode($backup, JSON_THROW_ON_ERROR));
    }

    /**
     * Absent, not null. A key whose value is always null teaches a reader to
     * stop looking at it; the banner's test is "is there a backup section",
     * and an absent key answers that honestly. `install.php` and the package
     * smoke test both read this status with no Backups module wired.
     */
    public function test_no_backup_half_means_no_backup_key(): void
    {
        $dto = new SchedulerStatusDto(
            verified: false,
            lastRunAt: null,
            source: null,
            lastSent: null,
            lastFailed: null,
            phpVersion: null,
            missingExtensions: null,
            cliCommand: 'php /srv/htdocs/backend/bin/cron.php',
            drainUrl: null,
            recommendedIntervalMinutes: 15,
        );

        $this->assertArrayNotHasKey('backup', $dto->toArray());
    }

    /**
     * An allow-list, asserted as one. A new field reaches a club office only
     * by appearing in this list too — which is a diff a reviewer sees.
     */
    public function test_the_office_array_carries_exactly_the_flag_and_the_interval(): void
    {
        $array = $this->dto()->toOfficeArray();

        $this->assertSame(['verified', 'setup'], array_keys($array));
        $this->assertSame(['recommended_interval_minutes'], array_keys($array['setup']));
        $this->assertFalse($array['verified']);
        $this->assertSame(15, $array['setup']['recommended_interval_minutes']);
    }

    /**
     * Named individually as well, because these are the fields the grant was
     * widened *around*: the command carries this installation's document root,
     * the URL names the trigger endpoint, and the rest is the self-check's
     * reading of a host no club office can reach.
     */
    public function test_the_office_array_names_no_path_and_no_host_detail(): void
    {
        $encoded = json_encode($this->dto()->toOfficeArray(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('cron.php', $encoded);
        $this->assertStringNotContainsString('httpdocs', $encoded);
        $this->assertStringNotContainsString('cli_command', $encoded);
        $this->assertStringNotContainsString('drain_url', $encoded);
        $this->assertStringNotContainsString('cron/drain', $encoded);
        $this->assertStringNotContainsString('php_version', $encoded);
        $this->assertStringNotContainsString('missing_extensions', $encoded);
        $this->assertStringNotContainsString('last_run_at', $encoded);
        $this->assertStringNotContainsString('declared_interval', $encoded);
        $this->assertStringNotContainsString('observed_interval', $encoded);
    }

    /**
     * **The backup half is the operator's, whole.**
     *
     * ADR-0044's rule is to mirror the grant on the surface the thing points
     * at, and every backup surface is `admin`: the measured rows render on
     * `GET /api/admin/security-check`, which is `ADMIN_ONLY`. A Kassenwart
     * cannot open a hosting panel, so a missing backup cron is not theirs to
     * fix — and the command names this installation's document root, which is
     * exactly what #677's allow-list exists to keep back.
     */
    public function test_the_office_array_carries_no_backup_section_at_all(): void
    {
        $office = $this->dto()->toOfficeArray();

        $this->assertArrayNotHasKey('backup', $office);
        $this->assertStringNotContainsString(
            'backup',
            json_encode($office, JSON_THROW_ON_ERROR),
            'not even the word: a club office has nothing to do here'
        );
    }

    /**
     * The one thing the banner keys on has to survive the redaction in both
     * directions — a verified installation renders nothing, and that is the
     * state every healthy installation is in forever after its first tick.
     */
    public function test_the_office_array_reports_a_verified_installation_as_verified(): void
    {
        $this->assertTrue($this->dto(verified: true)->toOfficeArray()['verified']);
    }
}
