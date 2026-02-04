<?php

declare(strict_types=1);

namespace App\Shared\Validation;

class Validator
{
    private array $errors = [];

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
            'min'      => (is_string($value) && strlen($value) < (int)$param) ? "{$field} must be at least {$param} characters" : null,
            'max'      => (is_string($value) && strlen($value) > (int)$param) ? "{$field} must be at most {$param} characters" : null,
            'boolean'  => (!is_bool($value) && $value !== null && $value !== 0 && $value !== 1 && $value !== '0' && $value !== '1') ? "{$field} must be a boolean" : null,
            'uuid'     => ($value && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) ? "{$field} must be a valid UUID" : null,
            'date'     => ($value && !strtotime($value)) ? "{$field} must be a valid date" : null,
            'array'    => ($value !== null && !is_array($value)) ? "{$field} must be an array" : null,
            'json'     => ($value !== null && !is_array($value) && json_decode((string)$value) === null) ? "{$field} must be valid JSON" : null,
            'regex'    => ($value !== null && $param && !preg_match($param, (string)$value)) ? "{$field} format is invalid" : null,
            'same'     => ($value !== null && $param && $value !== ($data[$param] ?? null)) ? "{$field} must match {$param}" : null,
            'nullable' => null,
            'in'       => ($value !== null && $param && !in_array((string)$value, explode(',', $param), true)) ? "{$field} must be one of: {$param}" : null,
            default    => null,
        };
    }
}
