<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Backups;

use App\Modules\Backups\Domain\BackupRetention;
use App\Modules\Backups\Services\BackupKeyring;
use App\Modules\Backups\Services\BackupService;
use App\Modules\Backups\Services\DatabaseDump;
use App\Shared\Logging\Logger;
use App\Shared\Security\BackupSealedBox;
use PDO;
use Tests\Feature\DatabaseTestCase;
use Tests\Support\ScratchSchema;
use Tests\Support\SqlScript;
use Tests\Support\TempTree;

/**
 * The hand-rolled dumper, diffed against `mariadb-dump`.
 *
 * #686's top risk is that `DatabaseDump` is subtly wrong, and the reason it is
 * the *top* risk is that the failure is silent: nothing detects it until the
 * day of a restore, which is the worst day to find out. {@see RestoreRoundTripTest}
 * closes most of that by proving the archive reloads the database it came from
 * — but it compares the dumper against *itself*, so a mistake the dumper makes
 * consistently in both directions survives it.
 *
 * This file closes the rest by introducing a second opinion. The reference host
 * has no `mysqldump` (ADR-0031), which is the whole reason the PHP dumper
 * exists — but **CI has one**, because the workflow already runs a
 * `mariadb:10.11` service container. So both dumps are taken of the same seeded
 * database, restored into two scratch schemas, and the *restored states* are
 * compared.
 *
 * ### Restored state, never dump text
 *
 * The two files differ on batching, formatting, comment style, the order of
 * table options and where they put a `LOCK TABLES`. None of that means
 * anything, and a text diff would be a permanent source of false failures that
 * somebody eventually deletes. What matters is whether a server, fed each file,
 * ends up in the same place — so that is the comparison: normalised
 * `SHOW CREATE TABLE` per table, plus ordered row checksums, every table,
 * uniformly.
 *
 * ### This does not make `mysqldump` a runtime path
 *
 * It cannot be one: the reference host has no shell. And a hybrid would be
 * worse than either — it would halve the field exposure of the PHP path, which
 * is the path every club actually runs, so the bugs would be found by clubs
 * instead of by CI. (ADR-0049 decision 7 and *Alternatives considered*.)
 *
 * Part of #692, epic #686.
 */
class DumpOracleTest extends DatabaseTestCase
{
    use TempTree;
    use ScratchSchema;
    use SqlScript;

    /**
     * Set in CI, where a dump binary is installed on purpose.
     *
     * With it set, a missing binary fails the run instead of skipping it. A
     * data-dependent skip reads as coverage while verifying nothing — this
     * repository's own anti-pattern, and the `lint-e2e` job exists to catch the
     * Playwright version of it. The oracle is the assertion CI is *for*, so CI
     * must never quietly not run it.
     */
    private const REQUIRED_ENV = 'BACKUP_ORACLE_REQUIRED';

    private string $tempTree = '';
    private string $backupDir = '';
    private string $ours = '';
    private string $theirs = '';

    private string $publicKeys = '';
    private string $secretKey = '';

    /** @var list<string> */
    private array $createdMemberIds = [];
    /** @var list<string> */
    private array $createdMandateIds = [];
    /** @var list<string> */
    private array $createdKeyIds = [];

    /**
     * Every path is assigned before anything that can skip.
     *
     * CLAUDE.md's destructive-cleanup rule, and this file is exactly the case
     * that motivated it: it *does* skip, on any machine without a dump binary,
     * and `tearDown()` runs for a skipped test anyway.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tempTree = self::makeTempTree('clubbar-oracle');
        $this->backupDir = $this->tempTree . '/backups';

        $keypair = sodium_crypto_box_keypair();
        $this->secretKey = sodium_crypto_box_secretkey($keypair);
        $this->publicKeys = 'admin:' . bin2hex(sodium_crypto_box_publickey($keypair));

        $this->requireDumpBinaryOrSkip();

        $this->seedTheAwkwardValues();
    }

    protected function tearDown(): void
    {
        self::dropScratchSchema($this->ours);
        self::dropScratchSchema($this->theirs);
        $this->ours = '';
        $this->theirs = '';

        self::removeTempTree($this->tempTree);

        $this->deleteById('mandates', $this->createdMandateIds);
        $this->deleteById('members', $this->createdMemberIds);
        $this->deleteById('encryption_keys', $this->createdKeyIds);

        $this->createdMandateIds = [];
        $this->createdMemberIds = [];
        $this->createdKeyIds = [];

        parent::tearDown();
    }

    /**
     * Two dumps, two restores, one comparison — every table, uniformly.
     *
     * There is no skip list and no per-table special-casing on either side,
     * because #703 removed the classification that would have needed
     * reproducing here. Every base table is dumped in full by both, so the
     * comparison is a plain loop.
     */
    public function test_the_php_dumper_restores_to_what_mariadb_dump_restores_to(): void
    {
        $ours = $this->restoreOurArchive();
        $theirs = $this->restoreTheirDump();

        $tables = $this->baseTablesOf($this->db);
        $this->assertNotEmpty($tables);

        $this->assertSame(
            $this->baseTablesOf($ours),
            $this->baseTablesOf($theirs),
            'The two restores do not even hold the same tables.'
        );

        $ddlMismatches = [];
        $rowMismatches = [];

        foreach ($tables as $table) {
            if ($this->normalisedDdl($ours, $table) !== $this->normalisedDdl($theirs, $table)) {
                $ddlMismatches[] = $table;
            }

            if ($this->rowFingerprints($ours, $table) !== $this->rowFingerprints($theirs, $table)) {
                $rowMismatches[] = $table;
            }
        }

        // Collected and reported together rather than failing on the first:
        // when this does go red it will be because of a systematic difference,
        // and "23 tables" is a different diagnosis from "only `mandates`".
        $this->assertSame(
            [],
            $ddlMismatches,
            'These tables restore to a different structure from the one `mariadb-dump` produces: '
            . implode(', ', $ddlMismatches)
            . '. Compare `SHOW CREATE TABLE` in the two scratch schemas.'
        );

        $this->assertSame(
            [],
            $rowMismatches,
            'These tables restore to different rows from the ones `mariadb-dump` produces: '
            . implode(', ', $rowMismatches) . '.'
        );
    }

