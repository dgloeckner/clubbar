<?php

declare(strict_types=1);

/**
 * SEPA Character Sanitizer
 *
 * Converts strings to the "EPC Basic Character Set" as defined in:
 * - EPC217-08 "Best Practices SEPA Requirements for an Extended Character Set (UNICODE Subset)"
 * - EPC217-08 SEPA Conversion Table (Excel)
 * - Anlage 3 des DFÜ-Abkommens der Deutschen Kreditwirtschaft (DK)
 *
 * Allowed characters (EPC Basic Latin Character Set):
 *   a-z A-Z 0-9 / - ? : ( ) . , ' + [Space]
 *
 * Conversion strategy:
 *   1. Explicit German replacements (DK alternative: ä→ae, ö→oe, ü→ue, ß→ss)
 *   2. Explicit special character replacements per EPC217-08
 *   3. Unicode NFD normalization to decompose remaining accented chars (é→e+combining accent)
 *   4. Strip combining diacritical marks (\p{Mn})
 *   5. Transliterate common ligatures and special letters (æ→ae, ø→o, ð→d, þ→th, etc.)
 *   6. Remove anything not in the allowed SEPA charset
 *   7. Normalize whitespace
 *   8. Enforce max length (70 chars for names, 140 for Verwendungszweck)
 *
 * Requires PHP intl extension (Normalizer class).
 *
 * @see https://www.europeanpaymentscouncil.eu/document-library/guidance-documents/sepa-requirements-extended-character-set-unicode-subset-best
 */
class SepaSanitizer
{
    /** SEPA max lengths per field type */
    public const MAX_LENGTH_NAME = 70;
    public const MAX_LENGTH_REFERENCE = 35;
    public const MAX_LENGTH_REMITTANCE = 140;
    public const MAX_LENGTH_ID = 35;

    /**
     * Allowed characters in the EPC Basic Latin Character Set.
     * Regex character class for the final cleanup.
     */
    private const ALLOWED_CHARS_PATTERN = '/[^a-zA-Z0-9\/\-\?\:\(\)\.\,\'\+\s]/u';

    /**
     * Step 1: German-specific replacements.
     *
     * Per DK FAQ (Deutsche Kreditwirtschaft), the "alternative" forms (ae, oe, ue, ss)
     * are explicitly allowed alongside the EPC Best Practice short forms (a, o, u, s).
     * We use the long forms because they are more readable for German names.
     *
     * IMPORTANT: These MUST be applied BEFORE NFD normalization, because NFD would
     * decompose ä into a + U+0308 (combining diaeresis), and stripping the combining
     * mark would yield just "a" instead of "ae".
     */
    private const GERMAN_REPLACEMENTS = [
        'Ä' => 'Ae', 'ä' => 'ae',
        'Ö' => 'Oe', 'ö' => 'oe',
        'Ü' => 'Ue', 'ü' => 'ue',
        'ß' => 'ss',
    ];

    /**
     * Step 2: Special character replacements per EPC217-08 / DK.
     */
    private const SPECIAL_REPLACEMENTS = [
        '&' => '+',
        '*' => '.',
        '@' => '.',
        '#' => '.',
        '%' => '.',
        '!' => '.',
        '=' => '-',
        ';' => ',',
        '€' => 'EUR',
        '£' => 'GBP',
        '$' => 'USD',
        '"' => '.',              // Straight double quote
        "\u{201C}" => '.',       // Left double quotation mark "
        "\u{201D}" => '.',       // Right double quotation mark "
        "\u{00AB}" => '.',       // Left-pointing double angle quotation mark «
        "\u{00BB}" => '.',       // Right-pointing double angle quotation mark »
        "\u{2018}" => "'",       // Left single quotation mark '
        "\u{2019}" => "'",       // Right single quotation mark '
        "\u{2013}" => '-',       // En dash –
        "\u{2014}" => '-',       // Em dash —
        "\u{2026}" => '.',       // Ellipsis …
        "\u{00B7}" => '.',       // Middle dot ·
    ];

