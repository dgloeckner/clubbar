<?php

declare(strict_types=1);

namespace App\Shared\Services;

use App\Shared\DTOs\HealthResponseDto;

class HealthCheckService
{
    public function check(): HealthResponseDto
    {
        return new HealthResponseDto(
            status: 'ok',
            timestamp: (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
