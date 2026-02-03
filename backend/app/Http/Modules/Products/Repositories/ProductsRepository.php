<?php

namespace App\Http\Modules\Products\Repositories;

use App\Models\Product;
use App\Shared\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

/**
 * ProductsRepository - Data Access for Products
 *
 * Extends BaseRepository with standard CRUD inherited methods.
 * Adds domain-specific queries for Products.
 *
 * Pattern 005: Repository Interface
 * Pattern 011: Shared Base Repository (inherits all CRUD operations)
 *
 * Inherited Methods from BaseRepository:
 * - findById(string $id): ?Model
 * - findByIds(array $ids): Collection
 * - findAll(): Collection
 * - create(array $attributes): Model
 * - updateById(string $id, array $attributes): ?Model
 * - deleteById(string $id): bool
 * - count(): int
 * - exists(string $id): bool
 *
 * Module-Specific Methods:
 * - findModifiedSince(int $sinceTimestamp): Collection
 * - findActive(): Collection
 * - findByCategory(string $categoryId): Collection
 * - search(string $query): Collection
 * - filter(array $filters): Collection
 */
class ProductsRepository extends BaseRepository
{
    /**
     * Initialize repository with Product model.
     */
    public function __construct()
    {
        parent::__construct(new Product());
    }

    /**
     * Find products modified since timestamp (for Terminal delta sync).
     *
     * Terminal API calls this to fetch products changed since last sync.
     * Returns ALL products (active and inactive) so the terminal can update
     * its local cache — the terminal filters is_active for display.
     * Per API spec: "Inactive products: Returned with is_active: false."
     * Used for GET /api/sync/products?since={timestamp}
     *
     * @param int $sinceTimestamp Unix timestamp
     * @return Collection Products modified since timestamp
     */
    public function findModifiedSince(int $sinceTimestamp): Collection
    {
        return $this->query()
            ->where('products.updated_at', '>=', date('Y-m-d H:i:s', $sinceTimestamp))
            ->orderBy('products.updated_at', 'asc')
            ->get();
    }

    /**
     * Find all active products.
     *
     * Returns products that are active and belong to active categories.
     * Used for terminal display.
     *
     * @return Collection Active products
     */
    public function findActive(): Collection
    {
        return $this->query()
            ->where('products.is_active', true)
            ->whereHas('category', function ($q) {
                $q->where('is_active', true);
            })
            ->orderBy('products.created_at', 'asc')
            ->get();
    }

    /**
     * Find products by category.
     *
     * Used for listing products in a specific category.
     *
     * @param string $categoryId
     * @return Collection Products in category
     */
    public function findByCategory(string $categoryId): Collection
    {
        return $this->query()
            ->where('category_id', $categoryId)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Search products by name.
     *
     * Searches product names in all languages (JSON search).
     * Used for admin product search.
     *
     * @param string $query Search query
     * @return Collection Products matching search
     */
    public function search(string $query): Collection
    {
        return $this->query()
            ->where(function ($q) use ($query) {
                // Search in JSON names field (MySQL JSON_CONTAINS)
                // This is MySQL-specific; adjust for other databases
                $q->whereRaw("JSON_SEARCH(names, 'one', ?) IS NOT NULL", ["%{$query}%"]);
            })
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Filter products with multiple criteria.
     *
     * Supports filtering by status, category, and search.
     * Used for admin product list with filters.
     *
     * @param array $filters Filters: status ('active'|'inactive'|'all'), category_id, search
     * @return \Illuminate\Database\Eloquent\Builder Query builder for pagination/sorting
     */
    public function filter(array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $query = $this->query();

        // Filter by status
        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $query->where('products.is_active', $filters['status'] === 'active');
        }

        // Filter by category
        if (isset($filters['category_id'])) {
            $query->where('products.category_id', $filters['category_id']);
        }

        // Search by name
        if (isset($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->whereRaw("JSON_SEARCH(names, 'one', ?) IS NOT NULL", ["%{$filters['search']}%"]);
            });
        }

        return $query;
    }

    /**
     * Get products with category name.
     *
     * Loads category relationship for efficient querying.
     *
     * @return \Illuminate\Database\Eloquent\Builder Query builder with category loaded
     */
    public function withCategory(): \Illuminate\Database\Eloquent\Builder
    {
        return $this->query()->with('category');
    }
}
