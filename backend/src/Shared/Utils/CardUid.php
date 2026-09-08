<?php

declare(strict_types=1);

namespace App\Shared\Utils;

/**
 * The one canonical spelling of an RFID card UID.
 *
 * A card UID is matched by exact string comparison — `members.card_uid` is a
 * UNIQUE column and every lookup is an equality test — so the *spelling* a UID
 * happens to arrive in decides whether a valid card is recognised. That would
 * be harmless if a chip had one spelling. It does not:
 *
 * | The same 4-byte chip, as different readers print it | |
 * |---|---|
 * | `001EB4CB`   | uppercase hex, the canonical form |
 * | `001eb4cb`   | lowercase hex (issue #18) |
 * | `00:1E:B4:CB`, `00-1E-B4-CB`, `00 1E B4 CB` | grouped by byte |
 * | `0x001EB4CB` | prefixed, as a diagnostic tool prints it |
 * | `1EB4CBA`    | a leading zero dropped mid-byte |
 * | `0002012363` | the same value in decimal, zero-padded to 10 digits |
 * | `CBB41E00`   | least-significant byte first |
 *
 * Storing whatever arrived means a club that replaces a broken reader with a
 * differently configured one finds that **no member card matches any more** —
 * every single UID has to be re-entered by hand, and nothing in the failure
 * says why: each card simply reads as unknown.
 *
 * So: parse the input, reduce it to one canonical form, and store only that.
 * Canonical is **uppercase hex, no separators, whole bytes, four to ten of
 * them** — `001EB4CB`.
 *
 * The terminal reads the same dialects (`terminal-frontend/lib/utils/card_uid.dart`)
 * and is one step more forgiving: it pads a short *scan* out to four bytes,
 * because its input comes from a reader rather than from fingers. See
 * {@see canonicalize()}.
 *
 * ## What this class deliberately does not do
 *
 * It never guesses at *decimal*. `12345678` is a perfectly good 4-byte hex UID
 * and a perfectly good decimal one, and no rule can tell them apart from the
 * string alone. The backend is the store of record: a wrong guess here silently
 * files a card under a UID no reader will ever produce. Decimal is resolved
 * where the answer is actually known — by the terminal's configured reader
 * profile (`rfidReader.uidFormat`), and by an admin explicitly converting a
 * value in the member form — and reaches this class already as hex.
 *
 * Byte order is the same kind of question and gets the same answer: a reversed
 * UID is a valid UID, so it is the reader profile that knows, not the string.
 *
 * @see \App\Modules\Members\Controllers\AdminController::FIELD_RULES
 */
final class CardUid
{
    /** No card technology in ADR-0014's table has a UID shorter than 4 bytes. */
    public const MIN_BYTES = 4;

    /** 10 bytes is the widest ISO 14443 UID, and 20 chars is the column. */
    public const MAX_BYTES = 10;

    /**
     * The canonical spelling, and the only thing the column may hold.
     *
     * Whole bytes, so a UID is never stored with a byte half-written: an odd
     * digit count means a leading zero was dropped somewhere, which is exactly
     * the case {@see canonicalize()} repairs rather than passes through.
     */
    public const PATTERN = '/^(?:[0-9A-F]{2}){' . self::MIN_BYTES . ',' . self::MAX_BYTES . '}$/';

    /**
     * Characters readers and diagnostic tools use to group bytes, all of which
     * carry no information and are dropped.
     */
    private const SEPARATORS = ['-', ':', '.', '_', ' ', "\t", "\r", "\n"];

    /** Whether $value is already exactly what may be stored. */
    public static function isCanonical(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * Reduce one reader's or one tool's spelling of a hex UID to the canonical
     * form, or return null when the input is not a hex UID at all.
     *
     * Null means "I cannot read this as a card UID" and is not an error on its
     * own: the caller leaves the value alone and lets validation produce the
     * message. That is deliberate — it keeps this method from mangling values
     * that only look like UIDs, the `ANON-…` placeholder an anonymized member
     * carries (ADR-0017) being the one that exists today.
     */
    public static function canonicalize(string $raw): ?string
    {
        $cleaned = str_replace(self::SEPARATORS, '', trim($raw));

        // `0x` as a diagnostic tool prints it. Stripped before the hex test so
        // the `x` does not make the whole value unreadable.
        if (preg_match('/^0[xX]/', $cleaned) === 1) {
            $cleaned = substr($cleaned, 2);
        }

        if ($cleaned === '' || preg_match('/^[0-9A-Fa-f]+$/', $cleaned) !== 1) {
            return null;
        }

        $canonical = strtoupper($cleaned);

        // Complete a half-written byte. An odd digit count means a leading zero
        // went missing between the card and the keyboard, and `01EB4CB` is the
        // same chip as `001EB4CB` rather than a neighbour of it.
        //
        // Only the half byte. Whole missing bytes are *not* invented here: on
        // this side the input comes from fingers, and `ABCD` is a volunteer who
        // stopped typing, not a three-byte card. It is refused below and the
        // length rule says so — which is the typo defence the member form has
        // always had. The terminal pads a short scan to four bytes because its
        // input comes from a reader, which has no fingers; see
        // `terminal-frontend/lib/utils/card_uid.dart`.
        if (strlen($canonical) % 2 === 1) {
            $canonical = '0' . $canonical;
        }

        return self::isCanonical($canonical) ? $canonical : null;
    }
}
