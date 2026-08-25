<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Backups;

use App\Modules\Backups\Domain\BackupRetention;
use App\Modules\Backups\Services\BackupKeyring;
use App\Modules\Backups\Services\BackupService;
use App\Modules\Backups\Services\ConfigSnapshot;
use App\Modules\Backups\Services\DatabaseDump;
use App\Shared\Logging\Logger;
use App\Shared\Security\BackupSealedBox;
use PDO;
use Tests\Feature\DatabaseTestCase;
use Tests\Support\ScratchSchema;
use Tests\Support\SqlScript;
use Tests\Support\TempTree;

/**
 * The milestone that decides whether everything before it is a backup or a file.
 *
 * #688's tests prove the dumper *emits*: the right tables, the right counts,
 * bytes that survive the trip out. That is a different claim from the one a
 * club actually depends on, which is that the archive **loads** — and every way
 * a dump can be subtly unloadable survives an emission test untouched. A
 * missing `AUTO_INCREMENT`, a foreign key written before the table it points
 * at, an escape sequence that round-trips through PHP and not through the
 * server: each of those produces a file that looks exactly like a backup until
 * the day somebody needs it.
 *
 * So this file runs the **real** backup path — `BackupService::run()`, the same
 * call the nightly cron makes — decrypts what it wrote with the recipient's
 * private key, imports the result into an empty schema, and compares the two
 * databases.
 *
 * ### Four claims, because three of them would pass a broken dumper
 *
 * 1. **Rows.** Ordered checksums per table, every base table, source vs restored.
 * 2. **Schema.** Normalised `SHOW CREATE TABLE` per table. Row equality alone
 *    would pass unchanged against a dumper that dropped every one of this
 *    schema's secondary indexes, its foreign keys with their `ON DELETE`
 *    clauses, and the `CHECK` constraints migration `007` adds — none of which
 *    holds a row (ADR-0049 decision 7). This assertion is a few lines and is
 *    the only thing that proves them.
 * 3. **The header's own claims.** The archive header is the durable record
 *    (decision 8), so its manifest counts and its `plaintext_sha256` are
 *    promises, and a promise nothing checks is a comment. The manifest is
 *    compared against what the *restore* actually produced, not against what
 *    the dumper reported — comparing the dumper to itself proves nothing.
 * 4. **One table alone.** The runbook's second section tells an operator to
 *    repair a single table from its own section rather than restore everything,
 *    because restoring everything discards every booking since the dump. A
 *    procedure nobody has executed is the same kind of belief as an untested
 *    backup, so it is executed here.
 *
 * Part of #692, epic #686. ADR-0049 decision 7.
 */
class RestoreRoundTripTest extends DatabaseTestCase
{
    use TempTree;
    use ScratchSchema;
    use SqlScript;

    /**
     * A `config.php` whose text is awkward on purpose: it mentions the block's
     * own close marker, which is exactly what the base64 encoding exists to
     * survive.
     */
    private const CONFIG_FIXTURE = <<<'PHP'
        <?php
        // Mentions -- <<< CONFIG deliberately.
        return [
            'security' => [
                'totp_encryption_key' => 'zzzz-not-a-real-key-ümlaut',
                'iban_fingerprint_key' => 'also-not-real',
            ],
        ];
        PHP;

    private string $tempTree = '';
    private string $backupDir = '';
    private string $scratch = '';
    private string $configPath = '';

    private string $publicKeys = '';
    private string $secretKey = '';

    /** @var list<string> */
    private array $createdMemberIds = [];
    /** @var list<string> */
    private array $createdMandateIds = [];
    /** @var list<string> */
    private array $createdKeyIds = [];

