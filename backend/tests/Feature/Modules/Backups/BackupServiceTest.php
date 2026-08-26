<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Backups;

use App\Modules\Backups\Domain\BackupRetention;
use App\Modules\Backups\Services\BackupJournal;
use App\Modules\Backups\Services\BackupKeyring;
use App\Modules\Backups\Services\BackupService;
use App\Modules\Backups\Services\DatabaseDump;
use App\Shared\Logging\Logger;
use App\Shared\Security\BackupSealedBox;
use App\Modules\Backups\Transport\BackupTransport;
use App\Modules\Backups\Transport\RemoteArchive;
use Tests\Feature\DatabaseTestCase;
use Tests\Support\FakeBackupTransport;
use Tests\Support\TempTree;

/**
 * A backup run against a real database and a real filesystem, because what it
 * can get wrong lives in both: whether the archive actually opens, whether the
 * directory ends up 0700, and whether a run that failed left a journal line
 * saying so.
 *
 * **Nothing here queries a backup table, because there are none.** The run
 * writes into the database it dumps exactly nowhere (ADR-0049 decision 8), so
 * every assertion below reads the artifacts instead: the archive and its
 * header, the journal beside it, and the directory the two live in. That is
 * also what a club has after a restore, which is the point.
 *
 * The archive directory is a temp tree ({@see TempTree}), never a path built
 * from a property that might not be set — CLAUDE.md, destructive test cleanup.
 *
 * Part of #690 and #703, epic #686.
 */
class BackupServiceTest extends DatabaseTestCase
{
    use TempTree;

    private string $tempTree = '';
    private string $backupDir = '';

    private string $publicKeys;
    private string $secretKey;

    protected function setUp(): void
    {
        parent::setUp();

        // Assigned before anything that could skip, and under the system temp
        // directory, so cleanup can never point anywhere else.
        $this->tempTree = self::makeTempTree('clubbar-backup-test');
        $this->backupDir = $this->tempTree . '/backups';

        $keypair = sodium_crypto_box_keypair();
        $this->secretKey = sodium_crypto_box_secretkey($keypair);
        $this->publicKeys = 'admin:' . bin2hex(sodium_crypto_box_publickey($keypair));
    }

    protected function tearDown(): void
    {
        self::removeTempTree($this->tempTree);

        parent::tearDown();
    }

    /**
     * The whole point, end to end: what the run wrote is an archive the holder
     * of the private half can open, and it contains the database.
     */
    public function test_a_run_writes_an_archive_the_recipient_can_actually_open(): void
    {
        $outcome = $this->service()->run('cli');

        $this->assertTrue($outcome->producedAnArchive(), $outcome->summary);

        $path = $this->backupDir . '/' . $outcome->filename;
        $plaintext = BackupSealedBox::open((string) file_get_contents($path), $this->secretKey);

        $this->assertStringContainsString('CREATE TABLE `members`', $plaintext);
        $this->assertStringContainsString("SET time_zone = '+00:00'", $plaintext);
    }

    /**
     * The archive is the record (ADR-0049 decision 8), so everything a reader
     * needs in order to know what they are holding has to be in its header —
     * and readable with no key at all.
     *
     * Asserted from a *real* run rather than from a hand-built header, because
     * the failure this guards against is the service forgetting to describe
     * what it sealed. `BackupSealedBox` would happily write nulls.
     */
    public function test_the_archive_describes_itself_without_any_key(): void
    {
        $outcome = $this->service()->run('cli');

        $sealed = (string) file_get_contents($this->backupDir . '/' . $outcome->filename);
        $header = BackupSealedBox::readHeader($sealed);

        $this->assertSame(
            (string) $this->db->query('SELECT DATABASE()')->fetchColumn(),
            $header['instance']['database']
        );
        $this->assertSame(
            (string) $this->db->query('SELECT MAX(file) FROM _migrations')->fetchColumn(),
            $header['schema_version'],
            'Which application version can load this archive, without opening it.'
        );
        $this->assertSame(DatabaseDump::FORMAT_VERSION, $header['dump_format']);
        $this->assertArrayHasKey(
            'members',
            $header['manifest'],
            'The manifest is what is inside, answerable without a private key.'
        );

        $this->assertSame(
            hash('sha256', BackupSealedBox::open($sealed, $this->secretKey)),
            $header['plaintext_sha256'],
            'A restore proves it decrypted what was sealed by comparing against the header.'
        );
    }

