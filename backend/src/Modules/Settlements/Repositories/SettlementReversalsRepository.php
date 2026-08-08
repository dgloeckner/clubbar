<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Repositories;

use App\Shared\Logging\Logger;
use App\Shared\Repository\SafeQuery;
use App\Shared\Utils\Uuid;
use PDO;

/**
 * The `settlement_reversals` table — append-only events, one per member per
 * settlement (schema 007, ruling #148).
 *
 * There is no update and no delete here on purpose. The table is the single
 * source of truth behind the derived settlement status, so a row that could be
 * edited away would take the status with it.
 */
class SettlementReversalsRepository
{
    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    /**
     * Record one member's reversal.
     *
     * The caller wraps this together with releasing that member's claims in a
     * single DB transaction (ruling #148 §8) — on its own it is one write of
     * two and commits nothing.
     */
    public function create(array $data): array
    {
        $id = $data['id'] ?? Uuid::v4();

        $stmt = $this->db->prepare(
            'INSERT INTO settlement_reversals
                (id, settlement_id, member_id, reason, amount_cents, bank_reference, notes, created_by_admin_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $data['settlement_id'],
            $data['member_id'],
            $data['reason'],
            (int) $data['amount_cents'],
            $data['bank_reference'] ?? null,
            $data['notes'] ?? null,
            $data['created_by_admin_id'],
        ]);

        $this->logger->info('Settlement reversal recorded', [
            'settlement_id' => $data['settlement_id'],
            'member_id' => $data['member_id'],
            'reason' => $data['reason'],
        ]);

        return $this->findById($id);
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare(self::SELECT . ' WHERE sr.id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string, mixed>> Oldest first — the order they happened in. */
    public function findBySettlementId(string $settlementId): array
    {
        $stmt = $this->db->prepare(self::SELECT . ' WHERE sr.settlement_id = ? ORDER BY sr.created_at ASC, sr.id ASC');
        $stmt->execute([$settlementId]);

        return $stmt->fetchAll();
    }

    /**
     * Which of these members already have a reversal on this settlement.
     *
     * The `UNIQUE (settlement_id, member_id)` constraint is the real guard
     * against reversing a member twice; this read exists so the ordinary case
     * answers with a named 409 instead of a raw constraint violation.
     *
     * @param list<string> $memberIds
     * @return list<string>
     */
    public function findReversedMemberIds(string $settlementId, array $memberIds = []): array
    {
        $sql = 'SELECT member_id FROM settlement_reversals WHERE settlement_id = ?';
        $params = [$settlementId];

        if ($memberIds !== []) {
            [$placeholders, $inParams] = SafeQuery::inClause(array_values($memberIds), 'string');
            $sql .= " AND member_id IN ({$placeholders})";
            $params = array_merge($params, $inParams);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map(static fn(array $row): string => $row['member_id'], $stmt->fetchAll());
    }

    private const SELECT =
        'SELECT sr.*, m.first_name, m.last_name, a.display_name AS admin_display_name
           FROM settlement_reversals sr
           LEFT JOIN members m ON m.id = sr.member_id
           LEFT JOIN admin_users a ON a.id = sr.created_by_admin_id';
}
