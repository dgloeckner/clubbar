<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

/**
 * Base exception for all application-specific exceptions
 */
abstract class AppException extends \Exception
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Get the HTTP status code for this exception
     */
    abstract public function getHttpStatusCode(): int;

    /**
     * Get the error code for API responses
     */
    abstract public function getErrorCode(): string;
}
