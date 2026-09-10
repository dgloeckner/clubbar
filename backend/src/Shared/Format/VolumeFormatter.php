<?php

declare(strict_types=1);

namespace App\Shared\Format;

/**
 * A product's size, written the way each language writes it.
 *
 * The stored value is language-neutral whole millilitres (ADR-0056): the size
 * is data, not part of the translated name, so every surface formats it for its
 * own reader rather than reading whatever punctuation the admin happened to
 * type. `500` becomes `0,5 l` for a German member and `0.5 l` for an English
 * one, from one number.
 *
 * The rule this implements lives in `api/fixtures/volume-format.json`, and so
 * do its vectors. PHP, TypeScript and Dart each implement it, and each
 * language's test suite reads **that file** — three implementations of one rule
 * drift apart, three implementations checked against one file drift visibly, in
 * CI, on the commit that did it. A new vector belongs in the fixture, never in
 * one suite.
 *
 * Hand-rolled rather than `NumberFormatter`, for the reason
 * {@see \App\Modules\Notifications\Mail\MailFormat} gives: `ext-intl` is not
 * guaranteed on a mass-hosting tariff (ADR-0031), and a Deckelauszug that
 * renders a size on one host and fatals on the next is not a trade worth making
 * for one decimal separator.
 */
final class VolumeFormatter
{
    /**
     * Below this, litres stop reading as a size: 99 ml is `0,1 l` at one
     * decimal and `0,00 l` would be the honest rendering of anything under
     * 5 ml. At 100 ml the litre value gains a non-zero first decimal, which is
     * where litres start saying something.
     */
    private const MILLILITRE_THRESHOLD = 100;

    /** Languages that write a decimal point rather than a decimal comma. */
    private const POINT_LANGUAGES = ['en'];

    /**
     * `500, 'de'` → `0,5 l`; `500, 'en'` → `0.5 l`; `20` → `20 ml`.
     *
     * The separator before the unit is a NO-BREAK SPACE, so a size never wraps
     * across a line — on a terminal badge or in a statement column alike.
     *
     * An unknown language falls back to the decimal comma rather than to an
     * exception: this is called from the middle of rendering a statement, and a
     * size in the wrong punctuation is a smaller failure than a statement that
     * does not render.
     */
    public static function format(int $ml, string $lang): string
    {
        if ($ml < self::MILLILITRE_THRESHOLD) {
            return $ml . "\u{00A0}ml";
        }

        // Half away from zero, decided on integers: `1005 / 1000` is a float
        // whose nearest double is below 1.005, so any rounding that goes
        // through one answers `1,00 l` on some platforms and `1,01 l` on
        // others. There is no float here for a platform to disagree about.
        $hundredths = intdiv($ml + 5, 10);
        $whole = intdiv($hundredths, 100);
        $fraction = $hundredths % 100;

        $separator = in_array(strtolower($lang), self::POINT_LANGUAGES, true) ? '.' : ',';

        if ($fraction === 0) {
            $number = (string) $whole;                                  // 1000 → `1`, never `1,00`
        } elseif ($fraction % 10 === 0) {
            $number = $whole . $separator . intdiv($fraction, 10);      // 1500 → `1,5`
        } else {
            $number = $whole . $separator . str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
        }

        return $number . "\u{00A0}l";
    }

    /**
     * The same, for a value that may not be there at all.
     *
     * `null` means the product has no size — a Sauna-Token, a Kaffee — and the
     * caller prints the name alone. Returning an empty string rather than
     * something like `—` keeps that decision where it belongs: the caller knows
     * whether it is building a line of prose or a table cell.
     */
    public static function formatOrEmpty(?int $ml, string $lang): string
    {
        return $ml === null ? '' : self::format($ml, $lang);
    }

    /**
     * `Weizenbier` + `500` → `Weizenbier 0,5 l`; with no volume, the name alone.
     *
     * This is the one place the "name, then size" order is decided, so that
     * every surface that prints a product — the transaction list, the terminal
     * history, the Deckelauszug, settlement mail, the CSV exports — prints it
     * the same way (ADR-0056).
     */
    public static function withName(string $name, ?int $ml, string $lang): string
    {
        return $ml === null ? $name : $name . ' ' . self::format($ml, $lang);
    }
}
