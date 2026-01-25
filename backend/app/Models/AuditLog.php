<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AuditLog Model - Append-Only Audit Trail
 *
 * Per ADR-0013 (Audit Logging for Master Data Changes):
 * - Records all changes to master data with complete context
 * - Append-only: entries are created but never updated or deleted
 * - Timestamps immutable (no updated_at field)
 * - Relationships: Links to AdminUser who performed action (nullable)
 *
 * Audit entries capture:
 * - who: admin_user_id (null for system/failed login actions)
 * - what: entity_type + entity_id (identifies what was changed)
 * - how: action (create, update, delete, anonymize, etc.)
 * - values: old_values and new_values (before/after comparison)
 * - when: created_at (immutable timestamp)
 * - where: ip_address + user_agent (security context)
 */
class AuditLog extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'audit_log';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'int';

    /**
     * Disable timestamps (only created_at, no updated_at)
     * Append-only entries are never modified
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'admin_user_id',
        'action',
        'entity_type',
        'entity_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Relationship: Audit entry belongs to an admin user
     *
     * An audit entry may be associated with an admin user (nullable)
     * for regular operations. Failed login attempts and system actions
     * have admin_user_id = null.
     *
     * @return BelongsTo
     */
    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id', 'id');
    }
}
