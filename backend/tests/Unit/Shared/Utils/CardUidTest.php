<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Utils;

use App\Shared\Utils\CardUid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The canonical spelling of a card UID.
 *
 * A card UID is matched by exact string comparison, so every spelling of one
 * chip has to collapse onto one value before it is stored. The dialects below
 * are not hypothetical: they are what different readers and different
 * diagnostic tools print for the same 4-byte chip.
 */
final class CardUidTest extends TestCase
{
    /** The worked example, in every spelling a tool prints it. */
    private const CANONICAL = '001EB4CB';

    #[DataProvider('hexDialects')]
    public function test_canonicalize_collapses_every_hex_dialect_onto_one_value(
        string $raw,
        string $expected,
    ): void {
        $this->assertSame($expected, CardUid::canonicalize($raw));
    }

    /** @return array<string, array{string, string}> */
    public static function hexDialects(): array
    {
        return [
            'already canonical'        => [self::CANONICAL, self::CANONICAL],
            'lower case'               => ['001eb4cb', self::CANONICAL],
            'mixed case'               => ['001Eb4Cb', self::CANONICAL],
            'colon separated'          => ['00:1E:B4:CB', self::CANONICAL],
            'hyphen separated'         => ['00-1e-b4-cb', self::CANONICAL],
            'space separated'          => ['00 1E B4 CB', self::CANONICAL],
            'dot separated'            => ['00.1e.b4.cb', self::CANONICAL],
            'surrounding whitespace'   => ["  001eb4cb\n", self::CANONICAL],
            '0x prefixed'              => ['0x001EB4CB', self::CANONICAL],
            '0X prefixed, lower case'  => ['0X001eb4cb', self::CANONICAL],
            'half-written byte'        => ['1EB4CBA', '01EB4CBA'],
            'ten bytes, the widest'    => ['AABBCCDDEEFF00112233', 'AABBCCDDEEFF00112233'],
        ];
    }

    #[DataProvider('notCardUids')]
    public function test_canonicalize_returns_null_for_what_is_not_a_card_uid(string $raw): void
    {
        // Null rather than a mangled value: the caller leaves the input alone
        // and lets the format rule produce the message.
        $this->assertNull(CardUid::canonicalize($raw));
    }

    /** @return array<string, array{string}> */
    public static function notCardUids(): array
    {
        return [
            'empty'                => [''],
            'whitespace only'      => ['   '],
            'separators only'      => [':-.'],
            'not hex'              => ['GHIJKLMN'],
            // Three whole bytes: refused rather than padded up to four. The
            // input on this side comes from fingers, so `ABCD` is a volunteer
            // who stopped typing, not a card. The terminal pads a short *scan*,
            // because a reader has no fingers.
            'three bytes'          => ['1EB4CB'],
            'a slip of the hand'   => ['ABCD'],
            'the anonymized placeholder' => ['ANON-8ba7b8109dad11d'],
            'eleven bytes'         => ['AABBCCDDEEFF001122334'],
        ];
    }

    public function test_canonicalize_leaves_the_anonymized_placeholder_alone(): void
    {
        // `anonymize()` writes `ANON-…` straight into the column, bypassing this
        // path entirely. It is covered here because the one thing this method
        // must never do is rewrite it into something that looks like a card.
        $this->assertNull(CardUid::canonicalize('ANON-8ba7b8109dad11d'));
    }

    public function test_canonicalize_does_not_guess_at_decimal(): void
    {
        // 0002012363 is the decimal spelling of 001EB4CB *and* a perfectly good
        // 5-byte hex UID. Nothing in the string says which, so the backend —
        // the store of record — reads it as what it literally is. Decimal is
        // resolved where the answer is known: the terminal's reader profile, or
        // an admin converting the value explicitly in the member form.
        $this->assertSame('0002012363', CardUid::canonicalize('0002012363'));
    }

    public function test_canonicalize_is_idempotent(): void
    {
        foreach (self::hexDialects() as [$raw, $expected]) {
            $once = CardUid::canonicalize($raw);
            $this->assertNotNull($once);
            $this->assertSame($once, CardUid::canonicalize($once));
        }
    }

    #[DataProvider('canonicalValues')]
    public function test_isCanonical_accepts_whole_uppercase_hex_bytes(string $value): void
    {
        $this->assertTrue(CardUid::isCanonical($value));
    }

    /** @return array<string, array{string}> */
    public static function canonicalValues(): array
    {
        return [
            'four bytes' => ['001EB4CB'],
            'five bytes' => ['0002012363'],
            'seven bytes' => ['04D23E5A6B7C8D'],
            'ten bytes'  => ['AABBCCDDEEFF00112233'],
        ];
    }

    #[DataProvider('nonCanonicalValues')]
    public function test_isCanonical_rejects_the_spellings_normalization_removes(string $value): void
    {
        $this->assertFalse(CardUid::isCanonical($value));
    }

    /** @return array<string, array{string}> */
    public static function nonCanonicalValues(): array
    {
        return [
            'lower case'      => ['001eb4cb'],
            'separators'      => ['00:1E:B4:CB'],
            'three bytes'     => ['1EB4CB'],
            'half a byte'     => ['01EB4CB'],
            'eleven bytes'    => ['AABBCCDDEEFF001122334'],
            'the placeholder' => ['ANON-8ba7b8109dad11d'],
            'empty'           => [''],
        ];
    }

    public function test_canonicalize_always_produces_a_canonical_value(): void
    {
        foreach ([...self::hexDialects(), ...array_map(
            static fn (array $row): array => [$row[0], $row[0]],
            self::nonCanonicalValues(),
        )] as [$raw]) {
            $canonical = CardUid::canonicalize($raw);
            if ($canonical !== null) {
                $this->assertTrue(
                    CardUid::isCanonical($canonical),
                    "canonicalize({$raw}) produced a non-canonical value",
                );
            }
        }
    }
}
