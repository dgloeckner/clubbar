<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\DTOs;

final readonly class AuditLogDto
{
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
        public string $createdAt,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            adminUserId: $row['admin_user_id'] ?? null,
            adminUserName: $row['admin_display_name'] ?? $row['display_name'] ?? null,
            action: $row['action'],
            entityType: $row['entity_type'],
            entityId: $row['entity_id'],
            oldValues: isset($row['old_values']) ? (is_string($row['old_values']) ? json_decode($row['old_values'], true) : $row['old_values']) : null,
            newValues: isset($row['new_values']) ? (is_string($row['new_values']) ? json_decode($row['new_values'], true) : $row['new_values']) : null,
            ipAddress: $row['ip_address'] ?? null,
            userAgent: $row['user_agent'] ?? null,
            createdAt: $row['created_at'],
        );
    }

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
            'created_at' => $this->createdAt,
        ];
    }
}
