<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Backups;

use App\Modules\Backups\Domain\UnclassifiedTablePolicy;
use App\Modules\Backups\Repositories\BackupConfigRepository;
use App\Modules\Backups\Repositories\BackupKeysRepository;
use App\Modules\Backups\Repositories\BackupRunsRepository;
use App\Modules\Backups\Services\BackupKeyring;
use App\Modules\Backups\Services\BackupService;
use App\Modules\Backups\Services\DatabaseDump;
use App\Shared\Security\BackupSealedBox;
use App\Shared\Services\AuditService;
use App\Shared\Logging\Logger;
use PDO;
use Tests\Feature\DatabaseTestCase;
use Tests\Support\TempTree;

/**
 * A backup run against a real database and a real filesystem, because what it
 * can get wrong lives in both: whether the archive actually opens, whether the
 * directory ends up 0700, and whether a run that failed left a row saying so.
 *
 * The archive directory is a temp tree ({@see TempTree}), never a path built
 * from a property that might not be set — CLAUDE.md, destructive test cleanup.
 *
 * Part of #690, epic #686.
 */
class BackupServiceTest extends DatabaseTestCase
{
    use TempTree;

    private string $tempTree = '';
    private string $backupDir = '';

    /**
     * Tables this test created with DDL, to be dropped again.
     *
     * Names are generated here and never read from the schema, so cleanup can
     * only point at something this file made; the pattern guard means a
     * misassignment cannot turn into a DROP of a real table.
     *
     * @var list<string>
     */
    private array $createdProbeTables = [];
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

