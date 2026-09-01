<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

/**
 * A meter tripped: too many attempts from one source, too fast.
 *
 * Distinct from a `BusinessRuleException` on purpose. A business rule refusal
 * is a 409 that says *this cannot be done* — the club has switched
 * registration off, the mandate is missing, the balance is outstanding — and it
 * is answered by changing something. A throttle says *not right now*, is
 * answered by waiting, and must not be mistaken for the other: telling somebody
 * that registration is closed when it is merely busy sends them to the bar to
 * ask about a policy that does not exist.
 *
 * `RateLimitMiddleware` writes the same 429 shape directly, because it refuses
 * before any application code runs. This exception is for the meters that can
 * only be counted once a request is understood.
 */
class TooManyAttemptsException extends AppException
{
    public function __construct(
        private readonly int $retryAfterSeconds,
        string $message = 'Too many attempts. Please try again later.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getHttpStatusCode(): int
    {
        return 429;
    }

    public function getErrorCode(): string
    {
        return 'too_many_attempts';
    }

    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