    // -----------------------------------------------------------------------
    // The two restores
    // -----------------------------------------------------------------------

    /** The real backup path, decrypted and imported. */
    private function restoreOurArchive(): PDO
    {
        $outcome = (new BackupService(
            new DatabaseDump($this->db),
            new BackupKeyring(),
            $this->createMock(Logger::class),
            $this->backupDir,
            $this->publicKeys,
            BackupRetention::defaults(),
            'development',
        ))->run('cli');

        $this->assertTrue($outcome->producedAnArchive(), 'The backup run produced nothing: ' . $outcome->summary);

        $sql = BackupSealedBox::open(
            (string) file_get_contents($this->backupDir . '/' . $outcome->filename),
            $this->secretKey
        );

        [$db, $name] = self::createScratchSchema($this->db);
        $this->ours = $name;

        self::executeScript($db, $sql);

        return $db;
    }

    /** `mariadb-dump` of the same database, imported the same way. */
    private function restoreTheirDump(): PDO
    {
        $path = $this->tempTree . '/oracle.sql';

        // `--skip-dump-date` only so re-runs are byte-stable when somebody
        // looks at the file; nothing here reads it. `--single-transaction`
        // mirrors the consistent snapshot `DatabaseDump` takes, and
        // `--hex-blob` is what makes `mandates.iban_ciphertext` comparable
        // rather than a mangled string in the reference file too.
        $command = sprintf(
            '%s --host=%s --user=%s --password=%s --single-transaction --hex-blob '
            . '--skip-dump-date --routines=false --events=false --no-tablespaces %s > %s 2>%s',
            escapeshellcmd(self::dumpBinary()),
            escapeshellarg($this->dbHost()),
            escapeshellarg(getenv('DB_USER') ?: 'clubbar'),
            escapeshellarg(getenv('DB_PASS') ?: 'clubbar'),
            escapeshellarg((string) $this->db->query('SELECT DATABASE()')->fetchColumn()),
            escapeshellarg($path),
            escapeshellarg($path . '.err')
        );

        exec($command, $_, $status);

        $this->assertSame(
            0,
            $status,
            'mariadb-dump failed: ' . trim((string) @file_get_contents($path . '.err'))
        );

        [$db, $name] = self::createScratchSchema($this->db);
        $this->theirs = $name;

        // The reference dump sets a session time zone of its own; pin ours the
        // same way the archive does, so the two restores read `TIMESTAMP`
        // columns identically and the comparison is about the dumps rather
        // than about the sessions that loaded them.
        self::executeScript($db, "SET time_zone = '+00:00';\n" . (string) file_get_contents($path));

        return $db;
    }

    // -----------------------------------------------------------------------
    // Locating the oracle
    // -----------------------------------------------------------------------

    /**
     * Skip where there is genuinely no binary — but never in CI.
     *
     * The binary is absent in one ordinary place: the backend container, which
     * ships no database client. Running the Feature suite there is the workflow
     * CLAUDE.md recommends, so a hard failure would break it for everybody.
     * Running it on a host or a CI runner with `mariadb-client` installed is
     * what makes the oracle available, and {@see REQUIRED_ENV} is how CI says
     * "I am one of those" — after which a missing binary is a failure, not a
     * shrug.
     */
    private function requireDumpBinaryOrSkip(): void
    {
        if (self::dumpBinary() !== null) {
            return;
        }

        $message = 'No mariadb-dump/mysqldump on PATH, so the oracle has nothing to compare against. '
            . 'Install `mariadb-client` and run the suite against a reachable server '
            . '(DB_HOST=127.0.0.1 php8.3 vendor/bin/phpunit --filter DumpOracle).';

        if (getenv(self::REQUIRED_ENV) !== false && getenv(self::REQUIRED_ENV) !== '') {
            $this->fail(
                self::REQUIRED_ENV . ' is set, so this environment promised a dump binary and has none. '
                . 'CI must not silently skip the oracle. ' . $message
            );
        }

        $this->markTestSkipped($message);
    }

