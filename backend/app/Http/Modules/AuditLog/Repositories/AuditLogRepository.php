<?php

namespace App\Http\Modules\AuditLog\Repositories;

use App\Http\Modules\AuditLog\DTOs\AuditLogDto;
use App\Models\AuditLog;
use DateTimeImmutable;

/**
 * AuditLogRepository - Data Access for Audit Logs
 *
 * Implements Pattern 005: Repository Interface
 * Abstracts audit log queries and filtering logic.
 */
final class AuditLogRepository
{
    /**
     * List audit log entries with pagination and filters
     *
     * Supports filtering by:
     * - Date range (date_from, date_to)
     * - Action type (create, update, delete, etc.)
     * - Entity type (member, product, etc.)
     * - Admin user ID
     * - Free text search (entity_id, admin email, IP)
     *
     * @param int $limit Items per page
     * @param int $offset Starting position
     * @param array $filters Filter criteria
     * @return array ['items' => [...AuditLogDto...], 'total' => int]
     */
    public function listWithFilters(int $limit, int $offset, array $filters = []): array
    {
        // Build query
        $query = AuditLog::query()
            ->with('adminUser')
            ->orderBy('created_at', 'desc');

        // Apply filters
        $query = $this->applyFilters($query, $filters);

        // Get total count (before pagination)
        $total = $query->count();

        // Apply pagination
        $entries = $query
            ->skip($offset)
            ->take($limit)
            ->get();

        // Transform to DTOs
        $items = $entries->map(fn (AuditLog $entry) => new AuditLogDto(
            id: $entry->id,
            adminUserId: $entry->admin_user_id ? bin2hex($entry->admin_user_id) : null,
            adminUserName: $entry->adminUser?->name,
            action: $entry->action,
            entityType: $entry->entity_type,
            entityId: $entry->entity_id,
            oldValues: $entry->old_values,
            newValues: $entry->new_values,
            ipAddress: $entry->ip_address,
            userAgent: $entry->user_agent,
            createdAt: new DateTimeImmutable($entry->created_at->format('c')),
        ))->all();

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * Apply filters to audit log query
     *
     * @param mixed $query Eloquent query builder
     * @param array $filters Filter criteria
     * @return mixed Modified query
     */
    private function applyFilters($query, array $filters)
    {
        // Date range filters
        if (isset($filters['date_from'])) {
            $query = $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query = $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        // Action filter
        if (isset($filters['action']) && !empty($filters['action'])) {
            $query = $query->where('action', $filters['action']);
        }

        // Entity type filter
        if (isset($filters['entity_type']) && !empty($filters['entity_type'])) {
            $query = $query->where('entity_type', $filters['entity_type']);
        }

        // Admin user filter
        if (isset($filters['admin_user_id']) && !empty($filters['admin_user_id'])) {
            $adminId = hex2bin($filters['admin_user_id']);
            $query = $query->where('admin_user_id', $adminId);
        }

        // Free text search (entity_id, admin email, IP)
        if (isset($filters['search']) && !empty($filters['search'])) {
            $search = $filters['search'];
            $query = $query->where(function ($q) use ($search) {
                $q->where('entity_id', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%");
            });
        }

        return $query;
    }
}
