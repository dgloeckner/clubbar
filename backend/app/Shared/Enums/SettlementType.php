<?php

namespace App\Shared\Enums;

use Illuminate\Validation\Rules\Enum;

/**
 * Settlement Type Enumeration
 *
 * Represents the billing method for a settlement.
 *
 * Pattern 002: Enum for Type-Safe Domain Values
 * Provides type-safe constants for settlement types, preventing invalid string usage.
 */
enum SettlementType: string
{
    case SEPA = 'sepa';
    case MANUAL = 'manual';

    /**
     * Get human-readable label for display
     */
    public function label(): string
    {
        return match ($this) {
            self::SEPA => 'SEPA Direct Debit',
            self::MANUAL => 'Manual Settlement',
        };
    }

    /**
     * Check if settlement type requires SEPA eligibility filtering
     */
    public function requiresSepaEligibility(): bool
    {
        return $this === self::SEPA;
    }

    /**
     * Laravel validation rule factory
     */
    public static function validationRule(): Enum
    {
        return new Enum(self::class);
    }
}
