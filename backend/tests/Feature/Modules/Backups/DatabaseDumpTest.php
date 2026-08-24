<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Backups;

use App\Modules\Backups\Domain\NonUtcDumpSessionException;
use App\Modules\Backups\Domain\TableClass;
use App\Modules\Backups\Domain\TableClassification;
use App\Modules\Backups\Domain\UnclassifiedTableException;
use App\Modules\Backups\Domain\UnclassifiedTablePolicy;
use App\Modules\Backups\Services\DatabaseDump;
use PDO;
use Tests\Feature\DatabaseTestCase;

/**
 * The dumper against a real MariaDB, because the things it can get wrong are
 * things only a real server exhibits: what `SHOW CREATE TABLE` actually emits,
 * which columns report as binary, and whether a value survives the trip out.
 *
 * Restoring the archive and comparing row for row is #692's job. This file
 * proves the *emission*: the right tables, the right amount of each, and bytes
 * that come back the bytes they went in as.
 *
 * Part of #688, epic #686.
 */
class DatabaseDumpTest extends DatabaseTestCase
{
    private DatabaseDump $dump;

    /** @var list<string> */
    private array $createdMandateIds = [];
    /** @var list<string> */
    private array $createdMemberIds = [];
    /** @var list<string> */
    private array $createdKeyIds = [];

