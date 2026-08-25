<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Backups;

use App\Modules\Backups\Domain\TableClass;
use App\Modules\Backups\Domain\TableClassification;
use App\Modules\Backups\Services\DatabaseDump;
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
        $this->deleteById('mandates', $this->createdMandateIds);
        $this->deleteById('members', $this->createdMemberIds);
        $this->deleteById('encryption_keys', $this->createdKeyIds);

        $this->createdMandateIds = [];
        $this->createdMemberIds = [];
        $this->createdKeyIds = [];

        parent::tearDown();
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
        $manifest = $this->dump->dump(static fn(string $chunk) => null);

        $this->assertArrayHasKey('members', $manifest);
        $this->assertSame(
            (int) $this->db->query('SELECT COUNT(*) FROM members')->fetchColumn(),
            $manifest['members'],
            'The manifest is what a later restore is checked against; it must count real rows.'
        );
        $this->assertSame(0, $manifest['bank_codes'], 'A schema-only table contributes no rows.');
        $this->assertArrayNotHasKey('login_attempts', $manifest);
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
