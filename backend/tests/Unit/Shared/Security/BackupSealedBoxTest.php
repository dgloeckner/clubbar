<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Security;

use App\Shared\Security\BackupSealedBox;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The container that makes a backup safe to leave the host.
 *
 * The property under test is the one ADR-0049 is built on: **the server can
 * produce an archive it cannot itself read.** Sealing needs only public keys;
 * opening needs a private half that never touches the server. Everything below
 * either proves that, or proves the multi-recipient behaviour that makes a
 * volunteer's disappearance survivable.
 *
 * Part of #689, #703 and #691, epic #686.
 */
class BackupSealedBoxTest extends TestCase
{
    private string $pkA;
    private string $skA;
    private string $pkB;
    private string $skB;

    protected function setUp(): void
    {
        parent::setUp();

        $a = sodium_crypto_box_keypair();
        $this->pkA = sodium_crypto_box_publickey($a);
        $this->skA = sodium_crypto_box_secretkey($a);

        $b = sodium_crypto_box_keypair();
        $this->pkB = sodium_crypto_box_publickey($b);
        $this->skB = sodium_crypto_box_secretkey($b);
    }

    public function test_what_is_sealed_can_be_opened_by_the_recipient(): void
    {
        $archive = BackupSealedBox::seal('-- SQL dump --', [$this->recipient('admin', $this->pkA)]);

        $this->assertSame('-- SQL dump --', BackupSealedBox::open($archive, $this->skA));
    }

    /**
     * The whole point. Sealing takes public keys only, so a compromised server
     * yields archives it has no means to read.
     */
    public function test_sealing_never_needs_a_private_key(): void
    {
        // A sentinel rather than a word like "secret", which collides with the
        // algorithm name in the cleartext header and would make this pass or
        // fail for the wrong reason.
        $payload = 'PLAINTEXT-SENTINEL-9f3ac41d';

        $archive = BackupSealedBox::seal($payload, [$this->recipient('admin', $this->pkA)]);

        $this->assertStringNotContainsString($this->skA, $archive, 'The private key is not an input to sealing.');
        $this->assertStringNotContainsString($payload, $archive, 'The payload must not survive in the clear.');
    }

    /**
     * The Verein-shaped reason for a recipient *list*: one volunteer moving away
     * must not take the archives with them.
     */
    public function test_either_recipient_can_open_a_two_recipient_archive(): void
    {
        $archive = BackupSealedBox::seal('shared', [
            $this->recipient('admin', $this->pkA),
            $this->recipient('vorstand', $this->pkB),
        ]);

        $this->assertSame('shared', BackupSealedBox::open($archive, $this->skA));
        $this->assertSame('shared', BackupSealedBox::open($archive, $this->skB));
    }

    /**
     * The rotation overlap (ADR-0049 decision 3): after the outgoing key is
     * removed from the configuration, archives written during the window must
     * still open with it. Nothing re-seals them, so if this failed the overlap
     * would buy nothing.
     */
    public function test_an_archive_from_the_rotation_window_still_opens_with_the_retired_key(): void
    {
        $duringOverlap = BackupSealedBox::seal('overlap', [
            $this->recipient('outgoing', $this->pkA),
            $this->recipient('incoming', $this->pkB),
        ]);
        $afterRotation = BackupSealedBox::seal('after', [$this->recipient('incoming', $this->pkB)]);

        $this->assertSame('overlap', BackupSealedBox::open($duringOverlap, $this->skA));

        $this->expectException(InvalidArgumentException::class);
        BackupSealedBox::open($afterRotation, $this->skA);
    }

    public function test_a_key_the_archive_was_not_sealed_to_is_refused_by_name(): void
    {
        $archive = BackupSealedBox::seal('x', [$this->recipient('admin', $this->pkA)]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not sealed to this key/i');

        BackupSealedBox::open($archive, $this->skB);
    }

    /**
     * ADR-0031 rule 3, applied to encryption: refuse and report, never silently
     * degrade. An archive with no recipient is one nobody can ever open.
     */
    public function test_sealing_to_nobody_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/recipient/i');

        BackupSealedBox::seal('x', []);
    }

