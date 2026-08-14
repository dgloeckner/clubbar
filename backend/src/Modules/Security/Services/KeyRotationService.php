<?php

declare(strict_types=1);

namespace App\Modules\Security\Services;

use App\Modules\Security\DTOs\EncryptionKeyDto;
use App\Modules\Security\DTOs\KeyRotationBatchDto;
use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Modules\Security\Repositories\SealedIbanRepository;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Security\IbanSealedBox;
use App\Shared\Services\AuditService;

/**
 * Re-sealing every stored IBAN from one key generation to the next
 * (ADR-0036, #394).
 *
 * The sequence is: register the new public key (PENDING) → activate it (NEW
 * ACTIVE, OLD RETIRING — {@see EncryptionKeyService::activate()}, which is
 * where new writes start using the new key) → walk the backlog in batches here
 * → complete once nothing is left on the old key (OLD RETIRED).
 *
 * Three properties this class exists to guarantee:
 *
 * - **Resumable.** A batch is selected by "still on the old key", never by
 *   offset, and every row it moves is committed as it goes. Closing the browser
 *   mid-rotation loses nothing but the key in that tab; the next batch request
 *   picks up exactly where the last one stopped.
 * - **Concurrency-safe.** Each row moves with an optimistic
 *   `WHERE encryption_key_id = :old`, so a member edit that lands mid-rotation
 *   wins: it sealed the row under the ACTIVE key itself, and this rotation
 *   leaves that newer ciphertext alone rather than restoring what it read.
 * - **Keyless between batches.** The old private key arrives per batch request
 *   and is wiped when that request ends. It is never cached, never put in the
 *   session, and never written anywhere — the rotation holds no long-lived
 *   decryption capability, exactly like the export.
 */
class KeyRotationService
{
    /** Rows re-sealed per request — small enough to finish inside a shared-hosting timeout. */
    public const BATCH_SIZE = 100;

    /**
     * Keys whose rows may still be re-encrypted. RETIRING is the ordinary
     * rotation; COMPROMISED and REVOKED are the incident path, where the
     * backlog is drained with the same machinery but the key never becomes a
     * merely "retired" one.
     */
    private const ROTATABLE_STATUSES = ['retiring', 'compromised', 'revoked'];

    public function __construct(
        private EncryptionKeysRepository $keys,
        private SealedIbanRepository $sealedIbans,
        private EncryptionKeyService $encryptionKeys,
        private IbanSealedBox $sealedBox,
        private AuditService $auditService,
    ) {}

    /**
     * Re-seal up to {@see BATCH_SIZE} rows from $sourceKeyId onto the ACTIVE key.
     *
     * @param string $privateKeyBase64 the private half of the SOURCE key — the
     *        only thing that can open what is being rotated away from
     *
     * @throws \InvalidArgumentException unknown key, or a private key that does
     *         not belong to the source key
     * @throws \RuntimeException when the source key is not in a state that can
     *         be rotated away from (e.g. it is still the ACTIVE one)
     * @throws EncryptionNotConfiguredException when no key was ever activated
     * @throws EncryptionKeyExpiredException when the ACTIVE key's cryptoperiod
     *         is over — sealing under an expired key is exactly what rotation
     *         is supposed to end
     */
    public function rotateBatch(string $sourceKeyId, string $privateKeyBase64, ?string $adminId): KeyRotationBatchDto
    {
        $source = $this->requireRotatableKey($sourceKeyId);
        $target = $this->keys->requireOperationalActive();

        if ($target['id'] === $source['id']) {
            throw new \RuntimeException('A key cannot be rotated onto itself.');
        }

        // The same check the export makes, pointed at the key being rotated
        // away from rather than the ACTIVE one: derive the public half of the
        // supplied secret and compare fingerprints, never trial decryption.
        $secretRaw = $this->encryptionKeys->validatePrivateKeyFor($source, $privateKeyBase64);

        $processed = 0;
        $skipped = 0;

        try {
            foreach ($this->sealedIbans->findBatchByKey($source['id'], self::BATCH_SIZE) as $row) {
                // An unopenable row means the stored key id and the ciphertext
                // disagree — a data-integrity fault, not something to skip
                // quietly: skipping would let the rotation "complete" while
                // leaving a row nobody can ever read again.
                $plaintext = $this->sealedBox->open($row['iban_ciphertext'], $secretRaw);

                try {
                    $moved = $this->sealedIbans->reseal(
                        $row['id'],
                        $source['id'],
                        $this->sealedBox->seal($plaintext, $target['public_key']),
                        $target['id'],
                    );
                } finally {
                    sodium_memzero($plaintext);
                }

                $moved ? $processed++ : $skipped++;
            }
        } finally {
            sodium_memzero($secretRaw);
        }

        $remaining = $this->sealedIbans->countByKey($source['id']);

        // Every step audited with its row count (ADR-0036): the audit trail is
        // how the club shows afterwards that a rotation covered everything.
        $this->auditService->log(
            action: AuditAction::KEY_ROTATION_BATCH_COMPLETED,
            entityType: EntityType::ENCRYPTION_KEY,
            entityId: $source['id'],
            oldValues: null,
            newValues: [
                'affected_record_count' => $processed,
                'skipped_record_count' => $skipped,
                'remaining_record_count' => $remaining,
                'target_key_id' => $target['id'],
            ],
            adminUserId: $adminId,
        );

        return new KeyRotationBatchDto(
            sourceKeyId: $source['id'],
            targetKeyId: $target['id'],
            processed: $processed,
            skipped: $skipped,
            remaining: $remaining,
        );
    }

