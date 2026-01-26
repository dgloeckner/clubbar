<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SepaConfig Model
 *
 * Represents the organization's SEPA Direct Debit configuration.
 * Single-row table (id=1) - only one configuration per system.
 *
 * Implements ADR-0007: SEPA Configuration Management
 * Stores creditor (organization) details required for SEPA XML generation.
 */
class SepaConfig extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'sepa_config';

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
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'creditor_id',
        'creditor_name',
        'creditor_iban',
        'creditor_address_street',
        'creditor_address_city',
        'creditor_address_country',
        'updated_by_admin_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the admin user who last updated the config
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'updated_by_admin_id', 'id');
    }

    /**
     * Get the single SEPA configuration
     *
     * Static method to easily access the one and only SEPA config row
     */
    public static function getConfig(): ?self
    {
        return self::find(1);
    }

    /**
     * Check if SEPA configuration is complete
     *
     * A configuration is complete when all required fields are filled.
     */
    public function isConfigured(): bool
    {
        return !empty($this->creditor_id)
            && !empty($this->creditor_name)
            && !empty($this->creditor_iban)
            && !empty($this->creditor_address_street)
            && !empty($this->creditor_address_city)
            && !empty($this->creditor_address_country);
    }
}
