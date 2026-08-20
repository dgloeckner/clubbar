<?php

declare(strict_types=1);

namespace App\Shared\Validation;

use App\Shared\Utils\BankingCalendar;

class Validator
{
    /**
     * The date shapes the `date` rule accepts, in the order they are tried.
     *
     * A plain `Y-m-d` is what every date field in this system stores and what
     * every client sends; the ISO-8601 variants are here so a timestamp
     * serialised by a JavaScript client is not rejected out of hand.
     */
    private const DATE_FORMATS = [
        'Y-m-d',
        'Y-m-d H:i:s',
        'Y-m-d\TH:i:s',
        'Y-m-d\TH:i:sP',
        'Y-m-d\TH:i:s.vP',
        'Y-m-d\TH:i:s\Z',
        'Y-m-d\TH:i:s.v\Z',
    ];

    private array $errors = [];

    public function __construct(
        private \PDO $pdo
    ) {}

    public function validate(array $data, array $rules): bool
    {
        $this->errors = [];

        foreach ($rules as $field => $fieldRules) {
            $value = $data[$field] ?? null;

            foreach ($fieldRules as $rule) {
                $error = $this->check($field, $value, $rule, $data);
                if ($error) {
                    $this->errors[$field][] = $error;
                }
            }
        }

        return empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    private function check(string $field, mixed $value, string $rule, array $data): ?string
    {
        $parts = explode(':', $rule, 2);
        $ruleName = $parts[0];
        $param = $parts[1] ?? null;

        return match ($ruleName) {
            'required' => ($value === null || $value === '') ? "{$field} is required" : null,
            'string'   => (!is_string($value) && $value !== null) ? "{$field} must be a string" : null,
            'integer'  => $this->validateInteger($field, $value),
            'numeric'  => (!is_numeric($value) && $value !== null) ? "{$field} must be numeric" : null,
            'email'    => ($value && !filter_var($value, FILTER_VALIDATE_EMAIL)) ? "{$field} must be a valid email" : null,
            'min'      => $this->validateMin($field, $value, $param),
            'max'      => $this->validateMax($field, $value, $param),
            'gt'       => (is_numeric($value) && $value <= (int)$param) ? "{$field} must be greater than {$param}" : null,
            'gte'      => (is_numeric($value) && $value < (int)$param) ? "{$field} must be at least {$param}" : null,
            'lt'       => (is_numeric($value) && $value >= (int)$param) ? "{$field} must be less than {$param}" : null,
            'lte'      => (is_numeric($value) && $value > (int)$param) ? "{$field} must be at most {$param}" : null,
            'boolean'  => (!is_bool($value) && $value !== null && $value !== 0 && $value !== 1 && $value !== '0' && $value !== '1') ? "{$field} must be a boolean" : null,
            'uuid'     => ($value && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) ? "{$field} must be a valid UUID" : null,
            'date'     => $this->validateDate($field, $value),
            'past_date' => $this->validatePastDate($field, $value),
            'business_day' => $this->validateBusinessDay($field, $value),
            'array'    => ($value !== null && !is_array($value)) ? "{$field} must be an array" : null,
            'json'     => ($value !== null && !is_array($value) && json_decode((string)$value) === null) ? "{$field} must be valid JSON" : null,
            'regex'    => ($value !== null && $param && !preg_match($param, (string)$value)) ? "{$field} format is invalid" : null,
            'same'     => ($value !== null && $param && $value !== ($data[$param] ?? null)) ? "{$field} must match {$param}" : null,
            'nullable' => null,
            'in'       => ($value !== null && $param && !in_array((string)$value, explode(',', $param), true)) ? "{$field} must be one of: {$param}" : null,
            'iban'     => $this->validateIban($field, $value),
            'unique'   => $this->validateUnique($field, $value, $param),
            default    => null,
        };
    }

    /**
     * Whole numbers only.
     *
     * The rule used to be `is_numeric()`, which passes `"1.5"` — and every
     * caller of an `integer` field casts with `(int)`, so `amount_cents: "12.9"`
     * was accepted and then silently booked as 12 cents (#117). Anything that
     * would not survive the cast intact is rejected here instead.
     */
    private function validateInteger(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $message = "{$field} must be an integer";

        if (is_bool($value) || !is_numeric($value)) {
            return $message;
        }

        // Compare as floats so the check survives numeric strings and floats
        // alike: "12" and 12.0 pass, "1.5" and 1e30 do not.
        return (float) (int) $value === (float) $value ? null : $message;
    }

    /**
     * Calendar dates only, in a format the database and the SEPA XML accept.
     *
     * `strtotime()` parses relative expressions, so the rule used to accept
     * `"next tuesday"`, `"now"` and `"+1 day"` (#117) — values that reach a
     * DATE column as garbage, or worse, as a date that changes with the clock.
     * A date must now match one of the formats below exactly; the round-trip
     * comparison also rules out overflow like `2026-02-30`.
     */
    private function validateDate(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $message = "{$field} must be a valid date";

        if (!is_string($value)) {
            return $message;
        }

        return $this->parseDate($value) === null ? $message : null;
    }

    /**
     * A calendar date that is not in the future.
     *
     * Two fields need this and neither could express it: `mandate_signed_at`
     * carried only `['nullable', 'date']`, so a mandate could be recorded as
     * signed next month, and `date_of_birth` (ADR-0045) has the sharper version
     * of the same problem — a member born tomorrow is younger than every
     * `min_age` forever and the Jugendschutz check silently refuses them for
     * good.
     *
     * The rule owns exactly one question. A malformed value returns null here
     * and is reported once by `date`; an absent one is `required`'s business.
     * Today passes: a mandate signed this morning is the ordinary case.
     */
    private function validatePastDate(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '' || !is_string($value)) {
            return null;
        }

        $parsed = $this->parseDate($value);

        if ($parsed === null) {
            // Unparseable — `date` names it. Saying so twice would make one
            // mistake read as two.
            return null;
        }

        $today = new \DateTimeImmutable('today');

        return $parsed->format('Y-m-d') > $today->format('Y-m-d')
            ? "{$field} must not be in the future"
            : null;
    }

