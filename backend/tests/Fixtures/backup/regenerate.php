<?php

/**
 * Regenerate `golden.cbb` and `golden.plaintext.sha256`.
 *
 *   php8.3 backend/tests/Fixtures/backup/regenerate.php
 *
 * The fixture is one archive that **both** container implementations open —
 * `BackupSealedBox` in PHP and `tools/backup-decryptor.js` in the browser a key
 * holder restores from. Neither side is its own witness, so a format change
 * that breaks either reader fails that reader's own test rather than surfacing
 * on the day of a restore.
 *
 * Which is exactly why regenerating it is a deliberate act with a script rather
 * than a step somebody reconstructs from memory: run this **only** when
 * {@see BackupSealedBox::VERSION} changes, and expect
 * `BackupSealedBoxGoldenFixtureTest` (PHP) and
 * `e2etests/scripts/backup-decryptor-interop.test.mjs` (JS) to need updating in
 * the same commit.
 *
 * It seals to the two keypairs already published in this repository (ADR-0036),
 * so the fixture leaks nothing that was not already public — and
 * {@see BackupSealedBox::seal()} still refuses those keys outside development,
 * which is why this passes `development` explicitly.
 *
 * `created_at` is pinned rather than taken from the clock, so re-running this
 * without a format change produces a byte-identical header and an empty diff.
 *
 * Part of #689 and #703, epic #686.
 */

declare(strict_types=1);

use App\Modules\Backups\Services\ConfigSnapshot;
use App\Modules\Backups\Services\DatabaseDump;
use App\Shared\Security\BackupSealedBox;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

/**
 * The published development public keys (ADR-0036), as the golden archive's two
 * recipients — and as *raw bytes*, which is the one thing easy to get wrong
 * here: hex would be 64 bytes and refused with a length error pointing at the
 * key rather than at the conversion.
 *
 * @return list<array{label: string, public_key: string}>
 */
function recipients(): array
{
    return [
        ['label' => 'admin', 'public_key' => sodium_hex2bin(
            '7479840773cdbd0f57bacf5c8488818e55845ee19207aaf685b74869c1682155'
        )],
        ['label' => 'vorstand', 'public_key' => sodium_hex2bin(
            '515f0f4eb534478980d7320182b4c9427b851f3f082cfb31e18b84b9e952d040'
        )],
    ];
}

/**
 * A payload whose **compressed** body spans three stream chunks.
 *
 * The size that matters is the compressed one, because that is what the stream
 * cipher frames — and #691's compression is exactly what made the old
 * plaintext-sized fixture stop testing framing at all: 135 KB of repetitive
 * SQL gzips to 5 KB, which is one chunk, and a single-chunk archive passes
 * even if the framing between chunks is wrong.
 *
 * So the payload is shaped like a real dump rather than filled with one
 * repeated byte: repetitive `INSERT` lines that compress the way SQL does, and
 * hex literals standing in for `mandates.iban_ciphertext`, which are sealed
 * boxes and therefore compress hardly at all. The mix is what gives a
 * believable ratio; the loop is what guarantees the framing is exercised
 * whatever that ratio turns out to be.
 *
 * @return array{0: string, 1: int} the SQL, and the rows it holds — so the
 *         header's manifest describes the payload rather than merely
 *         resembling it.
 */
