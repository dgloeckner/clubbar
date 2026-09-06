<?php

declare(strict_types=1);

namespace App\Modules\Terminals\DTOs;

use App\Modules\Terminals\Enums\TerminalVersionState;
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
        /**
         * Unacknowledged anomalies for this terminal (ADR-0041). Defaulted so
         * every existing construction site keeps working — a list rendered
         * without the counts joined in simply reports none, rather than
         * claiming a terminal is fine when nobody asked.
         */
        public int $openAnomalyCount = 0,
        /**
         * What the terminal last said it was running, in `X-Terminal-Version`
         * (ADR-0054). Null until a build carrying the header has synced.
         */
        public ?string $reportedVersion = null,
        public ?string $reportedVersionAt = null,
        /** A tag whose update failed there and which its updater will never retry. */
        public ?string $blockedVersion = null,
        /**
         * This backend's own version, to measure the two above against. Null
         * where the caller had no reason to look it up, which reads as
         * {@see TerminalVersionState::UNKNOWN} rather than as agreement.
         */
        public ?string $backendVersion = null,
    ) {}

    public static function fromRow(array $row, ?string $backendVersion = null): self
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
            openAnomalyCount: (int) ($row['open_anomaly_count'] ?? 0),
            reportedVersion: $row['reported_version'] ?? null,
            reportedVersionAt: $row['reported_version_at'] ?? null,
            blockedVersion: $row['blocked_version'] ?? null,
            backendVersion: $backendVersion,
        );
    }

    public function versionState(): TerminalVersionState
    {
        return TerminalVersionState::classify(
            $this->reportedVersion,
            $this->blockedVersion,
            $this->backendVersion,
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
            // ADR-0041. Sits beside the lifecycle fields because it answers the
            // same question the credentials board exists for — is this token
            // healthy — from the other direction: not "is it about to expire"
            // but "is somebody else already using it".
            'open_anomaly_count' => $this->openAnomalyCount,
            'has_open_anomaly' => $this->openAnomalyCount > 0,
            // ADR-0054. `version_state` is the field the page renders; the raw
            // strings sit beside it because "behind" is only actionable once
            // you can see *what* it is behind at, and because a support
            // conversation asks for the tag, not for the classification.
            'reported_version' => $this->reportedVersion,
            'reported_version_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->reportedVersionAt),
            'blocked_version' => $this->blockedVersion,
            'backend_version' => $this->backendVersion,
            'version_state' => $this->versionState()->value,
            'created_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->createdAt),
            'updated_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->updatedAt),
        ];
    }
}
