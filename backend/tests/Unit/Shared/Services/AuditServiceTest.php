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
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(AuditLogRepository::class);
        $this->auditService = new AuditService($this->repository);
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
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

    /**
     * #886: with no trusted proxies configured, the fallback IP is
     * $_SERVER['REMOTE_ADDR'] as before — X-Forwarded-For is ignored.
     */
    public function test_log_falls_back_to_remote_addr_with_no_trusted_proxies(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';

        $this->repository->expects($this->once())
            ->method('insert')
            ->with($this->callback(fn(array $row): bool => $row['ip_address'] === '203.0.113.7'));

        $this->auditService->log(AuditAction::LOGIN_FAILED, EntityType::ADMIN_USER, 'admin-1');
    }

    /**
     * A trusted proxy's X-Forwarded-For is believed, so the audit row names
     * the real actor rather than the load balancer in front of it.
     */
    public function test_log_uses_the_forwarded_address_from_a_trusted_proxy(): void
    {
        $auditService = new AuditService($this->repository, '10.0.0.5');
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';

        $this->repository->expects($this->once())
            ->method('insert')
            ->with($this->callback(fn(array $row): bool => $row['ip_address'] === '198.51.100.1'));

        $auditService->log(AuditAction::LOGIN_FAILED, EntityType::ADMIN_USER, 'admin-1');
    }

    /** An explicitly passed IP always wins over anything derived from $_SERVER. */
    public function test_log_prefers_an_explicitly_passed_ip_address(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

        $this->repository->expects($this->once())
            ->method('insert')
            ->with($this->callback(fn(array $row): bool => $row['ip_address'] === '192.0.2.99'));

        $this->auditService->log(
            AuditAction::LOGIN_FAILED,
            EntityType::ADMIN_USER,
            'admin-1',
            ipAddress: '192.0.2.99',
        );
    }
}