    public function test_the_header_names_its_recipients_so_a_holder_knows_which_key_to_fetch(): void
    {
        $archive = BackupSealedBox::seal('x', [
            $this->recipient('admin', $this->pkA),
            $this->recipient('vorstand', $this->pkB),
        ]);

        $header = BackupSealedBox::readHeader($archive);

        $this->assertSame(BackupSealedBox::VERSION, $header['version']);
        $this->assertCount(2, $header['recipients']);
        $this->assertSame(['admin', 'vorstand'], array_column($header['recipients'], 'label'));
        $this->assertSame(
            [$this->fingerprint($this->pkA), $this->fingerprint($this->pkB)],
            array_column($header['recipients'], 'fingerprint'),
            'The fingerprint is what lets a key holder match an archive to a key in the safe.'
        );
    }

    /**
     * The header is readable without any key at all — that is what lets the
     * decryptor say "fetch key X" before asking for anything.
     */
    public function test_the_header_is_readable_without_a_key(): void
    {
        $archive = BackupSealedBox::seal('x', [$this->recipient('admin', $this->pkA)]);

        $this->assertIsArray(BackupSealedBox::readHeader($archive));
    }

    public function test_a_truncated_archive_is_refused_rather_than_partly_restored(): void
    {
        $archive = BackupSealedBox::seal(str_repeat('payload', 10000), [
            $this->recipient('admin', $this->pkA),
        ]);

        $this->expectException(InvalidArgumentException::class);
        BackupSealedBox::open(substr($archive, 0, (int) (strlen($archive) * 0.8)), $this->skA);
    }

    public function test_a_tampered_body_is_refused(): void
    {
        $archive = BackupSealedBox::seal('trustworthy', [$this->recipient('admin', $this->pkA)]);
        $tampered = substr($archive, 0, -5) . strrev(substr($archive, -5));

        $this->expectException(InvalidArgumentException::class);
        BackupSealedBox::open($tampered, $this->skA);
    }

    public function test_a_payload_larger_than_one_chunk_round_trips(): void
    {
        $payload = random_bytes(BackupSealedBox::CHUNK_BYTES * 2 + 1234);

        $archive = BackupSealedBox::seal($payload, [$this->recipient('admin', $this->pkA)]);

        $this->assertSame($payload, BackupSealedBox::open($archive, $this->skA));
    }

    public function test_a_published_fixture_key_is_refused_outside_development(): void
    {
        // The ADR-0036 development keypair, published in this repository and
        // already blocked for IBANs. A backup sealed to it is a backup sealed
        // to everyone holding a clone.
        $published = sodium_hex2bin('7479840773cdbd0f57bacf5c8488818e55845ee19207aaf685b74869c1682155');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/published in this repository/i');

        BackupSealedBox::seal('x', [$this->recipient('dev', $published)], [], 'production');
    }

    /** @return array{label: string, public_key: string} */
    private function recipient(string $label, string $publicKey): array
    {
        return ['label' => $label, 'public_key' => $publicKey];
    }

    private function fingerprint(string $publicKey): string
    {
        return hash('sha256', $publicKey);
    }

    /**
     * A wrong-length key is caught with a message naming the recipient, not
     * with a libsodium error naming a parameter. The likeliest cause is hex
     * where raw bytes were meant — 64 characters instead of 32 bytes — and
     * "has a 64-byte public key; expected 32" points straight at it.
     */
    public function test_a_public_key_of_the_wrong_length_names_the_recipient(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/"vorstand" has a 64-byte public key; expected 32/');

        BackupSealedBox::seal('x', [['label' => 'vorstand', 'public_key' => bin2hex($this->pkA)]]);
    }

