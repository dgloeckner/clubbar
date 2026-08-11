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
}
