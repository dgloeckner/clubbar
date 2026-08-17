<?php

declare(strict_types=1);

namespace App\Modules\Security\Services;

use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Services\AdminNotifier;
use App\Modules\Security\DTOs\EncryptionKeyDto;
use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Modules\Security\Repositories\SealedIbanRepository;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Logging\Logger;
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
        private SealedIbanRepository $sealedIbans,
        private IbanSealedBox $sealedBox,
        private AuditService $auditService,
        private AdminNotifier $adminNotifier,
        private Logger $logger,
    ) {}

    /**
     * Tell every admin that a key moved through its lifecycle (ADR-0036).
     *
     * Queued beside the audit entry rather than instead of it, because the two
     * reach different people: the audit log is read by whoever goes looking,
     * and this is for the admin who never does. It is the only signal a key
     * swap produces that leaves the panel — the dashboard banner speaks only
     * for a key that is missing or expiring, so one activated today with 365
     * days on it is silent by construction.
     *
     * Best effort, and never a gate. It queues; it does not send (ADR-0038
     * rule 3), an install with no mail configured discards it at the transport,
     * and the lifecycle change it describes has already been committed. A
     * failure here must not roll back a key operation that succeeded, so it is
     * caught and logged rather than thrown.
     *
     * The occasion is the event name: a key is registered once, activated once
     * (only a PENDING key can be activated) and revoked once, so the kind and
     * the key id already identify the occurrence. No tier, no generation.
     */
    private function announce(MailKind $kind, string $keyId, ?string $adminId): void
    {
        try {
            $this->adminNotifier->warnAdmins($kind, $keyId, self::occasionFor($kind), $adminId);
        } catch (\Throwable $e) {
            $this->logger->error('Encryption key lifecycle notice could not be queued', [
                'kind' => $kind->value,
                'key_id' => $keyId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function occasionFor(MailKind $kind): string
    {
        return match ($kind) {
            MailKind::ENCRYPTION_KEY_REGISTERED => 'registered',
            MailKind::ENCRYPTION_KEY_ACTIVATED => 'activated',
            MailKind::ENCRYPTION_KEY_REVOKED => 'revoked',
            default => throw new \InvalidArgumentException(
                sprintf('%s is not a key lifecycle event', $kind->value)
            ),
        };
    }

    /**
     * Every key with its rotation backlog attached (#394).
     *
     * The row count is what turns the listing into a rotation status board: a
     * RETIRING key with rows left still needs batches, and one with zero rows
     * is ready to retire. One grouped count covers the whole list.
     *
     * @return EncryptionKeyDto[]
     */
    public function listKeys(): array
    {
        $counts = $this->sealedIbans->countsByKey();

        return array_map(
            fn(array $row) => EncryptionKeyDto::fromRow($row, sealedRecordCount: $counts[$row['id']] ?? 0),
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

        $this->announce(MailKind::ENCRYPTION_KEY_REGISTERED, $row['id'], $adminId);

        return EncryptionKeyDto::fromRow($row);
    }

    /**
     * Activate a pending key. Starts its 365-day cryptoperiod; a previously
     * active key moves to RETIRING, which is the opening move of a rotation
     * (the batch re-encryption is P5's KeyRotationService).
     *
     * Activation demands the private half of the key being activated. Nothing
     * at registration proves a public key has a counterpart at all — 32 bytes
     * out of a mistyped clipboard register exactly like a generated key — and
     * activation is the moment that stops being harmless: from here every IBAN
     * is sealed under this key, and if the club cannot open it nobody can. The
     * proof is a public-key derivation, never a trial decryption, and the
     * secret is wiped as soon as it has been compared.
     *
     * @throws \InvalidArgumentException when no key has that id
     * @throws PrivateKeyMismatchException when the supplied key belongs to
     *         another keypair, or is not a key at all
     */
    public function activate(string $id, string $privateKeyBase64, ?string $adminId): EncryptionKeyDto
    {
        $key = $this->repository->findById($id);
        if ($key === null) {
            throw new \InvalidArgumentException('Unknown encryption key.');
        }

        $secretRaw = $this->validatePrivateKeyFor($key, $privateKeyBase64);
        sodium_memzero($secretRaw);

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

        $this->announce(MailKind::ENCRYPTION_KEY_ACTIVATED, $id, $adminId);

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

        $this->announce(MailKind::ENCRYPTION_KEY_REVOKED, $id, $adminId);

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
            throw new PrivateKeyMismatchException('The private key must be 32 raw bytes, base64-encoded.');
        }

        $derivedPublic = $this->sealedBox->publicKeyFromSecret($secretRaw);

        if (!hash_equals($keyRow['fingerprint_sha256'], hash('sha256', $derivedPublic))) {
            sodium_memzero($secretRaw);
            throw new PrivateKeyMismatchException(
                sprintf('The supplied private key does not match key "%s".', $keyRow['key_identifier'])
            );
        }

        return $secretRaw;
    }
}