    /** Not a Club Bar archive at all — refused before anything is decrypted. */
    public function test_a_file_that_is_not_an_archive_is_refused_by_its_magic(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/bad magic/i');

        BackupSealedBox::open('just some bytes that are not an archive at all', $this->skA);
    }

    /**
     * A future format must not be opened by guesswork. Restoring a version this
     * build does not understand would be the one failure mode worse than
     * refusing: a partial restore that looked like a whole one.
     */
    public function test_an_archive_from_a_future_version_is_refused_rather_than_guessed_at(): void
    {
        $archive = BackupSealedBox::seal('x', [$this->recipient('admin', $this->pkA)]);

        // The version byte follows the magic, so a later build's archive is
        // told apart from a corrupt file before anything else is parsed.
        $tampered = substr_replace($archive, chr(9), strlen(BackupSealedBox::MAGIC), 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/version 9.*reads version/i');

        BackupSealedBox::open($tampered, $this->skA);
    }

    /**
     * The version byte and the JSON say the same thing, and disagreeing is a
     * refusal rather than a preference for whichever came first. They can only
     * disagree if somebody edited the header — which is not a corruption the
     * stream's tags would catch, because the header is deliberately outside the
     * encryption.
     */
    public function test_a_header_whose_version_contradicts_the_container_is_refused(): void
    {
        $archive = BackupSealedBox::seal('x', [$this->recipient('admin', $this->pkA)]);

        // Equal length, so the header's own length prefix stays honest.
        $tampered = str_replace('"version":' . BackupSealedBox::VERSION, '"version":9', $archive);
        $this->assertNotSame($archive, $tampered, 'Precondition: the header JSON carries a version.');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/header says version 9 but the container says 3/i');

        BackupSealedBox::open($tampered, $this->skA);
    }

    /**
     * The body is compressed before it is sealed (#691).
     *
     * A dump is SQL: highly repetitive, and the thing being paid for is upload
     * bandwidth on a club's connection and quota on the club's storage. This is
     * the slice where size costs something, which is why the compression lands
     * here and not in #690 — and why the decryptor's inflate side ships in the
     * same slice, so no archive ever exists that the shipped tool cannot open.
     */
    public function test_a_compressible_payload_produces_a_materially_smaller_archive(): void
    {
        // Shaped like a dump rather than random bytes, which would not compress
        // at all and would make this assertion vacuous.
        $payload = str_repeat("INSERT INTO `members` (`id`,`last_name`) VALUES (1,'Muster');\n", 2000);

        $archive = BackupSealedBox::seal($payload, [$this->recipient('admin', $this->pkA)]);

        $this->assertLessThan(
            strlen($payload) / 4,
            strlen($archive),
            'A repetitive SQL payload must compress; if this ever fails the body is going out raw.'
        );
    }

    public function test_a_compressed_archive_still_opens_to_exactly_what_went_in(): void
    {
        // Every byte class a dump carries: multibyte, the escapes the emitter
        // writes, NUL inside a hex literal's neighbourhood, and enough length
        // to span more than one stream chunk once compressed.
        $payload = str_repeat("Müller-Lüdenscheidt O\\'Brien \x00\x1a\n", 5000);

        $archive = BackupSealedBox::seal($payload, [$this->recipient('admin', $this->pkA)]);

        $this->assertSame($payload, BackupSealedBox::open($archive, $this->skA));
    }

    public function test_the_header_says_how_the_body_was_compressed(): void
    {
        // Stated rather than assumed: a reader must not have to infer the
        // codec from the bytes, and a future archive that is not compressed
        // has to be able to say so in the same field.
        $header = BackupSealedBox::readHeader(
            BackupSealedBox::seal('x', [$this->recipient('admin', $this->pkA)])
        );

        $this->assertSame('gzip', $header['compression']);
    }

    /**
     * The checksum describes the **plaintext**, not the compressed bytes.
     *
     * This is the one that would be easy to get wrong and hard to notice. What
     * a restore holds in its hands is the `.sql` the decryptor hands back, so
     * that is what the header's promise has to be about. A checksum of the
     * compressed intermediate would verify a thing nobody ever sees, and would
     * silently start disagreeing with the decryptor the day the compression
     * level changed.
     */
    public function test_the_header_checksum_describes_the_plaintext_not_the_compressed_bytes(): void
    {
        $payload = str_repeat('compress me, and then tell me about me. ', 500);

        $header = BackupSealedBox::readHeader(
            BackupSealedBox::seal($payload, [$this->recipient('admin', $this->pkA)])
        );

        $this->assertSame(strlen($payload), $header['plaintext_bytes']);
        $this->assertSame(hash('sha256', $payload), $header['plaintext_sha256']);
    }

    /**
     * Everything a reader needs in order to know what they are holding, before
     * they can open it.
     *
     * This is the half of ADR-0049 decision 8 that replaced three database
     * tables: there is no `backup_runs` row to look the archive up in, so an
     * archive found on a share years later has to be self-describing or it is
     * unidentifiable. None of it is secret — table names and row counts are not
     * member data — and everything that could change the plaintext is still
     * inside the authenticated stream.
     */
    public function test_the_header_describes_the_archive_without_any_key(): void
    {
        $payload = 'INSERT INTO `members` VALUES (1);';

        $archive = BackupSealedBox::seal($payload, [$this->recipient('admin', $this->pkA)], [
            'instance_id' => 'f0f0f0f0-0000-4000-8000-000000000001',
            'instance_name' => 'SV Musterstadt',
            'database' => 'clubbar_prod',
            'schema_version' => '054_credit_limit_digest.sql',
            'dump_format' => 1,
            'manifest' => ['members' => 1, 'transactions' => 42],
        ]);

        $header = BackupSealedBox::readHeader($archive);

        $this->assertSame('SV Musterstadt', $header['instance']['name']);
        $this->assertSame('f0f0f0f0-0000-4000-8000-000000000001', $header['instance']['id']);
        $this->assertSame('clubbar_prod', $header['instance']['database']);
        $this->assertSame('054_credit_limit_digest.sql', $header['schema_version']);
        $this->assertSame(1, $header['dump_format']);
        $this->assertSame(['members' => 1, 'transactions' => 42], $header['manifest']);
        $this->assertSame(strlen($payload), $header['plaintext_bytes']);
        $this->assertSame(
            hash('sha256', $payload),
            $header['plaintext_sha256'],
            'A restore proves it decrypted what was sealed by comparing against this.'
        );
    }

    /**
     * A caller that says nothing about the payload still produces a header that
     * *answers* — with nulls rather than with missing keys.
     *
     * A reader must never have to tell "this archive does not say" apart from
     * "this build did not know to ask". Only the backup job seals archives in
     * production, and it always describes them; this is about what a header
     * promises structurally.
     */
    public function test_an_undescribed_archive_still_answers_every_header_question(): void
    {
        $header = BackupSealedBox::readHeader(
            BackupSealedBox::seal('x', [$this->recipient('admin', $this->pkA)])
        );

        $this->assertNull($header['schema_version']);
        $this->assertNull($header['dump_format']);
        $this->assertNull($header['instance']['name']);
        $this->assertSame([], $header['manifest']);
    }

    /** A header shorter than its own length prefix claims is a truncated file. */
    public function test_an_archive_cut_inside_its_header_is_refused(): void
    {
        $archive = BackupSealedBox::seal('x', [$this->recipient('admin', $this->pkA)]);

        $this->expectException(\InvalidArgumentException::class);

        BackupSealedBox::open(substr($archive, 0, strlen(BackupSealedBox::MAGIC) + 6), $this->skA);
    }
}
