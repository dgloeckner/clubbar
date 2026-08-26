<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Security;

use App\Modules\Backups\Services\ConfigSnapshot;
use App\Shared\Security\BackupSealedBox;
use PHPUnit\Framework\TestCase;

/**
 * One committed archive, opened by both implementations.
 *
 * The container has two readers: `BackupSealedBox` here, and
 * `tools/backup-decryptor.js` in the browser a key holder restores from. If
 * those drift apart, the failure surfaces on the day of a restore — the worst
 * possible moment, and the one this whole epic exists to avoid.
 *
 * So neither side is allowed to be its own witness. `tests/Fixtures/backup/
 * golden.cbb` was sealed once, is committed, and is opened by *both*:
 *
 *   - this test, and
 *   - `e2etests/scripts/backup-decryptor-interop.test.mjs`, which runs the
 *     JavaScript against the same bytes in CI's `lint-e2e` job.
 *
 * A format change that breaks either reader fails that reader's own test,
 * which is what makes "they cannot drift apart silently" a fact rather than an
 * intention. Regenerate the fixture only when the format version changes —
 * `php8.3 tests/Fixtures/backup/regenerate.php` — and expect both tests to
 * need updating together.
 *
 * It is sealed to the two keypairs already published in this repository
 * (ADR-0036), so the fixture leaks nothing that was not already public, and
 * {@see BackupSealedBox::seal()} still refuses those keys outside development.
 *
 * Part of #689 and #703, epic #686.
 */
class BackupSealedBoxGoldenFixtureTest extends TestCase
{
    private const DEV_SECRET_A = 'f678fb17b592c29db54e43f808ee74fd67f7dd5c6c405b24e3e31ead38f3058a';

    public function test_the_committed_archive_still_opens(): void
    {
        $plaintext = BackupSealedBox::open($this->archive(), sodium_hex2bin(self::DEV_SECRET_A));

        $this->assertSame(
            trim(file_get_contents($this->fixturePath('golden.plaintext.sha256'))),
            hash('sha256', $plaintext),
            'The committed archive no longer decrypts to what it was sealed from. Either the '
            . 'container format changed (regenerate the fixture and update the JS test too) or '
            . 'BackupSealedBox has a bug.'
        );
    }

    public function test_the_committed_archive_names_both_of_its_recipients(): void
    {
        $header = BackupSealedBox::readHeader($this->archive());

        $this->assertSame(BackupSealedBox::VERSION, $header['version']);
        $this->assertSame(BackupSealedBox::ALGORITHM, $header['algorithm']);
        $this->assertSame(['admin', 'vorstand'], array_column($header['recipients'], 'label'));
    }

    /**
     * The committed archive describes itself, with no key (ADR-0049 decision 8).
     *
     * Asserted on the *fixture* rather than only on a freshly sealed archive
     * because this is the artifact a key holder actually meets: bytes written
     * by an earlier build, opened years later, with nothing else to identify
     * them by. A field silently dropped from the header would still pass a
     * round-trip test.
     */
    public function test_the_committed_archive_says_what_it_holds(): void
    {
        $header = BackupSealedBox::readHeader($this->archive());

        $this->assertSame('SV Musterstadt', $header['instance']['name']);
        $this->assertSame('clubbar', $header['instance']['database']);
        $this->assertNotNull($header['instance']['id']);
        $this->assertSame('054_credit_limit_digest.sql', $header['schema_version']);
        $this->assertSame(1, $header['dump_format']);
        $this->assertArrayHasKey('members', $header['manifest']);

        $this->assertSame(
            trim(file_get_contents($this->fixturePath('golden.plaintext.sha256'))),
            $header['plaintext_sha256'],
            'The header states the checksum of what was sealed, so a restore can prove it '
            . 'decrypted that and not something else.'
        );
    }

    /**
     * The fixture's **compressed** body spans three chunks on purpose.
     *
     * The size that matters is the compressed one, because that is what the
     * stream cipher frames — and this is the assertion #691 nearly turned
     * vacuous: the old fixture was sized by its plaintext, and 135 KB of
     * repetitive SQL gzips to 5 KB, which is a single chunk. A single-chunk
     * archive passes even if the framing between chunks is wrong, which is the
     * one thing this fixture is here to catch.
     *
     * Asserted on the archive itself rather than on the plaintext, so it stays
     * true whatever the compression ratio turns out to be.
     */
    public function test_the_fixture_is_large_enough_to_exercise_chunk_framing(): void
    {
        $this->assertGreaterThan(
            BackupSealedBox::CHUNK_BYTES * 2,
            strlen($this->archive()),
            'Shrinking this fixture below three chunks would quietly stop testing the framing. '
            . 'Regenerate with tests/Fixtures/backup/regenerate.php, which sizes it by the '
            . 'compressed body for exactly this reason.'
        );
    }

    /**
     * The body is compressed, and the header says so rather than leaving a
     * reader to sniff it (#691).
     */
    public function test_the_committed_archive_is_compressed_and_says_which_codec(): void
    {
        $header = BackupSealedBox::readHeader($this->archive());

        $this->assertSame(BackupSealedBox::COMPRESSION, $header['compression']);
        $this->assertLessThan(
            $header['plaintext_bytes'],
            strlen($this->archive()),
            'A compressed archive must be smaller than what went into it.'
        );
    }

    /**
     * The config block, from the PHP side of the same fixture the JS side reads.
     *
     * `e2etests/scripts/backup-decryptor-interop.test.mjs` asserts the same
     * bytes through `tools/backup-decryptor.js`. Two readers, one fixture,
     * neither its own witness — which is the whole arrangement this file exists
     * for, extended to the format that carries `config.php` (#692).
     */
    public function test_the_fixture_carries_the_config_block_both_readers_must_understand(): void
    {
        $plaintext = BackupSealedBox::open($this->archive(), sodium_hex2bin(self::DEV_SECRET_A));

        $this->assertTrue(BackupSealedBox::readHeader($this->archive())['config_included']);

        $this->assertSame(
            (string) file_get_contents($this->fixturePath('golden.config.php.txt')),
            ConfigSnapshot::extract($plaintext),
            'The config block did not survive, so a restore onto a new host would arrive '
            . 'without the key that decrypts every admin\'s second factor.'
        );
    }

    private function archive(): string
    {
        return (string) file_get_contents($this->fixturePath('golden.cbb'));
    }

    private function fixturePath(string $name): string
    {
        return dirname(__DIR__, 3) . '/Fixtures/backup/' . $name;
    }
}
