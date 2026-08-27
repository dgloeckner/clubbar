<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Backups\Services;

use App\Modules\Backups\Services\ArchiveDirectory;
use App\Modules\Backups\Services\BackupJournal;
use App\Modules\Backups\Services\BackupSchedule;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\TempTree;

/**
 * The backup job as a scheduled job — what the panel banner needs (#693).
 *
 * `BackupStatusCheck` answers the admin who *went looking*, on the security
 * self-check. This answers the volunteer who has the hosting panel open right
 * now, and never went looking at all. The installer prints both cron lines and
 * then promises the panel "shows the same instructions until a run has been
 * seen"; these are the tests for the half of that promise that was missing.
 *
 * Part of #693, epic #686.
 */
class BackupScheduleTest extends TestCase
{
    use TempTree;

    private const KEYS = 'admin:bb637d8ec1cb92bca0467e59faa6d61f6b7f8088103e5b89d7afdc01f1efa45c';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = self::makeTempTree('backup-schedule');
    }

    protected function tearDown(): void
    {
        self::removeTempTree($this->dir);
    }

    /**
     * **The coupling invariant, asserted structurally** — the same rule
     * `BackupStatusCheck` carries, and here it matters more.
     *
     * That class feeds one page an admin chooses to open. This one feeds a
     * banner that renders on top of *every* page in the panel, so a call to the
     * storage provider here would put a tenant outage between an admin and
     * every screen they own — and the transport's 120-second timeout and
     * `Retry-After` retries are sized for a nightly chunk upload, not for
     * somebody waiting on a navigation.
     *
     * On the constructor rather than on behaviour, because behaviour can stay
     * green while the coupling is added.
     */
    public function test_it_cannot_be_given_a_transport_or_the_backup_service(): void
    {
        $parameters = (new ReflectionClass(BackupSchedule::class))
            ->getConstructor()
            ?->getParameters() ?? [];

        foreach ($parameters as $parameter) {
            $type = (string) $parameter->getType();

            $this->assertStringNotContainsString(
                'Transport',
                $type,
                'a banner on every page may not depend on the storage provider'
            );
            // BackupService carries the transport and the database. Holding one
            // to ask `isConfigured()` would drag both in through the back door.
            $this->assertStringNotContainsString('BackupService', $type);
        }
    }

    /** Configuring a recipient key *is* the on-switch (ADR-0049 decision 2). */
    public function test_backups_are_off_until_a_recipient_key_is_configured(): void
    {
        $this->assertFalse($this->schedule(keys: '')->isConfigured());
        $this->assertFalse($this->schedule(keys: '   ')->isConfigured());
        $this->assertTrue($this->schedule()->isConfigured());
    }

    /**
     * **The state the banner exists for.** Backups switched on, and the nightly
     * job never added to the hosting panel — which is otherwise
     * indistinguishable from a job running fine every night.
     */
    public function test_an_empty_directory_means_nothing_has_ever_run(): void
    {
        $this->assertFalse($this->schedule()->hasEverRun());
    }

    /**
     * A run that died before writing anything still happened. The journal is
     * what knows, and it is the reason "no archive" and "no run" are different
     * answers.
     */
    public function test_a_failed_run_still_counts_as_having_run(): void
    {
        (new BackupJournal($this->dir))->failed('the database refused the connection');

        $this->assertTrue($this->schedule()->hasEverRun());
    }

    /**
     * And the opposite case: a directory whose journal was deleted, or has
     * rolled past its bounded window, still has the archives to show for it.
     * Either half is enough.
     */
    public function test_an_archive_with_no_journal_counts_too(): void
    {
        file_put_contents(
            $this->dir . '/' . ArchiveDirectory::FILENAME_PREFIX . '20260825-030000-1a2b3c4d' . ArchiveDirectory::EXTENSION,
            'sealed'
        );

        $this->assertTrue($this->schedule()->hasEverRun());
    }

    /** A note an operator left in the directory is not evidence of a backup. */
    public function test_a_foreign_file_is_not_a_run(): void
    {
        file_put_contents($this->dir . '/notes-for-my-successor.txt', 'the key is in the safe');

        $this->assertFalse($this->schedule()->hasEverRun());
    }

    /**
     * The command has to be pasteable, which means absolute — a panel's cron
     * form has no working directory to resolve against.
     */
    public function test_the_command_names_this_installation(): void
    {
        $command = $this->schedule()->cliCommand();

        $this->assertSame('php /srv/htdocs/backend/bin/backup.php', $command);
    }

    /** A trailing slash on the document root must not produce a doubled one. */
    public function test_the_command_survives_a_trailing_slash(): void
    {
        $this->assertSame(
            'php /srv/htdocs/backend/bin/backup.php',
            $this->schedule(documentRoot: '/srv/htdocs/')->cliCommand()
        );
    }

    /**
     * **Both halves of the gate, because the route needs both.**
     * `BackupCronController` mounts nothing without a cron secret *and* a
     * recipient key, so offering the URL on either alone would be instructions
     * for a 404 — which reads as a broken installation rather than an
     * unconfigured one.
     */
    public function test_the_trigger_url_needs_a_secret_and_a_key(): void
    {
        $this->assertNull($this->schedule()->triggerUrl(false), 'no cron secret, no route');
        $this->assertNull($this->schedule(keys: '')->triggerUrl(true), 'backups off, no route');
        $this->assertSame(
            'https://verein.example/api/cron/backup',
            $this->schedule()->triggerUrl(true)
        );
    }

    public function test_the_trigger_url_survives_a_trailing_slash(): void
    {
        $this->assertSame(
            'https://verein.example/api/cron/backup',
            $this->schedule(appUrl: 'https://verein.example/')->triggerUrl(true)
        );
    }

    /**
     * **No cron expression anywhere in this class.** Triggering is external —
     * a hosting panel fires the job and the application never reads a cadence.
     * A schedule constant here would be the first step to shipping one over the
     * API, where it would read as configuration the application honours.
     */
    public function test_it_publishes_no_schedule(): void
    {
        foreach ((new ReflectionClass(BackupSchedule::class))->getConstants() as $name => $value) {
            $this->assertDoesNotMatchRegularExpression(
                '/\*|\bcron\s*expression\b/i',
                (string) $value,
                sprintf('%s looks like a schedule; the panel fires this job, not us', $name)
            );
        }
    }

    // ---------------------------------------------------------------- helpers

    private function schedule(
        string $keys = self::KEYS,
        string $documentRoot = '/srv/htdocs',
        string $appUrl = 'https://verein.example',
    ): BackupSchedule {
        return new BackupSchedule($documentRoot, $appUrl, $this->dir, $keys);
    }
}
