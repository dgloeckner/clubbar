<?php

namespace App\Services;

use App\DTOs\HealthResponseDto;

/**
 * HealthCheckService
 *
 * Provides health check functionality for the application.
 * Returns system status and timestamp for monitoring and connectivity verification.
 */
final readonly class HealthCheckService
{
    /**
     * Perform a health check and return status
     *
     * @return HealthResponseDto
     */
    public function check(): HealthResponseDto
    {
        return new HealthResponseDto(
            status: 'ok',
            timestamp: new \DateTimeImmutable(),
        );
    }
}
