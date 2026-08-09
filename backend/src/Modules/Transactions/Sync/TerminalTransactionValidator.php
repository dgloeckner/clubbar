<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Sync;

/**
 * Judges one uploaded terminal transaction: can this row be stored at all?
 *
 * Ruling #143 §1 draws the line here and nowhere else. A row is refused only
 * when it is *structurally unstorable* — never because a business rule now
 * says the sale should not have happened. By the time a batch arrives the
 * drink is in the member's hand, so a refusal does not undo the sale, it only
 * loses the record of one.
 *
 * These checks previously ran in `SyncController` as a **batch-wide** gate:
 * any one bad row and the entire upload came back `422 validation_failed`
 * with nothing stored. The terminal treats a non-2xx as transient and retries
 * the batch unchanged, and it only reaches its quarantine path on a 2xx — so a
 * row that could never become storable wedged every good sale queued behind it
 * for the life of the terminal (#259). Same judgement, per-row verdict, so the
 * batch can route around it.
 *
 * The verdict is deliberately taken on the row *after*
 * {@see TerminalTransactionAllowlist::build()}: every field a terminal owns
 * survives that rebuild, and judging the rebuilt row means no unvetted payload
 * key can influence the decision.
 */
final class TerminalTransactionValidator
{
    /**
     * Fields without which the row cannot be written.
     *
     * `id` is first among equals: it is the client-generated idempotency key
     * ADR-0004's retry guarantee rests on. A row without one cannot be
     * deduplicated, which is exactly how `INSERT IGNORE` came to report a
     * discarded row as accepted (#82).
     */
    private const REQUIRED = ['id', 'member_id', 'product_id', 'amount_cents', 'created_at'];

    /**
     * The rejection this row earns, or null when it can be stored.
     *
     * @param array<string, mixed> $row a row built by TerminalTransactionAllowlist
     * @return array{error: string, transaction_id: string|null, message: string}|null
     */
    public static function rejectionFor(array $row): ?array
    {
        foreach (self::REQUIRED as $field) {
            if (!isset($row[$field]) || $row[$field] === '') {
                return self::rejection($row, "{$field} is required");
            }
        }

        if (!self::isPositiveWholeNumber($row['amount_cents'])) {
            return self::rejection($row, 'amount_cents must be a positive whole number of cents');
        }

        return null;
    }

    /**
     * A terminal sends a JSON number, but a client that stringifies it is not
     * lying about the sale — and the sale already happened, so a type
     * technicality must not be what loses it. A fractional value is refused
     * rather than truncated: silently storing 350 for a claimed 350.7 invents
     * an amount nobody charged.
     */
    private static function isPositiveWholeNumber(mixed $amount): bool
    {
        if (is_int($amount)) {
            return $amount > 0;
        }

        // is_numeric() would accept 3.5, "3.5" and "1e3"; a boolean is numeric
        // to (int) but says nothing about money.
        if (!is_string($amount)) {
            return false;
        }

        return preg_match('/^\d+$/', $amount) === 1 && (int) $amount > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{error: string, transaction_id: string|null, message: string}
     */
    private static function rejection(array $row, string $message): array
    {
        $id = $row['id'] ?? null;

        return [
            // The same code the database-refused case uses, and for the same
            // reason: resubmitting the row unchanged cannot help, so the
            // terminal must quarantine rather than retry it. Which of the two
            // refused it is in the message, where a human reads it.
            'error' => 'unstorable',
            'transaction_id' => is_string($id) && $id !== '' ? $id : null,
            'message' => $message,
        ];
    }
}