    /**
     * Assigned before anything that could skip — CLAUDE.md, destructive test
     * cleanup. `$this->scratch` stays `''` until a schema exists, and
     * {@see ScratchSchema::dropScratchSchema()} refuses `''`.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tempTree = self::makeTempTree('clubbar-roundtrip');
        $this->backupDir = $this->tempTree . '/backups';

        $keypair = sodium_crypto_box_keypair();
        $this->secretKey = sodium_crypto_box_secretkey($keypair);
        $this->publicKeys = 'admin:' . bin2hex(sodium_crypto_box_publickey($keypair));

        // A stand-in for the installation's `config.php`, inside the temp tree
        // so nothing outside it is ever read. Its contents carry the two values
        // whose absence makes a restored database unusable, so the assertion
        // below is about the bytes a club would actually need back.
        $this->configPath = $this->tempTree . '/config.php';
        file_put_contents($this->configPath, self::CONFIG_FIXTURE);

        $this->seedTheAwkwardValues();
    }

    protected function tearDown(): void
    {
        self::dropScratchSchema($this->scratch);
        $this->scratch = '';

        self::removeTempTree($this->tempTree);

        // By tracked id, in foreign-key order. This suite shares one database
        // with every other Feature test, so a predicate-based delete here would
        // be deleting somebody else's fixtures.
        $this->deleteById('mandates', $this->createdMandateIds);
        $this->deleteById('members', $this->createdMemberIds);
        $this->deleteById('encryption_keys', $this->createdKeyIds);

        $this->createdMandateIds = [];
        $this->createdMemberIds = [];
        $this->createdKeyIds = [];

        parent::tearDown();
    }

    /**
     * Dump, seal, decrypt, restore, compare — rows and schema, every table.
     */
    public function test_the_restored_database_matches_the_one_that_was_dumped(): void
    {
        [$scratch, $header, $sql] = $this->runBackupAndRestore();

        $tables = $this->baseTablesOf($this->db);

        $this->assertNotEmpty($tables, 'The source schema has no tables; the comparison would be vacuous.');

        $restoredTables = $this->baseTablesOf($scratch);
        $this->assertSame(
            $tables,
            $restoredTables,
            'The restored schema does not hold the same set of tables as the source.'
        );

        foreach ($tables as $table) {
            $this->assertSame(
                $this->normalisedDdl($this->db, $table),
                $this->normalisedDdl($scratch, $table),
                sprintf(
                    'The restored `%s` is not the same table: indexes, foreign keys or CHECK '
                    . 'constraints did not survive the dump. Row equality would not have caught this.',
                    $table
                )
            );

            $this->assertSame(
                $this->rowFingerprints($this->db, $table),
                $this->rowFingerprints($scratch, $table),
                sprintf('The rows of `%s` did not survive the round trip.', $table)
            );
        }

        // Named explicitly as well as covered by the loop: this is the column
        // whose corruption would be silent and total. A VARBINARY that came
        // back as UTF-8-mangled bytes is an IBAN nobody can decrypt, and every
        // other assertion in this file would still pass.
        $this->assertGreaterThan(
            0,
            count($this->rowFingerprints($scratch, 'mandates')),
            'No mandates were restored, so the VARBINARY path was never exercised.'
        );

        $this->assertNotNull($header['plaintext_sha256']);
        $this->assertSame(
            hash('sha256', $sql),
            $header['plaintext_sha256'],
            'The header promises a checksum of the plaintext; the decryptor produced something else.'
        );
    }

    /**
     * The header is the record, so what it says is a proof obligation.
     *
     * The manifest is compared against the **restored** row counts rather than
     * against the source's: the source is what the dumper read, so comparing
     * the two would only prove the dumper agrees with itself. What a club needs
     * to know is that the number in the header is the number of rows they get
     * back.
     */
    public function test_the_header_manifest_counts_what_the_restore_actually_produced(): void
    {
        [$scratch, $header] = $this->runBackupAndRestore();

        $manifest = (array) $header['manifest'];

        $this->assertNotEmpty($manifest, 'An archive with an empty manifest describes nothing.');

        // Both sides through PHP's sort, not one through PHP's and one through
        // the server's. `ORDER BY TABLE_NAME` sorts under the schema's
        // collation, which weights `_` differently from a byte comparison —
        // `settlements` lands before `settlement_announcements` there and after
        // it here. Comparing the two orders directly fails on a correct
        // manifest, which is a test bug wearing a dumper bug's clothes.
        $this->assertSame(
            $this->sorted($this->baseTablesOf($this->db)),
            $this->sorted(array_keys($manifest)),
            'The manifest does not name exactly the tables that were dumped.'
        );

        foreach ($manifest as $table => $claimed) {
            $actual = (int) $scratch->query(
                'SELECT COUNT(*) FROM ' . $this->quoteIdentifier((string) $table)
            )->fetchColumn();

            $this->assertSame(
                (int) $claimed,
                $actual,
                sprintf(
                    'The header claims %d rows in `%s`; the restore produced %d.',
                    (int) $claimed,
                    $table,
                    $actual
                )
            );
        }
    }

