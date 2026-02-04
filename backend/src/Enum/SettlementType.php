<?php

declare(strict_types=1);

namespace App\Enum;

enum SettlementType: string
{
    case SEPA = 'sepa';
    case MANUAL = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::SEPA => 'SEPA Direct Debit',
            self::MANUAL => 'Manual Settlement',
        };
    }

    public function requiresSepaEligibility(): bool
    {
        return $this === self::SEPA;
    }
}
