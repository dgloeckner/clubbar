<?php

declare(strict_types=1);

namespace App\Modules\Security\DTOs;

use App\Shared\Security\CredentialLifecycle;
use App\Shared\Utils\DateFormatter;

class EncryptionKeyDto
{
    public function __construct(
        public readonly string $id,
        public readonly string $keyIdentifier,
        public readonly string $algorithm,
        public readonly string $publicKeyBase64,
        public readonly string $fingerprintSha256,
        public readonly string $status,
        public readonly string $createdAt,
        public readonly ?string $activatedAt,
        public readonly ?string $expiresAt,
        public readonly ?string $retiredAt,
        public readonly ?int $daysUntilExpiry,
        public readonly string $lifecycleState,
        /**
         * Mandate rows still sealed under this key — the rotation backlog
         * (#394). Null when the caller did not ask for it; the listing does,
         * single-key responses do not.
         */
        public readonly ?int $sealedRecordCount = null,
    ) {}

    public static function fromRow(array $row, ?\DateTimeImmutable $now = null, ?int $sealedRecordCount = null): self
    {
        $expiresAt = $row['expires_at'] ?? null;
        $status = $row['status'];

        // Lifecycle warnings only make sense for keys that are still in use;
        // a retired or revoked key showing "EXPIRED" would read as a problem
        // when it is the resolved end state.
        $operational = in_array($status, ['active', 'retiring'], true);

        return new self(
            id: $row['id'],
            keyIdentifier: $row['key_identifier'],
            algorithm: $row['algorithm'],
            publicKeyBase64: base64_encode($row['public_key']),
            fingerprintSha256: $row['fingerprint_sha256'],
            status: $status,
            createdAt: $row['created_at'],
            activatedAt: $row['activated_at'] ?? null,
            expiresAt: $expiresAt,
            retiredAt: $row['retired_at'] ?? null,
            daysUntilExpiry: $operational ? CredentialLifecycle::daysUntilExpiry($expiresAt, $now) : null,
            lifecycleState: $operational ? CredentialLifecycle::state($expiresAt, $now) : CredentialLifecycle::STATE_OK,
            sealedRecordCount: $sealedRecordCount,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'key_identifier' => $this->keyIdentifier,
            'algorithm' => $this->algorithm,
            'public_key' => $this->publicKeyBase64,
            'fingerprint_sha256' => $this->fingerprintSha256,
            'status' => $this->status,
            // Labelled UTC like every other timestamp the API emits (#365). A
            // bare "2026-08-12 16:46:00" is parsed by the browser as *local*
            // time, which shifts the rendered value by the reader's own offset.
            'created_at' => DateFormatter::toUtcIso($this->createdAt),
            'activated_at' => DateFormatter::toUtcIso($this->activatedAt),
            'expires_at' => DateFormatter::toUtcIso($this->expiresAt),
            'retired_at' => DateFormatter::toUtcIso($this->retiredAt),
            'days_until_expiry' => $this->daysUntilExpiry,
            'lifecycle_state' => $this->lifecycleState,
            'sealed_record_count' => $this->sealedRecordCount,
        ];
    }
}
