<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

/**
 * A query parameter is malformed or out of range.
 *
 * Distinct from ValidationException (422, request *body*): a bad `per_page`
 * is a malformed request line, answered with 400 `invalid_request` and a
 * `messages` map keyed by the parameter name the caller actually used.
 */
class InvalidQueryParameterException extends AppException
{
    /**
     * @param array<string, list<string>> $messages
     */
    public function __construct(
        string $message,
        private array $messages = [],
    ) {
        parent::__construct($message);
    }

    public function getHttpStatusCode(): int
    {
        return 400;
    }

    public function getErrorCode(): string
    {
        return 'invalid_request';
    }

    /**
     * @return array<string, list<string>>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }
}
