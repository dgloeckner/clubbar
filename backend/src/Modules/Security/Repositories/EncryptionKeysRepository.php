<?php

declare(strict_types=1);

namespace App\Modules\Security\Repositories;

use App\Shared\Logging\Logger;
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

    public function findActive(): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM encryption_keys WHERE status = 'active'");
        $stmt->execute();
        return $stmt->fetch() ?: null;
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