    /**
     * The one place a date string becomes a date, shared by `date` and
     * `past_date` so the two cannot disagree about what parses.
     */
    private function parseDate(string $value): ?\DateTimeImmutable
    {
        foreach (self::DATE_FORMATS as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $value);

            if ($parsed !== false && $parsed->format($format) === $value) {
                return $parsed;
            }
        }

        return null;
    }

    private function validateMin(string $field, mixed $value, ?string $param): ?string
    {
        if ($param === null) {
            return null;
        }

        if (is_string($value) && strlen($value) < (int)$param) {
            return "{$field} must be at least {$param} characters";
        }

        if (is_numeric($value) && $value < (int)$param) {
            return "{$field} must be at least {$param}";
        }

        if (is_array($value) && count($value) < (int)$param) {
            return "{$field} must contain at least {$param} items";
        }

        return null;
    }

    private function validateMax(string $field, mixed $value, ?string $param): ?string
    {
        if ($param === null) {
            return null;
        }

        // For string values, check string length (not numeric value)
        // This prevents numeric strings like "0013466849" from being compared as numbers
        if (is_string($value)) {
            if (strlen($value) > (int)$param) {
                return "{$field} must be at most {$param} characters";
            }
            return null;
        }

        // For non-string numeric values (int, float), check numeric value
        if (is_numeric($value) && $value > (int)$param) {
            return "{$field} must be at most {$param}";
        }

        return null;
    }

    /**
     * Validate that a date is a TARGET2 bank business day.
     *
     * SEPA requires the requested collection date to be a settlement day, so a
     * weekend or TARGET2 closing day would produce an invalid ReqdColltnDt.
     * Null passes — `required` owns the missing-value case.
     */
    private function validateBusinessDay(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $message = "{$field} must be a bank business day (Mon-Fri, excluding TARGET2 closing days)";

        try {
            return BankingCalendar::isBusinessDay((string) $value) ? null : $message;
        } catch (\InvalidArgumentException) {
            // Unparseable input is reported by the `date` rule; fail closed here
            // rather than letting the exception escape as a 500.
            return $message;
        }
    }

    /**
     * Validate IBAN using ISO 7064 Mod 97-10 checksum.
     */
    private function validateIban(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null; // nullable — let 'required' handle presence
        }

        $iban = strtoupper(str_replace(' ', '', (string)$value));

        // Format: 2 letters + 2 digits + 11-30 alphanumeric
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
            return "{$field} must be a valid IBAN";
        }

        // Rearrange: move first 4 chars to end
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);

        // Replace letters with numbers (A=10 .. Z=35)
        $numeric = '';
        for ($i = 0; $i < strlen($rearranged); $i++) {
            $ch = $rearranged[$i];
            if (ctype_alpha($ch)) {
                $numeric .= (string)(ord($ch) - 55);
            } else {
                $numeric .= $ch;
            }
        }

        // Mod 97 using bcmod for arbitrary precision
        if (bcmod($numeric, '97') !== '1') {
            return "{$field} must be a valid IBAN";
        }

        return null;
    }

    /**
     * Validate unique constraint against database
     * Format: unique:table,column,excludeId
     * Example: unique:members,card_uid or unique:members,card_uid,123
     */
    private function validateUnique(string $field, mixed $value, ?string $param): ?string
    {
        if ($value === null || $value === '' || $param === null) {
            return null;
        }

        $parts = explode(',', $param);
        $table = $parts[0] ?? null;
        $column = $parts[1] ?? null;
        $excludeId = $parts[2] ?? null;

        if (!$table || !$column) {
            return null;
        }

        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$column} = :value";
        $params = ['value' => $value];

        if ($excludeId) {
            $sql .= " AND id != :exclude_id";
            $params['exclude_id'] = $excludeId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $count = $stmt->fetchColumn();

        if ($count > 0) {
            return "The {$field} has already been taken";
        }

        return null;
    }
}
