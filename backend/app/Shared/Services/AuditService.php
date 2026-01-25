<?php

namespace App\Shared\Services;

use App\Models\AuditLog;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Utils\IbanMasker;

/**
 * AuditService - Cross-Cutting Concern for Audit Logging
 *
 * Per Pattern 016 (Audit Logging for Compliance & Transparency):
 * Centralized service for recording all changes to master data.
 * Implements ADR-0013 audit logging requirements.
 *
 * Responsibilities:
 * - Create audit_log entries with full context (who, what, when, where)
 * - Apply sensitive data masking (IBAN, passwords)
 * - Auto-capture request context (IP, user agent)
 * - Handle nullable admin_user_id (system/failed action scenarios)
 *
 * Injected as singleton (Pattern 008) into services that perform CRUD.
 */
final readonly class AuditService
{
    /**
     * Log an audit entry for a master data change
     *
     * Per ADR-0013:
     * - All changes to master data must be logged
     * - Sensitive fields (IBAN, passwords) must be masked
     * - Old and new values captured for complete history
     * - Request context (IP, user agent) captured for security
     * - Admin user identity linked to action for accountability
     *
     * @param AuditAction $action Type of action (create, update, delete, anonymize, etc.)
     * @param EntityType $entityType Type of entity affected (member, product, etc.)
     * @param string $entityId Primary key of affected record (UUID format)
     * @param array|null $oldValues Field values before change (null for create)
     * @param array|null $newValues Field values after change (null for delete)
     * @param string|null $adminUserId UUID of admin who performed action (nullable for system/failed actions)
     * @param string|null $ipAddress Client IP address (auto-captured from request if not provided)
     * @param string|null $userAgent Browser/client identifier (auto-captured from request if not provided)
     * @return void
     */
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
        // Auto-capture request context if not explicitly provided
        $ipAddress ??= request()->ip();
        $userAgent ??= request()->userAgent();

        // Apply sensitive data masking (IBAN, passwords, API tokens)
        $oldValues = $this->maskSensitiveFields($oldValues);
        $newValues = $this->maskSensitiveFields($newValues);

        // Create immutable audit log entry
        AuditLog::create([
            'admin_user_id' => $adminUserId,
            'action' => $action->value,
            'entity_type' => $entityType->value,
            'entity_id' => $entityId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }

    /**
     * Apply sensitive data masking to field values
     *
     * Per ADR-0013 Section "Sensitive Data Handling":
     * - IBAN: Masked as "DE89****...****4567" (first 4 + last 4 visible)
     * - Password: Replaced with "[CHANGED]" (never log hash or plaintext)
     * - API Token: Removed entirely (never logged)
     *
     * Masking is applied to both old_values and new_values to ensure
     * sensitive data is never stored in audit log, while preserving enough
     * information for identification and troubleshooting.
     *
     * @param array|null $values Field values to mask
     * @return array|null Masked values (or null if input is null)
     */
    private function maskSensitiveFields(?array $values): ?array
    {
        // Null values pass through unchanged
        if ($values === null) {
            return null;
        }

        // Mask IBAN if present
        if (isset($values['iban']) && is_string($values['iban'])) {
            $values['iban'] = IbanMasker::mask($values['iban']);
        }

        // Mask password field
        if (isset($values['password'])) {
            $values['password'] = '[CHANGED]';
        }

        // Remove API tokens (never log them)
        unset($values['api_token']);

        return $values;
    }
}
