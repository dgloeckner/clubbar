<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Transaction Model
 *
 * Represents a financial transaction (purchase or correction).
 * Immutable - transactions are never updated or deleted.
 *
 * Implements ADR-0004: Immutable Transaction Storage
 * Corrections are handled via reverse transactions, not by modifying original transactions.
 */
class Transaction extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'transactions';

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
     * Disable updated_at since transactions are immutable
     *
     * @var bool
     */
    public const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'member_id',
        'product_id',
        'amount_cents',
        'transaction_type',
        'notes',
        'related_transaction_id',
        'created_by_terminal_id',
        'created_by_admin_id',
        'settlement_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount_cents' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * Get the member this transaction belongs to
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id', 'id');
    }

    /**
     * Get the product (if any) for this transaction
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    /**
     * Get the settlement this transaction belongs to (if settled)
     */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class, 'settlement_id', 'id');
    }

    /**
     * Get the related transaction (for corrections)
     */
    public function relatedTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'related_transaction_id', 'id');
    }

    /**
     * Check if transaction is a purchase
     */
    public function isPurchase(): bool
    {
        return $this->transaction_type === 'purchase';
    }

    /**
     * Check if transaction is a correction (reversal)
     */
    public function isCorrection(): bool
    {
        return $this->transaction_type === 'correction';
    }

    /**
     * Check if transaction is unsettled
     */
    public function isUnsettled(): bool
    {
        return $this->settlement_id === null;
    }

    /**
     * Check if transaction is settled
     */
    public function isSettled(): bool
    {
        return $this->settlement_id !== null;
    }
}
