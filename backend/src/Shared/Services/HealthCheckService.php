<?php

declare(strict_types=1);

namespace App\Shared\Services;

use App\Modules\Instance\Services\InstanceConfigService;
use App\Shared\DTOs\HealthResponseDto;

class HealthCheckService
{
    public function __construct(
        private readonly InstanceConfigService $instanceConfigService,
    ) {}

    public function check(): HealthResponseDto
    {
        return new HealthResponseDto(
            status: 'ok',
            timestamp: (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'),
            version: $this->getVersion(),
            instanceName: $this->instanceConfigService->getInstanceName(),
        );
    }

    private function getVersion(): string
    {
        $versionFile = dirname(__DIR__, 3) . '/VERSION';
        if (file_exists($versionFile)) {
            return trim(file_get_contents($versionFile));
        }
        return 'dev';
    }
}
