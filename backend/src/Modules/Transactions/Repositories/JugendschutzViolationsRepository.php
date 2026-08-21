<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Repositories;

use App\Shared\Enums\AuditAction;
use PDO;

/**
 * Recorded underage sales, and whether anybody has looked at them
 * (#622, ADR-0045 §3).
 *
 * **Reads the audit log; owns only the acknowledgement.** The
 * `jugendschutz_violation` audit entry M7 writes is the record, and this class
 * never writes, alters or deletes one. What it adds is a row in
 * `jugendschutz_violation_acks` saying a human has dealt with a given entry —
 * which is the whole reason the badge can go quiet without the incident going
 * anywhere (migration 051 argues this against 050's own header).
 *
 * A repository over another module's table would normally be a smell. It is the
 * right shape here precisely because the alternative — a second
 * `jugendschutz_violations` table mirroring the audit payload — is two records
 * of one fact, free to drift apart, and 050 already settled where the fact
 * lives.
 */
class JugendschutzViolationsRepository
{
    public function __construct(private PDO $db) {}

    /**
     * How many violations nobody has acknowledged, and when the most recent of
     * those happened.
     *
     * One query rather than two: the count and the timestamp must describe the
     * same set, and two round trips can straddle an acknowledgement and report
     * a "latest" that is no longer in the count.
     *
     * `occurred_at` comes from `created_at` on the audit row — the moment the
     * violation was *recorded*, not the moment of the sale. The sale's own
     * timestamp is inside `new_values`, and sorting on a JSON extraction would
     * give up the index for a difference of at most one sync interval. The
     * message that names the sale is the mail; this is a "go and look" figure.
     *
     * @return array{count: int, latest_occurred_at: string|null}
     */
    public function unacknowledgedSummary(): array
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS c, MAX(a.created_at) AS latest
               FROM audit_log a
               LEFT JOIN jugendschutz_violation_acks k ON k.audit_log_id = a.id
              WHERE a.action = ? AND k.audit_log_id IS NULL'
        );
        $stmt->execute([AuditAction::JUGENDSCHUTZ_VIOLATION->value]);
        $row = $stmt->fetch() ?: [];

        return [
            'count' => (int) ($row['c'] ?? 0),
            'latest_occurred_at' => $row['latest'] ?? null,
        ];
    }

    /**
     * The violations still waiting to be looked at, newest first.
     *
     * **Deliberately narrow.** Each row carries the acknowledgement key, the
     * sale it names, the age that was breached and when it was recorded — and
     * no member and no age-at-sale. The dashboard this feeds is `TREASURY`, so
     * a Kassenwart reading it may see members in other surfaces; this one still
     * does not show them, because it renders on a screen that may be open in
     * the clubroom and ADR-0045 rule 6 does not stop at the till. The
     * transaction id is the handle into the journal, which their role permits.
     *
     * `min_age` is read out of the recorded payload rather than from the
     * product, so it keeps saying what the limit was **at the sale** after
     * somebody clears it (invariant 4).
     *
     * @return list<array{id: int, transaction_id: string, min_age: int, recorded_at: string}>
     */
    public function listUnacknowledged(int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT a.id,
                    a.entity_id AS transaction_id,
                    JSON_UNQUOTE(JSON_EXTRACT(a.new_values, \'$.min_age\')) AS min_age,
                    a.created_at AS recorded_at
               FROM audit_log a
               LEFT JOIN jugendschutz_violation_acks k ON k.audit_log_id = a.id
              WHERE a.action = ? AND k.audit_log_id IS NULL
              ORDER BY a.created_at DESC, a.id DESC
              LIMIT ?'
        );
        $stmt->bindValue(1, AuditAction::JUGENDSCHUTZ_VIOLATION->value);
        $stmt->bindValue(2, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'transaction_id' => (string) $row['transaction_id'],
            'min_age' => (int) $row['min_age'],
            'recorded_at' => (string) $row['recorded_at'],
        ], $stmt->fetchAll());
    }

    /**
     * Mark one recorded violation as dealt with.
     *
     * Returns false — rather than throwing — when the entry is not a violation,
     * does not exist, or has already been acknowledged. All three are the same
     * answer to the caller: *nothing was acknowledged just now*. The second
     * admin clicking the same alert is not an error, and the controller turns
     * a false into a 404/409 with the reason it looked up itself.
     *
     * **The action check is what makes an `audit_log.id` key safe.** Every
     * audited action in the system shares that id space, so an unguarded insert
     * would let an ack be written against a login or a settlement export — and
     * the dashboard would be quietened by a row with nothing to do with it.
     */
    public function acknowledge(int $auditLogId, ?string $adminUserId, ?string $note): bool
    {
        if (!$this->isViolation($auditLogId)) {
            return false;
        }

        // INSERT IGNORE on the primary key settles the race between two admins
        // clearing the same alert: the second insert affects no rows and the
        // first acknowledgement — with its actor and its note — stands.
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO jugendschutz_violation_acks
                 (audit_log_id, acknowledged_at, acknowledged_by_admin_id, note)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$auditLogId, date('Y-m-d H:i:s'), $adminUserId, $note]);

        return $stmt->rowCount() > 0;
    }

    /** Is this audit entry a Jugendschutz violation at all? */
    public function isViolation(int $auditLogId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM audit_log WHERE id = ? AND action = ?');
        $stmt->execute([$auditLogId, AuditAction::JUGENDSCHUTZ_VIOLATION->value]);

        return $stmt->fetchColumn() !== false;
    }

    /** Has it already been dealt with? Lets the controller tell 404 from 409. */
    public function isAcknowledged(int $auditLogId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM jugendschutz_violation_acks WHERE audit_log_id = ?');
        $stmt->execute([$auditLogId]);

        return $stmt->fetchColumn() !== false;
    }
}
