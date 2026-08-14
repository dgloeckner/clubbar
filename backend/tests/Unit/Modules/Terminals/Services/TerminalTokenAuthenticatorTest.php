<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Terminals\Services;

use App\Modules\Auth\Services\TokenService;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Modules\Terminals\Services\TerminalTokenAuthenticator;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

/**
 * What a bearer token means, and what happens to it while it is being resolved
 * (#395).
 *
 * The promotion path is exercised end-to-end through the middleware in
 * Tests\Unit\Modules\Auth\Middleware\TerminalTokenAuthTest; what is pinned here
 * is the part with no HTTP in it at all — the lookup order, and the rule that an
 * expiry is recorded once per expiry rather than once per poll.
 */
class TerminalTokenAuthenticatorTest extends TestCase
{
    private TerminalsRepository $terminalsRepository;
    private AuditService $auditService;
    private TerminalTokenAuthenticator $authenticator;

    protected function setUp(): void
    {
        $this->terminalsRepository = $this->createMock(TerminalsRepository::class);
        $this->auditService = $this->createMock(AuditService::class);
        $this->authenticator = new TerminalTokenAuthenticator($this->terminalsRepository, $this->auditService);
    }

    private function terminal(array $overrides = []): array
    {
        return array_merge([
            'id' => 'terminal-1',
            'device_id' => 'device-1',
            'is_active' => 1,
            'token_expires_at' => '2026-08-01 12:00:00',
            'created_at' => '2025-08-01 12:00:00',
        ], $overrides);
    }

    public function test_an_active_token_resolves_without_touching_the_other_lookups(): void
    {
        $this->terminalsRepository->method('findByTokenHash')->willReturn($this->terminal());
        $this->terminalsRepository->expects($this->never())->method('findByPendingTokenHash');
        $this->terminalsRepository->expects($this->never())->method('findExpiredByTokenHash');

        $result = $this->authenticator->authenticate('token');

        $this->assertTrue($result->isAuthenticated());
        $this->assertSame(TerminalTokenAuthenticator::OUTCOME_ACTIVE, $result->outcome);
    }

    public function test_a_pending_token_is_promoted_and_reported_as_such(): void
    {
        $token = 'staged';
        $this->terminalsRepository->method('findByTokenHash')->willReturn(null);
        $this->terminalsRepository->method('findByPendingTokenHash')->willReturn($this->terminal());
        $this->terminalsRepository->expects($this->once())
            ->method('promotePendingToken')
            ->with('terminal-1', TokenService::hashToken($token))
            ->willReturn($this->terminal(['token_expires_at' => '2027-08-01 12:00:00']));

        $result = $this->authenticator->authenticate($token);

        $this->assertTrue($result->isAuthenticated());
        $this->assertSame(TerminalTokenAuthenticator::OUTCOME_PROMOTED, $result->outcome);
        $this->assertSame('2027-08-01 12:00:00', $result->terminal['token_expires_at']);
    }

    public function test_an_unknown_token_is_not_reported_as_expired(): void
    {
        $this->terminalsRepository->method('findByTokenHash')->willReturn(null);
        $this->terminalsRepository->method('findByPendingTokenHash')->willReturn(null);
        $this->terminalsRepository->method('findExpiredByTokenHash')->willReturn(null);
        $this->auditService->expects($this->never())->method('logOnceSince');

        $result = $this->authenticator->authenticate('nonsense');

        $this->assertFalse($result->isAuthenticated());
        $this->assertFalse($result->isExpired());
        $this->assertSame(TerminalTokenAuthenticator::OUTCOME_UNKNOWN, $result->outcome);
    }

    /**
     * The window the dedup is anchored on is the token's own expiry, not "the
     * last hour" or "today": one entry per occurrence, and a terminal that
     * expires again next year gets a new one.
     */
    public function test_an_expired_token_is_audited_once_per_expiry(): void
    {
        $this->terminalsRepository->method('findByTokenHash')->willReturn(null);
        $this->terminalsRepository->method('findByPendingTokenHash')->willReturn(null);
        $this->terminalsRepository->method('findExpiredByTokenHash')->willReturn($this->terminal());

        $this->auditService->expects($this->once())
            ->method('logOnceSince')
            ->with(
                '2026-08-01 12:00:00',
                AuditAction::TERMINAL_TOKEN_EXPIRED,
                EntityType::TERMINAL,
                'terminal-1',
            );

        $result = $this->authenticator->authenticate('aged-out');

        $this->assertTrue($result->isExpired());
        $this->assertFalse($result->isAuthenticated());
    }

    /**
     * A row that carries a hash and no expiry is refused as expired
     * (fail-closed, #106) but has no instant to anchor the dedup window on.
     * Falling back to its creation is what keeps the write from being skipped
     * against an empty `since`.
     */
    public function test_a_row_with_no_expiry_anchors_the_window_on_its_creation(): void
    {
        $this->terminalsRepository->method('findByTokenHash')->willReturn(null);
        $this->terminalsRepository->method('findByPendingTokenHash')->willReturn(null);
        $this->terminalsRepository->method('findExpiredByTokenHash')
            ->willReturn($this->terminal(['token_expires_at' => null]));

        $this->auditService->expects($this->once())
            ->method('logOnceSince')
            ->with('2025-08-01 12:00:00');

        $this->authenticator->authenticate('never-expiring-but-refused');
    }
}
