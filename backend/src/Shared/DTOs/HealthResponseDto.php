<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

final readonly class HealthResponseDto
{
    public function __construct(
        public string $status,
        public string $timestamp,
    ) {}

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'timestamp' => $this->timestamp,
        ];
    }
}
