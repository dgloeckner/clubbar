<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Terminals\Services;

use App\Modules\Notifications\DTOs\EnqueueResultDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Services\AdminNotifier;
use App\Modules\Terminals\Repositories\TerminalAnomaliesRepository;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Modules\Terminals\Services\TerminalsService;
use App\Shared\Config\AppConfig;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Exceptions\DuplicateResourceException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Logging\Logger;
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
    private TerminalAnomaliesRepository $anomaliesRepository;
    private AdminNotifier $adminNotifier;
    private TerminalsService $terminalsService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->terminalsRepository = $this->createMock(TerminalsRepository::class);
        $this->auditService = $this->createMock(AuditService::class);
        $this->anomaliesRepository = $this->createMock(TerminalAnomaliesRepository::class);
        $this->adminNotifier = $this->createMock(AdminNotifier::class);
        $this->adminNotifier->method('warnAdmins')->willReturn(new EnqueueResultDto(1, []));
        $this->terminalsService = new TerminalsService(
            $this->terminalsRepository,
            $this->auditService,
            new AppConfig(),
            $this->anomaliesRepository,
            $this->adminNotifier,
            $this->createMock(Logger::class),
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
            'pending_token_expires_at' => null,
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

        // Two entries: the terminal was enrolled, and a credential was issued
        // for it (#395). The credential's own trail has to be readable without
        // reconstructing it from terminal CRUD.
        $logged = [];
        $this->auditService->expects($this->exactly(2))
            ->method('log')
            ->willReturnCallback(function (AuditAction $action, EntityType $type, string $id, ?array $old, ?array $new) use (&$logged) {
                $logged[$action->value] = $new;
            });

        $this->terminalsService->createTerminal('Bar Terminal', 'BAR-MAIN-001');

        $this->assertSame('2026-11-07 09:00:00', $logged[AuditAction::CREATE->value]['token_expires_at']);
        $this->assertSame(
            '2026-11-07 09:00:00',
            $logged[AuditAction::TERMINAL_TOKEN_CREATED->value]['token_expires_at'],
        );
    }

    public function test_createTerminal_rejects_a_device_id_that_already_exists(): void
    {
        $this->terminalsRepository->method('findByDeviceId')->willReturn($this->terminalRow());
        $this->terminalsRepository->expects($this->never())->method('create');

        $this->expectException(DuplicateResourceException::class);

        $this->terminalsService->createTerminal('Bar Terminal', 'BAR-MAIN-001');
    }

    /**
     * Rotation stages the replacement rather than cutting over (#395): the
     * repository is asked for a *pending* token, and the token handed back to
     * the admin carries the pending lifetime, not the one still in force.
     */
    public function test_rotateToken_stages_a_pending_token_with_a_full_lifetime(): void
    {
        $this->terminalsRepository->expects($this->once())
            ->method('issuePendingToken')
            ->with(
                'terminal-uuid',
                $this->callback(fn(string $hash): bool => strlen($hash) === 64),
                $this->configuredTtlDays(),
            )
            ->willReturn($this->terminalRow(['pending_token_expires_at' => '2027-08-09 09:00:00']));

        $result = $this->terminalsService->rotateToken('terminal-uuid');
        $terminal = $result['terminal']->toArray();

        $this->assertSame(64, strlen($result['plaintext_token']));
        $this->assertSame('2027-08-09T09:00:00Z', $terminal['pending_token_expires_at']);
        $this->assertTrue($terminal['has_pending_token']);
        // The credential in the field is untouched — that is the whole point.
        $this->assertSame('2026-11-07T09:00:00Z', $terminal['token_expires_at']);
    }

    public function test_rotateToken_audits_the_new_expiry_without_recording_the_token(): void
    {
        $this->terminalsRepository->method('issuePendingToken')
            ->willReturn($this->terminalRow(['pending_token_expires_at' => '2027-08-09 09:00:00']));

        $this->auditService->expects($this->once())
            ->method('log')
            ->with(
                AuditAction::TERMINAL_TOKEN_ROTATED,
                EntityType::TERMINAL,
                'terminal-uuid',
                null,
                $this->callback(function (array $newValues): bool {
                    return $newValues['api_token'] === '[ROTATED]'
                        && $newValues['pending_token_expires_at'] === '2027-08-09 09:00:00'
                        && $newValues['replaces_token_expires_at'] === '2026-11-07 09:00:00';
                }),
            );

        $this->terminalsService->rotateToken('terminal-uuid');
    }

    public function test_rotateToken_reports_an_unknown_terminal_as_not_found(): void
    {
        $this->terminalsRepository->method('issuePendingToken')->willReturn(null);

        $this->expectException(NotFoundException::class);

        $this->terminalsService->rotateToken('does-not-exist');
    }

    /**
     * Revoking clears the lifetime along with the hash: nothing is left for a
     * later NOW() to compare against, so the row cannot come back to life.
     */
    /**
     * ADR-0043: minting announces itself.
     *
     * The subject is the terminal and the occasion names the event and the
     * generation — `enrolled:<token_issued_at>` — which is what makes a later
     * rotation of the same terminal a second message rather than one the
     * outbox's unique index swallows.
     */
    public function test_createTerminal_announces_the_issuance_to_every_active_admin(): void
    {
        $this->terminalsRepository->method('findByDeviceId')->willReturn(null);
        $this->terminalsRepository->method('create')->willReturn($this->terminalRow());

        $this->adminNotifier->expects($this->once())
            ->method('warnAdmins')
            ->with(
                MailKind::TERMINAL_TOKEN_ISSUED,
                'terminal-uuid',
                'enrolled:20260809090000',
                'admin-7',
            )
            ->willReturn(new EnqueueResultDto(2, []));

        $this->terminalsService->createTerminal('Bar Terminal', 'BAR-MAIN-001', 'admin-7');
    }

    /**
     * A rotation announces the *pending* generation, not the one still in the
     * field: the message is about the credential that was just created, and the
     * old one keeps working until the terminal presents its replacement (#395).
     */
    public function test_rotateToken_announces_the_generation_it_staged(): void
    {
        $this->terminalsRepository->method('issuePendingToken')->willReturn($this->terminalRow([
            'pending_token_issued_at' => '2026-08-16 14:23:17',
            'pending_token_expires_at' => '2027-08-16 14:23:17',
        ]));

        $this->adminNotifier->expects($this->once())
            ->method('warnAdmins')
            ->with(
                MailKind::TERMINAL_TOKEN_ISSUED,
                'terminal-uuid',
                'rotated:20260816142317',
                'admin-7',
            )
            ->willReturn(new EnqueueResultDto(2, []));

        $this->terminalsService->rotateToken('terminal-uuid', 'admin-7');
    }

    /**
     * Never a gate (ADR-0043). Rotation is the fix for a till that cannot sell,
     * so a queue that is unavailable must not turn the recovery path into a
     * second outage. The credential is already minted and already audited by the
     * time this runs; losing the announcement is strictly better than losing the
     * terminal.
     */
    public function test_a_queue_failure_does_not_cost_the_caller_its_token(): void
    {
        $this->terminalsRepository->method('issuePendingToken')->willReturn($this->terminalRow([
            'pending_token_issued_at' => '2026-08-16 14:23:17',
        ]));

        $this->adminNotifier->method('warnAdmins')
            ->willThrowException(new \RuntimeException('outbox unavailable'));

        $result = $this->terminalsService->rotateToken('terminal-uuid', 'admin-7');

        $this->assertSame(64, strlen($result['plaintext_token']));
    }

    /**
     * An unknown terminal never reaches the announcement: `rotateToken()` throws
     * on the missing row, so there is no terminal to be announced about and no
     * message queued against an id that points at nothing.
     */
    public function test_a_rotation_that_finds_no_terminal_announces_nothing(): void
    {
        $this->terminalsRepository->method('issuePendingToken')->willReturn(null);
        $this->adminNotifier->expects($this->never())->method('warnAdmins');

        $this->expectException(NotFoundException::class);

        $this->terminalsService->rotateToken('missing-uuid', 'admin-7');
    }

    public function test_revokeAccess_clears_the_token_lifetime_with_the_hash(): void
    {
        $this->terminalsRepository->expects($this->once())
            ->method('updateById')
            ->with('terminal-uuid', [
                'api_token_hash' => null,
                'token_issued_at' => null,
                'token_expires_at' => null,
                // A staged replacement is a credential too: leaving it behind
                // would let a revoked terminal walk straight back in (#395).
                'pending_token_hash' => null,
                'pending_token_issued_at' => null,
                'pending_token_expires_at' => null,
                'is_active' => 0,
            ])
            ->willReturn($this->terminalRow(['is_active' => 0]));

        $this->terminalsService->revokeAccess('terminal-uuid');
    }
}
