<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Backups\Services;

use App\Modules\Backups\Services\SqlValueEmitter;
use PHPUnit\Framework\TestCase;

/**
 * Turning one column value back into SQL. Every hazard #688 names lives here,
 * because this is the only place a value can be quietly altered on the way out.
 *
 * A dumper that mangles a column does not fail; it produces a file that looks
 * exactly like a backup and restores something subtly different. There is no
 * signal until someone needs it, so each case below is a named test rather than
 * a line in a table.
 *
 * Part of #688, epic #686.
 */
class SqlValueEmitterTest extends TestCase
{
    /**
     * The distinction MySQL will happily blur and nobody notices until a
     * NOT NULL column rejects a restore — or worse, until an `IS NULL` query
     * starts answering differently.
     */
    public function test_null_is_not_the_empty_string(): void
    {
        $this->assertSame('NULL', SqlValueEmitter::literal(null));
        $this->assertSame("''", SqlValueEmitter::literal(''));
    }

    public function test_plain_text_is_quoted(): void
    {
        $this->assertSame("'Getränkewart'", SqlValueEmitter::literal('Getränkewart'));
    }

    public function test_a_quote_cannot_end_the_literal_early(): void
    {
        $this->assertSame("'it\\'s'", SqlValueEmitter::literal("it's"));
    }

    public function test_a_backslash_survives_as_a_backslash(): void
    {
        // 'C:\path' must not restore as 'C:path'.
        $this->assertSame("'C:\\\\path'", SqlValueEmitter::literal('C:\\path'));
    }

    public function test_control_bytes_are_escaped_rather_than_embedded_raw(): void
    {
        $this->assertSame("'a\\nb'", SqlValueEmitter::literal("a\nb"));
        $this->assertSame("'a\\rb'", SqlValueEmitter::literal("a\rb"));
        $this->assertSame("'a\\0b'", SqlValueEmitter::literal("a\0b"));
        // 0x1A ends input on some Windows clients if emitted raw.
        $this->assertSame("'a\\Zb'", SqlValueEmitter::literal("a\x1Ab"));
    }

    /**
     * `mandates.iban_ciphertext` is VARBINARY(512) holding a libsodium sealed
     * box — arbitrary bytes that are not text in any encoding. Quoting and
     * escaping them is how a sealed IBAN comes back one byte different and
     * never opens again, so binary goes out as a hex literal and never as a
     * quoted string.
     */
    public function test_binary_is_emitted_as_a_hex_literal(): void
    {
        $sealed = "\x00\x01\xFF\xFE\x7Fbin\x00";

        $this->assertSame("X'0001FFFE7F62696E00'", SqlValueEmitter::literal($sealed, binary: true));
    }

    public function test_binary_round_trips_byte_for_byte(): void
    {
        $bytes = random_bytes(512);

        $literal = SqlValueEmitter::literal($bytes, binary: true);
        $recovered = hex2bin(substr($literal, 2, -1));

        $this->assertSame($bytes, $recovered, 'A VARBINARY column must survive the dump unchanged.');
    }

    public function test_empty_binary_is_distinguishable_from_null(): void
    {
        $this->assertSame("X''", SqlValueEmitter::literal('', binary: true));
        $this->assertSame('NULL', SqlValueEmitter::literal(null, binary: true));
    }

    /**
     * Emoji and other astral-plane codepoints are four bytes in utf8mb4. A
     * dumper that walks bytes rather than characters can split one in half.
     */
    public function test_four_byte_utf8mb4_passes_through_intact(): void
    {
        $beyondBmp = '🍺 Ünïcödé 𝔊';

        $this->assertSame("'{$beyondBmp}'", SqlValueEmitter::literal($beyondBmp));
    }

    /**
     * MariaDB stores '0000-00-00' where a legacy row has no date. It is not
     * NULL and it is not a valid date; a dumper that "helpfully" normalises it
     * changes data it was asked to copy.
     */
    public function test_a_zero_date_is_copied_verbatim(): void
    {
        $this->assertSame("'0000-00-00'", SqlValueEmitter::literal('0000-00-00'));
        $this->assertSame("'0000-00-00 00:00:00'", SqlValueEmitter::literal('0000-00-00 00:00:00'));
    }

    /**
     * PDO hands numerics back as strings. Quoting them is correct for MySQL and
     * keeps the emitter free of type guessing — the one thing it must not do is
     * turn them into something else.
     */
    public function test_numeric_looking_values_are_not_reinterpreted(): void
    {
        $this->assertSame("'0'", SqlValueEmitter::literal('0'));
        $this->assertSame("'007'", SqlValueEmitter::literal('007'));
        $this->assertSame("'-1250'", SqlValueEmitter::literal('-1250'));
        $this->assertSame("'1.10'", SqlValueEmitter::literal('1.10'));
    }

    /**
     * A JSON column is text to PDO, and its quotes and backslashes are exactly
     * the characters that break a naive emitter.
     */
    public function test_json_survives_its_own_punctuation(): void
    {
        $json = '{"de":"Bier","note":"a \"quoted\" word","path":"C:\\\\x"}';

        $literal = SqlValueEmitter::literal($json);

        $this->assertStringStartsWith("'", $literal);
        $this->assertStringEndsWith("'", $literal);
        // Unescaping the emitted literal must give back exactly the input.
        $this->assertSame($json, stripcslashes(substr($literal, 1, -1)));
    }
}
