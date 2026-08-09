<?php

declare(strict_types=1);

namespace App\Modules\Terminals\DTOs;

final readonly class TerminalDto
{
    public function __construct(
        public string $id,
        public string $name,
        public string $deviceId,
        public bool $isActive,
        public ?string $lastSyncAt,
        public ?string $lastTransactionAt,
        public ?string $tokenIssuedAt,
        public ?string $tokenExpiresAt,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            name: $row['name'],
            deviceId: $row['device_id'],
            isActive: (bool) $row['is_active'],
            lastSyncAt: $row['last_sync_at'] ?? null,
            lastTransactionAt: $row['last_transaction_at'] ?? null,
            tokenIssuedAt: $row['token_issued_at'] ?? null,
            tokenExpiresAt: $row['token_expires_at'] ?? null,
            createdAt: $row['created_at'],
            updatedAt: $row['updated_at'],
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'device_id' => $this->deviceId,
            'is_active' => $this->isActive,
            'last_sync_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->lastSyncAt),
            'last_transaction_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->lastTransactionAt),
            // Exposed so an admin can rotate a terminal before it locks itself
            // out, rather than after (#106).
            'token_issued_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->tokenIssuedAt),
            'token_expires_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->tokenExpiresAt),
            'created_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->createdAt),
            'updated_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->updatedAt),
        ];
    }
}
