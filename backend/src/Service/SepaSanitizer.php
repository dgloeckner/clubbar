<?php

declare(strict_types=1);

namespace App\Service;

/**
 * SEPA Character Sanitizer
 *
 * Implements EPC (European Payments Council) character set rules for SEPA
 * payment messages (pain.008, pain.001, etc.).
 *
 * The SEPA Basic Latin Character Set allows:
 *   a-z A-Z 0-9 / - ? : ( ) . , ' + and space
 *
 * This sanitizer:
 * 1. Transliterates accented/special characters to ASCII equivalents
 * 2. Strips any remaining non-SEPA characters
 * 3. Normalizes whitespace (collapses multiple spaces, trims)
 * 4. Enforces field-specific max lengths per SEPA standard
 *
 * References:
 * - ADR-0006: Mandate reference allowed chars
 * - ADR-0008: SEPA XML export character encoding requirements
 * - EPC Best Practices: SEPA Character Set guidelines
 */
final class SepaSanitizer
{
    /**
     * SEPA-allowed characters regex pattern.
     * Matches: a-z A-Z 0-9 / - ? : ( ) . , ' + and space
     */
    private const SEPA_CHARSET_PATTERN = '/[^a-zA-Z0-9 \/\-\?\:\(\)\.\,\'\+]/u';

    /** Max length for name fields (creditor/debtor name) */
    public const MAX_NAME = 70;

    /** Max length for mandate reference */
    public const MAX_MANDATE_REFERENCE = 35;

    /** Max length for remittance information (unstructured) */
    public const MAX_REMITTANCE = 140;

    /** Max length for identifiers (message ID, end-to-end ID, payment info ID) */
    public const MAX_ID = 35;

    /**
     * Transliteration map for European characters to ASCII equivalents.
     *
     * Covers German, French, Spanish, Portuguese, Nordic, Polish, Czech,
     * Slovak, Hungarian, Romanian, Italian, and other common European scripts.
     */
    private const TRANSLITERATION_MAP = [
        // German
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',

        // French / Western European
        'à' => 'a', 'â' => 'a', 'æ' => 'ae',
        'ç' => 'c',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i',
        'ô' => 'o', 'œ' => 'oe',
        'ù' => 'u', 'û' => 'u',
        'ÿ' => 'y',
        'À' => 'A', 'Â' => 'A', 'Æ' => 'Ae',
        'Ç' => 'C',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Î' => 'I', 'Ï' => 'I',
        'Ô' => 'O', 'Œ' => 'Oe',
        'Ù' => 'U', 'Û' => 'U',
        'Ÿ' => 'Y',

        // Spanish / Portuguese
        'ñ' => 'n', 'Ñ' => 'N',
        'á' => 'a', 'Á' => 'A',
        'í' => 'i', 'Í' => 'I',
        'ó' => 'o', 'Ó' => 'O',
        'ú' => 'u', 'Ú' => 'U',
        'ã' => 'a', 'Ã' => 'A',
        'õ' => 'o', 'Õ' => 'O',

        // Nordic
        'å' => 'a', 'Å' => 'A',
        'ø' => 'o', 'Ø' => 'O',

        // Polish
        'ą' => 'a', 'Ą' => 'A',
        'ć' => 'c', 'Ć' => 'C',
        'ę' => 'e', 'Ę' => 'E',
        'ł' => 'l', 'Ł' => 'L',
        'ń' => 'n', 'Ń' => 'N',
        'ś' => 's', 'Ś' => 'S',
        'ź' => 'z', 'Ź' => 'Z',
        'ż' => 'z', 'Ż' => 'Z',

        // Czech / Slovak
        'č' => 'c', 'Č' => 'C',
        'ď' => 'd', 'Ď' => 'D',
        'ě' => 'e', 'Ě' => 'E',
        'ň' => 'n', 'Ň' => 'N',
        'ř' => 'r', 'Ř' => 'R',
        'š' => 's', 'Š' => 'S',
        'ť' => 't', 'Ť' => 'T',
        'ů' => 'u', 'Ů' => 'U',
        'ž' => 'z', 'Ž' => 'Z',

        // Hungarian
        'ő' => 'o', 'Ő' => 'O',
        'ű' => 'u', 'Ű' => 'U',

        // Romanian
        'ă' => 'a', 'Ă' => 'A',
        'ș' => 's', 'Ș' => 'S',
        'ț' => 't', 'Ț' => 'T',

        // Croatian / Serbian Latin
        'đ' => 'd', 'Đ' => 'D',

        // Icelandic
        'ð' => 'd', 'Ð' => 'D',
        'þ' => 'th', 'Þ' => 'Th',

        // Turkish
        'ğ' => 'g', 'Ğ' => 'G',
        'ı' => 'i', 'İ' => 'I',
        'ş' => 's', 'Ş' => 'S',
    ];

    /**
     * Sanitize a name field for SEPA (creditor or debtor name).
     */
    public static function sanitizeName(string $name): string
    {
        return self::sanitize($name, self::MAX_NAME);
    }

    /**
     * Sanitize a mandate reference for SEPA.
     */
    public static function sanitizeMandateReference(string $reference): string
    {
        return self::sanitize($reference, self::MAX_MANDATE_REFERENCE);
    }

    /**
     * Sanitize remittance information (unstructured purpose text).
     */
    public static function sanitizeRemittance(string $text): string
    {
        return self::sanitize($text, self::MAX_REMITTANCE);
    }

    /**
     * Sanitize a SEPA identifier (message ID, end-to-end ID, payment info ID).
     */
    public static function sanitizeId(string $id): string
    {
        return self::sanitize($id, self::MAX_ID);
    }

    /**
     * Sanitize an IBAN for SEPA (uppercase, no spaces).
     */
    public static function sanitizeIban(string $iban): string
    {
        return strtoupper(str_replace(' ', '', trim($iban)));
    }

    /**
     * Core sanitization: transliterate, strip invalid chars, normalize whitespace, truncate.
     */
    public static function sanitize(string $input, int $maxLength): string
    {
        // 1. Transliterate accented/special characters to ASCII
        $result = self::transliterate($input);

        // 2. Remove any characters not in the SEPA character set
        $result = (string) preg_replace(self::SEPA_CHARSET_PATTERN, '', $result);

        // 3. Collapse multiple spaces into one
        $result = (string) preg_replace('/\s+/', ' ', $result);

        // 4. Trim leading/trailing whitespace
        $result = trim($result);

        // 5. Truncate to max length
        if (strlen($result) > $maxLength) {
            $result = substr($result, 0, $maxLength);
            // Don't end on a trailing space after truncation
            $result = rtrim($result);
        }

        return $result;
    }

    /**
     * Transliterate accented and special characters to ASCII equivalents.
     */
    public static function transliterate(string $input): string
    {
        return strtr($input, self::TRANSLITERATION_MAP);
    }

    /**
     * Check if a string contains only SEPA-allowed characters.
     */
    public static function isValid(string $input): bool
    {
        return preg_match(self::SEPA_CHARSET_PATTERN, $input) === 0;
    }
}
