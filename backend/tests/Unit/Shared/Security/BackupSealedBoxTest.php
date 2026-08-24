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
 * Part of #689, epic #686.
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

        BackupSealedBox::seal('x', [$this->recipient('dev', $published)], 'production');
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
}
