<?php

declare(strict_types=1);

namespace App\Modules\Terminals\DTOs;

use App\Shared\Security\CredentialLifecycle;

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
        public ?string $pendingTokenExpiresAt,
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
            pendingTokenExpiresAt: $row['pending_token_expires_at'] ?? null,
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
            // Every authenticated terminal request refreshes this, so it is
            // also the credential's last-used stamp — what the Security &
            // Credentials page shows to answer "is this token still in use, or
            // is it a device somebody took home?" (#395).
            'last_sync_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->lastSyncAt),
            'last_transaction_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->lastTransactionAt),
            // Exposed so an admin can rotate a terminal before it locks itself
            // out, rather than after (#106).
            'token_issued_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->tokenIssuedAt),
            'token_expires_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->tokenExpiresAt),
            // A rotation that has been prepared but not yet entered at the
            // device (#395). While this is set two tokens authenticate, and the
            // admin's remaining job is to walk over and type one in — which is
            // exactly what the UI needs to be able to say.
            'pending_token_expires_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->pendingTokenExpiresAt),
            'has_pending_token' => $this->pendingTokenExpiresAt !== null,
            // Request-time, on the shared 90/30/7 tiers (ADR-0036): there is no
            // cron on shared hosting (ADR-0031), and a warning computed when it
            // is read cannot go stale.
            'lifecycle_state' => CredentialLifecycle::state($this->tokenExpiresAt),
            'days_until_expiry' => CredentialLifecycle::daysUntilExpiry($this->tokenExpiresAt),
            'created_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->createdAt),
            'updated_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->updatedAt),
        ];
    }
}