    /**
     * Step 5: Ligatures and special Latin letters that NFD won't decompose.
     * These need explicit mapping because Normalizer::normalize won't split them.
     */
    private const LIGATURE_REPLACEMENTS = [
        // Ligatures
        'Æ' => 'AE', 'æ' => 'ae',
        'Œ' => 'OE', 'œ' => 'oe',
        'ﬁ' => 'fi', 'ﬂ' => 'fl',
        'ﬀ' => 'ff', 'ﬃ' => 'ffi', 'ﬄ' => 'ffl',

        // Scandinavian / Icelandic
        'Ø' => 'O',  'ø' => 'o',
        'Ð' => 'D',  'ð' => 'd',
        'Þ' => 'Th', 'þ' => 'th',

        // Polish
        'Ł' => 'L',  'ł' => 'l',

        // Croatian / Serbian
        'Đ' => 'D',  'đ' => 'd',

        // Turkish
        'İ' => 'I',  'ı' => 'i',
    ];

    /**
     * Sanitize a string for SEPA usage.
     *
     * @param string   $input     The input string (UTF-8)
     * @param int|null $maxLength Optional max length to truncate to
     * @return string  The sanitized string containing only EPC Basic Latin chars
     *
     * @throws \RuntimeException if the intl extension is not available
     */
    public static function sanitize(string $input, ?int $maxLength = null): string
    {
        if (!class_exists(\Normalizer::class)) {
            throw new \RuntimeException(
                'PHP intl extension is required for SepaSanitizer. '
                . 'Install it via: apt install php-intl'
            );
        }

        $result = $input;

        // Step 1: German-specific replacements (BEFORE NFD!)
        $result = strtr($result, self::GERMAN_REPLACEMENTS);

        // Step 2: Special character replacements
        $result = strtr($result, self::SPECIAL_REPLACEMENTS);

        // Step 3: Ligatures and special letters (BEFORE NFD, as these don't decompose)
        $result = strtr($result, self::LIGATURE_REPLACEMENTS);

        // Step 4: NFD normalization — decomposes accented characters
        // e.g. é (U+00E9) → e (U+0065) + ◌́ (U+0301)
        $normalized = \Normalizer::normalize($result, \Normalizer::FORM_D);
        if ($normalized !== false) {
            $result = $normalized;
        }

        // Step 5: Strip combining diacritical marks (Unicode category Mn = Mark, Nonspacing)
        // This removes the accent/diacritic part, leaving only the base letter
        $result = preg_replace('/\p{Mn}+/u', '', $result);

        // Step 6: Remove anything not in the allowed SEPA charset
        $result = preg_replace(self::ALLOWED_CHARS_PATTERN, '', $result);

        // Step 7: Normalize whitespace (collapse multiple spaces, trim)
        $result = preg_replace('/\s+/', ' ', $result);
        $result = trim($result);

        // Step 8: Truncate if max length is specified
        if ($maxLength !== null && mb_strlen($result) > $maxLength) {
            $result = mb_substr($result, 0, $maxLength);
            $result = rtrim($result); // Don't end with a space after truncation
        }

        return $result;
    }

    /**
     * Convenience: sanitize a name field (max 70 chars).
     */
    public static function sanitizeName(string $name): string
    {
        return self::sanitize($name, self::MAX_LENGTH_NAME);
    }

    /**
     * Convenience: sanitize Verwendungszweck / remittance info (max 140 chars).
     */
    public static function sanitizeRemittance(string $text): string
    {
        return self::sanitize($text, self::MAX_LENGTH_REMITTANCE);
    }

    /**
     * Convenience: sanitize a reference / ID field (max 35 chars).
     * Additional SEPA rules: must not start/end with '/', must not contain '//'.
     */
    public static function sanitizeReference(string $reference): string
    {
        $result = self::sanitize($reference, self::MAX_LENGTH_REFERENCE);

        // SEPA reference rules (EPC217-08 §6.3):
        // - Must not start or end with '/'
        // - Must not contain '//'
        $result = preg_replace('#/{2,}#', '/', $result);
        $result = trim($result, '/');

        return $result;
    }

    /**
     * Validate whether a string already conforms to the EPC Basic Latin Character Set.
     *
     * @param string $input The string to validate
     * @return bool True if the string only contains allowed characters
     */
    public static function isValid(string $input): bool
    {
        return preg_match(self::ALLOWED_CHARS_PATTERN, $input) === 0;
    }

    /**
     * Get characters that would need to be replaced.
     * Useful for UI validation feedback.
     *
     * @param string $input The string to check
     * @return array<string> List of invalid characters found
     */
    public static function getInvalidCharacters(string $input): array
    {
        preg_match_all(self::ALLOWED_CHARS_PATTERN, $input, $matches);
        return array_unique($matches[0] ?? []);
    }
}
