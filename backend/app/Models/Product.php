<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Product Model
 *
 * Represents a product in the catalog for sale via the terminal.
 *
 * Attributes:
 * - id: UUID primary key
 * - category_id: UUID foreign key to categories
 * - names: Multilingual product names (JSON)
 * - descriptions: Multilingual product descriptions (JSON, optional)
 * - price_cents: Price in cents (e.g., 350 = €3.50)
 * - is_active: Available for sale flag
 * - created_at, updated_at: Timestamps
 *
 * Relationships:
 * - belongsTo(Category): Product's category
 *
 * Implements ADR-0002: Product Internationalization
 * Implements ADR-0004: Immutable Transaction Storage (products not deleted, only deactivated)
 */
class Product extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'products';

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
        'category_id',
        'names',
        'descriptions',
        'price_cents',
        'is_active',
        'icon_name',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'names' => 'array',
        'descriptions' => 'array',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the category this product belongs to.
     *
     * @return BelongsTo
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id', 'id');
    }
}
