<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Terminals\Services;

use App\Modules\Instance\Services\InstanceConfigService;
use App\Modules\Terminals\Services\PairingService;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0035: clearing a pairing mismatch is a deliberate, audited act — a
 * terminal calls this only after staff at the bar has confirmed it is safe
 * to trust this backend's history again. The service's whole job is to make
 * that moment leave a record and hand back the value the terminal should now
 * treat as paired.
 */
class PairingServiceTest extends TestCase
{
    private InstanceConfigService $instanceConfigService;
    private AuditService $auditService;
    private PairingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instanceConfigService = $this->createMock(InstanceConfigService::class);
        $this->auditService = $this->createMock(AuditService::class);

        $this->service = new PairingService($this->instanceConfigService, $this->auditService);
    }

    public function test_acknowledgeRepair_returns_the_current_instance_id(): void
    {
        $this->instanceConfigService->method('getInstanceId')->willReturn('a1b2c3d4-e5f6-4789-a0b1-c2d3e4f5a6b7');

        $result = $this->service->acknowledgeRepair('terminal-1');

        $this->assertSame('a1b2c3d4-e5f6-4789-a0b1-c2d3e4f5a6b7', $result);
    }

    public function test_acknowledgeRepair_writes_an_audit_entry_for_the_terminal(): void
    {
        $this->instanceConfigService->method('getInstanceId')->willReturn('a1b2c3d4-e5f6-4789-a0b1-c2d3e4f5a6b7');

        $this->auditService->expects($this->once())
            ->method('log')
            ->with(
                action: AuditAction::TERMINAL_REPAIR,
                entityType: EntityType::TERMINAL,
                entityId: 'terminal-1',
                oldValues: null,
                newValues: ['instance_id' => 'a1b2c3d4-e5f6-4789-a0b1-c2d3e4f5a6b7'],
                adminUserId: null,
            );

        $this->service->acknowledgeRepair('terminal-1');
    }
}
