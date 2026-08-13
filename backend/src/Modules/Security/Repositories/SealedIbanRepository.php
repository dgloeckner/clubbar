<?php

declare(strict_types=1);

namespace App\Modules\Security\Repositories;

use PDO;

/**
 * The sealed IBAN columns of `mandates`, seen as ciphertext rather than as
 * member data (ADR-0036).
 *
 * Rotation re-seals rows in bulk and has no business reading a member, so it
 * does not go through MembersRepository: everything here works on
 * `iban_ciphertext` and `encryption_key_id` and never touches a name, a
 * reference or a balance. Nothing in this class can produce a plaintext IBAN —
 * opening a ciphertext needs the private key, which lives with the caller for
 * the length of one request and never reaches a repository.
 */
class SealedIbanRepository
{
    public function __construct(private PDO $db) {}

    /** How many mandate rows are still sealed under a given key. */
    public function countByKey(string $keyId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM mandates WHERE encryption_key_id = ?');
        $stmt->execute([$keyId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Row counts per key, for the Security & Credentials listing.
     *
     * @return array<string, int> key id => rows sealed under it
     */
    public function countsByKey(): array
    {
        $rows = $this->db
            ->query('SELECT encryption_key_id, COUNT(*) AS row_count FROM mandates WHERE encryption_key_id IS NOT NULL GROUP BY encryption_key_id')
            ->fetchAll();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['encryption_key_id']] = (int) $row['row_count'];
        }

        return $counts;
    }

    /**
     * The next batch still sealed under $keyId.
     *
     * Ordered by id so a resumed rotation walks the same sequence, and paged by
     * "still on the old key" rather than by offset — a batch that succeeds
     * removes its rows from the result set, so the next call naturally picks up
     * where this one stopped even if the browser closed in between.
     *
     * @return array<int, array{id: string, iban_ciphertext: string}>
     */
    public function findBatchByKey(string $keyId, int $limit): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, iban_ciphertext FROM mandates WHERE encryption_key_id = ? ORDER BY id LIMIT ' . (int) $limit
        );
        $stmt->execute([$keyId]);

        return $stmt->fetchAll();
    }

    /**
     * Move one row from the old key to the new one, if it is still on the old
     * key.
     *
     * The `encryption_key_id = :old` predicate is the whole concurrency story
     * (ADR-0036): a member edit that lands mid-rotation re-seals the row under
     * the ACTIVE key by itself, and this UPDATE then matches nothing and leaves
     * that fresher ciphertext alone. The alternative — a blind write — would
     * roll the row back to the IBAN the rotation read a moment earlier.
     *
     * @return bool true when the row was re-sealed, false when something else got there first
     */
    public function reseal(string $mandateId, string $oldKeyId, string $newCiphertext, string $newKeyId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE mandates SET iban_ciphertext = ?, encryption_key_id = ? WHERE id = ? AND encryption_key_id = ?'
        );
        $stmt->execute([$newCiphertext, $newKeyId, $mandateId, $oldKeyId]);

        return $stmt->rowCount() === 1;
    }
}
