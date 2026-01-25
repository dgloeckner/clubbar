<?php

namespace App\Shared\Utils;

/**
 * IBAN Masking Utility for Audit Log Security
 *
 * Per ADR-0013, Section "Sensitive Data Handling":
 * IBAN values must be masked in audit log entries to protect banking information.
 *
 * Masking pattern: "DE89****...****4567" (first 4 + last 4 visible)
 * This preserves enough information for identification while protecting account details.
 *
 * Examples:
 * - "DE89370400440532013000" → "DE89****...****3000"
 * - "FR1420041010050500013M02606" → "FR14****...****2606"
 * - "GB82WEST12345698765432" → "GB82****...****5432"
 */
final class IbanMasker
{
    /**
     * Mask an IBAN value for safe logging
     *
     * @param string $iban Full IBAN to mask
     * @return string Masked IBAN (first 4 + last 4 visible)
     */
    public static function mask(string $iban): string
    {
        // If IBAN is too short to safely mask, return fully masked
        if (strlen($iban) < 8) {
            return '****';
        }

        // Extract first 4 and last 4 characters
        $first = substr($iban, 0, 4);
        $last = substr($iban, -4);

        // Return masked format: "DE89****...****3000"
        return "{$first}****...****{$last}";
    }
}
