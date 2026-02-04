<?php

declare(strict_types=1);

namespace App\Modules\Terminals\DTOs;

final readonly class TerminalWithTokenDto
{
    public function __construct(
        public string $id,
        public string $name,
        public string $deviceId,
        public bool $isActive,
        public ?string $lastSyncAt,
        public string $createdAt,
        public string $updatedAt,
        public string $apiToken,
    ) {}

    public static function fromRowWithToken(array $row, string $plaintextToken): self
    {
        return new self(
            id: $row['id'],
            name: $row['name'],
            deviceId: $row['device_id'],
            isActive: (bool) $row['is_active'],
            lastSyncAt: $row['last_sync_at'] ?? null,
            createdAt: $row['created_at'],
            updatedAt: $row['updated_at'],
            apiToken: $plaintextToken,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'device_id' => $this->deviceId,
            'is_active' => $this->isActive,
            'last_sync_at' => $this->lastSyncAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'api_token' => $this->apiToken,
        ];
    }
}
