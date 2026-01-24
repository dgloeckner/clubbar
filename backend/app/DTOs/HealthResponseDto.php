<?php

namespace App\DTOs;

use DateTimeImmutable;

/**
 * HealthResponseDto
 *
 * Immutable data transfer object for health check response.
 * Encapsulates server status and timestamp in a type-safe structure.
 */
final readonly class HealthResponseDto
{
    public function __construct(
        public string $status,
        public DateTimeImmutable $timestamp,
    ) {}

    /**
     * Convert DTO to array for JSON serialization
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'timestamp' => $this->timestamp->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
