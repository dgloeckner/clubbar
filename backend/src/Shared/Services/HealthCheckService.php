<?php

declare(strict_types=1);

namespace App\Shared\Services;

use App\Modules\Instance\Services\InstanceConfigService;
use App\Shared\DTOs\HealthResponseDto;
use App\Shared\Version\AppVersion;

class HealthCheckService
{
    public function __construct(
        private readonly InstanceConfigService $instanceConfigService,
        // ADR-0054 made this string the target every terminal installs, so it
        // is read through one class rather than inline here.
        private readonly AppVersion $appVersion = new AppVersion(),
    ) {}

    public function check(): HealthResponseDto
    {
        return new HealthResponseDto(
            status: 'ok',
            timestamp: (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'),
            version: $this->appVersion->current(),
            instanceName: $this->instanceConfigService->getInstanceName(),
            instanceId: $this->instanceConfigService->getInstanceId(),
        );
    }
}
