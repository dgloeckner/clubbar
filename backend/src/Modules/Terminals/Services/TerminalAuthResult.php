<?php

declare(strict_types=1);

namespace App\Modules\Terminals\Services;

/**
 * What a bearer token resolved to, and how (#395).
 *
 * The outcome is carried alongside the row rather than inferred from it because
 * "no terminal" now has two answers the caller must tell apart — a token that
 * aged out, and one that was never issued — and "a terminal" has two the caller
 * may want to log differently.
 *
 * @see TerminalTokenAuthenticator
 */
final readonly class TerminalAuthResult
{
    public function __construct(
        public ?array $terminal,
        public string $outcome,
    ) {}

    public function isAuthenticated(): bool
    {
        return $this->terminal !== null;
    }

    public function isExpired(): bool
    {
        return $this->outcome === TerminalTokenAuthenticator::OUTCOME_EXPIRED;
    }
}
