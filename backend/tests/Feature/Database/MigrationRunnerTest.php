<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Db\MigrationRunner;
use Tests\Feature\DatabaseTestCase;

/**
 * upgrade.php resolved the migrations directory from dirname($configFile)
 * instead of the directory the package was actually unpacked into. On an
 * installation whose data directory had been relocated (ADR-0031 decision 2),
 * that pointed at a directory with no *.sql files at all — and migrate()
 * treated "found nothing" the same as "nothing pending", reporting DONE
 * without ever applying 017-020. Every deploy since read as a green
 * "migrations succeeded" while the schema silently fell behind.
 *
 * These tests cover the two things that let that happen: glob() returning
 * nothing must fail loudly, and the normal apply/skip cycle must keep working
 * once it does.
 */
class MigrationRunnerTest extends DatabaseTestCase
{
    private string $migrationsDir = '';

    protected function tearDown(): void
    {
        if ($this->migrationsDir !== '' && is_dir($this->migrationsDir)) {
            foreach (glob($this->migrationsDir . '/*.sql') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->migrationsDir);
        }
        parent::tearDown();
    }

    public function test_fails_fast_when_the_directory_has_no_migration_files(): void
    {
        $this->migrationsDir = sys_get_temp_dir() . '/migration-runner-empty-' . bin2hex(random_bytes(6));
        mkdir($this->migrationsDir);

        $runner = new MigrationRunner($this->db);
        $log = $runner->migrate($this->migrationsDir, 'test');

        $this->assertCount(1, $log);
        $this->assertSame('FAIL', $log[0]['status']);
        $this->assertStringContainsString($this->migrationsDir, $log[0]['message']);

        $statuses = array_column($log, 'status');
        $this->assertNotContains('DONE', $statuses, 'an empty directory must never be reported as a successful migration run');
    }

    public function test_applies_a_pending_migration_and_skips_it_once_already_applied(): void
    {
        $this->migrationsDir = sys_get_temp_dir() . '/migration-runner-' . bin2hex(random_bytes(6));
        mkdir($this->migrationsDir);

        $table = 'migration_runner_test_' . bin2hex(random_bytes(6));
        file_put_contents(
            $this->migrationsDir . '/900_test_table.sql',
            "CREATE TABLE {$table} (id INT PRIMARY KEY);"
        );

        try {
            $runner = new MigrationRunner($this->db);

            $firstRun = $runner->migrate($this->migrationsDir, 'test');
            $this->assertSame(
                ['OK', 'DONE'],
                array_column($firstRun, 'status'),
                'the pending migration must run and the batch must report DONE'
            );

            $secondRun = (new MigrationRunner($this->db))->migrate($this->migrationsDir, 'test');
            $this->assertSame(
                ['SKIP', 'DONE'],
                array_column($secondRun, 'status'),
                'an already-applied migration must be skipped, not re-run'
            );
        } finally {
            $this->db->exec("DROP TABLE IF EXISTS {$table}");
            $this->db->prepare('DELETE FROM _migrations WHERE file = ?')->execute(['900_test_table.sql']);
        }
    }

    public function test_fails_on_a_migration_modified_after_it_was_applied(): void
    {
        $this->migrationsDir = sys_get_temp_dir() . '/migration-runner-tamper-' . bin2hex(random_bytes(6));
        mkdir($this->migrationsDir);

        $table = 'migration_runner_test_' . bin2hex(random_bytes(6));
        $file = $this->migrationsDir . '/900_test_table.sql';
        file_put_contents($file, "CREATE TABLE {$table} (id INT PRIMARY KEY);");

        try {
            (new MigrationRunner($this->db))->migrate($this->migrationsDir, 'test');

            // Tampering after the fact: the checksum on disk no longer matches
            // what was recorded when it was applied.
            file_put_contents($file, "CREATE TABLE {$table} (id INT PRIMARY KEY, extra_column INT);");

            $log = (new MigrationRunner($this->db))->migrate($this->migrationsDir, 'test');

            $this->assertCount(1, $log);
            $this->assertSame('FAIL', $log[0]['status']);
            $this->assertStringContainsString('INTEGRITY VIOLATION', $log[0]['message']);
        } finally {
            $this->db->exec("DROP TABLE IF EXISTS {$table}");
            $this->db->prepare('DELETE FROM _migrations WHERE file = ?')->execute(['900_test_table.sql']);
        }
    }
}