    /**
     * `mariadb-dump` for preference; `mysqldump` is the same binary under its
     * old name on a MariaDB client, and a genuinely different program on a
     * MySQL one. Either is a useful second opinion; the first is the one whose
     * output this schema was written against.
     */
    private static function dumpBinary(): ?string
    {
        if (($override = getenv('MARIADB_DUMP_BIN')) !== false && $override !== '') {
            return is_executable($override) ? $override : null;
        }

        foreach (['mariadb-dump', 'mysqldump'] as $candidate) {
            $path = trim((string) shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null'));

            if ($path !== '' && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function dbHost(): string
    {
        return getenv('DB_HOST') ?: 'database';
    }

    // -----------------------------------------------------------------------
    // Comparison — deliberately the same shape as RestoreRoundTripTest's
    // -----------------------------------------------------------------------

    /** @return list<string> */
    private function baseTablesOf(PDO $db): array
    {
        return $db->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
             ORDER BY TABLE_NAME"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * `SHOW CREATE TABLE`, minus what legitimately differs.
     *
     * `AUTO_INCREMENT=n` for the reason {@see RestoreRoundTripTest} gives, and
     * here a second one that is specific to the oracle: the two dumpers write
     * the counter differently, so keeping it would fail on every table with a
     * surrogate key while proving nothing about either.
     */
    private function normalisedDdl(PDO $db, string $table): string
    {
        $row = $db->query('SHOW CREATE TABLE ' . $this->quoteIdentifier($table))->fetch(PDO::FETCH_NUM);

        return trim((string) preg_replace('/\s*AUTO_INCREMENT=\d+/', '', (string) $row[1]));
    }

    /** @return list<string> */
    private function rowFingerprints(PDO $db, string $table): array
    {
        $rows = $db->query('SELECT * FROM ' . $this->quoteIdentifier($table))->fetchAll(PDO::FETCH_ASSOC);

        $fingerprints = array_map(
            static function (array $row): string {
                $parts = [];
                foreach ($row as $column => $value) {
                    $parts[] = $column . '=' . ($value === null ? "\0NULL\0" : "\0S\0" . $value);
                }

                return hash('sha256', implode("\x1f", $parts));
            },
            $rows
        );

        sort($fingerprints);

        return $fingerprints;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    // -----------------------------------------------------------------------
    // Fixtures — the same awkward values, for the same reasons
    // -----------------------------------------------------------------------

    private function seedTheAwkwardValues(): void
    {
        $unicode = $this->insertMember('Jörg-Ømer', "O'Brien \\ \"quoted\"\nsecond line");
        $this->insertMember('', 'Empty-First-Name');

        $this->insertMandate($unicode, random_bytes(96));
        $this->insertMandate($unicode, str_repeat("\xfe\xff\x00\x80", 24));
    }

    private function insertMember(string $firstName, string $lastName): string
    {
        $id = $this->generateUuid();

        $this->db->prepare(
            'INSERT INTO members (id, first_name, last_name, is_active, created_at)
             VALUES (?, ?, ?, 1, NOW())'
        )->execute([$id, $firstName, $lastName]);

        $this->createdMemberIds[] = $id;

        return $id;
    }

    private function insertMandate(string $memberId, string $ciphertext): void
    {
        $id = $this->generateUuid();

        $stmt = $this->db->prepare(
            'INSERT INTO mandates (id, member_id, reference, iban_ciphertext, iban_last4,
                                   iban_fingerprint, encryption_key_id, signed_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), NOW())'
        );
        $stmt->bindValue(1, $id);
        $stmt->bindValue(2, $memberId);
        $stmt->bindValue(3, 'ORACL' . substr($this->generateUuid(), 0, 12));
        $stmt->bindValue(4, $ciphertext, PDO::PARAM_LOB);
        $stmt->bindValue(5, '3000');
        $stmt->bindValue(6, hash('sha256', $id));
        $stmt->bindValue(7, $this->ensureEncryptionKey());
        $stmt->execute();

        $this->createdMandateIds[] = $id;
    }

    private function ensureEncryptionKey(): string
    {
        $existing = $this->db->query('SELECT id FROM encryption_keys LIMIT 1')->fetchColumn();
        if ($existing !== false) {
            return (string) $existing;
        }

        $id = $this->generateUuid();
        $stmt = $this->db->prepare(
            'INSERT INTO encryption_keys (id, key_identifier, algorithm, public_key,
                                          fingerprint_sha256, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->bindValue(1, $id);
        $stmt->bindValue(2, 'oracle-' . substr($id, 0, 8));
        $stmt->bindValue(3, 'SODIUM_CRYPTO_BOX_SEAL');
        $stmt->bindValue(4, random_bytes(32), PDO::PARAM_LOB);
        $stmt->bindValue(5, hash('sha256', $id));
        $stmt->bindValue(6, 'pending');
        $stmt->execute();

        $this->createdKeyIds[] = $id;

        return $id;
    }

    /** @param list<string> $ids */
    private function deleteById(string $table, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $stmt = $this->db->prepare(
            'DELETE FROM ' . $this->quoteIdentifier($table)
            . ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute($ids);
    }
}
