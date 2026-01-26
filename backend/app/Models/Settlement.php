<?php

namespace App\Models;

use App\Shared\Enums\ManualReason;
use App\Shared\Enums\SettlementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Settlement Model
 *
 * Represents a settlement (SEPA or manual) for periodic billing.
 *
 * Implements UC-A30: Create Settlement, ADR-0007: SEPA Configuration
 * Each settlement contains multiple settlement_items (one per transaction).
 */
class Settlement extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'settlements';

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
        'settlement_type',
        'manual_reason',
        'settlement_date',
        'execution_date',
        'period_start',
        'period_end',
        'sepa_message_id',
        'total_amount_cents',
        'member_count',
        'is_cancelled',
        'cancelled_at',
        'cancelled_by_admin_id',
        'exported_at',
        'notes',
        'created_by_admin_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'settlement_type' => SettlementType::class,
        'manual_reason' => ManualReason::class,
        'settlement_date' => 'date',
        'execution_date' => 'date',
        'period_start' => 'date',
        'period_end' => 'date',
        'total_amount_cents' => 'integer',
        'member_count' => 'integer',
        'is_cancelled' => 'boolean',
        'cancelled_at' => 'datetime',
        'exported_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the admin user who created this settlement
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by_admin_id', 'id');
    }

    /**
     * Get the admin user who cancelled this settlement (if cancelled)
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'cancelled_by_admin_id', 'id');
    }

    /**
     * Get all settlement items (line items) in this settlement
     */
    public function items(): HasMany
    {
        return $this->hasMany(SettlementItem::class, 'settlement_id', 'id');
    }

    /**
     * Check if settlement is cancelled
     */
    public function isCancelled(): bool
    {
        return $this->is_cancelled === true;
    }

    /**
     * Check if settlement has been exported
     */
    public function isExported(): bool
    {
        return $this->exported_at !== null;
    }

    /**
     * Check if settlement is a SEPA settlement
     */
    public function isSepa(): bool
    {
        return $this->settlement_type === SettlementType::SEPA;
    }

    /**
     * Check if settlement is a manual settlement
     */
    public function isManual(): bool
    {
        return $this->settlement_type === SettlementType::MANUAL;
    }
}
