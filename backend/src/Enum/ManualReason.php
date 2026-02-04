<?php

declare(strict_types=1);

namespace App\Enum;

enum ManualReason: string
{
    case CASH = 'cash';
    case BANK_TRANSFER = 'bank_transfer';
    case WRITE_OFF = 'write_off';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CASH => 'Cash Payment',
            self::BANK_TRANSFER => 'Bank Transfer',
            self::WRITE_OFF => 'Write Off',
            self::OTHER => 'Other',
        };
    }
}
