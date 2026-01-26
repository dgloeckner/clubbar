<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SettlementItem Model
 *
 * Represents a single transaction line item in a settlement.
 * One row per transaction that's part of a settlement.
 * Immutable once created - no timestamps.
 */
class SettlementItem extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'settlement_items';

    /**
     * Indicates if the model has timestamps.
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
        'settlement_id',
        'transaction_id',
        'member_id',
        'amount_cents',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount_cents' => 'integer',
    ];

    /**
     * Get the settlement this item belongs to
     */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class, 'settlement_id', 'id');
    }

    /**
     * Get the transaction for this line item
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'id');
    }

    /**
     * Get the member for this line item
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id', 'id');
    }
}
