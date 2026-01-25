<?php

namespace App\Http\Modules\AuditLog\DTOs;

use DateTimeImmutable;

/**
 * AuditLogDto - Data Transfer Object for Audit Log Entries
 *
 * Implements Pattern 003: Data Transfer Objects
 * Immutable value object for serializing audit log entries to API responses.
 */
final readonly class AuditLogDto
{
    /**
     * @param int $id Unique audit log entry ID
     * @param string|null $adminUserId UUID of admin who performed action
     * @param string|null $adminUserName Name of admin (joined from admin_users table)
     * @param string $action Type of action (create, update, delete, etc.)
     * @param string $entityType Type of entity affected (member, product, etc.)
     * @param string $entityId Primary key of affected record
     * @param array|null $oldValues Field values before change
     * @param array|null $newValues Field values after change
     * @param string|null $ipAddress Client IP address (IPv4/IPv6)
     * @param string|null $userAgent Browser/client identifier
     * @param DateTimeImmutable $createdAt Immutable timestamp of action
     */
    public function __construct(
        public int $id,
        public ?string $adminUserId,
        public ?string $adminUserName,
        public string $action,
        public string $entityType,
        public string $entityId,
        public ?array $oldValues,
        public ?array $newValues,
        public ?string $ipAddress,
        public ?string $userAgent,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * Convert DTO to array for JSON serialization
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'admin_user_id' => $this->adminUserId,
            'admin_user_name' => $this->adminUserName,
            'action' => $this->action,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'old_values' => $this->oldValues,
            'new_values' => $this->newValues,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'created_at' => $this->createdAt->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
