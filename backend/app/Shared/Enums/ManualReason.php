<?php

namespace App\Shared\Enums;

use Illuminate\Validation\Rules\Enum;

/**
 * Manual Settlement Reason Enumeration
 *
 * Represents the reason for a manual (non-SEPA) settlement.
 *
 * Pattern 002: Enum for Type-Safe Domain Values
 */
enum ManualReason: string
{
    case CASH = 'cash';
    case BANK_TRANSFER = 'bank_transfer';
    case WRITE_OFF = 'write_off';
    case OTHER = 'other';

    /**
     * Get human-readable label for display
     */
    public function label(): string
    {
        return match ($this) {
            self::CASH => 'Cash Payment',
            self::BANK_TRANSFER => 'Bank Transfer',
            self::WRITE_OFF => 'Write Off',
            self::OTHER => 'Other',
        };
    }

    /**
     * Laravel validation rule factory
     */
    public static function validationRule(): Enum
    {
        return new Enum(self::class);
    }
}
