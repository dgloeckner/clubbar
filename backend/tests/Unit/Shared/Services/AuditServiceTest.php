<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Services;

use App\Modules\AuditLog\Repositories\AuditLogRepository;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

/**
 * The once-per-occurrence audit write (#395).
 *
 * An audit entry normally records a deliberate act, so one entry per act is
 * right. A *condition* being observed is not an act: a terminal polling with an
 * expired token would write an identical row every minute and bury the rest of
 * the log. This is the primitive that stops it.
 */
class AuditServiceTest extends TestCase
{
    private AuditLogRepository $repository;
    private AuditService $auditService;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(AuditLogRepository::class);
        $this->auditService = new AuditService($this->repository);
    }

    public function test_logOnceSince_writes_when_nothing_has_been_recorded_in_the_window(): void
    {
        $this->repository->expects($this->once())
            ->method('hasEntrySince')
            ->with('terminal-1', 'terminal_token_expired', '2026-08-01 12:00:00')
            ->willReturn(false);
        $this->repository->expects($this->once())->method('insert');

        $this->assertTrue($this->auditService->logOnceSince(
            '2026-08-01 12:00:00',
            AuditAction::TERMINAL_TOKEN_EXPIRED,
            EntityType::TERMINAL,
            'terminal-1',
        ));
    }

    public function test_logOnceSince_writes_nothing_when_the_window_already_holds_an_entry(): void
    {
        $this->repository->method('hasEntrySince')->willReturn(true);
        $this->repository->expects($this->never())->method('insert');

        $this->assertFalse($this->auditService->logOnceSince(
            '2026-08-01 12:00:00',
            AuditAction::TERMINAL_TOKEN_EXPIRED,
            EntityType::TERMINAL,
            'terminal-1',
        ));
    }

    /** The dedup must not cost the masking every other audit write gets. */
    public function test_logOnceSince_masks_sensitive_fields_like_an_ordinary_write(): void
    {
        $this->repository->method('hasEntrySince')->willReturn(false);
        $this->repository->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (array $row): bool {
                $new = json_decode((string) json_encode($row['new_values']), true);
                return $new['api_token'] === '[MASKED]';
            }));

        $this->auditService->logOnceSince(
            '2026-08-01 12:00:00',
            AuditAction::TERMINAL_TOKEN_EXPIRED,
            EntityType::TERMINAL,
            'terminal-1',
            ['api_token' => 'super-secret'],
        );
    }
}
