<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Backups\Services;

use App\Modules\Backups\Domain\BackupRetention;
use App\Modules\Backups\Services\ArchiveDirectory;
use App\Modules\Backups\Services\BackupJournal;
use App\Modules\Backups\Services\BackupStatusCheck;
use App\Shared\Security\SecurityFinding;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\TempTree;

/**
 * Observing the backup job, which nothing did before (#693).
 *
 * ADR-0038's rule — *"no scheduled path may exist that nothing observes"* — is
 * the only reason ADR-0049 was allowed a backup cron separate from the mail
 * drain. Until this, the four `backup` rows all read `config.php`: they
 * reported what the club **intended**. A cron never added, or dropped in a
 * tariff migration, was indistinguishable from one running fine.
 *
 * Part of #693, epic #686.
 */
class BackupStatusCheckTest extends TestCase
{
    use TempTree;

    private const KEYS = 'admin:bb637d8ec1cb92bca0467e59faa6d61f6b7f8088103e5b89d7afdc01f1efa45c';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = self::makeTempTree('backup-status');
    }

    protected function tearDown(): void
    {
        self::removeTempTree($this->dir);
    }

    /**
     * **The coupling invariant, asserted structurally.**
     *
     * These rows render on the security self-check — the page an admin opens to
     * find out what is broken. A call to the storage provider here would let a
     * tenant outage break exactly that page, and the transport's 120-second
     * timeout and `Retry-After` retries are sized for a nightly chunk upload,
     * not for somebody waiting on a page load.
     *
     * Asserted on the constructor rather than on behaviour, because behaviour
     * can be green while the coupling is added: a transport parameter cannot
     * appear without this failing.
     */
    public function test_it_cannot_be_given_a_transport(): void
    {
        $parameters = (new ReflectionClass(BackupStatusCheck::class))
            ->getConstructor()
            ?->getParameters() ?? [];

        foreach ($parameters as $parameter) {
            $this->assertStringNotContainsString(
                'Transport',
                (string) $parameter->getType(),
                'the status check must not reach the storage provider: a page may not depend on it'
            );
        }
    }

    /**
     * **The row this milestone exists for.** Silence and "the cron was never
     * added to the hosting panel" are the same thing to a club until something
     * says otherwise.
     */
    public function test_a_job_that_never_ran_says_so_rather_than_staying_silent(): void
    {
        $finding = $this->findingById($this->check(), 'backup_ever_ran');

        $this->assertSame(SecurityFinding::FAIL, $finding->status);
        $this->assertStringContainsString('ever been observed', $finding->observed);
        // The remedy has to name the actual cause, or an admin reads "backup
        // broken" and starts debugging the backup rather than the scheduler.
        $this->assertStringContainsString('scheduler', (string) $finding->remedy);
    }

    /**
     * Backups off is a legitimate state (ADR-0049 decision 2) and
     * `BackupConfigCheck` already reports it. Four more rows repeating it is
     * how a report teaches its reader to skim past the section.
     */
    public function test_no_recipient_keys_produces_no_status_rows_at_all(): void
    {
        $this->assertSame([], $this->check(keys: ''));
    }

    /**
     * **Never-run and ran-but-produced-nothing are different failures** with
     * different remedies — one is a missing cron entry, the other is a job
     * dying before it writes.
     */
    public function test_a_job_that_ran_and_produced_nothing_is_a_different_row(): void
    {
        $this->journal()->failed('the database refused the connection');

        $findings = $this->check();

        $this->assertNull($this->maybeFindingById($findings, 'backup_ever_ran'));
        $this->assertSame(SecurityFinding::FAIL, $this->findingById($findings, 'backup_last_run')->status);
    }

    public function test_a_recent_archive_passes(): void
    {
        $this->archive(hoursAgo: 6, bytes: 2048);

        $finding = $this->findingById($this->check(), 'backup_last_run');

        $this->assertSame(SecurityFinding::PASS, $finding->status);
    }

    public function test_an_archive_older_than_two_nights_fails(): void
    {
        $this->archive(hoursAgo: 60, bytes: 2048);

        $finding = $this->findingById($this->check(), 'backup_last_run');

        $this->assertSame(SecurityFinding::FAIL, $finding->status);
        $this->assertStringContainsString('60 hours', $finding->observed);
    }

    /**
     * The instant comes from the filename, so a copy, a restore from a
     * provider's recycle bin, or a stray `touch` cannot make a stale archive
     * look fresh — the name records when the snapshot was taken, the mtime only
     * records when the file was last handled.
     */
    public function test_the_age_comes_from_the_filename_not_the_mtime(): void
    {
        $name = 'clubbar-' . gmdate('Ymd-His', $this->now - 60 * 3600) . '-1a2b3c4d.cbb';
        file_put_contents($this->dir . '/' . $name, 'sealed');
        touch($this->dir . '/' . $name, $this->now);

        $finding = $this->findingById($this->check(), 'backup_last_run');

        $this->assertSame(SecurityFinding::FAIL, $finding->status, 'a touched file must not read as fresh');
    }

    /**
     * **The failure that survives longest unnoticed.** A host whose uploads
     * have failed for six weeks still writes a perfectly good local archive
     * every night, so a single "backups are fine" row would be green
     * throughout. Local and remote are both kept — 30 days and 90 — and an
     * upload is a copy, not a move.
     */
    public function test_a_local_archive_with_no_upload_does_not_read_as_healthy(): void
    {
        $this->archive(hoursAgo: 2, bytes: 2048);

        $findings = $this->check();

        $this->assertSame(SecurityFinding::PASS, $this->findingById($findings, 'backup_last_run')->status);
        $this->assertNotSame(
            SecurityFinding::PASS,
            $this->findingById($findings, 'backup_last_upload')->status,
            'a local-only backup is half done'
        );
    }

    /**
     * **Unknown, never "never".** Only the journal records an upload — an
     * archive on disk looks identical whether or not it was pushed — and the
     * journal is read through a bounded window, so an older success can fall
     * out of it. Reporting that as "never uploaded" would send a club chasing a
     * transport that is working.
     */
    public function test_an_upload_older_than_the_journal_window_is_unknown_not_never(): void
    {
        $this->archive(hoursAgo: 2, bytes: 2048);
        $this->journal()->uploaded('clubbar-old.cbb', 'msgraph://store', 'backups/clubbar-old.cbb', 2048);
        // Push that upload out of the tail with newer, unrelated entries.
        for ($i = 0; $i < 400; $i++) {
            $this->journal()->failed(str_repeat('a padding failure that fills the window. ', 6));
        }

        $finding = $this->findingById($this->check(), 'backup_last_upload');

        $this->assertSame(SecurityFinding::UNKNOWN, $finding->status);
        $this->assertStringNotContainsString('never', strtolower($finding->observed));
    }

    public function test_a_recent_upload_passes(): void
    {
        $this->archive(hoursAgo: 2, bytes: 2048);
        $this->journal()->uploaded('clubbar-x.cbb', 'msgraph://store', 'backups/clubbar-x.cbb', 2048);

        $this->assertSame(
            SecurityFinding::PASS,
            $this->findingById($this->check(), 'backup_last_upload')->status
        );
    }

    /**
     * A webspace that fills stops accepting everything — mandate uploads, logs,
     * the next archive — so archives over the cap are a failure of the whole
     * installation, not just of the backup.
     */
    public function test_archives_over_the_local_cap_fail(): void
    {
        $this->archive(hoursAgo: 2, bytes: 4096);

        $finding = $this->findingById(
            $this->check(retention: BackupRetention::fromOverrides(null, 1024, null)),
            'backup_local_size'
        );

        $this->assertSame(SecurityFinding::FAIL, $finding->status);
    }

    /** A stray file an operator left in the directory is not an archive. */
    public function test_a_foreign_file_is_neither_counted_nor_mistaken_for_a_backup(): void
    {
        file_put_contents($this->dir . '/notes-for-my-successor.txt', str_repeat('x', 4096));

        $finding = $this->findingById($this->check(), 'backup_ever_ran');

        $this->assertSame(SecurityFinding::FAIL, $finding->status, 'a text file is not a backup');
    }

    // ---------------------------------------------------------------- helpers

    private int $now = 1788000000;

    /** @return list<SecurityFinding> */
    private function check(
        string $keys = self::KEYS,
        ?BackupRetention $retention = null,
    ): array {
        return (new BackupStatusCheck(
            $this->dir,
            $keys,
            $retention ?? BackupRetention::defaults(),
            $this->now,
        ))->findings();
    }

    private function journal(): BackupJournal
    {
        return new BackupJournal($this->dir);
    }

    private function archive(int $hoursAgo, int $bytes): void
    {
        $name = ArchiveDirectory::FILENAME_PREFIX
            . gmdate('Ymd-His', $this->now - $hoursAgo * 3600)
            . '-1a2b3c4d' . ArchiveDirectory::EXTENSION;

        file_put_contents($this->dir . '/' . $name, str_repeat('s', $bytes));
    }

    /** @param list<SecurityFinding> $findings */
    private function findingById(array $findings, string $id): SecurityFinding
    {
        $finding = $this->maybeFindingById($findings, $id);

        if ($finding === null) {
            $this->fail(sprintf(
                'No finding "%s". Got: %s.',
                $id,
                implode(', ', array_map(static fn (SecurityFinding $f): string => $f->id, $findings)) ?: '(none)'
            ));
        }

        return $finding;
    }

    /** @param list<SecurityFinding> $findings */
    private function maybeFindingById(array $findings, string $id): ?SecurityFinding
    {
        foreach ($findings as $finding) {
            if ($finding->id === $id) {
                return $finding;
            }
        }

        return null;
    }
}
