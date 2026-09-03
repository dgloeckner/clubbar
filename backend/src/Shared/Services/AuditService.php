<?php

declare(strict_types=1);

namespace App\Shared\Services;

use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Modules\AuditLog\Repositories\AuditLogRepository;

class AuditService
{
    public function __construct(
        private AuditLogRepository $auditLogRepository,
    ) {}

    public function log(
        AuditAction $action,
        EntityType $entityType,
        string $entityId,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $adminUserId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): void {
        $this->auditLogRepository->insert([
            'admin_user_id' => $adminUserId,
            'action' => $action->value,
            'entity_type' => $entityType->value,
            'entity_id' => $entityId,
            'old_values' => $this->maskSensitiveFields($oldValues),
            'new_values' => $this->maskSensitiveFields($newValues),
            'ip_address' => $ipAddress ?? ($_SERVER['REMOTE_ADDR'] ?? null),
            'user_agent' => $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? null),
        ]);
    }

    /**
     * Whether this admin account has ever authored an audit row.
     *
     * Delegates to the repository; exposed here because `AuditService` is what
     * modules are wired to (Pattern 008), and `AdminUsersService` already
     * holds one.
     */
    public function hasEntriesByActor(string $adminUserId): bool
    {
        return $this->auditLogRepository->hasEntriesByActor($adminUserId);
    }

    /**
     * Write this entry only if the same (entity, action) pair has not been
     * recorded since $since — for conditions that are *observed* rather than
     * performed, and would otherwise be re-observed on every poll (#395).
     *
     * @return bool Whether an entry was written.
     */
    public function logOnceSince(
        string $since,
        AuditAction $action,
        EntityType $entityType,
        string $entityId,
        ?array $newValues = null,
        ?string $adminUserId = null,
    ): bool {
        if ($this->auditLogRepository->hasEntrySince($entityId, $action->value, $since)) {
            return false;
        }

        $this->log(
            action: $action,
            entityType: $entityType,
            entityId: $entityId,
            newValues: $newValues,
            adminUserId: $adminUserId,
        );

        return true;
    }

    private function maskSensitiveFields(?array $values): ?array
    {
        if ($values === null) return null;

        $sensitive = ['password', 'api_token', 'api_token_hash', 'private_key', 'iban_fingerprint'];
        foreach ($sensitive as $field) {
            if (isset($values[$field])) {
                $values[$field] = '[MASKED]';
            }
        }

        if (isset($values['iban']) && $values['iban'] !== '[MASKED]') {
            $values['iban'] = \App\Shared\Utils\IbanMasker::mask($values['iban']);
        }

        return $values;
    }
}