function plaintext(): array
{
    $sql = "-- Club Bar database dump\n"
        . "-- Restore with a client that honours the session settings below.\n"
        . "--\n\n"
        . "SET NAMES utf8mb4;\n"
        . "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n"
        . "SET FOREIGN_KEY_CHECKS = 0;\n"
        . "SET time_zone = '+00:00';\n"
        . "\n-- >>> TABLE members\n"
        . "DROP TABLE IF EXISTS `members`;\n"
        . "CREATE TABLE `members` (`id` char(36) NOT NULL, `last_name` varchar(100) NOT NULL,"
        . " `sealed` varbinary(512) DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB"
        . " DEFAULT CHARSET=utf8mb4;\n";

    // Deterministic rather than random: re-running this script without a format
    // change must produce a byte-identical fixture, or every regeneration is a
    // diff nobody can review.
    $row = 0;
    $target = BackupSealedBox::CHUNK_BYTES * 2;

    while (strlen((string) gzencode($sql, 6)) <= $target) {
        $sql .= sprintf(
            "INSERT INTO `members` (`id`,`last_name`,`sealed`) VALUES\n('%s','%s',X'%s');\n",
            sprintf('00000000-0000-4000-8000-%012d', $row),
            // A name that needs escaping, and one that needs multibyte handling.
            $row % 2 === 0 ? "O\\'Brien" : 'Müller-Lüdenscheidt',
            // Stands in for a sealed box: high-entropy, so it resists
            // compression the way real ciphertext does.
            strtoupper(hash('sha256', 'clubbar-fixture-' . $row))
        );
        $row++;
    }

    return [$sql . "-- <<< TABLE members\n-- Dump complete\n", $row];
}

/**
 * The `config.php` the golden archive carries, and the block that carries it.
 *
 * Rendered by {@see ConfigSnapshot} rather than hand-written here, so the
 * fixture is a *product* of the PHP implementation. That is what makes the JS
 * side's `extractConfig` test a real cross-check: if the two ever disagree
 * about the format, the JS test goes red against bytes PHP actually produced,
 * not against a copy of the format somebody transcribed twice.
 *
 * The content mentions the close marker on purpose — the one case base64
 * exists to survive.
 *
 * @return array{0: string, 1: string} the config's own bytes, and the block
 */
function configBlock(): array
{
    $config = "<?php\n"
        . "// A golden fixture. Mentions -- <<< CONFIG deliberately.\n"
        . "return [\n"
        . "    'security' => ['totp_encryption_key' => 'not-a-real-key-ümlaut'],\n"
        . "];\n";

    $path = tempnam(sys_get_temp_dir(), 'clubbar-golden-config');
    file_put_contents($path, $config);

    try {
        $block = (new ConfigSnapshot($path))->render();
    } finally {
        // Named exactly, never globbed, and under the system temp directory
        // (CLAUDE.md, destructive cleanup).
        unlink($path);
    }

    return [$config, $block];
}

/**
 * A dump shaped for the per-table split, and the pieces PHP cuts out of it.
 *
 * The golden archive holds one table, which cannot test the property that
 * matters most: that a section stops at *its own* closing marker instead of
 * swallowing the table after it. So this is a second, plaintext-only fixture —
 * two tables, an `ALTER DATABASE` line to be dropped, a value containing the
 * marker text, and a trailing config block that must not be mistaken for a
 * table.
 *
 * The expected pieces are cut here, by {@see \Tests\Support\SqlScript} — the
 * same helper {@see \Tests\Feature\Modules\Backups\RestoreRoundTripTest}
 * uses to restore one table into a real database. That is what makes the
 * browser tool's splitter answerable to something: `backup-decryptor.js`
 * reproduces these bytes or its interop test goes red, and these bytes are
 * known to restore.
 */
