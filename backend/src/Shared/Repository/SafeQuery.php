<?php

declare(strict_types=1);

namespace App\Shared\Repository;

class SafeQuery
{
    public static function column(string $input, array $allowed): string
    {
        if (!in_array($input, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid column: {$input}");
        }
        return $input;
    }

    public static function direction(string $input): string
    {
        return strtoupper($input) === 'DESC' ? 'DESC' : 'ASC';
    }

    public static function table(string $input, array $allowed): string
    {
        if (!in_array($input, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid table: {$input}");
        }
        return $input;
    }

    public static function inClause(array $values, string $type = 'int'): array
    {
        if (empty($values)) {
            throw new \InvalidArgumentException("IN clause cannot be empty");
        }

        $sanitized = match ($type) {
            'int'    => array_map('intval', $values),
            'string' => array_values($values),
            default  => throw new \InvalidArgumentException("Unknown type: {$type}"),
        };

        $placeholders = implode(',', array_fill(0, count($sanitized), '?'));

        return [$placeholders, $sanitized];
    }

    /**
     * Neutralise the LIKE wildcards in a search term, so a search matches the
     * characters the user typed rather than the pattern they spell.
     *
     * The backslash has to go first. `str_replace` with array arguments runs
     * its pairs in order over the result of the previous one, so escaping `%`
     * and `_` before the backslash meant the very backslashes just introduced
     * were escaped a second time: `Deckel_1` came out as `Deckel\\_1`, which
     * LIKE reads as "a literal backslash, then any one character" and which
     * therefore matches nothing at all. Any search containing `_` or `%` — an
     * email local part, a name with an underscore, "100%" in a note — silently
     * returned no rows.
     *
     * Escaping the backslash first leaves `Deckel\_1`: a literal underscore,
     * and no wildcard for a caller to smuggle in.
     */
    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    public static function buildUpdate(array $data, array $allowed): array
    {
        $fields = [];
        $values = [];

        foreach ($data as $key => $value) {
            if (!in_array($key, $allowed, true)) continue;
            $fields[] = "{$key} = ?";
            $values[] = $value;
        }

        if (empty($fields)) {
            throw new \InvalidArgumentException("No valid fields to update");
        }

        return [implode(', ', $fields), $values];
    }
}
