<?php

declare(strict_types=1);

namespace App\Shared\Utils;

class IbanMasker
{
    public static function mask(?string $iban): ?string
    {
        if ($iban === null) return null;
        if (strlen($iban) < 8) return '****';
        return substr($iban, 0, 4) . str_repeat('*', strlen($iban) - 8) . substr($iban, -4);
    }
}