    /**
     * ADR-0031 decision 2. An archive under the document root would be a URL
     * the day `.htaccess` stops being honoured, which has already happened once
     * (#383) — so the directory is owner-only and so is the file.
     */
    public function test_the_archive_and_its_directory_are_owner_only(): void
    {
        $outcome = $this->service()->run('cli');

        $this->assertSame('0700', substr(sprintf('%o', fileperms($this->backupDir)), -4));
        $this->assertSame(
            '0600',
            substr(sprintf('%o', fileperms($this->backupDir . '/' . $outcome->filename)), -4)
        );
    }

    /** No `.part` survives a completed run — the rename is what makes it atomic. */
    public function test_no_partial_file_is_left_behind(): void
    {
        $this->service()->run('cli');

        $this->assertSame([], glob($this->backupDir . '/*.part') ?: []);
    }

    /**
     * The journal is what the panel and the self-check read, and the only
     * record of an attempt that produced no file.
     */
    public function test_the_journal_records_the_attempt_and_what_it_produced(): void
    {
        $outcome = $this->service()->run('cli');

        $entries = $this->journalEntries();

        $this->assertSame(['started', 'written'], array_column($entries, 'event'));
        $this->assertSame('cli', $entries[0]['trigger']);
        $this->assertSame($outcome->filename, $entries[1]['filename']);
        $this->assertSame(
            hash('sha256', (string) file_get_contents($this->backupDir . '/' . $outcome->filename)),
            $entries[1]['sha256'],
            'The checksum answers "is this the file we wrote" without a private key.'
        );
        $this->assertNotEmpty($entries[1]['recipients']);
    }

