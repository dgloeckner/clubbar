<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Repositories;

use PDO;
use App\Shared\Logging\Logger;

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

    public function listWithFilters(int $limit, int $offset, array $filters = []): array
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
        if (isset($filters['search'])) {
            $escaped = SafeQuery::escapeLike($filters['search']);
            $where[] = '(al.entity_id LIKE ? OR al.ip_address LIKE ?)';
            $params = array_merge($params, ["%{$escaped}%", "%{$escaped}%"]);
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM audit_log al {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $dataParams = array_merge($params, [$limit, $offset]);
        $stmt = $this->db->prepare(
            "SELECT al.*, au.display_name as admin_display_name FROM audit_log al LEFT JOIN admin_users au ON al.admin_user_id = au.id {$whereClause} ORDER BY al.created_at DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute($dataParams);

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }
}
