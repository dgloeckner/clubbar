<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

/**
 * Thrown when validation fails
 */
class ValidationException extends AppException
{
    public function __construct(
        string $message,
        private array $errors = []
    ) {
        parent::__construct($message);
    }

    public function getHttpStatusCode(): int
    {
        return 422;
    }

    public function getErrorCode(): string
    {
        return 'validation_failed';
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
