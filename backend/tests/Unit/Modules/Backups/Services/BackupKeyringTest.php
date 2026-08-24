<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Backups\Services;

use App\Modules\Backups\Domain\BackupKeyringException;
use App\Modules\Backups\Repositories\BackupKeysRepository;
use App\Modules\Backups\Services\BackupKeyring;
use PHPUnit\Framework\TestCase;

/**
 * Who an archive is sealed to, and the two ways that can be nobody.
 *
 * Every failure here is deliberately fatal, and the tests say so in their
 * names: there is no sealing to whichever entries happened to parse. An archive
 * missing the one recipient still in the country is not a smaller problem than
 * no archive — it is a worse one, because it looks like a backup (ADR-0031
 * rule 3).
 *
 * Part of #690, epic #686.
 */
class BackupKeyringTest extends TestCase
{
    private const KEY_A = 'aa11223344556677889900aabbccddeeff00112233445566778899aabbccddee';
    private const KEY_B = 'bb11223344556677889900aabbccddeeff00112233445566778899aabbccddee';

    public function test_two_recipients_are_parsed_in_order(): void
    {
        $recipients = $this->keyring()->parse("admin:" . self::KEY_A . "\nvorstand:" . self::KEY_B);

        $this->assertSame(['admin', 'vorstand'], array_map(fn($r) => $r->label, $recipients));
        $this->assertSame(self::KEY_A, $recipients[0]->publicKeyHex);
    }

    /**
     * The seal side takes raw bytes and checks the *length*, so handing it hex
     * fails with "64 bytes, expected 32" — a message that points at the key
     * rather than at the conversion. Asserted here so the conversion has a
     * test of its own.
     */
    public function test_a_recipient_hands_the_seal_side_raw_bytes_not_hex(): void
    {
        $recipient = $this->keyring()->parse('admin:' . self::KEY_A)[0];

        $this->assertSame(32, strlen($recipient->toSealRecipient()['public_key']));
        $this->assertSame(hash('sha256', sodium_hex2bin(self::KEY_A)), $recipient->fingerprint());
    }

    public function test_no_configured_key_means_no_archive_rather_than_a_plaintext_one(): void
    {
        $this->expectException(BackupKeyringException::class);
        $this->expectExceptionMessageMatches('/plaintext backup is never written/');

        $this->keyring()->recipients('');
    }

    public function test_a_malformed_entry_names_itself_so_the_fix_is_an_edit(): void
    {
        $this->expectException(BackupKeyringException::class);
        $this->expectExceptionMessageMatches('/label:hexkey/');

        $this->keyring()->parse('admin:not-a-key');
    }

    /**
     * Two recipients under one label would leave the offline decryptor unable
     * to tell a holder which envelope to fetch — the one job its header does.
     */
    public function test_a_duplicated_label_is_refused(): void
    {
        $this->expectException(BackupKeyringException::class);
        $this->expectExceptionMessageMatches('/twice/');

        $this->keyring()->parse("admin:" . self::KEY_A . "\nADMIN:" . self::KEY_B);
    }

    /**
     * The precedence this class exists for: `config.php` declares, the database
     * can take away, and the database wins. A key marked compromised has to
     * stop being sealed to *now* — not once somebody edits a file over FTP.
     */
    public function test_a_compromised_key_is_dropped_even_though_config_still_names_it(): void
    {
        $blocked = hash('sha256', sodium_hex2bin(self::KEY_A));

        $recipients = $this->keyring($blocked)
            ->recipients("admin:" . self::KEY_A . "\nvorstand:" . self::KEY_B);

        $this->assertSame(['vorstand'], array_map(fn($r) => $r->label, $recipients));
    }

    /**
     * And when that leaves nothing, it is still a refusal rather than a
     * degraded archive — with the advice that matters, because a compromise is
     * when backups matter most.
     */
    public function test_every_key_compromised_refuses_rather_than_sealing_to_a_blocked_one(): void
    {
        $blocked = hash('sha256', sodium_hex2bin(self::KEY_A));

        $this->expectException(BackupKeyringException::class);
        $this->expectExceptionMessageMatches('/Add a replacement key/');

        $this->keyring($blocked)->recipients('admin:' . self::KEY_A);
    }

    public function test_blank_lines_and_stray_whitespace_are_not_entries(): void
    {
        $recipients = $this->keyring()->parse("\n  admin:" . self::KEY_A . "  \n\n");

        $this->assertCount(1, $recipients);
    }

    private function keyring(string ...$compromised): BackupKeyring
    {
        $keys = $this->createMock(BackupKeysRepository::class);
        $keys->method('compromisedFingerprints')->willReturn(array_values($compromised));

        return new BackupKeyring($keys);
    }
}
