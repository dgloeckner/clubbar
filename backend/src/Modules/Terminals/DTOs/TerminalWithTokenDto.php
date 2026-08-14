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
        public ?string $tokenIssuedAt,
        public ?string $tokenExpiresAt,
        public ?string $pendingTokenExpiresAt,
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
            tokenIssuedAt: $row['token_issued_at'] ?? null,
            tokenExpiresAt: $row['token_expires_at'] ?? null,
            pendingTokenExpiresAt: $row['pending_token_expires_at'] ?? null,
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
            'last_sync_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->lastSyncAt),
            // The moment this freshly issued token stops working (#106) — the
            // one response where the operator can still write it down with it.
            'token_issued_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->tokenIssuedAt),
            'token_expires_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->tokenExpiresAt),
            // On a rotation this — not `token_expires_at` — is the lifetime the
            // token printed below carries, because the token below is the
            // pending one and the active columns still describe the credential
            // it will replace (#395).
            'pending_token_expires_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->pendingTokenExpiresAt),
            'has_pending_token' => $this->pendingTokenExpiresAt !== null,
            'created_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->createdAt),
            'updated_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->updatedAt),
            'api_token' => $this->apiToken,
        ];
    }
}
