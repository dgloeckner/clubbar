<?php

declare(strict_types=1);

namespace App\Modules\Terminals\Services;

use App\Modules\Auth\Services\TokenService;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Services\AuditService;

/**
 * Resolves a bearer token to a terminal, and owns the two state changes that
 * can happen while it does (#395, ADR-0036).
 *
 * This sits between the middleware and the repository because authenticating a
 * terminal is no longer a pure lookup. A token presented for the first time
 * *promotes* — it becomes the terminal's active credential and retires the one
 * it replaces — and a token that has aged out is a fact worth recording once.
 * Neither belongs in a PSR-15 middleware, and both must happen on every path
 * that authenticates a terminal, so they live here rather than in a caller.
 *
 * The lookup order is deliberate: active first, pending second. During an
 * overlap both tokens authenticate, and the terminal that is still using the
 * old one must not pay for a second query on every sync.
 */
final class TerminalTokenAuthenticator
{
    public const OUTCOME_ACTIVE = 'active';
    public const OUTCOME_PROMOTED = 'promoted';
    public const OUTCOME_EXPIRED = 'expired';
    public const OUTCOME_UNKNOWN = 'unknown';

    public function __construct(
        private TerminalsRepository $terminalsRepository,
        private AuditService $auditService,
    ) {}

    public function authenticate(string $plainToken): TerminalAuthResult
    {
        $sha256 = TokenService::hashToken($plainToken);

        $terminal = $this->terminalsRepository->findByTokenHash($sha256);
        if ($terminal !== null) {
            return new TerminalAuthResult($terminal, self::OUTCOME_ACTIVE);
        }

        $pending = $this->terminalsRepository->findByPendingTokenHash($sha256);
        if ($pending !== null) {
            return $this->promote($pending, $sha256);
        }

        $expired = $this->terminalsRepository->findExpiredByTokenHash($sha256);
        if ($expired !== null) {
            $this->recordExpiry($expired);
            return new TerminalAuthResult(null, self::OUTCOME_EXPIRED);
        }

        return new TerminalAuthResult(null, self::OUTCOME_UNKNOWN);
    }

    /**
     * First use of a staged token is what activates it — the admin never has to
     * declare the rollout finished, and a token that was written down but never
     * entered never displaces the one that is working.
     */
    private function promote(array $terminal, string $sha256): TerminalAuthResult
    {
        $promoted = $this->terminalsRepository->promotePendingToken($terminal['id'], $sha256);

        if ($promoted === null) {
            // Another request promoted it between our read and our write. The
            // token is now the active one, so the ordinary lookup answers — and
            // only the request that won the race writes the audit entry.
            $current = $this->terminalsRepository->findByTokenHash($sha256);

            return $current !== null
                ? new TerminalAuthResult($current, self::OUTCOME_ACTIVE)
                : new TerminalAuthResult(null, self::OUTCOME_UNKNOWN);
        }

        $this->auditService->log(
            action: AuditAction::TERMINAL_TOKEN_ACTIVATED,
            entityType: EntityType::TERMINAL,
            entityId: $promoted['id'],
            newValues: [
                'device_id' => $promoted['device_id'],
                'token_expires_at' => $promoted['token_expires_at'],
            ],
        );

        return new TerminalAuthResult($promoted, self::OUTCOME_PROMOTED);
    }

    /**
     * One entry per expiry, not one per poll. A terminal keeps trying every
     * sync cycle for as long as it stays switched on, and an audit log that
     * records each attempt tells an admin nothing they did not learn from the
     * first line while hiding everything else on the page.
     */
    private function recordExpiry(array $terminal): void
    {
        $expiredAt = $terminal['token_expires_at']
            ?? $terminal['pending_token_expires_at']
            // A row carrying a hash and no expiry is refused as expired
            // (fail-closed, #106) but has no instant to anchor the window on;
            // its creation is the earliest moment it could have been wrong.
            ?? $terminal['created_at'];

        $this->auditService->logOnceSince(
            since: (string) $expiredAt,
            action: AuditAction::TERMINAL_TOKEN_EXPIRED,
            entityType: EntityType::TERMINAL,
            entityId: $terminal['id'],
            newValues: [
                'device_id' => $terminal['device_id'],
                'token_expires_at' => $terminal['token_expires_at'],
            ],
        );
    }
}
