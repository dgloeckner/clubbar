<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Terminals\Services;

use App\Modules\Terminals\Repositories\TerminalAnomaliesRepository;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Modules\Terminals\Services\TerminalsService;
use App\Shared\Config\AppConfig;
use App\Shared\Enums\AuditAction;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

/**
 * Acknowledging a terminal anomaly (ADR-0041 §4).
 *
 * The property worth pinning is what acknowledgement is *not*: it clears a
 * notice and leaves the credential exactly as it was. An admin who concludes a
 * till really was cloned revokes it through the path that already exists.
 */
class TerminalsServiceAnomalyTest extends TestCase
{
    private TerminalsRepository $terminalsRepository;
    private TerminalAnomaliesRepository $anomalies;
    private AuditService $auditService;
    private TerminalsService $service;

    protected function setUp(): void
    {
        $this->terminalsRepository = $this->createMock(TerminalsRepository::class);
        $this->anomalies = $this->createMock(TerminalAnomaliesRepository::class);
        $this->auditService = $this->createMock(AuditService::class);

        $this->service = new TerminalsService(
            $this->terminalsRepository,
            $this->auditService,
            new AppConfig(),
            $this->anomalies,
        );
    }

    private function anomaly(array $overrides = []): array
    {
        return array_merge([
            'id' => 'anomaly-1',
            'terminal_id' => 'terminal-1',
            'kind' => 'concurrent_ip',
            'first_detected_at' => '2026-08-15 18:00:00',
            'occurrence_count' => 3,
        ], $overrides);
    }

    public function testAcknowledgingAnOpenAnomalyAuditsIt(): void
    {
        $this->anomalies->method('findById')->willReturn($this->anomaly());
        $this->anomalies->method('acknowledge')->willReturn(true);

        $this->auditService->expects($this->once())
            ->method('log')
            ->with(
                AuditAction::TERMINAL_ANOMALY_ACKNOWLEDGED,
                $this->anything(),
                'terminal-1',
                $this->anything(),
                $this->callback(fn(array $v) => $v['anomaly_id'] === 'anomaly-1' && $v['kind'] === 'concurrent_ip'),
                'admin-1',
            );

        $this->assertTrue($this->service->acknowledgeAnomaly('terminal-1', 'anomaly-1', 'admin-1'));
    }

    /**
     * The credential is untouched. This is the whole posture of ADR-0041 in one
     * assertion: detection alerts, and never enforces.
     */
    public function testAcknowledgingDoesNotTouchTheCredential(): void
    {
        $this->anomalies->method('findById')->willReturn($this->anomaly());
        $this->anomalies->method('acknowledge')->willReturn(true);

        $this->terminalsRepository->expects($this->never())->method('updateById');

        $this->service->acknowledgeAnomaly('terminal-1', 'anomaly-1', 'admin-1');
    }

    /**
     * The id alone must not be the authority — otherwise a mistyped terminal in
     * the URL silently clears an alert about a different till.
     */
    public function testAnomalyBelongingToAnotherTerminalIsRefused(): void
    {
        $this->anomalies->method('findById')->willReturn($this->anomaly(['terminal_id' => 'terminal-2']));

        $this->anomalies->expects($this->never())->method('acknowledge');
        $this->auditService->expects($this->never())->method('log');

        $this->assertFalse($this->service->acknowledgeAnomaly('terminal-1', 'anomaly-1', 'admin-1'));
    }

    public function testUnknownAnomalyIsRefused(): void
    {
        $this->anomalies->method('findById')->willReturn(null);

        $this->auditService->expects($this->never())->method('log');

        $this->assertFalse($this->service->acknowledgeAnomaly('terminal-1', 'nope', 'admin-1'));
    }

    /**
     * Two admins clearing the same alert is a race the repository settles with
     * a guarded UPDATE. The loser must not write a second audit entry claiming
     * they did it too.
     */
    public function testLosingTheAcknowledgementRaceWritesNoAuditEntry(): void
    {
        $this->anomalies->method('findById')->willReturn($this->anomaly());
        $this->anomalies->method('acknowledge')->willReturn(false);

        $this->auditService->expects($this->never())->method('log');

        $this->assertFalse($this->service->acknowledgeAnomaly('terminal-1', 'anomaly-1', 'admin-2'));
    }
}