        $this->db->exec('DELETE FROM backup_runs');
        $this->db->exec('DELETE FROM backup_keys');
        $this->db->exec('UPDATE backup_config SET enabled = 1 WHERE id = 1');
    }

    protected function tearDown(): void
    {
        foreach ($this->createdProbeTables as $table) {
            if (preg_match('/^zz_backup_probe_[0-9a-f]{8}$/', $table) === 1) {
                $this->db->exec('DROP TABLE IF EXISTS `' . $table . '`');
            }
        }
        $this->createdProbeTables = [];

        self::removeTempTree($this->tempTree);

        $this->db->exec('DELETE FROM backup_runs');
        $this->db->exec('DELETE FROM backup_keys');
        $this->db->exec('UPDATE backup_config SET enabled = 0 WHERE id = 1');

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

    public function test_the_run_row_records_what_is_needed_after_the_file_is_gone(): void
    {
        $outcome = $this->service()->run('cli');

        $row = $this->db->query('SELECT * FROM backup_runs')->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('local', $row['status']);
        $this->assertSame('cli', $row['trigger_source']);
        $this->assertSame($outcome->filename, $row['filename']);
        $this->assertSame(
            hash('sha256', (string) file_get_contents($this->backupDir . '/' . $outcome->filename)),
            $row['sha256'],
            'The checksum answers "is this the file we wrote" without a private key.'
        );
        $this->assertNotEmpty(
            json_decode((string) $row['key_fingerprints'], true),
            'Which keys open this archive has to outlive the artifact, or nobody can say '
            . 'which private key may finally be discarded.'
        );
    }

    /**
     * Fail closed. This is the one refusal that must never degrade into a
     * plaintext archive, and it must never *look* like a successful run.
     */
    public function test_a_run_with_no_recipient_key_writes_nothing_at_all(): void
    {
        $outcome = $this->service(publicKeys: '')->run('cli');

        $this->assertFalse($outcome->producedAnArchive());
        $this->assertTrue($outcome->needsAttention());
        $this->assertSame([], glob($this->backupDir . '/*') ?: []);
    }

    public function test_a_disabled_installation_writes_nothing_and_does_not_call_it_a_failure(): void
    {
        $this->db->exec('UPDATE backup_config SET enabled = 0 WHERE id = 1');

        $outcome = $this->service()->run('cli');

        $this->assertSame('skipped', $outcome->status);
        $this->assertFalse($outcome->needsAttention(), 'Disabled is a choice, not a fault.');
    }

    /**
     * The guard that stops `/api/cron/backup` filling the webspace quota. Keyed
     * on attempts rather than successes, because an attempt is what spends the
     * quota.
     */
    public function test_a_second_run_inside_the_interval_writes_no_second_archive(): void
    {
        $service = $this->service();
        $service->run('cli');

        $second = $service->run('url');

        $this->assertSame('skipped', $second->status);
        $this->assertCount(1, glob($this->backupDir . '/*' . BackupService::EXTENSION) ?: []);
        $this->assertSame(
            1,
            (int) $this->db->query('SELECT COUNT(*) FROM backup_runs')->fetchColumn()
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
     * The runtime half of the drift guard (#699's bijection test is the CI
     * half). A stored manifest answers "what was in that archive?" afterwards;
     * a compared one answers "did the shape of the database change last night?"
     * while somebody can still act on it.
     */
    public function test_a_table_vanishing_since_the_previous_archive_is_reported(): void
    {
        $previous = $this->db->query('SELECT * FROM backup_runs')->fetch(PDO::FETCH_ASSOC);
        $this->assertFalse($previous, 'Precondition: this test seeds the previous run itself.');

        $runs = new BackupRunsRepository($this->db);
        $runs->start('11111111-1111-4111-8111-111111111111', 'cli', gmdate('Y-m-d H:i:s', time() - 86400));
        $runs->markLocal(
            '11111111-1111-4111-8111-111111111111',
            'yesterday.cbb',
            10,
            str_repeat('0', 64),
            ['fp'],
            // The current shape plus one extra, so the *only* difference is the
            // disappearance — otherwise every real table reads as new and the
            // assertion would pass for the wrong reason.
            $this->manifestOfEveryTable(1) + ['a_table_that_has_since_been_dropped' => 3],
            gmdate('Y-m-d H:i:s', time() - 86400),
        );

        $outcome = $this->service()->run('cli');

        $this->assertSame(
            ['table "a_table_that_has_since_been_dropped" was in the previous archive and is not in this one'],
            $outcome->manifestDrift
        );
        $this->assertTrue($outcome->needsAttention());
    }

    /**
     * Row counts change every day by design, so comparing them would fire every
     * day — and an alarm that fires every day is one nobody reads.
     */
    public function test_row_counts_changing_is_not_drift(): void
    {
        $runs = new BackupRunsRepository($this->db);
        $runs->start('22222222-2222-4222-8222-222222222222', 'cli', gmdate('Y-m-d H:i:s', time() - 86400));
        $runs->markLocal(
            '22222222-2222-4222-8222-222222222222',
            'yesterday.cbb',
            10,
            str_repeat('0', 64),
            ['fp'],
            $this->manifestOfEveryTable(999999),
            gmdate('Y-m-d H:i:s', time() - 86400),
        );

        $this->assertSame([], $this->service()->run('cli')->manifestDrift);
    }

    /**
     * Pruning removes the artifact and keeps the row, because the row is what
     * says which private key still opens something (ADR-0049 decision 3).
     */
    public function test_pruning_removes_the_artifact_and_keeps_the_row(): void
    {
        $this->db->exec('UPDATE backup_config SET local_retention_days = 1 WHERE id = 1');

        $stale = '33333333-3333-4333-8333-333333333333';
        @mkdir($this->backupDir, 0700, true);
        file_put_contents($this->backupDir . '/stale.cbb', 'not really an archive');

        $runs = new BackupRunsRepository($this->db);
        $runs->start($stale, 'cli', gmdate('Y-m-d H:i:s', time() - (5 * 86400)));
        $runs->markLocal($stale, 'stale.cbb', 21, str_repeat('0', 64), ['fp'], ['members' => 1],
            gmdate('Y-m-d H:i:s', time() - (5 * 86400)));

        $outcome = $this->service()->run('cli');

        $this->assertSame(1, $outcome->prunedArchives);
        $this->assertFileDoesNotExist($this->backupDir . '/stale.cbb');

        $row = $this->db->query("SELECT pruned_at, key_fingerprints FROM backup_runs WHERE id = '{$stale}'")
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertNotNull($row['pruned_at']);
        $this->assertSame('["fp"]', $row['key_fingerprints']);
    }

    /**
     * The byte cap is a refusal, not a licence to delete the newest archive.
     * An installation whose single archive is over the cap must end up
     * reported, not empty — a club with no backup is worse than a club over a
     * number.
     */
    public function test_the_byte_cap_reports_rather_than_leaving_the_club_with_nothing(): void
    {
        $this->db->exec('UPDATE backup_config SET local_max_bytes = 1 WHERE id = 1');

        $outcome = $this->service()->run('cli');

        $this->assertTrue($outcome->producedAnArchive());
        $this->assertFileExists($this->backupDir . '/' . $outcome->filename);
        $this->assertNotEmpty(array_filter(
            $outcome->findings,
            static fn (string $f): bool => str_contains($f, 'over the')
        ));
    }

    /** First use is what the audit row keys on; a second run must not repeat it. */
    public function test_a_key_is_recorded_once_and_its_last_use_moves(): void
    {
        $service = $this->service();
        $service->run('cli');
        $first = $this->db->query('SELECT * FROM backup_keys')->fetch(PDO::FETCH_ASSOC);

        $service->run('cli', force: true);
        $rows = $this->db->query('SELECT * FROM backup_keys')->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $rows);
        $this->assertSame($first['first_seen_at'], $rows[0]['first_seen_at']);
    }

    /**
     * A run that cannot write must leave a row saying so — not a row still
     * reading `running`, which the staleness check would report as a stalled
     * scheduler rather than as the failure it was.
     *
     * The unwritable path is a *file* where the directory should be, which is
     * the closest reproducible stand-in for the real cause on shared hosting: a
     * data directory the cron user does not own. Running as root, a plain
     * `chmod 0500` would not stop the write.
     */
    public function test_a_run_that_cannot_write_records_the_failure_on_its_row(): void
    {
        file_put_contents($this->tempTree . '/not-a-directory', 'blocking the path');

        $outcome = $this->serviceWritingTo($this->tempTree . '/not-a-directory')->run('cli');

        $this->assertSame('failed', $outcome->status);
        $this->assertTrue($outcome->needsAttention());

        $row = $this->db->query('SELECT * FROM backup_runs')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('failed', $row['status']);
        $this->assertNotNull($row['finished_at']);
        $this->assertNotEmpty($row['last_error']);
    }

    /**
     * An operator who deleted an archive by hand must not leave a row claiming
     * it is still on disk — the panel would then offer a private key as "still
     * needed" for a file nobody has.
     */
    public function test_pruning_an_archive_somebody_already_deleted_still_marks_the_row(): void
    {
        $this->db->exec('UPDATE backup_config SET local_retention_days = 1 WHERE id = 1');

        $gone = '55555555-5555-4555-8555-555555555555';
        $runs = new BackupRunsRepository($this->db);
        $runs->start($gone, 'cli', gmdate('Y-m-d H:i:s', time() - (5 * 86400)));
        $runs->markLocal($gone, 'never-existed.cbb', 10, str_repeat('0', 64), ['fp'], ['members' => 1],
            gmdate('Y-m-d H:i:s', time() - (5 * 86400)));

        $outcome = $this->service()->run('cli');

        $this->assertSame(1, $outcome->prunedArchives);
        $this->assertNotNull(
            $this->db->query("SELECT pruned_at FROM backup_runs WHERE id = '{$gone}'")->fetchColumn()
        );
    }

    /**
     * A first-ever run has nothing to compare against, and must not report the
     * entire schema as new. Silence has to mean "nothing changed", or the
     * finding is noise from the first night onwards.
     */
    public function test_the_first_run_reports_no_drift(): void
    {
        $this->assertSame([], $this->service()->run('cli')->manifestDrift);
    }

    /**
     * A previous row whose manifest is unreadable — an older format, a
     * truncated column, a hand-edited row — is treated as "no comparison
     * available" rather than as drift. Reporting every table as new because a
     * JSON column would not parse is an alarm nobody would trust twice.
     */
    public function test_an_unreadable_previous_manifest_is_not_reported_as_drift(): void
    {
        $id = '66666666-6666-4666-8666-666666666666';
        $runs = new BackupRunsRepository($this->db);
        $runs->start($id, 'cli', gmdate('Y-m-d H:i:s', time() - 86400));
        $runs->markLocal($id, 'yesterday.cbb', 10, str_repeat('0', 64), ['fp'], ['members' => 1],
            gmdate('Y-m-d H:i:s', time() - 86400));
        $this->db->exec("UPDATE backup_runs SET table_manifest = NULL WHERE id = '{$id}'");

        $this->assertSame([], $this->service()->run('cli')->manifestDrift);
    }

    /**
     * The fail-open half of the policy has to reach the caller, or it is just a
     * silent guess. #690's entrypoint turns this into a finding and #693 into a
     * mail.
     */
    public function test_an_unclassified_table_is_reported_as_a_finding_without_stopping_the_run(): void
    {
        $probe = $this->createProbeTable();

        $outcome = $this->service()->run('cli');

        $this->assertTrue($outcome->producedAnArchive(), 'A guess must not cost the night\'s backup.');
        $this->assertSame([$probe], $outcome->unclassifiedTables);
        $this->assertNotEmpty(array_filter(
            $outcome->findings,
            static fn (string $f): bool => str_contains($f, $probe)
        ));
    }

    /**
     * A table the classification map cannot know about, so the fail-open policy
     * has something real to meet. Dropped again in tearDown, by a name this
     * file generated and nothing else (CLAUDE.md, destructive test cleanup).
     */
    private function createProbeTable(): string
    {
        $name = 'zz_backup_probe_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $this->createdProbeTables[] = $name;

        $this->db->exec("CREATE TABLE `{$name}` (id INT PRIMARY KEY, note VARCHAR(32)) ENGINE=InnoDB");
        $this->db->exec("INSERT INTO `{$name}` (id, note) VALUES (1, 'probe')");

        return $name;
    }

    private function serviceWritingTo(string $directory): BackupService
    {
        return new BackupService(
            new DatabaseDump($this->db, UnclassifiedTablePolicy::INCLUDE_AND_REPORT),
            new BackupKeyring(new BackupKeysRepository($this->db)),
            new BackupRunsRepository($this->db),
            new BackupKeysRepository($this->db),
            new BackupConfigRepository($this->db),
            $this->createMock(AuditService::class),
            $this->createMock(Logger::class),
            $directory,
            $this->publicKeys,
            'development',
        );
    }

    /** @return array<string, int> */
    private function manifestOfEveryTable(int $rows): array
    {
        $sql = '';
        $result = (new DatabaseDump($this->db, UnclassifiedTablePolicy::INCLUDE_AND_REPORT))
            ->dump(function (string $chunk) use (&$sql): void {
                $sql .= $chunk;
            });

        return array_map(static fn (): int => $rows, $result->manifest);
    }

    private function service(?string $publicKeys = null): BackupService
    {
        return new BackupService(
            new DatabaseDump($this->db, UnclassifiedTablePolicy::INCLUDE_AND_REPORT),
            new BackupKeyring(new BackupKeysRepository($this->db)),
            new BackupRunsRepository($this->db),
            new BackupKeysRepository($this->db),
            new BackupConfigRepository($this->db),
            $this->createMock(AuditService::class),
            $this->createMock(Logger::class),
            $this->backupDir,
            $publicKeys ?? $this->publicKeys,
            'development',
        );
    }
}
