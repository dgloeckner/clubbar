<?php

declare(strict_types=1);

namespace App\Modules\Security\DTOs;

use App\Shared\Security\CredentialLifecycle;

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
    ) {}

    public static function fromRow(array $row, ?\DateTimeImmutable $now = null): self
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
            'created_at' => $this->createdAt,
            'activated_at' => $this->activatedAt,
            'expires_at' => $this->expiresAt,
            'retired_at' => $this->retiredAt,
            'days_until_expiry' => $this->daysUntilExpiry,
            'lifecycle_state' => $this->lifecycleState,
        ];
    }
}