    /**
     * Close the rotation: verify nothing is left on the old key, then retire it.
     *
     * The zero-row check is the point of this being a separate step. "The last
     * batch reported nothing left" is a claim about a moment that has passed;
     * this re-counts before declaring the old key done, so a row written under
     * it in the meantime blocks the transition instead of being stranded.
     *
     * @throws \RuntimeException when rows are still sealed under the key
     */
    public function completeRotation(string $sourceKeyId, ?string $adminId): EncryptionKeyDto
    {
        $source = $this->requireRotatableKey($sourceKeyId);

        $remaining = $this->sealedIbans->countByKey($source['id']);
        if ($remaining > 0) {
            throw new \RuntimeException(sprintf(
                'Cannot retire this key: %d mandate(s) are still sealed under it. Re-encrypt them first.',
                $remaining,
            ));
        }

        // A compromised or revoked key keeps saying so. Only the orderly
        // RETIRING → RETIRED transition happens here; an incident is not
        // resolved by finishing the clean-up it caused.
        $status = $source['status'] === 'retiring' ? 'retired' : $source['status'];
        $this->keys->updateStatus($source['id'], $status, (new \DateTimeImmutable())->format('Y-m-d H:i:s'));

        $this->auditService->log(
            action: AuditAction::KEY_ROTATION_COMPLETED,
            entityType: EntityType::ENCRYPTION_KEY,
            entityId: $source['id'],
            oldValues: ['status' => $source['status']],
            newValues: ['status' => $status, 'affected_record_count' => 0],
            adminUserId: $adminId,
        );

        if ($status === 'retired') {
            $this->auditService->log(
                action: AuditAction::KEY_RETIRED,
                entityType: EntityType::ENCRYPTION_KEY,
                entityId: $source['id'],
                oldValues: ['status' => $source['status']],
                newValues: ['status' => $status],
                adminUserId: $adminId,
            );
        }

        return EncryptionKeyDto::fromRow(
            $this->keys->findById($source['id']),
            sealedRecordCount: 0,
        );
    }

    /** Rows still sealed under a key — the rotation progress the admin page shows. */
    public function outstandingRecordCount(string $keyId): int
    {
        return $this->sealedIbans->countByKey($keyId);
    }

    private function requireRotatableKey(string $id): array
    {
        $key = $this->keys->findById($id);

        if ($key === null) {
            throw new \InvalidArgumentException('Unknown encryption key.');
        }

        if (!in_array($key['status'], self::ROTATABLE_STATUSES, true)) {
            throw new \RuntimeException(sprintf(
                'Only a retiring, revoked or compromised key can be rotated away from; this one is %s. '
                . 'Activate the replacement key first — that is what starts the rotation.',
                $key['status'],
            ));
        }

        return $key;
    }
}
