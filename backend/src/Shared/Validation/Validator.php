<?php

declare(strict_types=1);

namespace App\Shared\Validation;

use App\Shared\Utils\BankingCalendar;

class Validator
{
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
            'integer'  => (!is_numeric($value) && $value !== null) ? "{$field} must be an integer" : null,
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
            'date'     => ($value && !strtotime($value)) ? "{$field} must be a valid date" : null,
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
