<?php

declare(strict_types=1);

namespace App\Shared\Utils;

/**
 * The one CSV writer.
 *
 * Four builders with four dialects were in the tree (issue #119). One of them
 * joined fields with `;` and no escaping at all, so a member called
 * "Meier; Hans" silently shifted every column after it — the export looked
 * fine and was wrong. Two disagreed on the delimiter, and the money columns
 * disagreed on the unit: one wrote euros, another wrote raw cents under a
 * header that just said "amount".
 *
 * Everything the club exports goes through here: RFC 4180 quoting, `;` as the
 * delimiter (what German spreadsheet locales expect), and money as euros with
 * two decimals via money().
 */
final class Csv
{
    public const DELIMITER = ';';

    /**
     * @param list<string> $headers
     * @param iterable<array<string|int, mixed>> $rows Field order follows the
     *        row's own value order; keys are ignored.
     */
    public static function build(array $headers, iterable $rows, string $delimiter = self::DELIMITER): string
    {
        $lines = [];

        if ($headers !== []) {
            $lines[] = self::line($headers, $delimiter);
        }

        foreach ($rows as $row) {
            $lines[] = self::line(array_values((array) $row), $delimiter);
        }

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    /**
     * Integer cents as a plain decimal euro amount: no thousands separator, no
     * currency symbol, always two decimals, sign preserved for credits.
     */
    public static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * @param list<mixed> $fields
     */
    private static function line(array $fields, string $delimiter): string
    {
        return implode($delimiter, array_map(
            static fn(mixed $field): string => self::escape($field, $delimiter),
            $fields
        ));
    }

    private static function escape(mixed $field, string $delimiter): string
    {
        $value = match (true) {
            $field === null => '',
            is_bool($field) => $field ? '1' : '',
            is_array($field) => (string) json_encode($field, JSON_UNESCAPED_UNICODE),
            default => (string) $field,
        };

        $needsQuoting = str_contains($value, $delimiter)
            || str_contains($value, '"')
            || str_contains($value, "\n")
            || str_contains($value, "\r");

        return $needsQuoting ? '"' . str_replace('"', '""', $value) . '"' : $value;
    }
}
