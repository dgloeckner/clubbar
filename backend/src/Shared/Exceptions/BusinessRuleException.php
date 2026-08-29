<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

/**
 * Thrown when a business rule is violated.
 *
 * Every refusal names a {@see BusinessRuleReason}. The reason — not the
 * English message — is what the admin panel translates, so the constructor
 * requires it: a rule added later cannot reach an admin's screen in a
 * language they did not choose without someone deliberately removing this
 * argument.
 */
class BusinessRuleException extends AppException
{
    /**
     * @param BusinessRuleReason $reason What refused, as a translatable code.
     * @param string $message The English sentence, for the log and raw API callers.
     * @param array<string, string|int|float|bool|null> $params Values the
     *        translated sentence interpolates. Amounts are integer cents under
     *        a `*_cents` key; see {@see BusinessRuleReason} rule 3.
     * @param \Throwable|null $previous The failure this one translates, if any.
     */
    public function __construct(
        private readonly BusinessRuleReason $reason,
        string $message,
        private readonly array $params = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getHttpStatusCode(): int
    {
        return 409;
    }

    public function getErrorCode(): string
    {
        return 'business_rule_violation';
    }

    public function getReason(): BusinessRuleReason
    {
        return $this->reason;
    }

    /** @return array<string, string|int|float|bool|null> */
    public function getParams(): array
    {
        return $this->params;
    }
}
