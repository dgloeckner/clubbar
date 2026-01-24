<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Member Model
 *
 * Represents a club/bar member.
 *
 * Note: Database table does not exist yet. This model is a placeholder
 * for the Members module structure. Database integration will be implemented
 * in Milestone 4 with proper schema and migrations.
 *
 * Current State (Phase 3): Used by repository for type hinting and structure
 */
class Member extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'members';

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
    public $incrementing = false;

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'card_uid',
        'first_name',
        'last_name',
        'email',
        'phone',
        'preferred_language',
        'is_active',
        'is_sepa_valid',
        'iban',
        'mandate_reference',
        'deleted_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'is_sepa_valid' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
