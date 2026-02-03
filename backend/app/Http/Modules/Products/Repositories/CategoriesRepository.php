<?php

namespace App\Http\Modules\Products\Repositories;

use App\Models\Category;
use App\Shared\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

/**
 * CategoriesRepository - Data Access for Product Categories
 *
 * Extends BaseRepository with standard CRUD inherited methods.
 * Adds domain-specific queries for Categories.
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
 * - getOrdered(): Collection
 * - getWithProductCount(): Collection
 */
class CategoriesRepository extends BaseRepository
{
    /**
     * Initialize repository with Category model.
     */
    public function __construct()
    {
        parent::__construct(new Category());
    }

    /**
     * Find categories modified since timestamp (for Terminal delta sync).
     *
     * Terminal API calls this to fetch categories changed since last sync.
     * Returns ALL categories (active and inactive) so the terminal can update
     * its local cache — the terminal filters is_active for display.
     * Per API spec: "Inactive categories: Returned with is_active: false."
     * Used for GET /api/sync/categories?since={timestamp}
     *
     * @param int $sinceTimestamp Unix timestamp
     * @return Collection Categories modified since timestamp, ordered by display_order
     */
    public function findModifiedSince(int $sinceTimestamp): Collection
    {
        return $this->query()
            ->where('updated_at', '>=', date('Y-m-d H:i:s', $sinceTimestamp))
            ->orderBy('display_order', 'asc')
            ->get();
    }

    /**
     * Find all active categories.
     *
     * Used for admin list and product assignment validation.
     *
     * @return Collection Active categories ordered by display_order
     */
    public function findActive(): Collection
    {
        return $this->query()
            ->where('is_active', true)
            ->orderBy('display_order', 'asc')
            ->get();
    }

    /**
     * Get all categories ordered by display_order.
     *
     * Used for admin category list.
     *
     * @return Collection All categories (active and inactive) ordered by display_order
     */
    public function getOrdered(): Collection
    {
        return $this->query()
            ->orderBy('display_order', 'asc')
            ->get();
    }

    /**
     * Get all categories with product count.
     *
     * Used for admin list view showing category + product count.
     * Includes inactive categories for admin management.
     *
     * @return Collection Categories with product count included (via withCount)
     */
    public function getWithProductCount(): Collection
    {
        return $this->query()
            ->withCount('products')
            ->orderBy('display_order', 'asc')
            ->get();
    }

    /**
     * Check if category has any products.
     *
     * Used for validation before deleting category.
     *
     * @param string $categoryId
     * @return bool True if category has products
     */
    public function hasProducts(string $categoryId): bool
    {
        $category = $this->findById($categoryId);
        return $category && $category->products()->exists();
    }

    /**
     * Get next available display_order.
     *
     * Used when creating new category to auto-assign display order.
     *
     * @return int Next display order value
     */
    public function getNextDisplayOrder(): int
    {
        $max = $this->query()->max('display_order');
        return ($max ?? 0) + 1;
    }

    /**
     * Reorder categories based on provided order array.
     *
     * Updates display_order for all provided categories atomically.
     * Used for drag-and-drop reordering in admin UI.
     *
     * @param array $order Array of category IDs in desired order
     * @return void
     */
    public function reorder(array $order): void
    {
        foreach ($order as $index => $categoryId) {
            $this->query()
                ->where('id', $categoryId)
                ->update(['display_order' => $index + 1]);
        }
    }
}
