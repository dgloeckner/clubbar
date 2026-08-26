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

[$payload, $rows] = plaintext();

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
    ],
    'development',
    '2026-08-25T03:00:00+00:00',
);

file_put_contents(__DIR__ . '/golden.cbb', $archive);
file_put_contents(__DIR__ . '/golden.plaintext.sha256', hash('sha256', $payload) . "\n");

printf(
    "Wrote golden.cbb (%d bytes, container version %d, %s) over a %d-byte plaintext in %d rows.\n",
    strlen($archive),
    BackupSealedBox::VERSION,
    BackupSealedBox::COMPRESSION,
    strlen($payload),
    $rows
);
