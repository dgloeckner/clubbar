<?php

namespace App\Shared\Enums;

/**
 * AuditAction Enum - Type-Safe Audit Action Values
 *
 * Per Pattern 002: Enum for Type-Safe Domain Values
 * Per ADR-0013: Defines all audit action types that can be logged
 *
 * Actions:
 * - CREATE: New record created (old_values: null, new_values: all fields)
 * - UPDATE: Record modified (old_values: changed fields, new_values: changed fields)
 * - DELETE: Record hard-deleted (old_values: all fields, new_values: null)
 * - ACTIVATE: Record activated (old_values: {is_active: false}, new_values: {is_active: true})
 * - DEACTIVATE: Record deactivated (old_values: {is_active: true}, new_values: {is_active: false})
 * - REORDER: Items reordered (old_values: null, new_values: {new_order})
 * - ANONYMIZE: GDPR anonymization (old_values: PII masked, new_values: anonymized state)
 * - LOGIN: Admin login successful (old_values: null, new_values: null)
 * - LOGOUT: Admin logout (old_values: null, new_values: null)
 * - LOGIN_FAILED: Failed login attempt (old_values: null, new_values: {attempted_email})
 * - EXPORT: Data export (old_values: null, new_values: {export_type, format})
 * - SETTLEMENT_CREATE: Settlement created (old_values: null, new_values: {settlement_id, total})
 * - SETTLEMENT_CANCEL: Settlement cancelled (old_values: {settlement_id}, new_values: null)
 * - SETTLEMENT_EXPORT: Settlement exported (old_values: null, new_values: {settlement_id, format})
 */
enum AuditAction: string
{
    case CREATE = 'create';
    case UPDATE = 'update';
    case DELETE = 'delete';
    case ACTIVATE = 'activate';
    case DEACTIVATE = 'deactivate';
    case REORDER = 'reorder';
    case ANONYMIZE = 'anonymize';
    case LOGIN = 'login';
    case LOGOUT = 'logout';
    case LOGIN_FAILED = 'login_failed';
    case EXPORT = 'export';
    case SETTLEMENT_CREATE = 'settlement_create';
    case SETTLEMENT_CANCEL = 'settlement_cancel';
    case SETTLEMENT_EXPORT = 'settlement_export';
}
