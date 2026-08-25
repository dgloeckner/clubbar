<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Backups\Services;

use App\Modules\Backups\Domain\BackupKeyringException;
use App\Modules\Backups\Services\BackupKeyring;
use PHPUnit\Framework\TestCase;

/**
 * Who an archive is sealed to, and what happens when the answer is nobody.
 *
 * Every failure here is deliberately fatal, and the tests say so in their
 * names: there is no sealing to whichever entries happened to parse. An archive
 * missing the one recipient still in the country is not a smaller problem than
 * no archive — it is a worse one, because it looks like a backup (ADR-0031
 * rule 3).
 *
 * The keyring reads `config.php` and nothing else (#703). An earlier draft
 * also consulted a `backup_keys.compromised_at` blocklist in the database, and
 * let it outrank the file; that went with the rest of the backup's database
 * state, because *a restore reverted it* — importing an archive predating the
 * compromise silently un-decided a security decision, at exactly the moment
 * somebody is restoring because something went wrong.
 *
 * Part of #690 and #703, epic #686.
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
        $this->expectExceptionMessageMatches('/configuring a key is what switches/i');

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

    public function test_blank_lines_and_stray_whitespace_are_not_entries(): void
    {
        $recipients = $this->keyring()->parse("\n  admin:" . self::KEY_A . "  \n\n");

        $this->assertCount(1, $recipients);
    }

    private function keyring(): BackupKeyring
    {
        return new BackupKeyring();
    }
}
