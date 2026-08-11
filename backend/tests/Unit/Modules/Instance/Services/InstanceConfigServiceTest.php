<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Instance\Services;

use App\Modules\Instance\Repositories\InstanceConfigRepository;
use App\Modules\Instance\Services\InstanceConfigService;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

class InstanceConfigServiceTest extends TestCase
{
    private InstanceConfigRepository $instanceConfigRepository;
    private AuditService $auditService;
    private InstanceConfigService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instanceConfigRepository = $this->createMock(InstanceConfigRepository::class);
        $this->auditService = $this->createMock(AuditService::class);

        $this->service = new InstanceConfigService($this->instanceConfigRepository, $this->auditService);
    }

    // ── getConfig ────────────────────────────────────

    public function test_getConfig_returns_the_stored_name(): void
    {
        $this->instanceConfigRepository->method('getConfig')->willReturn(['instance_name' => 'FRGS Ruderbar']);

        $this->assertSame('FRGS Ruderbar', $this->service->getConfig()->instanceName);
    }

    public function test_getConfig_falls_back_to_the_stock_name_when_no_row_exists(): void
    {
        $this->instanceConfigRepository->method('getConfig')->willReturn(null);

        $this->assertSame('Club Bar', $this->service->getConfig()->instanceName);
    }

    // ── getInstanceName ────────────────────────────────────

    public function test_getInstanceName_returns_the_stored_name(): void
    {
        $this->instanceConfigRepository->method('getConfig')->willReturn(['instance_name' => 'FRGS Ruderbar']);

        $this->assertSame('FRGS Ruderbar', $this->service->getInstanceName());
    }

    public function test_getInstanceName_falls_back_when_the_name_is_empty(): void
    {
        $this->instanceConfigRepository->method('getConfig')->willReturn(['instance_name' => '']);

        $this->assertSame('Club Bar', $this->service->getInstanceName());
    }

    public function test_getInstanceName_falls_back_when_no_row_exists(): void
    {
        $this->instanceConfigRepository->method('getConfig')->willReturn(null);

        $this->assertSame('Club Bar', $this->service->getInstanceName());
    }

    // ── updateConfig ────────────────────────────────────

    public function test_updateConfig_returns_null_when_repository_reports_no_row(): void
    {
        $this->instanceConfigRepository->method('getConfig')->willReturn(null);
        $this->instanceConfigRepository->method('updateConfig')->willReturn(null);

        $this->auditService->expects($this->never())->method('log');

        $result = $this->service->updateConfig(['instance_name' => 'New Name'], 'admin-1');

        $this->assertNull($result);
    }

    public function test_updateConfig_persists_and_returns_the_updated_dto(): void
    {
        $this->instanceConfigRepository->method('getConfig')->willReturn(['instance_name' => 'Old Name']);
        $this->instanceConfigRepository->method('updateConfig')
            ->with(['instance_name' => 'New Name'], 'admin-1')
            ->willReturn(['instance_name' => 'New Name']);

        $result = $this->service->updateConfig(['instance_name' => 'New Name'], 'admin-1');

        $this->assertSame('New Name', $result->instanceName);
    }

    public function test_updateConfig_writes_audit_entry_with_old_and_new_name(): void
    {
        $this->instanceConfigRepository->method('getConfig')->willReturn(['instance_name' => 'Old Name']);
        $this->instanceConfigRepository->method('updateConfig')->willReturn(['instance_name' => 'New Name']);

        $this->auditService->expects($this->once())
            ->method('log')
            ->with(
                $this->anything(),
                $this->anything(),
                '1',
                ['instance_name' => 'Old Name'],
                ['instance_name' => 'New Name'],
                'admin-1',
            );

        $this->service->updateConfig(['instance_name' => 'New Name'], 'admin-1');
    }

    public function test_updateConfig_writes_audit_entry_with_null_old_values_on_first_configuration(): void
    {
        $this->instanceConfigRepository->method('getConfig')->willReturn(null);
        $this->instanceConfigRepository->method('updateConfig')->willReturn(['instance_name' => 'FRGS Ruderbar']);

        $this->auditService->expects($this->once())
            ->method('log')
            ->with(
                $this->anything(),
                $this->anything(),
                '1',
                null,
                ['instance_name' => 'FRGS Ruderbar'],
                'admin-1',
            );

        $this->service->updateConfig(['instance_name' => 'FRGS Ruderbar'], 'admin-1');
    }
}