    /**
     * The journal sits beside the archives, never inside the database.
     *
     * This is the defect that removed the three tables: an archive containing
     * its own half-written `running` row means restoring one resurrects a run
     * that never finishes, and reads as a stalled scheduler. A file in the
     * backup directory cannot be inside the dump it describes.
     */
    public function test_the_journal_lives_beside_the_archives_and_not_in_the_database(): void
    {
        $this->service()->run('cli');

        $this->assertFileExists($this->backupDir . '/' . BackupJournal::FILENAME);

        $tables = $this->db->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'backup%'"
        )->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertSame([], $tables, 'The backup owns no table in the database it dumps.');
    }

    /**
     * Fail closed. This is the one refusal that must never degrade into a
     * plaintext archive, and it must never *look* like a successful run.
     *
     * Reaching it at all means somebody called a run on an installation with no
     * key: the entrypoints ask {@see BackupService::isConfigured()} first, so
     * an unconfigured club is silent rather than failing nightly.
     */
    public function test_a_run_with_no_recipient_key_writes_nothing_at_all(): void
    {
        $service = $this->service(publicKeys: '');

        $this->assertFalse($service->isConfigured());

        $outcome = $service->run('cli');

        $this->assertFalse($outcome->producedAnArchive());
        $this->assertTrue($outcome->needsAttention());
        $this->assertSame([], glob($this->backupDir . '/*') ?: []);
    }

    /**
     * A typo is not an off switch.
     *
     * `isConfigured()` asks whether anything is there, not whether it parses —
     * because treating a malformed key as "backups are off" would let one wrong
     * character silence a club's backups with no complaint anywhere, which is
     * the worst outcome available here.
     */
    public function test_a_malformed_key_fails_loudly_rather_than_reading_as_switched_off(): void
    {
        $service = $this->service(publicKeys: 'admin:not-a-key');

        $this->assertTrue($service->isConfigured());

        $outcome = $service->run('cli');

        $this->assertSame('failed', $outcome->status);
        $this->assertSame([], glob($this->backupDir . '/*' . BackupService::EXTENSION) ?: []);
    }

    /**
     * The guard that stops `/api/cron/backup` filling the webspace quota. Keyed
     * on attempts rather than successes, because an attempt is what spends the
     * quota — and read from the journal, which is the only place an attempt
     * that wrote nothing is recorded.
     */
    public function test_a_second_run_inside_the_interval_writes_no_second_archive(): void
    {
        $service = $this->service();
        $service->run('cli');

        $second = $service->run('url');

        $this->assertSame('skipped', $second->status);
        $this->assertCount(1, glob($this->backupDir . '/*' . BackupService::EXTENSION) ?: []);
        $this->assertSame(
            ['started', 'written'],
            array_column($this->journalEntries(), 'event'),
            'A skipped run appends nothing: it did not start.'
        );
    }

    /** An operator asking for a run by hand is not the caller the guard is aimed at. */
    public function test_force_overrides_the_interval(): void
    {
        $service = $this->service();
        $service->run('cli');

        $this->assertTrue($service->run('cli', force: true)->producedAnArchive());
    }

    /**
     * Pruning reads the directory, because the directory is what is true.
     *
     * There is no table of what *should* be there to disagree with what is: an
     * operator who deleted an archive by hand has deleted it, and a file that
     * arrived by some other route is still occupying the quota. The journal
     * records the removal and is never consulted about what exists.
     */
    public function test_an_archive_past_its_retention_is_removed_and_the_removal_journalled(): void
    {
        $stale = $this->plantArchive(daysAgo: 40, bytes: 21);

        $outcome = $this->service(retention: BackupRetention::fromOverrides(localDays: 30))->run('cli');

        $this->assertSame(1, $outcome->prunedArchives);
        $this->assertFileDoesNotExist($this->backupDir . '/' . $stale);

        $pruned = array_values(array_filter(
            $this->journalEntries(),
            static fn (array $e): bool => $e['event'] === 'pruned'
        ));
        $this->assertSame($stale, $pruned[0]['filename']);
        $this->assertSame('age', $pruned[0]['reason']);
    }

    /** An archive inside its window stays, whatever else the run does. */
    public function test_an_archive_inside_its_retention_is_left_alone(): void
    {
        $recent = $this->plantArchive(daysAgo: 3, bytes: 21);

        $outcome = $this->service(retention: BackupRetention::fromOverrides(localDays: 30))->run('cli');

        $this->assertSame(0, $outcome->prunedArchives);
        $this->assertFileExists($this->backupDir . '/' . $recent);
    }

    /**
     * The byte cap is a refusal, not a licence to delete the newest archive.
     * An installation whose single archive is over the cap must end up
     * reported, not empty — a club with no backup is worse than a club over a
     * number.
     */
    public function test_the_byte_cap_reports_rather_than_leaving_the_club_with_nothing(): void
    {
        $outcome = $this->service(retention: BackupRetention::fromOverrides(localMaxBytes: 1))->run('cli');

        $this->assertTrue($outcome->producedAnArchive());
        $this->assertFileExists($this->backupDir . '/' . $outcome->filename);
        $this->assertNotEmpty(array_filter(
            $outcome->findings,
            static fn (string $f): bool => str_contains($f, 'over the')
        ));
    }

    /**
     * Over the cap with room to prune: the oldest goes, the newest never does.
     */
    public function test_the_byte_cap_takes_the_oldest_archive_first(): void
    {
        // The cap has to be expressed relative to a real archive, so the test
        // states "room for tonight's archive and one more" rather than a magic
        // number that stops meaning that the moment the schema grows.
        $sizing = $this->service()->run('cli');
        unlink($this->backupDir . '/' . $sizing->filename);
        $cap = $sizing->bytes + 4096 + 512;

        $oldest = $this->plantArchive(daysAgo: 5, bytes: 4096);
        $newer = $this->plantArchive(daysAgo: 2, bytes: 4096);

        $outcome = $this->service(
            retention: BackupRetention::fromOverrides(localDays: 30, localMaxBytes: $cap)
        )->run('cli', force: true);

        $this->assertFileDoesNotExist(
            $this->backupDir . '/' . $oldest,
            'The cap takes the archive whose loss costs least.'
        );
        $this->assertFileExists($this->backupDir . '/' . $newer);
        $this->assertFileExists($this->backupDir . '/' . $outcome->filename);
    }

    /**
     * Anything that is not an archive this job wrote is not this job's to
     * delete — a `.part` from a killed run, the journal itself, a note somebody
     * left for their successor. Pruning globs one deliberate pattern rather
     * than emptying a directory.
     */
    public function test_pruning_touches_nothing_but_the_archives_it_writes(): void
    {
        @mkdir($this->backupDir, 0700, true);
        file_put_contents($this->backupDir . '/notes-for-my-successor.txt', 'the safe key is with Ute');
        touch($this->backupDir . '/notes-for-my-successor.txt', time() - (400 * 86400));

        $this->service(retention: BackupRetention::fromOverrides(localDays: 1))->run('cli');

        $this->assertFileExists($this->backupDir . '/notes-for-my-successor.txt');
    }

    /**
     * A run that cannot write must leave a journal line saying so — a failure
     * with no record is indistinguishable from a scheduler that never ran,
     * which is the silent-stall failure this whole epic exists to prevent.
     *
     * The unwritable path is a *file* where the directory should be, which is
     * the closest reproducible stand-in for the real cause on shared hosting: a
     * data directory the cron user does not own. Running as root, a plain
     * `chmod 0500` would not stop the write.
     */
    public function test_a_run_that_cannot_write_reports_the_failure(): void
    {
        file_put_contents($this->tempTree . '/not-a-directory', 'blocking the path');

        $outcome = $this->service(directory: $this->tempTree . '/not-a-directory')->run('cli');

        $this->assertSame('failed', $outcome->status);
        $this->assertTrue($outcome->needsAttention());
        $this->assertNotEmpty($outcome->summary);
    }

    /**
     * Two runs in the same second must not collide on a filename, which is what
     * the random suffix is for now that there is no run id to take one from.
     */
    public function test_two_runs_never_claim_the_same_filename(): void
    {
        $service = $this->service();

        $first = $service->run('cli')->filename;
        $second = $service->run('cli', force: true)->filename;

        $this->assertNotSame($first, $second);
        $this->assertCount(2, glob($this->backupDir . '/*' . BackupService::EXTENSION) ?: []);
    }

    /**
     * An archive whose name says when it was written, so retention needs
     * neither a database row nor a trustworthy `mtime`.
     *
     * @return string the filename
     */
    private function plantArchive(int $daysAgo, int $bytes): string
    {
        @mkdir($this->backupDir, 0700, true);

        $name = sprintf(
            'clubbar-%s-%s%s',
            gmdate('Ymd-His', time() - ($daysAgo * 86400)),
            bin2hex(random_bytes(4)),
            BackupService::EXTENSION
        );

        file_put_contents($this->backupDir . '/' . $name, str_repeat('x', $bytes));

        return $name;
    }

    /** @return list<array<string, mixed>> */
    private function journalEntries(): array
    {
        $path = $this->backupDir . '/' . BackupJournal::FILENAME;
        if (!is_file($path)) {
            return [];
        }

        return array_values(array_map(
            static fn (string $line): array => json_decode($line, true),
            array_filter(explode("\n", (string) file_get_contents($path)), static fn ($l) => trim($l) !== '')
        ));
    }

    /**
     * The archive is pushed off the host, which is the point: a backup on the
     * same webspace as the database is not off-site.
     */
    public function test_a_written_archive_is_offered_to_the_remote(): void
    {
        $transport = new FakeBackupTransport();

        $outcome = $this->service(transport: $transport)->run('cli');

        $this->assertSame([$outcome->filename], $transport->uploaded);
        $this->assertStringContainsString('Uploaded', (string) $outcome->remoteSummary);
        $this->assertSame([], $outcome->findings);
        $this->assertStringContainsString('"event":"uploaded"', $this->journalContents());
    }

    /**
     * A failed upload is a **finding**, not a failed run. The archive exists,
     * is sealed and is on the webspace; reporting the night as a failure would
     * hide that the local copy is fine, and a club reading "backup failed"
     * deletes nothing and trusts nothing.
     */
    public function test_a_remote_that_refuses_does_not_turn_the_run_into_a_failure(): void
    {
        $outcome = $this->service(transport: new FakeBackupTransport('failed'))->run('cli');

        $this->assertTrue($outcome->producedAnArchive(), $outcome->summary);
        $this->assertTrue($outcome->needsAttention());
        $this->assertNotSame([], $outcome->findings);
        $this->assertStringContainsString('"event":"upload_failed"', $this->journalContents());
    }

    /**
     * A club on a slow uplink whose archive needs three nights to leave the
     * building is working correctly. Recording that as a failure would teach
     * its operator to skip the lines that matter.
     */
    public function test_an_upload_that_ran_out_of_budget_is_progress_rather_than_a_finding(): void
    {
        $outcome = $this->service(transport: new FakeBackupTransport('partial'))->run('cli');

        $this->assertTrue($outcome->producedAnArchive(), $outcome->summary);
        $this->assertSame([], $outcome->findings);
        $this->assertStringContainsString('"event":"upload_progress"', $this->journalContents());
    }

    /**
     * Remote retention is what gives an ADR-0029 erasure an end date: a member
     * anonymised today still exists in full inside every archive sealed before
     * today, and those have to age out of the remote as well as the disk.
     */
    public function test_expired_remote_archives_are_deleted_after_a_successful_push(): void
    {
        $transport = (new FakeBackupTransport())->holding([
            new RemoteArchive('i1', 'clubbar-20200101-030000-aaaa.cbb', 10, 'd1'),
            new RemoteArchive('i2', 'clubbar-20200201-030000-bbbb.cbb', 10, 'd1'),
        ]);

        $this->service(transport: $transport)->run('cli');

        // The newest is never deleted, whatever the arithmetic says: an
        // installation whose backups stopped must end up reported, not empty.
        $this->assertSame(
            ['clubbar-20200101-030000-aaaa.cbb'],
            array_map(static fn (RemoteArchive $a): string => $a->name, $transport->deleted)
        );
        $this->assertStringContainsString('"event":"remote_pruned"', $this->journalContents());
    }

    /**
     * The nights the upload fails are exactly the nights the old remote copies
     * matter, so retention does not run on them.
     */
    public function test_nothing_is_deleted_remotely_on_a_night_the_upload_failed(): void
    {
        $transport = (new FakeBackupTransport('failed'))->holding([
            new RemoteArchive('i1', 'clubbar-20200101-030000-aaaa.cbb', 10, 'd1'),
            new RemoteArchive('i2', 'clubbar-20200201-030000-bbbb.cbb', 10, 'd1'),
        ]);

        $this->service(transport: $transport)->run('cli');

        $this->assertSame([], $transport->deleted);
    }

    /** A store that cannot be listed costs a night of retention, never the run. */
    public function test_a_store_that_cannot_be_listed_is_reported_rather_than_fatal(): void
    {
        $outcome = $this->service(
            transport: (new FakeBackupTransport())->refusingToList('the library is gone')
        )->run('cli');

        $this->assertTrue($outcome->producedAnArchive(), $outcome->summary);
        $this->assertStringContainsString('the library is gone', implode(' ', $outcome->findings));
    }

    /** With no DSN configured nothing is attempted and nothing is said. */
    public function test_no_remote_configured_leaves_the_run_exactly_as_it_was(): void
    {
        $outcome = $this->service()->run('cli');

        $this->assertTrue($outcome->producedAnArchive(), $outcome->summary);
        $this->assertNull($outcome->remoteSummary);
        $this->assertStringNotContainsString('upload', $this->journalContents());
    }

    private function journalContents(): string
    {
        return (string) @file_get_contents($this->backupDir . '/' . BackupJournal::FILENAME);
    }

    private function service(
        ?string $publicKeys = null,
        ?string $directory = null,
        ?BackupRetention $retention = null,
        ?BackupTransport $transport = null,
    ): BackupService {
        return new BackupService(
            new DatabaseDump($this->db),
            new BackupKeyring(),
            $this->createMock(Logger::class),
            $directory ?? $this->backupDir,
            $publicKeys ?? $this->publicKeys,
            $retention ?? BackupRetention::defaults(),
            'development',
            $transport,
        );
    }
}
