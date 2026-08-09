<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Terminals\Services;

use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Modules\Terminals\Services\TerminalsService;
use App\Shared\Config\AppConfig;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Exceptions\DuplicateResourceException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

/**
 * Terminal token lifetime, from the service's side (#106).
 *
 * The service's job is to hand the configured TTL to the repository on every
 * path that issues a token, and to clear the lifetime on the path that takes
 * one away. What the database then does with those values is pinned by
 * Tests\Feature\Modules\Terminals\Repositories\TerminalsRepositoryTest.
 */
class TerminalsServiceTest extends TestCase
{
    private TerminalsRepository $terminalsRepository;
    private AuditService $auditService;
    private TerminalsService $terminalsService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->terminalsRepository = $this->createMock(TerminalsRepository::class);
        $this->auditService = $this->createMock(AuditService::class);
        $this->terminalsService = new TerminalsService(
            $this->terminalsRepository,
            $this->auditService,
            new AppConfig(),
        );
    }

    /** The default TTL, which no environment variable overrides in the test run. */
    private function configuredTtlDays(): int
    {
        return (new AppConfig())->tokenTtlDays;
    }

    private function terminalRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 'terminal-uuid',
            'name' => 'Bar Terminal',
            'device_id' => 'BAR-MAIN-001',
            'is_active' => 1,
            'last_sync_at' => null,
            'token_issued_at' => '2026-08-09 09:00:00',
            'token_expires_at' => '2026-11-07 09:00:00',
            'created_at' => '2026-08-09 09:00:00',
            'updated_at' => '2026-08-09 09:00:00',
        ], $overrides);
    }

    public function test_createTerminal_passes_the_configured_token_lifetime_to_the_repository(): void
    {
        $this->terminalsRepository->method('findByDeviceId')->willReturn(null);
        $this->terminalsRepository->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $data): bool {
                return $data['token_ttl_days'] === $this->configuredTtlDays()
                    && strlen($data['api_token_hash']) === 64;
            }))
            ->willReturn($this->terminalRow());

        $result = $this->terminalsService->createTerminal('Bar Terminal', 'BAR-MAIN-001');

        $this->assertSame(64, strlen($result['plaintext_token']));
        $this->assertSame('2026-11-07T09:00:00Z', $result['terminal']->toArray()['token_expires_at']);
    }

    /**
     * The audit trail records when the issued token dies, so "who could still
     * reach the API in August?" is answerable from the log alone.
     */
    public function test_createTerminal_audits_the_expiry_of_the_token_it_issued(): void
    {
        $this->terminalsRepository->method('findByDeviceId')->willReturn(null);
        $this->terminalsRepository->method('create')->willReturn($this->terminalRow());

        $this->auditService->expects($this->once())
            ->method('log')
            ->with(
                AuditAction::CREATE,
                EntityType::TERMINAL,
                'terminal-uuid',
                null,
                $this->callback(fn(array $newValues): bool => $newValues['token_expires_at'] === '2026-11-07 09:00:00'),
            );

        $this->terminalsService->createTerminal('Bar Terminal', 'BAR-MAIN-001');
    }

    public function test_createTerminal_rejects_a_device_id_that_already_exists(): void
    {
        $this->terminalsRepository->method('findByDeviceId')->willReturn($this->terminalRow());
        $this->terminalsRepository->expects($this->never())->method('create');

        $this->expectException(DuplicateResourceException::class);

        $this->terminalsService->createTerminal('Bar Terminal', 'BAR-MAIN-001');
    }

    public function test_rotateToken_issues_a_new_token_with_a_full_lifetime(): void
    {
        $this->terminalsRepository->expects($this->once())
            ->method('rotateToken')
            ->with(
                'terminal-uuid',
                $this->callback(fn(string $hash): bool => strlen($hash) === 64),
                $this->configuredTtlDays(),
            )
            ->willReturn($this->terminalRow());

        $result = $this->terminalsService->rotateToken('terminal-uuid');

        $this->assertSame(64, strlen($result['plaintext_token']));
        $this->assertSame('2026-11-07T09:00:00Z', $result['terminal']->toArray()['token_expires_at']);
    }

    public function test_rotateToken_audits_the_new_expiry_without_recording_the_token(): void
    {
        $this->terminalsRepository->method('rotateToken')->willReturn($this->terminalRow());

        $this->auditService->expects($this->once())
            ->method('log')
            ->with(
                AuditAction::UPDATE,
                EntityType::TERMINAL,
                'terminal-uuid',
                null,
                $this->callback(function (array $newValues): bool {
                    return $newValues['api_token'] === '[ROTATED]'
                        && $newValues['token_expires_at'] === '2026-11-07 09:00:00';
                }),
            );

        $this->terminalsService->rotateToken('terminal-uuid');
    }

    public function test_rotateToken_reports_an_unknown_terminal_as_not_found(): void
    {
        $this->terminalsRepository->method('rotateToken')->willReturn(null);

        $this->expectException(NotFoundException::class);

        $this->terminalsService->rotateToken('does-not-exist');
    }

    /**
     * Revoking clears the lifetime along with the hash: nothing is left for a
     * later NOW() to compare against, so the row cannot come back to life.
     */
    public function test_revokeAccess_clears_the_token_lifetime_with_the_hash(): void
    {
        $this->terminalsRepository->expects($this->once())
            ->method('updateById')
            ->with('terminal-uuid', [
                'api_token_hash' => null,
                'token_issued_at' => null,
                'token_expires_at' => null,
                'is_active' => 0,
            ])
            ->willReturn($this->terminalRow(['is_active' => 0]));

        $this->terminalsService->revokeAccess('terminal-uuid');
    }
}
