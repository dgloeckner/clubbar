<?php

declare(strict_types=1);

namespace App\Modules\Members\Domain;

/**
 * What makes a SEPA mandate usable — asked once, answered the same way
 * everywhere (ADR-0020, #164).
 *
 * A mandate is three facts, not two: an account to debit, a reference to name
 * it by, and **the date the member signed it**. The third one is the only one
 * that records a real-world event; the other two are data entry. ADR-0020's
 * amendment of 2026-08-07 says exactly that — "a mandate is a single record
 * carrying reference, IBAN *and signature date*" — and then five call sites
 * went on checking the first two:
 *
 * ```php
 * !empty($row['mandate_reference']) && !empty($row['has_iban'])
 * ```
 *
 * with the repository asking a sixth question in SQL (`md.id IS NOT NULL`).
 * The signature date was in none of them, which is why `SepaExportService`
 * needed a fallback — `$member['mandate_signed_at'] ?? $settlement['settlement_date']`
 * — to have anything to put in `DtOfSgntr`. That fallback wrote the day the
 * treasurer clicked *export* into the bank file as the day the member signed:
 * an invented date in the one field the club would have to stand behind if the
 * debit were ever disputed. And the dispute is the long kind — a collection
 * taken without a valid mandate is not an authorised direct debit but an
 * *unauthorised* transaction, reclaimable for **13 months** under § 676b
 * Abs. 2 BGB rather than the eight weeks of § 675x Abs. 4 (ADR-0028 §3). The
 * fix is not a better fallback. It is not needing one, because a mandate
 * without a signature date is not a mandate.
 *
 * **This is one definition with two renderings**, and they must agree:
 *
 * - {@see self::isComplete()} for a row already in hand;
 * - {@see self::SQL} for a query that filters or counts without loading rows.
 *
 * Both name the same three columns. ADR-0020 records what happens when they
 * drift: the dashboard once read `iban IS NULL OR mandate_reference IS NULL`
 * while everything else used `empty()`, so a member with `iban = ''` was valid
 * on one screen and invalid on every other. "One lookup replaces four
 * expressions" is that ADR's phrasing, and this class is the lookup.
 *
 * **The consequence is deliberately wide.** `is_sepa_valid` is what the
 * terminal refuses a card scan on (ADR-0020: terminal blocks, no grace
 * period), what the roster filters on, and what the completeness panel counts.
 * Tightening it here means a member whose mandate has no signature date can no
 * longer run up a tab the club has no collectable mandate for — which is the
 * outcome ADR-0020 exists to produce, arrived at one field late.
 */
final class MandateCompleteness
{
    /**
     * The same predicate as {@see self::isComplete()}, for SQL.
     *
     * Written against the `mandates md` alias that `MembersRepository`'s
     * mandate join establishes. `md.id IS NOT NULL` is not redundant with the
     * `signed_at` check: on a LEFT JOIN miss every `md.*` column is NULL, and
     * spelling out "there is a mandate row" keeps the expression readable as
     * the sentence it is rather than as an accident of join semantics.
     *
     * `reference` and `iban_ciphertext` are NOT NULL on `mandates`, so the row
     * existing is what carries those two thirds of the definition.
     */
    public const SQL = '(md.id IS NOT NULL AND md.signed_at IS NOT NULL)';

    /** The negation, for the queries that count or list what is *missing*. */
    public const SQL_INCOMPLETE = '(md.id IS NULL OR md.signed_at IS NULL)';

    /**
     * Whether this member row carries a mandate the club could actually
     * collect against.
     *
     * Takes a row rather than three arguments because every caller has one —
     * from `MANDATE_COLUMNS`, which aliases `md.signed_at` to
     * `mandate_signed_at`. `empty()` rather than `isset()` throughout: an IBAN
     * stored as `''` is the exact divergence ADR-0020 records, and a
     * zero-length reference is not a reference.
     *
     * @param array<string, mixed> $row A `members` row joined with its mandate.
     */
    public static function isComplete(array $row): bool
    {
        return !empty($row['has_iban'])
            && !empty($row['mandate_reference'])
            && !empty($row['mandate_signed_at']);
    }

    /**
     * Which of the three facts this row is missing, worst first.
     *
     * For telling a member *why* rather than only *that* — the exclusion
     * report a treasurer acts on, and the log line behind it.
     *
     * @param array<string, mixed> $row
     * @return list<string> Column names, empty when the mandate is complete.
     */
    public static function missingParts(array $row): array
    {
        $missing = [];
        if (empty($row['has_iban'])) $missing[] = 'iban';
        if (empty($row['mandate_reference'])) $missing[] = 'mandate_reference';
        if (empty($row['mandate_signed_at'])) $missing[] = 'mandate_signed_at';

        return $missing;
    }
}
