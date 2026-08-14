<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use App\Modules\Notifications\Enums\MailLanguage;

/**
 * Money and dates, written the way each language writes them.
 *
 * Deliberately hand-rolled rather than `IntlDateFormatter` / `NumberFormatter`:
 * `ext-intl` is not guaranteed on a mass-hosting tariff (ADR-0031), and a
 * pre-notification that renders `21.08.2026` on one host and a fatal error on
 * the next is not a trade worth making for two formats.
 *
 * Both formats are chosen to be unambiguous rather than idiomatic where the two
 * conflict: `21 August 2026` in English instead of a numeric form that means
 * different days on the two sides of the Atlantic, and `EUR 12.34` instead of a
 * euro sign whose position is itself a convention.
 */
final class MailFormat
{
    /** Full month names, because a numeric English date is ambiguous by design. */
    private const MONTHS_EN = [
        1 => 'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
    ];

    /** `1234` → `12,34 €` (de) or `EUR 12.34` (en). */
    public static function money(int $cents, MailLanguage $language): string
    {
        $amount = $cents / 100;

        return $language === MailLanguage::German
            ? number_format($amount, 2, ',', '.') . ' €'
            : 'EUR ' . number_format($amount, 2, '.', ',');
    }

    /**
     * `2026-08-21` (or a full datetime) → `21.08.2026` / `21 August 2026`.
     *
     * An unparseable value comes back verbatim rather than as an empty string:
     * a due date is the one field a member checks against their account, and a
     * blank where it belongs is worse than a raw one.
     */
    public static function date(?string $date, MailLanguage $language): string
    {
        if ($date === null || trim($date) === '') {
            return '';
        }

        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return $date;
        }

        if ($language === MailLanguage::German) {
            return date('d.m.Y', $timestamp);
        }

        return date('j', $timestamp) . ' ' . self::MONTHS_EN[(int) date('n', $timestamp)] . ' ' . date('Y', $timestamp);
    }

    /**
     * The member's account as the announcement may name it: the last four
     * characters and nothing else.
     *
     * Built from `mandates.iban_last4`, which is all the read model exposes
     * since ADR-0036 — there is no full IBAN available here to truncate, and
     * that is the point. A null or short value degrades to the mask alone
     * rather than leaking whatever fragment happens to be stored.
     */
    public static function maskedAccount(?string $last4): string
    {
        $last4 = trim((string) $last4);
        if ($last4 === '') {
            return '';
        }

        return '**** ' . mb_substr($last4, -4);
    }
}
