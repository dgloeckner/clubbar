<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Enums;

/**
 * How a settlement's balance is actually collected from (or written off for)
 * the member(s) it covers.
 *
 * Ruling #163: this single field replaces both the old (unstored)
 * `settlement_type` controller parameter and the free-string `manual_reason`
 * column.
 *
 * Cash is absent because the club takes none — a standing constraint of the
 * remediation map (#170), not an omission. The old `cash` reason is what made
 * #163's double collection possible: a member paid in cash, the settlement was
 * still exportable, and the bank debited them a second time.
 */
enum SettlementMethod: string
{
    case DIRECT_DEBIT = 'direct_debit';
    case BANK_TRANSFER = 'bank_transfer';
    case WRITE_OFF = 'write_off';

    public function label(): string
    {
        return match ($this) {
            self::DIRECT_DEBIT => 'SEPA Direct Debit',
            self::BANK_TRANSFER => 'Bank Transfer',
            self::WRITE_OFF => 'Write Off',
        };
    }

    /**
     * Only direct-debit settlements may be turned into a pain.008 SEPA export
     * (#163) — bank_transfer and write_off settlements were never collected
     * through the SEPA mandate and must never be submitted to the bank.
     */
    public function isSepaExportable(): bool
    {
        return $this === self::DIRECT_DEBIT;
    }
}
