<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Sync;

/**
 * Builds the insert row for an uploaded terminal transaction from an explicit
 * allowlist (#79, ruling #144 §3–4).
 *
 * The sync path used to hand the whole client array to the repository, which
 * read `transaction_type`, `related_transaction_id` and `created_by_admin_id`
 * straight out of it. A terminal token could therefore forge a storno — and
 * since #169 made the UNIQUE index on `related_transaction_id` the arbiter of
 * "a transaction is stornoable at most once", a forged link *consumed the
 * slot*: the treasurer's genuine storno of that purchase would be refused with
 * 409 forever. Write-integrity bug and denial-of-correction in one.
 *
 * The fix is structural rather than a validation rule: the row is rebuilt from
 * named fields, so an unknown field cannot reach SQL whatever the OpenAPI spec
 * happens to permit. The repository must not depend on middleware for its
 * invariants — the OAS validator is one `APP_ENV` away from being inert.
 *
 * What the terminal owns, and what it does not, follows ruling #144 §3.
 */
final class TerminalTransactionAllowlist
{
    /**
     * Fields a terminal legitimately owns: the sale it recorded at the bar, and
     * the dispenser telemetry of its own hardware. `created_at` is the sale time
     * (`occurred_at`); it is stored as sent and flagged when implausible, never
     * rewritten — see TransactionsService::flagImplausibleSaleTime().
     */
    private const TERMINAL_OWNED = [
        'id',
        'member_id',
        'product_id',
        'amount_cents',
        'created_at',
        'dispenser_tx_id',
        'dispenser_requested',
        'dispenser_actual',
    ];

    /**
     * @param array $payload one entry of the client's `transactions` array
     * @param string|null $terminalId from the authenticated token, never the payload
     */
    public static function build(array $payload, ?string $terminalId): array
    {
        $row = [];

        foreach (self::TERMINAL_OWNED as $field) {
            if (array_key_exists($field, $payload)) {
                $row[$field] = $payload[$field];
            }
        }

        // Server-owned. Set unconditionally, so a client value is overwritten
        // rather than merely defaulted over.
        //
        // A terminal never makes corrections: a storno is admin-only via
        // POST /admin/transactions/{id}/storno (#169) and a payout is reachable
        // only through offboarding (#173).
        $row['transaction_type'] = 'purchase';
        $row['related_transaction_id'] = null;
        $row['created_by_admin_id'] = null;
        $row['created_by_terminal_id'] = $terminalId;

        return $row;
    }
}