    /**
     * Tables this test created with DDL, to be dropped again.
     *
     * Names are generated below and never read from the schema, so cleanup can
     * only ever point at something this file made (CLAUDE.md, destructive test
     * cleanup): an empty list drops nothing, and the pattern guard means a
     * misassignment cannot turn into a DROP of a real table.
     *
     * @var list<string>
     */
    private array $createdProbeTables = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dump = new DatabaseDump($this->db);
    }

    /**
     * Remove exactly the rows this test created, in foreign-key order.
     *
     * Deleting by tracked id and never by a predicate: this suite shares one
     * database with every other Feature test, and `encryption_keys` in
     * particular is a table other suites make assertions about. CI applies
     * migrations without seed.sql, so a key row left behind here would be the
     * *first* one those tests see.
     */
    protected function tearDown(): void
    {
        $this->dropProbeTables();
        $this->deleteById('mandates', $this->createdMandateIds);
        $this->deleteById('members', $this->createdMemberIds);
        $this->deleteById('encryption_keys', $this->createdKeyIds);

        $this->createdMandateIds = [];
        $this->createdMemberIds = [];
        $this->createdKeyIds = [];

        parent::tearDown();
    }

    /**
     * Drop only names this file generated, and only names still shaped like one.
     */
    private function dropProbeTables(): void
    {
        foreach ($this->createdProbeTables as $table) {
            if (preg_match('/^zz_backup_probe_[0-9a-f]{8}$/', $table) !== 1) {
                continue;
            }

            $this->db->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }

        $this->createdProbeTables = [];
    }

    /** @param list<string> $ids */
    private function deleteById(string $table, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->db->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})")->execute($ids);
    }

    /**
     * The test that makes {@see TableClassification}'s throw reachable by a
     * migration rather than only by a typo — in both directions, because a
     * dropped table lingering in the map never fires on its own.
     */
    public function test_every_table_in_the_live_schema_is_classified(): void
    {
        $live = $this->liveTables();
        $classified = TableClassification::tables();

        sort($live);
        $missing = array_values(array_diff($live, $classified));
        $stale = array_values(array_diff($classified, $live));

        $this->assertSame([], $missing, sprintf(
            'These tables exist and no one decided whether they belong in a backup: %s. '
            . 'Add each to TableClassification::MAP.',
            implode(', ', $missing)
        ));

        $this->assertSame([], $stale, sprintf(
            'These tables are classified but no longer exist: %s. A dropped table left in '
            . 'the map is drift nothing else would catch.',
            implode(', ', $stale)
        ));
    }

    public function test_a_full_table_contributes_its_structure_and_its_rows(): void
    {
        $this->createMember();

        $sql = $this->dumpToString();

        $this->assertStringContainsString('CREATE TABLE `members`', $sql);
        $this->assertMatchesRegularExpression('/INSERT INTO `members`/', $sql);
    }

    /**
     * bank_codes is ~20k rows of reference data. Its structure must be there so
     * a restore is loadable; its rows must not, or they dominate every archive.
     */
    public function test_a_schema_only_table_contributes_structure_but_no_rows(): void
    {
        $sql = $this->dumpToString();

        $this->assertStringContainsString('CREATE TABLE `bank_codes`', $sql);
        $this->assertStringNotContainsString('INSERT INTO `bank_codes`', $sql);
    }

    public function test_a_skipped_table_contributes_nothing_at_all(): void
    {
        $sql = $this->dumpToString();

        foreach (TableClassification::tablesOfClass(TableClass::SKIP) as $skipped) {
            $this->assertStringNotContainsString("CREATE TABLE `{$skipped}`", $sql);
            $this->assertStringNotContainsString("INSERT INTO `{$skipped}`", $sql);
        }
    }

    /**
     * The hazard the whole slice exists for. `mandates.iban_ciphertext` is
     * VARBINARY(512) holding a sealed box; if the dumper quotes it as text it
     * comes back a different sequence of bytes and never opens again.
     */
    public function test_a_binary_column_is_emitted_as_the_exact_bytes_it_holds(): void
    {
        $memberId = $this->createMember();
        $sealed = random_bytes(96);
        $this->insertMandateWithCiphertext($memberId, $sealed);

        $sql = $this->dumpToString();

        $this->assertStringContainsString(
            "X'" . strtoupper(bin2hex($sealed)) . "'",
            $sql,
            'A VARBINARY value must appear as a hex literal of exactly its stored bytes.'
        );
    }

    /**
     * The emitter escapes with backslashes, which is only correct while the
     * restoring session allows them. The archive has to say so itself — the
     * operator restoring it will not be setting session variables by hand.
     */
    public function test_the_archive_pins_the_session_it_expects_to_be_restored_under(): void
    {
        $sql = $this->dumpToString();

        $this->assertStringContainsString('SET NAMES utf8mb4', $sql);
        $this->assertStringContainsString('NO_BACKSLASH_ESCAPES', $sql);
        $this->assertStringContainsString('FOREIGN_KEY_CHECKS', $sql);
    }

    public function test_the_manifest_counts_what_was_written(): void
    {
        $manifest = $this->dump->dump(static fn(string $chunk) => null)->manifest;

        $this->assertArrayHasKey('members', $manifest);
        $this->assertSame(
            (int) $this->db->query('SELECT COUNT(*) FROM members')->fetchColumn(),
            $manifest['members'],
            'The manifest is what a later restore is checked against; it must count real rows.'
        );
        $this->assertSame(0, $manifest['bank_codes'], 'A schema-only table contributes no rows.');
        $this->assertArrayNotHasKey('login_attempts', $manifest);
    }

    /**
     * The failure with no symptom.
     *
     * A `TIMESTAMP` is stored as an instant and rendered to text in the
     * *session* zone, so the same literal means different instants in different
     * sessions. This dump is read in UTC (ConnectionFactory pins it); an
     * operator importing through the host's phpMyAdmin is not, and every one of
     * the schema's TIMESTAMP columns then lands shifted by the host's offset —
     * settlement dates, audit timestamps and the ADR-0038 seven-day window
     * moving together, consistently, with nothing about the result looking
     * wrong. So the archive states its own session.
     *
     * The proof runs the conversion a restore would run, rather than a whole
     * round trip (that is #692's): `UNIX_TIMESTAMP('<literal>')` interprets a
     * string in the session zone, which is exactly what an INSERT into a
     * TIMESTAMP column does. Both directions are asserted, because the negative
     * is what says this test would notice the line going missing again.
     */
    public function test_the_archive_pins_utc_so_a_restore_cannot_shift_every_timestamp(): void
    {
        $memberId = $this->createMember();
        $sql = $this->dumpToString();

        $this->assertStringContainsString(
            "SET time_zone = '+00:00'",
            $sql,
            'Without this line every TIMESTAMP in the archive is read back in the importer\'s zone.'
        );

        $instant = (int) $this->db
            ->query("SELECT UNIX_TIMESTAMP(created_at) FROM members WHERE id = '{$memberId}'")
            ->fetchColumn();
        $literal = $this->createdAtLiteralFor($sql, $memberId);

        $importer = $this->connectionInZone('+02:00');

        $this->assertSame(
            $instant - 7200,
            (int) $importer->query("SELECT UNIX_TIMESTAMP('{$literal}')")->fetchColumn(),
            'Precondition: an importer two hours east really does read the literal shifted. '
            . 'If this fails the assertion below proves nothing.'
        );

        $importer->exec($this->sessionPreambleOf($sql));

        $this->assertSame(
            $instant,
            (int) $importer->query("SELECT UNIX_TIMESTAMP('{$literal}')")->fetchColumn(),
            'After the archive has set its own session, the same literal must mean the same '
            . 'instant it did when it was written.'
        );
    }

    /**
     * A guard test, not a behaviour test: it asserts a fact about the schema.
     *
     * The dumper reads base tables and nothing else, so a view, trigger, routine
     * or event added by a later migration would be *silently absent* from the
     * archive rather than loudly missing. The day one is added, this says so
     * while a human is present, rather than the restore saying so years later.
     */
    public function test_the_schema_holds_no_views_triggers_or_routines_the_dumper_would_lose(): void
    {
        $counts = [
            'views' => 'SELECT COUNT(*) FROM information_schema.TABLES
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE <> \'BASE TABLE\'',
            'triggers' => 'SELECT COUNT(*) FROM information_schema.TRIGGERS
                           WHERE TRIGGER_SCHEMA = DATABASE()',
            'routines' => 'SELECT COUNT(*) FROM information_schema.ROUTINES
                           WHERE ROUTINE_SCHEMA = DATABASE()',
            'events' => 'SELECT COUNT(*) FROM information_schema.EVENTS
                         WHERE EVENT_SCHEMA = DATABASE()',
        ];

        foreach ($counts as $what => $query) {
            $this->assertSame(0, (int) $this->db->query($query)->fetchColumn(), sprintf(
                'The schema has gained %s. DatabaseDump emits base tables only, so these would '
                . 'be missing from every archive without anything reporting it. Either teach the '
                . 'dumper to emit them, or record here why losing them on a restore is acceptable.',
                $what
            ));
        }
    }

    /**
     * The second guard test. `START TRANSACTION WITH CONSISTENT SNAPSHOT` covers
     * InnoDB and nothing else, so a table on another engine is read at whatever
     * moment the cursor happens to reach it. The archive then tears between
     * tables — a settlement present without its items — and looks perfectly
     * normal.
     */
    public function test_every_table_is_innodb_so_the_snapshot_actually_covers_it(): void
    {
        $other = $this->db->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND ENGINE <> 'InnoDB'
             ORDER BY TABLE_NAME"
        )->fetchAll(PDO::FETCH_COLUMN);

        $this->assertSame([], $other, sprintf(
            'These tables are not InnoDB: %s. The dump\'s consistent snapshot does not cover '
            . 'them, so the archive can tear between tables while looking correct.',
            implode(', ', $other)
        ));
    }

    /**
     * The asymmetry ADR-0049 decision 1 states: CI fails closed, the 03:00 run
     * fails open and reports. Both halves are asserted here, because either one
     * alone is a policy that only appears to exist.
     */
    public function test_an_unclassified_table_is_included_and_reported_rather_than_ending_the_run(): void
    {
        $probe = $this->createProbeTable();

        try {
            $this->dump->dump(static fn(string $chunk) => null);
            $this->fail('The default policy must refuse a table nobody classified.');
        } catch (UnclassifiedTableException $expected) {
            $this->assertStringContainsString($probe, $expected->getMessage());
        }

        $sql = '';
        $result = (new DatabaseDump($this->db, UnclassifiedTablePolicy::INCLUDE_AND_REPORT))
            ->dump(function (string $chunk) use (&$sql): void {
                $sql .= $chunk;
            });

        $this->assertSame(
            [$probe],
            $result->unclassifiedTables,
            'A guessed table has to reach the caller, or the fail-open half is simply silent.'
        );
        $this->assertTrue($result->guessed());
        $this->assertStringContainsString(
            "CREATE TABLE `{$probe}`",
            $sql,
            'An unrecognised table is likelier business data than ephemera, so it is included.'
        );
        $this->assertStringContainsString("INSERT INTO `{$probe}`", $sql);
        $this->assertSame(1, $result->manifest[$probe]);
    }

    /**
     * Restoring everything is the wrong remedy for a single lost table, and on
     * shared hosting it is often not a remedy at all: phpMyAdmin has an upload
     * limit a grown archive will not fit under. Both wants are the same
     * property — a boundary something can cut on, which the human-readable
     * comment that was there before is not.
     */
    public function test_each_table_is_delimited_so_one_of_them_can_be_restored_alone(): void
    {
        $this->createMember();

        $sql = $this->dumpToString();
        $section = $this->sectionFor($sql, 'members');

        $this->assertStringContainsString('CREATE TABLE `members`', $section);
        $this->assertStringContainsString('INSERT INTO `members`', $section);
        $this->assertStringNotContainsString(
            'CREATE TABLE `products`',
            $section,
            'A cut between markers must yield one table and no part of the next.'
        );
        $this->assertStringNotContainsString('-- >>> TABLE', $section);
    }

    /**
     * Without named columns a section only loads into a byte-identical schema,
     * so the first migration adding a nullable column retires every archive
     * taken before it — exactly when a single-table restore is wanted.
     */
    public function test_inserts_name_their_columns_so_a_single_table_restores_into_a_grown_schema(): void
    {
        $this->createMember();

        $sql = $this->dumpToString();

        $this->assertMatchesRegularExpression(
            '/INSERT INTO `members` \(`id`,.*`created_at`.*\) VALUES/',
            $sql,
            'Every INSERT names the columns it fills.'
        );
    }

    /**
     * The reading half of the same time-zone problem: a literal written from a
     * non-UTC session is wrong before the archive's header ever gets a say.
     * DatabaseDump takes any PDO, so it checks rather than assuming.
     */
    public function test_a_dump_taken_on_a_non_utc_connection_is_refused_rather_than_written(): void
    {
        $berlin = $this->connectionInZone('+02:00');

        $this->expectException(NonUtcDumpSessionException::class);

        (new DatabaseDump($berlin))->dump(static fn(string $chunk) => null);
    }

    /**
     * The archive recreates its tables but never the schema they land in, so the
     * database's own defaults would otherwise be whatever the host's phpMyAdmin
     * created. The statement omits the database name on purpose: unnamed, it
     * applies to whichever schema the restore is actually running in.
     */
    public function test_the_archive_sets_the_character_set_of_the_schema_it_lands_in(): void
    {
        $sql = $this->dumpToString();

        $this->assertMatchesRegularExpression(
            '/ALTER DATABASE CHARACTER SET utf8mb4 COLLATE \w+;/',
            $sql
        );
    }

    private function dumpToString(): string
    {
        $sql = '';
        $this->dump->dump(function (string $chunk) use (&$sql): void {
            $sql .= $chunk;
        });

        return $sql;
    }

    /** @return list<string> */
    private function liveTables(): array
    {
        return $this->db->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * A connection that is deliberately *not* UTC, standing in for the host's
     * own phpMyAdmin session.
     */
    private function connectionInZone(string $offset): PDO
    {
        $host = getenv('DB_HOST') ?: 'database';
        $name = getenv('DB_NAME') ?: 'clubbar';
        $user = getenv('DB_USER') ?: 'clubbar';
        $pass = getenv('DB_PASS') ?: 'clubbar';

        $pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name),
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec("SET time_zone = '{$offset}'");

        return $pdo;
    }

    /** Everything the archive sets before its first table. */
    private function sessionPreambleOf(string $sql): string
    {
        $end = strpos($sql, '-- >>> TABLE');
        $this->assertNotFalse($end, 'The archive has no table markers to end its preamble at.');

        return substr($sql, 0, $end);
    }

    /** The one table's statements, between its own markers. */
    private function sectionFor(string $sql, string $table): string
    {
        $open = "-- >>> TABLE {$table}\n";
        $close = "-- <<< TABLE {$table}\n";

        $from = strpos($sql, $open);
        $to = strpos($sql, $close);
        $this->assertNotFalse($from, "No opening marker for `{$table}`.");
        $this->assertNotFalse($to, "No closing marker for `{$table}`.");

        return substr($sql, $from + strlen($open), $to - $from - strlen($open));
    }

    /** The `created_at` literal the dump wrote for one member row. */
    private function createdAtLiteralFor(string $sql, string $memberId): string
    {
        $matched = preg_match(
            "/\('" . preg_quote($memberId, '/') . "',.*?'(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)'/",
            $this->sectionFor($sql, 'members'),
            $m
        );
        $this->assertSame(1, $matched, 'The dump wrote no timestamp literal for the fixture row.');

        return $m[1];
    }

    /**
     * A table the classification map cannot know about, so the policy has
     * something real to meet. One row, so the manifest count is checkable.
     */
    private function createProbeTable(): string
    {
        $name = 'zz_backup_probe_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $this->createdProbeTables[] = $name;

        $this->db->exec(
            "CREATE TABLE `{$name}` (id INT PRIMARY KEY, note VARCHAR(32)) ENGINE=InnoDB"
        );
        $this->db->exec("INSERT INTO `{$name}` (id, note) VALUES (1, 'probe')");

        return $name;
    }
    private function createMember(): string
    {
        $id = $this->generateUuid();
        $stmt = $this->db->prepare(
            'INSERT INTO members (id, first_name, last_name, is_active, created_at)
             VALUES (?, ?, ?, 1, NOW())'
        );
        $stmt->execute([$id, 'Dump', 'Fixture ' . substr($id, 0, 8)]);
        $this->createdMemberIds[] = $id;

        return $id;
    }

    private function insertMandateWithCiphertext(string $memberId, string $ciphertext): void
    {
        $mandateId = $this->generateUuid();

        $stmt = $this->db->prepare(
            'INSERT INTO mandates (id, member_id, reference, iban_ciphertext, iban_last4,
                                   iban_fingerprint, encryption_key_id, signed_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), NOW())'
        );
        $stmt->bindValue(1, $mandateId);
        $stmt->bindValue(2, $memberId);
        $stmt->bindValue(3, 'DUMPTEST' . substr($this->generateUuid(), 0, 12));
        $stmt->bindValue(4, $ciphertext, \PDO::PARAM_LOB);
        $stmt->bindValue(5, '3000');
        $stmt->bindValue(6, str_repeat('a', 64));
        $stmt->bindValue(7, $this->ensureEncryptionKey());
        $stmt->execute();

        $this->createdMandateIds[] = $mandateId;
    }

    /**
     * Create a key row rather than skipping when the schema has none.
     *
     * A skip here would be the failure mode this repository has already been
     * bitten by: a test that reports success for having found nothing. CI
     * applies migrations without seed.sql, so "no key row" is the normal state
     * there, and the assertion that matters would silently never run.
     */
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
        $stmt->bindValue(2, 'dump-test-' . substr($id, 0, 8));
        $stmt->bindValue(3, 'SODIUM_CRYPTO_BOX_SEAL');
        $stmt->bindValue(4, random_bytes(32), \PDO::PARAM_LOB);
        $stmt->bindValue(5, hash('sha256', $id));
        $stmt->bindValue(6, 'pending');
        $stmt->execute();

        $this->createdKeyIds[] = $id;

        return $id;
    }
}
