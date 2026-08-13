<?php

declare(strict_types=1);

namespace App\Modules\Security\Repositories;

use App\Modules\Security\Services\EncryptionKeyExpiredException;
use App\Modules\Security\Services\EncryptionNotConfiguredException;
use App\Shared\Logging\Logger;
use App\Shared\Security\CredentialLifecycle;
use App\Shared\Utils\Uuid;
use PDO;

class EncryptionKeysRepository
{
    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    public function create(array $data): array
    {
        $id = Uuid::v4();

        $stmt = $this->db->prepare(
            'INSERT INTO encryption_keys (id, key_identifier, algorithm, public_key, fingerprint_sha256, status, created_by_admin_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $data['key_identifier'],
            $data['algorithm'] ?? 'SODIUM_CRYPTO_BOX_SEAL',
            $data['public_key'],
            $data['fingerprint_sha256'],
            $data['status'] ?? 'pending',
            $data['created_by_admin_id'] ?? null,
        ]);

        return $this->findById($id);
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM encryption_keys WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findByFingerprint(string $fingerprint): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM encryption_keys WHERE fingerprint_sha256 = ?');
        $stmt->execute([$fingerprint]);
        return $stmt->fetch() ?: null;
    }

    /** All keys, newest first — the Security & Credentials listing. */
    public function findAll(): array
    {
        return $this->db
            ->query('SELECT * FROM encryption_keys ORDER BY created_at DESC, key_identifier DESC')
            ->fetchAll();
    }

    /**
     * The ACTIVE key.
     *
     * Ordered and limited rather than "the row that comes back", because the
     * one-ACTIVE invariant lives in {@see activateExclusive()} and not in the
     * schema — MariaDB has no partial unique index. Anything that writes the
     * table without going through this class (a re-applied `seed.sql`, a
     * hand-run UPDATE during an incident) can leave two rows ACTIVE, and an
     * unordered query would then hand different callers different keys within
     * one request: the write path would seal under one and the export validate
     * against the other. Newest activation wins; `id` is the final tiebreaker
     * because `activated_at` is a DATETIME and two activations inside the same
     * second are otherwise indistinguishable. Which key an arbitrary tie
     * resolves to matters less than that every caller resolves it the same way.
     */
    public function findActive(): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM encryption_keys WHERE status = 'active' ORDER BY activated_at DESC, created_at DESC, id DESC LIMIT 1"
        );
        $stmt->execute();
        return $stmt->fetch() ?: null;
    }

    /**
     * The ACTIVE key, verified operational — the gate in front of sealing any
     * IBAN and of the SEPA export. Expiry is a hard stop (ADR-0036 strict
     * policy): the cryptoperiod must be a real security boundary, not only an
     * export restriction. Key management itself stays reachable so the admin
     * can always rotate out of this state.
     */
    public function requireOperationalActive(): array
    {
        $key = $this->findActive();

        if ($key === null) {
            throw new EncryptionNotConfiguredException(
                'No active IBAN encryption key is registered. Register and activate a key under Settings → Security & Credentials before storing IBANs.'
            );
        }

        if (CredentialLifecycle::isExpired($key['expires_at'] ?? null)) {
            throw new EncryptionKeyExpiredException(
                'The active IBAN encryption key has expired. Rotate the encryption key before storing IBANs or creating another SEPA export.'
            );
        }

        return $key;
    }

    public function findByStatus(string $status): array
    {
        $stmt = $this->db->prepare('SELECT * FROM encryption_keys WHERE status = ?');
        $stmt->execute([$status]);
        return $stmt->fetchAll();
    }

    /**
     * Promote a pending key to ACTIVE and move the current ACTIVE key (if any)
     * to RETIRING, in one transaction — the "exactly one operational ACTIVE
     * key" invariant lives here. Returns the previously active row or null.
     */
    public function activateExclusive(string $id, string $activatedAt, string $expiresAt): ?array
    {
        $this->db->beginTransaction();
        try {
            $previous = $this->findActive();

            if ($previous !== null) {
                $stmt = $this->db->prepare("UPDATE encryption_keys SET status = 'retiring' WHERE id = ? AND status = 'active'");
                $stmt->execute([$previous['id']]);
            }

            $stmt = $this->db->prepare(
                "UPDATE encryption_keys SET status = 'active', activated_at = ?, expires_at = ? WHERE id = ? AND status = 'pending'"
            );
            $stmt->execute([$activatedAt, $expiresAt, $id]);

            if ($stmt->rowCount() !== 1) {
                $this->db->rollBack();
                throw new \RuntimeException('Key is not in pending state; only a pending key can be activated.');
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $this->logger->info('Encryption key activated', ['key_id' => $id]);

        return $previous;
    }

    public function updateStatus(string $id, string $status, ?string $retiredAt = null): void
    {
        if ($retiredAt !== null) {
            $stmt = $this->db->prepare('UPDATE encryption_keys SET status = ?, retired_at = ? WHERE id = ?');
            $stmt->execute([$status, $retiredAt, $id]);
            return;
        }

        $stmt = $this->db->prepare('UPDATE encryption_keys SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
    }
}
