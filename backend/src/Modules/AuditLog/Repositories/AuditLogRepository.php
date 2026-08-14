<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Repositories;

use PDO;
use App\Shared\Logging\Logger;
use App\Shared\Repository\SafeQuery;

class AuditLogRepository
{
    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    public function insert(array $data): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO audit_log (admin_user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['admin_user_id'] ?? null,
            $data['action'],
            $data['entity_type'],
            $data['entity_id'],
            isset($data['old_values']) ? json_encode($data['old_values']) : null,
            isset($data['new_values']) ? json_encode($data['new_values']) : null,
            $data['ip_address'] ?? null,
            $data['user_agent'] ?? null,
            $data['created_at'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Strip the payloads from every audit entry about one entity, for an
     * erasure request (GDPR Art. 17). The entries themselves stay: *that* an
     * admin acted on this id, and when, is the accountability record.
     *
     * **Scope, and its one precondition.** The sweep is keyed on `entity_id`
     * alone and no longer on the `entity_type` the row claims: ids here are
     * UUIDs, so a row carrying this member's id is about this member whatever
     * type it was filed under, and a mistyped entry is exactly the one an
     * erasure must not skip. It used to require `entity_type = 'member'`,
     * which made the completeness of an erasure depend on every writer
     * getting the type right (#115).
     *
     * What this cannot reach is an entry filed under a *different* id that
     * embeds member data inside its payload — a settlement's entry naming the
     * members it left out, say. Nulling that would erase the record for
     * everyone else in it, so the invariant runs the other way: **an audit
     * payload keyed to something other than a member must not carry member
     * PII**, only member ids, which the erasure turns into references to
     * nobody. {@see \App\Modules\Settlements\DTOs\ExcludedMemberDto::toAuditArray()}
     * is where that rule bit, and the tests that hold it are
     * `SepaExportServiceTest::test_the_audit_summary_carries_no_member_pii`
     * and this repository's own scrub tests.
     *
     * @return int Rows scrubbed.
     */
    public function scrubByEntityId(string $entityId): int
    {
        $stmt = $this->db->prepare(
            'UPDATE audit_log SET old_values = NULL, new_values = NULL WHERE entity_id = ?'
        );
        $stmt->execute([$entityId]);
        return $stmt->rowCount();
    }

    /**
     * Has this entity already been audited with this action since $since?
     *
     * The one dedup primitive the log needs. An audit entry normally records a
     * deliberate act, so writing one per act is right; a *condition* being
     * observed is not an act, and a terminal that polls every minute with an
     * expired token would otherwise write 1440 identical rows a day and bury
     * everything else in the log (#395). Anchoring the window on the instant
     * the condition began — the token's own expiry — keeps exactly one entry
     * per occurrence, and a later expiry of the same terminal writes a new one.
     */
    public function hasEntrySince(string $entityId, string $action, string $since): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM audit_log
              WHERE entity_id = ? AND action = ? AND created_at >= ?
              LIMIT 1'
        );
        $stmt->execute([$entityId, $action, $since]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $filters
     * @param string $sortKey Only `created_at` is orderable; anything else is
     *        rejected rather than silently ignored, because the audit screen
     *        used to render a sort arrow over an unchanged order (#125).
     */
    public function listWithFilters(int $limit, int $offset, array $filters = [], string $sortKey = 'created_at', string $sortOrder = 'desc'): array
    {
        $where = [];
        $params = [];

        if (isset($filters['date_from'])) {
            $where[] = 'DATE(al.created_at) >= ?';
            $params[] = $filters['date_from'];
        }
        if (isset($filters['date_to'])) {
            $where[] = 'DATE(al.created_at) <= ?';
            $params[] = $filters['date_to'];
        }
        if (isset($filters['action'])) {
            $where[] = 'al.action = ?';
            $params[] = $filters['action'];
        }
        if (isset($filters['entity_type'])) {
            $where[] = 'al.entity_type = ?';
            $params[] = $filters['entity_type'];
        }
        if (isset($filters['admin_user_id'])) {
            $where[] = 'al.admin_user_id = ?';
            $params[] = $filters['admin_user_id'];
        }
        if (isset($filters['entity_id'])) {
            $where[] = 'al.entity_id = ?';
            $params[] = $filters['entity_id'];
        }
        if (isset($filters['search'])) {
            $escaped = SafeQuery::escapeLike($filters['search']);
            $where[] = '(al.entity_id LIKE ? OR al.ip_address LIKE ?)';
            $params = array_merge($params, ["%{$escaped}%", "%{$escaped}%"]);
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $columnMap = ['created_at' => 'al.created_at'];
        $sortColumn = $columnMap[SafeQuery::column($sortKey, array_keys($columnMap))];
        $dir = SafeQuery::direction($sortOrder);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM audit_log al {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Entries written inside the same second are ordered by their insertion
        // id, so paging through the log cannot show a row twice or skip one.
        $dataParams = array_merge($params, [$limit, $offset]);
        $stmt = $this->db->prepare(
            "SELECT al.*, au.display_name as admin_user_name FROM audit_log al LEFT JOIN admin_users au ON al.admin_user_id = au.id {$whereClause} ORDER BY {$sortColumn} {$dir}, al.id {$dir} LIMIT ? OFFSET ?"
        );
        $stmt->execute($dataParams);

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }
}
