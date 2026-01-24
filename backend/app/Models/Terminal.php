<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Terminal Model
 *
 * Represents a physical POS terminal device with device-level API token authentication.
 *
 * Pattern Reference: Standard Eloquent model for entity persistence
 * Security: API tokens stored as bcrypt hashes (see Pattern 012)
 *
 * @property string $id UUID primary key
 * @property string $name Terminal display name (e.g., "Bar Counter Terminal 1")
 * @property string $device_id Unique device identifier (e.g., hardware serial number)
 * @property string|null $api_token_hash Bcrypt hash of terminal's API token (256-bit, cost 12)
 * @property bool $is_active Whether terminal can authenticate (soft-disable without deletion)
 * @property \DateTime|null $last_sync_at Last successful sync timestamp
 * @property \DateTime $created_at
 * @property \DateTime $updated_at
 */
class Terminal extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    /**
     * The table associated with the model.
     */
    protected $table = 'terminals';

    /**
     * The primary key for the model.
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = false;

    /**
     * The "type" of the primary key ID.
     */
    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'id',
        'name',
        'device_id',
        'api_token_hash',
        'is_active',
        'last_sync_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_active' => 'boolean',
        'last_sync_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Check if terminal is currently active.
     */
    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    /**
     * Query scope for active terminals only.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
