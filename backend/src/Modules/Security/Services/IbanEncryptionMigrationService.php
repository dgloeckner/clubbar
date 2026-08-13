<?php

declare(strict_types=1);

namespace App\Modules\Security\Services;

use App\Modules\BankCodes\Services\BankCodeService;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Security\IbanSealedBox;
use App\Shared\Services\AuditService;
use PDO;

/**
 * One-time sealing of legacy plaintext IBANs (ADR-0036).
 *
 * Runs as an admin-triggered action in resumable batches, not as a deploy
 * migration: sealing needs the ACTIVE public key, which exists only after the
 * admin has registered one — a step no unattended upgrade can perform. Each
 * row is sealed with the ACTIVE key, gets its last4/fingerprint/bank_name
 * computed from the plaintext while it is still readable, and then has the
 * plaintext NULLed in the same optimistic UPDATE. Idempotent by construction:
 * the WHERE clause only ever matches rows still carrying plaintext.
 */
class IbanEncryptionMigrationService
{
    public const BATCH_SIZE = 100;

    public function __construct(
        private PDO $db,
        private IbanSealedBox $sealedBox,
        private EncryptionKeyService $encryptionKeyService,
        private AuditService $auditService,
        private ?BankCodeService $bankCodeService = null,
    ) {}

    /** Rows still carrying a plaintext IBAN — the security self-check number. */
    public function countRemaining(): int
    {
        return (int) $this->db
            ->query('SELECT COUNT(*) FROM mandates WHERE iban IS NOT NULL')
            ->fetchColumn();
    }

    /**
     * Seal up to BATCH_SIZE legacy rows. Returns progress the UI can loop on.
     *
     * @return array{processed: int, remaining: int}
     */
    public function encryptBatch(?string $adminId = null): array
    {
        $key = $this->encryptionKeyService->requireOperationalActiveKey();

        $stmt = $this->db->prepare(
            'SELECT id, iban FROM mandates WHERE iban IS NOT NULL ORDER BY created_at ASC LIMIT ' . self::BATCH_SIZE
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $update = $this->db->prepare(
            'UPDATE mandates
             SET iban_ciphertext = ?, iban_last4 = ?, iban_fingerprint = ?, encryption_key_id = ?, bank_name = ?, iban = NULL
             WHERE id = ? AND iban = ?'
        );

        $processed = 0;
        foreach ($rows as $row) {
            // Optimistic on the plaintext value: a concurrent member edit that
            // already sealed (or changed) this row makes the UPDATE a no-op
            // instead of overwriting the newer state.
            $update->execute([
                $this->sealedBox->seal($row['iban'], $key['public_key']),
                IbanSealedBox::lastFour($row['iban']),
                $this->sealedBox->fingerprint($row['iban']),
                $key['id'],
                $this->bankCodeService?->getBankNameForIban($row['iban']),
                $row['id'],
                $row['iban'],
            ]);
            $processed += $update->rowCount();
        }

        $remaining = $this->countRemaining();

        if ($processed > 0) {
            $this->auditService->log(
                action: AuditAction::KEY_ROTATION_BATCH_COMPLETED,
                entityType: EntityType::ENCRYPTION_KEY,
                entityId: $key['id'],
                oldValues: null,
                newValues: ['kind' => 'plaintext_backfill', 'affected_record_count' => $processed, 'remaining' => $remaining],
                adminUserId: $adminId,
            );
        }

        return ['processed' => $processed, 'remaining' => $remaining];
    }
}
