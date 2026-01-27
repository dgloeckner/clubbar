<?php

namespace App\Http\Modules\Products\Services;

use App\DTOs\ProductDto;
use App\DTOs\SyncResultDto;
use App\Http\Modules\Products\Repositories\CategoriesRepository;
use App\Http\Modules\Products\Repositories\ProductsRepository;
use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Services\AuditService;
use App\Shared\Services\BaseService;
use DateTimeImmutable;

/**
 * ProductsService - Business Logic for Products
 *
 * Handles product operations:
 * - Terminal API: delta sync, product listing
 * - Admin API: CRUD operations with audit logging
 *
 * Implements Pattern 004: Service Layer
 * Implements Pattern 008: Service Provider Bindings
 * Implements Pattern 016: Audit Logging for all changes
 *
 * Key Methods:
 * - syncSince(): Terminal delta sync
 * - listProducts(): Admin listing with filters/sorting
 * - createProduct(): Create with validation
 * - updateProduct(): Update with change tracking
 * - toggleStatus(): Quick status toggle
 */
class ProductsService extends BaseService
{
    /**
     * Initialize service with repositories and audit service.
     *
     * @param ProductsRepository $productsRepository
     * @param CategoriesRepository $categoriesRepository
     * @param AuditService $auditService
     */
    public function __construct(
        private readonly ProductsRepository $productsRepository,
        private readonly CategoriesRepository $categoriesRepository,
        private readonly AuditService $auditService,
    ) {
        parent::__construct($productsRepository);
    }

    /**
     * Get products modified since timestamp (Terminal API).
     *
     * Returns delta sync result for /api/sync/products endpoint.
     * Only includes active products from active categories.
     *
     * @param int $since Unix timestamp (from query param)
     * @return SyncResultDto Contains products[], cursor, hasMore
     */
    public function syncSince(int $since): SyncResultDto
    {
        // Query database for products modified since timestamp
        $productModels = $this->productsRepository->findModifiedSince($since);

        // Transform models to DTOs
        $products = $productModels->map(fn($model) => $this->transform($model))->values();

        // Get latest timestamp for cursor (for next sync)
        $cursor = $productModels->isNotEmpty()
            ? $productModels->last()->updated_at->format('Y-m-d\TH:i:s\Z')
            : date('Y-m-d\TH:i:s\Z');

        return new SyncResultDto(
            items: $products->all(),
            cursor: $cursor,
            hasMore: false,
        );
    }

