<?php

declare(strict_types=1);

namespace App\Modules\Security\Services;

use App\Modules\Security\DTOs\EncryptionKeyDto;
use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Security\CredentialLifecycle;
use App\Shared\Security\IbanSealedBox;
use App\Shared\Services\AuditService;

/**
 * Lifecycle of the IBAN encryption keypairs (ADR-0036).
 *
 * The database holds public keys and metadata only. Registering a key means
 * the admin generated a keypair offline (tools/keypair-generator.html),
 * archived the private half, and pasted the public half here. Activation is
 * what starts the 365-day cryptoperiod and — when another key was active —
 * what begins a rotation.
 */
class EncryptionKeyService
{
    public function __construct(
        private EncryptionKeysRepository $repository,
        private IbanSealedBox $sealedBox,
        private AuditService $auditService,
    ) {}

    /** @return EncryptionKeyDto[] */
    public function listKeys(): array
    {
        return array_map(
            fn(array $row) => EncryptionKeyDto::fromRow($row),
            $this->repository->findAll(),
        );
    }

    public function register(string $publicKeyBase64, string $keyIdentifier, ?string $adminId): EncryptionKeyDto
    {
        $publicKeyRaw = base64_decode($publicKeyBase64, true);
        if ($publicKeyRaw === false || strlen($publicKeyRaw) !== 32) {
            throw new \InvalidArgumentException('The public key must be 32 raw bytes, base64-encoded.');
        }

        $this->sealedBox->rejectPublishedPublicKey($publicKeyRaw);

        $fingerprint = hash('sha256', $publicKeyRaw);
        if ($this->repository->findByFingerprint($fingerprint) !== null) {
            throw new \InvalidArgumentException('A key with this fingerprint is already registered.');
        }

        $row = $this->repository->create([
            'key_identifier' => $keyIdentifier,
            'public_key' => $publicKeyRaw,
            'fingerprint_sha256' => $fingerprint,
            'status' => 'pending',
            'created_by_admin_id' => $adminId,
        ]);

        $this->auditService->log(
            action: AuditAction::KEY_REGISTERED,
            entityType: EntityType::ENCRYPTION_KEY,
            entityId: $row['id'],
            oldValues: null,
            newValues: ['key_identifier' => $keyIdentifier, 'fingerprint_sha256' => $fingerprint],
            adminUserId: $adminId,
        );

        return EncryptionKeyDto::fromRow($row);
    }

    /**
     * Activate a pending key. Starts its 365-day cryptoperiod; a previously
     * active key moves to RETIRING, which is the opening move of a rotation
     * (the batch re-encryption is P5's KeyRotationService).
     */
    public function activate(string $id, ?string $adminId): EncryptionKeyDto
    {
        $key = $this->repository->findById($id);
        if ($key === null) {
            throw new \InvalidArgumentException('Unknown encryption key.');
        }

        $now = new \DateTimeImmutable();
        $previous = $this->repository->activateExclusive(
            $id,
            $now->format('Y-m-d H:i:s'),
            CredentialLifecycle::expiryFromActivation($now),
        );

        $this->auditService->log(
            action: AuditAction::KEY_ACTIVATED,
            entityType: EntityType::ENCRYPTION_KEY,
            entityId: $id,
            oldValues: null,
            newValues: ['key_identifier' => $key['key_identifier']],
            adminUserId: $adminId,
        );

        if ($previous !== null) {
            $this->auditService->log(
                action: AuditAction::KEY_ROTATION_STARTED,
                entityType: EntityType::ENCRYPTION_KEY,
                entityId: $previous['id'],
                oldValues: ['status' => 'active'],
                newValues: ['status' => 'retiring', 'successor_key_id' => $id],
                adminUserId: $adminId,
            );
        }

        return EncryptionKeyDto::fromRow($this->repository->findById($id));
    }

    /** Revoke (or mark compromised) — immediate, regardless of remaining lifetime. */
    public function revoke(string $id, bool $compromised, ?string $adminId): EncryptionKeyDto
    {
        $key = $this->repository->findById($id);
        if ($key === null) {
            throw new \InvalidArgumentException('Unknown encryption key.');
        }

        $status = $compromised ? 'compromised' : 'revoked';
        $this->repository->updateStatus($id, $status, (new \DateTimeImmutable())->format('Y-m-d H:i:s'));

        $this->auditService->log(
            action: $compromised ? AuditAction::KEY_MARKED_COMPROMISED : AuditAction::KEY_REVOKED,
            entityType: EntityType::ENCRYPTION_KEY,
            entityId: $id,
            oldValues: ['status' => $key['status']],
            newValues: ['status' => $status],
            adminUserId: $adminId,
        );

        return EncryptionKeyDto::fromRow($this->repository->findById($id));
    }

    public function getActiveKey(): ?array
    {
        return $this->repository->findActive();
    }

    /**
     * The ACTIVE key, verified operational (see
     * {@see EncryptionKeysRepository::requireOperationalActive()}).
     *
     * @throws EncryptionNotConfiguredException when no key was ever activated
     * @throws EncryptionKeyExpiredException when the active key's cryptoperiod is over
     */
    public function requireOperationalActiveKey(): array
    {
        return $this->repository->requireOperationalActive();
    }

    /**
     * Run $work with an opener for IBANs sealed under the ACTIVE key.
     *
     * This is the whole of the private key's life on the server (ADR-0036): it
     * arrives in one request body, is checked against the registered public
     * half, is held in a local for the length of the callback, and is wiped in
     * `finally` — so an exception thrown mid-export cannot leave key material
     * behind in a variable that outlives the request.
     *
     * The opener is handed to the caller rather than a list of plaintexts on
     * purpose: SEPA export invokes it per member, right where the IBAN goes
     * into the XML, so no plaintext collection is ever assembled.
     *
     * @template T
     * @param callable(callable(string): string): T $work
     * @return T
     *
     * @throws EncryptionNotConfiguredException when no key was ever activated
     * @throws EncryptionKeyExpiredException when the cryptoperiod is over — the
     *         export is refused until the admin rotates, while key management
     *         itself stays reachable so that rotation is always possible
     * @throws \InvalidArgumentException when the key does not match the ACTIVE one
     */
    public function withActivePrivateKey(string $privateKeyBase64, callable $work): mixed
    {
        $keyRow = $this->requireOperationalActiveKey();
        $secretRaw = $this->validatePrivateKeyFor($keyRow, $privateKeyBase64);

        try {
            return $work(fn(string $ciphertext): string => $this->sealedBox->open($ciphertext, $secretRaw));
        } finally {
            sodium_memzero($secretRaw);
        }
    }

    /**
     * Validate a temporarily supplied private key against a registered key row
     * (by deriving its public half — never by trial decryption) and return the
     * raw 32-byte secret. Throws on any mismatch; the caller decides which row
     * (ACTIVE for exports, RETIRING for rotation) the key must belong to.
     */
    public function validatePrivateKeyFor(array $keyRow, string $privateKeyBase64): string
    {
        $secretRaw = base64_decode(trim($privateKeyBase64), true);
        if ($secretRaw === false || strlen($secretRaw) !== 32) {
            throw new \InvalidArgumentException('The private key must be 32 raw bytes, base64-encoded.');
        }

        $derivedPublic = $this->sealedBox->publicKeyFromSecret($secretRaw);

        if (!hash_equals($keyRow['fingerprint_sha256'], hash('sha256', $derivedPublic))) {
            sodium_memzero($secretRaw);
            throw new \InvalidArgumentException(
                sprintf('The supplied private key does not match key "%s".', $keyRow['key_identifier'])
            );
        }

        return $secretRaw;
    }
}
