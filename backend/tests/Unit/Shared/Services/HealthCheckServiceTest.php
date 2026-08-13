<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Services;

use App\Modules\Instance\Services\InstanceConfigService;
use App\Shared\Services\HealthCheckService;
use PHPUnit\Framework\TestCase;

class HealthCheckServiceTest extends TestCase
{
    public function test_check_includes_the_configured_instance_name(): void
    {
        $instanceConfigService = $this->createMock(InstanceConfigService::class);
        $instanceConfigService->method('getInstanceName')->willReturn('FRGS Ruderbar');

        $result = (new HealthCheckService($instanceConfigService))->check();

        $this->assertSame('ok', $result->status);
        $this->assertSame('FRGS Ruderbar', $result->instanceName);
    }

    /**
     * instance_id lets a terminal (ADR-0035) tell this backend apart from one
     * with the same URL but a discontinuous history.
     */
    public function test_check_includes_the_configured_instance_id(): void
    {
        $instanceConfigService = $this->createMock(InstanceConfigService::class);
        $instanceConfigService->method('getInstanceId')->willReturn('a1b2c3d4-e5f6-4789-a0b1-c2d3e4f5a6b7');

        $result = (new HealthCheckService($instanceConfigService))->check();

        $this->assertSame('a1b2c3d4-e5f6-4789-a0b1-c2d3e4f5a6b7', $result->instanceId);
    }

    public function test_check_reports_a_null_instance_id_when_not_yet_configured(): void
    {
        $instanceConfigService = $this->createMock(InstanceConfigService::class);
        $instanceConfigService->method('getInstanceId')->willReturn(null);

        $result = (new HealthCheckService($instanceConfigService))->check();

        $this->assertNull($result->instanceId);
    }
}
