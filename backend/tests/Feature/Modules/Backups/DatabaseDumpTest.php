<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Backups;

use App\Modules\Backups\Domain\NonUtcDumpSessionException;
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
 * Part of #688, #699 and #703, epic #686.
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
     * Everything, and the list comes from the database rather than from us.
     *
     * This replaces the bijection test that policed a hand-maintained
     * classification map against the live schema (#703): there is no map to
     * drift any more, so what is worth asserting is the property the map was
     * *trying* to buy — that the archive holds every base table the schema has,
     * whatever the last migration added. A table added tomorrow is in
     * tomorrow's archive with no backup-side change, and this is what says so.
     */
    public function test_every_base_table_in_the_live_schema_is_in_the_archive(): void
    {
        $live = $this->liveBaseTables();
        sort($live);

        $manifest = array_keys($this->dump->dump(static fn(string $chunk) => null)->manifest);
        sort($manifest);

        $this->assertSame($live, $manifest, sprintf(
            'The archive covers %d of the schema\'s %d base tables. A table wrongly missing is '
            . 'missing on the day of a restore, and nothing before that day says so.',
            count($manifest),
            count($live)
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
     * The two kinds of table an earlier draft argued out of the archive, now in
     * it — and each is a *behaviour* worth pinning rather than a preference.
     *
     * `bank_codes` was schema-only, which meant a restored installation came
     * back with an empty lookup table and needed a re-import endpoint to fill
     * it (#703 deleted that endpoint along with the class). The rate-limit
     * counters were skipped, which was harmless and bought nothing. What
     * selection cost was unbounded — a table wrongly excluded is missing on the
     * day of a restore — and what it saved was bytes compression absorbs.
     */
    public function test_the_tables_selection_used_to_exclude_ride_along_in_full(): void
    {
        $sql = $this->dumpToString();

        foreach (['bank_codes', 'login_attempts', 'terminal_auth_attempts', 'terminal_ip_sightings'] as $table) {
            $this->assertStringContainsString(
                "CREATE TABLE `{$table}`",
                $sql,
                'A restored installation must come back complete, not needing a repopulation step.'
            );
        }
    }

    /**
     * What the archive says about the database it came from (ADR-0049
     * decision 8).
     *
     * There is no `backup_runs` row to look an archive up in any more, so these
     * three values exist only in the archive's own header. They are read here,
     * against a real schema, because that is where they can be wrong: the
     * migration ledger is created by the runner rather than by a migration, and
     * branding is a singleton row somebody can delete.
     */
    public function test_the_dump_can_describe_the_database_it_came_from(): void
    {
        $description = $this->dump->sourceDescription();

        $this->assertSame(
            (string) $this->db->query('SELECT DATABASE()')->fetchColumn(),
            $description['database']
        );
        $this->assertSame(
            (string) $this->db->query('SELECT MAX(file) FROM _migrations')->fetchColumn(),
            $description['schema_version'],
            'The schema version is the highest applied migration, so a reader knows which '
            . 'application version can load the archive.'
        );
        $this->assertSame(DatabaseDump::FORMAT_VERSION, $description['dump_format']);
        $this->assertNotNull(
            $description['instance_id'],
            'An archive found on a share has to be attributable to the club that wrote it.'
        );
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
        $this->assertSame(
            (int) $this->db->query('SELECT COUNT(*) FROM bank_codes')->fetchColumn(),
            $manifest['bank_codes'],
            'bank_codes is dumped in full like everything else; there is no schema-only class.'
        );
        $this->assertArrayHasKey('login_attempts', $manifest);
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
     * A table nobody has ever heard of is simply backed up.
     *
     * The earlier draft met this table with a hand-maintained map, a runtime
     * policy for the unclassified case, and a CI test policing the map — all of
     * it managing a hazard the map itself created (ADR-0049 decision 1 as
     * amended). Enumerating live deletes the hazard: there is nothing to
     * classify, so nothing can be unclassified, and completeness has no runtime
     * failure mode of its own. Confidentiality still fails closed, which is
     * `BackupServiceTest`'s.
     */
    public function test_a_table_nobody_declared_anywhere_is_simply_backed_up(): void
    {
        $probe = $this->createProbeTable();

        $sql = '';
        $result = $this->dump->dump(function (string $chunk) use (&$sql): void {
            $sql .= $chunk;
        });

        $this->assertStringContainsString("CREATE TABLE `{$probe}`", $sql);
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
    /**
     * Base tables only — deliberately the same filter the dumper applies, since
     * what is asserted is that the archive covers what it is *meant* to cover.
     * That the schema holds no views at all is a separate guard test above, and
     * it is the one that would fail if that ever changed.
     *
     * @return list<string>
     */
    private function liveBaseTables(): array
    {
        return $this->db->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
        )->fetchAll(\PDO::FETCH_COLUMN);
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
     * A table no map could have named, so "enumerated live" has something real
     * to meet. One row, so the manifest count is checkable.
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
