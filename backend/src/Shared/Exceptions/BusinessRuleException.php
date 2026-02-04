<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

/**
 * Thrown when a business rule is violated
 */
class BusinessRuleException extends AppException
{
    public function getHttpStatusCode(): int
    {
        return 400;
    }

    public function getErrorCode(): string
    {
        return 'business_rule_violation';
    }
}