    /**
     * Runbook section 2, executed rather than believed.
     *
     * The common disaster is one damaged table, and restoring the whole archive
     * is the *wrong* remedy for it — it discards every transaction booked since
     * the dump. The dumper writes terminated markers around each table's
     * section and names columns in every `INSERT` precisely so one section can
     * be imported alone. This drops a table from a restored schema and brings
     * it back from its own section, which is the only way to know the claim
     * holds.
     */
    public function test_one_table_can_be_restored_from_its_own_section(): void
    {
        [$scratch, , $sql] = $this->runBackupAndRestore();

        $before = $this->rowFingerprints($scratch, 'members');
        $this->assertNotEmpty($before, 'Nothing to lose, so nothing is proved by getting it back.');

        $ddl = $this->normalisedDdl($scratch, 'members');

        // Foreign keys off for the drop: `mandates` points at this table, and
        // the archive's own header switches them off for the same reason.
        $scratch->exec('SET FOREIGN_KEY_CHECKS = 0');
        $scratch->exec('DROP TABLE `members`');
        $scratch->exec('SET FOREIGN_KEY_CHECKS = 1');

        $this->assertNotContains('members', $this->baseTablesOf($scratch), 'The table is still there.');

        $section = self::tableSection($sql, 'members');
        $this->assertNotSame('', $section, 'The archive has no addressable section for `members`.');

        // The section alone, with the archive's session settings in front of
        // it — which is exactly what the runbook tells an operator to paste
        // into phpMyAdmin.
        self::executeScript($scratch, self::sessionPreamble($sql) . $section);

        $this->assertSame($ddl, $this->normalisedDdl($scratch, 'members'), 'The table came back different.');
        $this->assertSame($before, $this->rowFingerprints($scratch, 'members'), 'The rows came back different.');
    }

    /**
     * A restored database nobody can log in to is not a restore.
     *
     * `security.totp_encryption_key` decrypts every admin's TOTP secret and
     * lives in `config.php`, not in the database. Restore the rows without it
     * and every second factor fails, for every admin, with no way back in — and
     * it cannot be regenerated, because it is the key the stored ciphertext was
     * written under. `security.iban_fingerprint_key` is the same problem one
     * level down: mandate-change detection quietly stops recognising an IBAN it
     * has seen before.
     *
     * So the archive carries the file, and this asserts the club gets it back
     * byte for byte — while the payload stays one importable `.sql`, which is
     * the property that keeps the phpMyAdmin restore path a single step.
     */
    public function test_the_archive_carries_the_config_a_restore_cannot_do_without(): void
    {
        [$scratch, $header, $sql] = $this->runBackupAndRestore();

        $this->assertTrue(
            $header['config_included'],
            'The header must say whether an archive is a whole installation or only its rows — '
            . 'readable without a private key, because it changes what a restore still needs.'
        );

        $this->assertSame(
            self::CONFIG_FIXTURE,
            ConfigSnapshot::extract($sql),
            'The configuration did not survive the round trip. These are keys, not settings: '
            . 'nearly right is a restore that nearly works.'
        );

        // The block rode along inside the same payload that was just imported
        // into $scratch without error, which is the claim that matters for the
        // runbook: one file, pasted whole, and the comments are inert.
        $this->assertGreaterThan(
            0,
            count($this->baseTablesOf($scratch)),
            'The dump carrying a config block failed to import.'
        );
    }

    // -----------------------------------------------------------------------
    // The run itself
    // -----------------------------------------------------------------------

    /**
     * One real run, decrypted and imported into an empty schema.
     *
     * @return array{0: PDO, 1: array<string, mixed>, 2: string} connection, header, plaintext SQL
     */
    private function runBackupAndRestore(): array
    {
        if ($this->scratch !== '') {
            throw new \LogicException('Each test restores once; call this helper once per test.');
        }

        $outcome = (new BackupService(
            new DatabaseDump($this->db),
            new BackupKeyring(),
            $this->createMock(Logger::class),
            $this->backupDir,
            $this->publicKeys,
            BackupRetention::defaults(),
            'development',
            new ConfigSnapshot($this->configPath),
        ))->run('cli');

        $this->assertTrue($outcome->producedAnArchive(), 'The backup run produced nothing: ' . $outcome->summary);

        $sealed = (string) file_get_contents($this->backupDir . '/' . $outcome->filename);
        $header = BackupSealedBox::readHeader($sealed);
        $sql = BackupSealedBox::open($sealed, $this->secretKey);

        [$scratch, $name] = self::createScratchSchema($this->db);
        $this->scratch = $name;

        self::executeScript($scratch, $sql);

        return [$scratch, $header, $sql];
    }

    // -----------------------------------------------------------------------
    // Comparison
    // -----------------------------------------------------------------------