function writeSplitFixture(string $configBlock): void
{
    $sql = "-- Club Bar database dump\n"
        . "-- Restore with a client that honours the session settings below.\n"
        . "--\n\n"
        . "SET NAMES utf8mb4;\n"
        . "SET @OLD_SQL_MODE = @@SQL_MODE;\n"
        . "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n"
        . "SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;\n"
        . "SET FOREIGN_KEY_CHECKS = 0;\n"
        . "SET @OLD_TIME_ZONE = @@TIME_ZONE;\n"
        . "SET time_zone = '+00:00';  -- TIMESTAMP is converted by the session zone\n"
        . "ALTER DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
        . "\n-- >>> TABLE categories\n"
        . "DROP TABLE IF EXISTS `categories`;\n"
        . "CREATE TABLE `categories` (`id` char(36) NOT NULL, `name` varchar(100) NOT NULL,"
        . " PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n"
        . "INSERT INTO `categories` (`id`,`name`) VALUES\n"
        // A row whose data reads like a marker. It must not end the section:
        // the markers are anchored to line starts and terminated by name.
        . "('00000000-0000-4000-8000-000000000001','-- >>> TABLE getranke'),\n"
        . "('00000000-0000-4000-8000-000000000002','Süßwaren');\n"
        . "-- <<< TABLE categories\n"
        . "\n-- >>> TABLE members\n"
        . "DROP TABLE IF EXISTS `members`;\n"
        . "CREATE TABLE `members` (`id` char(36) NOT NULL, `last_name` varchar(100) NOT NULL,"
        . " `sealed` varbinary(512) DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB"
        . " DEFAULT CHARSET=utf8mb4;\n"
        . "INSERT INTO `members` (`id`,`last_name`,`sealed`) VALUES\n"
        . "('00000000-0000-4000-8000-000000000003','Müller-Lüdenscheidt',X'DEADBEEF'),\n"
        . "('00000000-0000-4000-8000-000000000004','O\\'Brien',NULL);\n"
        . "-- <<< TABLE members\n"
        . "\nSET time_zone = @OLD_TIME_ZONE;\n"
        . "SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;\n"
        . "SET SQL_MODE = @OLD_SQL_MODE;\n"
        . "-- Dump complete\n"
        . $configBlock;

    $cutter = new class {
        use \Tests\Support\SqlScript;

        public static function preamble(string $sql): string
        {
            return self::sessionPreamble($sql);
        }

        public static function section(string $sql, string $table): string
        {
            return self::tableSection($sql, $table);
        }
    };

    file_put_contents(__DIR__ . '/golden.split.sql', $sql);

    foreach (['categories', 'members'] as $table) {
        $piece = $cutter::preamble($sql) . $cutter::section($sql, $table);

        if (str_contains($piece, 'ALTER DATABASE') || $piece === $cutter::preamble($sql)) {
            throw new RuntimeException("The split fixture for {$table} came out wrong.");
        }

        file_put_contents(__DIR__ . "/golden.split.{$table}.sql", $piece);
    }
}

[$payload, $rows] = plaintext();
[$config, $configBlock] = configBlock();
$payload .= $configBlock;

writeSplitFixture($configBlock);

$archive = BackupSealedBox::seal(
    $payload,
    recipients(),
    [
        'instance_id' => '3f1c9a52-0d5e-4f6b-9c21-6b9a0e0f77aa',
        'instance_name' => 'SV Musterstadt',
        'database' => 'clubbar',
        'schema_version' => '054_credit_limit_digest.sql',
        'dump_format' => DatabaseDump::FORMAT_VERSION,
        'manifest' => ['members' => $rows],
        'config_included' => true,
    ],
    'development',
    '2026-08-25T03:00:00+00:00',
);

file_put_contents(__DIR__ . '/golden.cbb', $archive);
file_put_contents(__DIR__ . '/golden.plaintext.sha256', hash('sha256', $payload) . "\n");
// The plaintext's length, so no test has to hardcode it. `backup-decryptor.spec.ts`
// asserts what the page prints, and a fixture change would otherwise turn into a
// failing assertion about a byte count that looks like a decryptor bug and is not.
file_put_contents(__DIR__ . '/golden.plaintext.bytes', strlen($payload) . "\n");
// What both readers must get back out of the block above.
file_put_contents(__DIR__ . '/golden.config.php.txt', $config);

printf(
    "Wrote golden.cbb (%d bytes, container version %d, %s) over a %d-byte plaintext in %d rows.\n",
    strlen($archive),
    BackupSealedBox::VERSION,
    BackupSealedBox::COMPRESSION,
    strlen($payload),
    $rows
);