    /**
     * List products with filtering and pagination (Admin API).
     *
     * Supports filters: status, category_id, search
     * Supports sorting: name, price, category, created_at (default: category, name)
     *
     * @param int $page Page number (1-indexed)
     * @param int $perPage Results per page
     * @param array $filters Optional filters: status, category_id, search
     * @param string $sortBy Sort field: name|price|category|created_at
     * @param string $sortOrder asc|desc
     * @return PaginatedResultDto Paginated product results
     */
    public function listProducts(
        int $page = 1,
        int $perPage = 20,
        array $filters = [],
        string $sortBy = 'name',
        string $sortOrder = 'asc',
    ): PaginatedResultDto {
        // Start with query that loads category relationship
        $query = $this->productsRepository->query()->with('category');

        // Apply filters
        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $query->where('products.is_active', $filters['status'] === 'active');
        }
        if (isset($filters['category_id'])) {
            $query->where('products.category_id', $filters['category_id']);
        }
        if (isset($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->whereRaw("JSON_SEARCH(products.names, 'one', ?) IS NOT NULL", ["%{$filters['search']}%"]);
            });
        }

        // Count total before pagination
        $total = $query->count();

        // Apply sorting
        match ($sortBy) {
            'name' => $query->orderBy('products.names', $sortOrder),
            'price' => $query->orderBy('products.price_cents', $sortOrder),
            'category' => $query
                ->join('categories', 'products.category_id', '=', 'categories.id')
                ->orderBy('categories.display_order', 'asc')
                ->orderBy('products.names', 'asc'),
            'created_at' => $query->orderBy('products.created_at', $sortOrder),
            default => $query
                ->orderBy('products.category_id', 'asc')
                ->orderBy('products.names', 'asc'),
        };

        // Apply pagination
        $items = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        // Transform to DTOs
        $productDtos = $items->map(fn($model) => $this->transform($model))->all();

        return new PaginatedResultDto(
            items: $productDtos,
            total: $total,
            limit: $perPage,
            offset: ($page - 1) * $perPage,
        );
    }

    /**
     * Create new product.
     *
     * Validates all fields, creates product, logs audit entry.
     * New products are active by default.
     *
     * @param array $validated Validated request data: names, descriptions, price_cents, category_id
     * @return ProductDto Created product
     * @throws \RuntimeException If category not found or inactive
     */
    public function createProduct(array $validated): ProductDto
    {
        // Validate category exists and is active
        $category = $this->categoriesRepository->findById($validated['category_id']);
        if (!$category || !$category->is_active) {
            throw new \RuntimeException("Category {$validated['category_id']} not found or inactive");
        }

        // Create product (automatically active)
        $model = $this->productsRepository->create([
            'category_id' => $validated['category_id'],
            'names' => $validated['names'],
            'descriptions' => $validated['descriptions'] ?? null,
            'price_cents' => $validated['price_cents'],
            'is_active' => true,
        ]);

        // Log audit entry
        $this->auditService->log(
            action: AuditAction::CREATE,
            entityType: EntityType::PRODUCT,
            entityId: $model->id,
            newValues: [
                'names' => $model->names,
                'descriptions' => $model->descriptions,
                'price_cents' => $model->price_cents,
                'category_id' => $model->category_id,
                'is_active' => $model->is_active,
            ],
        );

        return $this->transform($model);
    }

    /**
     * Update product.
     *
     * Updates any field. Price changes apply only to new transactions.
     * Logs all changes via audit service.
     *
     * @param string $productId
     * @param array $validated Validated request data
     * @return ProductDto Updated product
     * @throws \RuntimeException If product or category not found
     */
    public function updateProduct(string $productId, array $validated): ProductDto
    {
        $model = $this->productsRepository->findById($productId);

        if (!$model) {
            throw new \RuntimeException("Product {$productId} not found");
        }

        // Validate category if changed
        if (isset($validated['category_id']) && $validated['category_id'] !== $model->category_id) {
            $category = $this->categoriesRepository->findById($validated['category_id']);
            if (!$category) {
                throw new \RuntimeException("Category {$validated['category_id']} not found");
            }
        }

        // Prepare changes for audit log
        $changes = [];
        if (isset($validated['names']) && $validated['names'] !== $model->names) {
            $changes['names'] = [
                'old' => $model->names,
                'new' => $validated['names'],
            ];
        }
        if (isset($validated['descriptions']) && $validated['descriptions'] !== ($model->descriptions ?? null)) {
            $changes['descriptions'] = [
                'old' => $model->descriptions,
                'new' => $validated['descriptions'],
            ];
        }
        if (isset($validated['price_cents']) && $validated['price_cents'] !== $model->price_cents) {
            $changes['price_cents'] = [
                'old' => $model->price_cents,
                'new' => $validated['price_cents'],
            ];
        }
        if (isset($validated['category_id']) && $validated['category_id'] !== $model->category_id) {
            $changes['category_id'] = [
                'old' => $model->category_id,
                'new' => $validated['category_id'],
            ];
        }

        // Update model
        $model->update($validated);

        // Log audit entry
        if (!empty($changes)) {
            // Construct oldValues and newValues from changes
            $oldValues = [];
            $newValues = [];
            foreach ($changes as $field => $change) {
                $oldValues[$field] = $change['old'];
                $newValues[$field] = $change['new'];
            }

            $this->auditService->log(
                action: AuditAction::UPDATE,
                entityType: EntityType::PRODUCT,
                entityId: $model->id,
                oldValues: $oldValues,
                newValues: $newValues,
            );
        }

        return $this->transform($model);
    }

    /**
     * Delete product (permanent hard delete).
     *
     * Removes product from database and logs audit entry.
     * Deleted products cannot be recovered.
     *
     * @param string $productId Product UUID
     * @return bool True if deleted, false if not found
     * @throws \RuntimeException If product not found
     */
    public function deleteProduct(string $productId): bool
    {
        $model = $this->productsRepository->findById($productId);

        if (!$model) {
            throw new \RuntimeException("Product {$productId} not found");
        }

        // Capture product data before deletion for audit
        $productData = [
            'names' => $model->names,
            'descriptions' => $model->descriptions,
            'price_cents' => $model->price_cents,
            'category_id' => $model->category_id,
            'is_active' => $model->is_active,
        ];

        // Delete product from database
        $deleted = $this->productsRepository->deleteById($productId);

        // Log audit entry if deletion was successful
        if ($deleted) {
            $this->auditService->log(
                action: AuditAction::DELETE,
                entityType: EntityType::PRODUCT,
                entityId: $productId,
                oldValues: $productData,
            );
        }

        return $deleted;
    }

    /**
     * Toggle product status (active ↔ inactive).
     *
     * Used for quick deactivate/activate from admin list or edit form.
     * Logs status change via audit service.
     *
     * @param string $productId
     * @param bool $isActive New status
     * @return ProductDto Updated product
     * @throws \RuntimeException If product not found
     */
    public function toggleStatus(string $productId, bool $isActive): ProductDto
    {
        $model = $this->productsRepository->findById($productId);

        if (!$model) {
            throw new \RuntimeException("Product {$productId} not found");
        }

        $oldStatus = $model->is_active;
        $model->update(['is_active' => $isActive]);

        // Log audit entry
        $this->auditService->log(
            action: $isActive ? AuditAction::ACTIVATE : AuditAction::DEACTIVATE,
            entityType: EntityType::PRODUCT,
            entityId: $model->id,
            oldValues: ['is_active' => $oldStatus],
            newValues: ['is_active' => $isActive],
        );

        return $this->transform($model);
    }

    /**
     * Transform Product model to DTO.
     *
     * Implements abstract method from BaseService.
     *
     * @param \App\Models\Product $entity
     * @return ProductDto
     */
    protected function transform($entity): ProductDto
    {
        return new ProductDto(
            id: $entity->id,
            names: $entity->names,
            descriptions: $entity->descriptions ?? [],
            priceCents: $entity->price_cents,
            categoryId: $entity->category_id,
            isActive: $entity->is_active,
            createdAt: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $entity->created_at->format('Y-m-d H:i:s')),
            updatedAt: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $entity->updated_at->format('Y-m-d H:i:s')),
        );
    }
}