    /**
     * Every base table, in a stable order — the same question
     * {@see DatabaseDump} asks, so the two cannot disagree about what a table is.
     *
     * @return list<string>
     */
    private function baseTablesOf(PDO $db): array
    {
        return $db->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
             ORDER BY TABLE_NAME"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * `SHOW CREATE TABLE`, with the parts that legitimately differ removed.
     *
     * Two things differ between a source table and a faithful restore of it and
     * mean nothing:
     *
     * - **`AUTO_INCREMENT=n`** on the table options. The counter reflects rows
     *   ever inserted, not rows present, so a restored table starts wherever
     *   its highest key put it. Comparing it would fail on a correct restore.
     * - **The schema name**, when MariaDB qualifies a constraint reference.
     *
     * Everything else is kept, deliberately: column order, types, nullability,
     * defaults, every index, every foreign key with its referential actions,
     * `CHECK` constraints, the engine, the charset and the collation. Those are
     * the things a dumper can silently lose.
     */
    private function normalisedDdl(PDO $db, string $table): string
    {
        $row = $db->query('SHOW CREATE TABLE ' . $this->quoteIdentifier($table))->fetch(PDO::FETCH_NUM);
        $ddl = (string) $row[1];

        $ddl = (string) preg_replace('/\s*AUTO_INCREMENT=\d+/', '', $ddl);

        return trim($ddl);
    }

    /**
     * An ordered, hashed image of a table's rows.
     *
     * Hashed rather than compared value by value so a mismatch prints a short
     * diff instead of a megabyte of member data into the CI log — and so binary
     * columns are compared as bytes rather than through PHPUnit's exporter,
     * which would render `mandates.iban_ciphertext` unreadably either way.
     *
     * Ordered by the whole row rather than by a primary key, because not every
     * table has one and `ORDER BY` on an unindexed set is still deterministic.
     * The comparison is about content, not about physical order — InnoDB does
     * not promise the latter and a restore has no reason to reproduce it.
     *
     * @return list<string>
     */
    private function rowFingerprints(PDO $db, string $table): array
    {
        $quoted = $this->quoteIdentifier($table);

        $rows = $db->query("SELECT * FROM {$quoted}")->fetchAll(PDO::FETCH_ASSOC);

        $fingerprints = array_map(
            static function (array $row): string {
                $parts = [];
                foreach ($row as $column => $value) {
                    // The type marker keeps NULL distinct from the empty string
                    // and from the literal text "NULL" — three different things
                    // a dumper can confuse, and the confusion is invisible once
                    // they are concatenated.
                    $parts[] = $column . '=' . ($value === null ? "\0NULL\0" : "\0S\0" . $value);
                }

                return hash('sha256', implode("\x1f", $parts));
            },
            $rows
        );

        sort($fingerprints);

        return $fingerprints;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    // -----------------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------------

    /**
     * The values a dumper gets wrong, written before the dump reads them.
     *
     * A round trip over a database of well-behaved ASCII proves very little.
     * Each of these has a specific way of not surviving:
     *
     * - **Non-ASCII** — a name that comes back as `Ã¼` if the archive's charset
     *   handling is wrong anywhere along the way.
     * - **Quotes, backslashes and newlines** — the escaping contract between
     *   `SqlValueEmitter` and the server. A backslash-escaped value read back
     *   under `NO_BACKSLASH_ESCAPES` is silently different, which is why the
     *   archive pins `SQL_MODE`.
     * - **A byte string that is not valid UTF-8** — `mandates.iban_ciphertext`
     *   is `VARBINARY`, and a sealed box is random bytes. This is the column
     *   whose corruption is both silent and unrecoverable, since nothing else
     *   holds the IBAN.
     * - **NULL beside the empty string**, which a dumper can collapse into one.
     */
    private function seedTheAwkwardValues(): void
    {
        $unicode = $this->insertMember('Jörg-Ømer', "O'Brien \\ \"quoted\"\nsecond line");
        $this->insertMember('', 'Empty-First-Name');

        $this->insertMandate($unicode, random_bytes(96));
        // Deliberately not random: a run of high bytes that is definitely not
        // valid UTF-8, so a charset-mangling restore cannot pass by luck.
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
        $stmt->bindValue(3, 'RTRIP' . substr($this->generateUuid(), 0, 12));
        $stmt->bindValue(4, $ciphertext, PDO::PARAM_LOB);
        $stmt->bindValue(5, '3000');
        $stmt->bindValue(6, hash('sha256', $id));
        $stmt->bindValue(7, $this->ensureEncryptionKey());
        $stmt->execute();

        $this->createdMandateIds[] = $id;
    }

    /**
     * A key row rather than a skip when the schema has none.
     *
     * CI applies migrations without `seed.sql`, so "no key row" is the normal
     * state there — and a skip would be this repository's own anti-pattern: a
     * test reporting success for having found nothing.
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
        $stmt->bindValue(2, 'roundtrip-' . substr($id, 0, 8));
        $stmt->bindValue(3, 'SODIUM_CRYPTO_BOX_SEAL');
        $stmt->bindValue(4, random_bytes(32), PDO::PARAM_LOB);
        $stmt->bindValue(5, hash('sha256', $id));
        $stmt->bindValue(6, 'pending');
        $stmt->execute();

        $this->createdKeyIds[] = $id;

        return $id;
    }

    /**
     * @param list<string> $ids
     */
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
