<?php

namespace App\Http\Modules\Products\Services;

use App\DTOs\CategoryDto;
use App\DTOs\SyncResultDto;
use App\Http\Modules\Products\Repositories\CategoriesRepository;
use App\Http\Modules\Products\Repositories\ProductsRepository;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Services\AuditService;
use App\Shared\Services\BaseService;
use DateTimeImmutable;

/**
 * CategoriesService - Business Logic for Categories
 *
 * Handles category operations:
 * - Terminal API: delta sync, product category metadata
 * - Admin API: CRUD operations with audit logging
 *
 * Implements Pattern 004: Service Layer
 * Implements Pattern 008: Service Provider Bindings
 * Implements Pattern 016: Audit Logging for all changes
 *
 * Key Methods:
 * - syncSince(): Terminal delta sync
 * - listCategories(): Admin listing with product count
 * - createCategory(): Create with audit log
 * - updateCategory(): Update with audit log
 * - toggleStatus(): Quick status toggle
 * - deleteCategory(): Delete if empty
 * - reorderCategories(): Update display order
 */
class CategoriesService extends BaseService
{
    /**
     * Initialize service with repositories and audit service.
     *
     * @param CategoriesRepository $categoriesRepository
     * @param ProductsRepository $productsRepository
     * @param AuditService $auditService
     */
    public function __construct(
        private readonly CategoriesRepository $categoriesRepository,
        private readonly ProductsRepository $productsRepository,
        private readonly AuditService $auditService,
    ) {
        parent::__construct($categoriesRepository);
    }

    /**
     * Get categories modified since timestamp (Terminal API).
     *
     * Returns delta sync result for /api/sync/categories endpoint.
     * Only includes active categories, sorted by display_order.
     *
     * @param int $since Unix timestamp (from query param)
     * @return SyncResultDto Contains categories[], cursor, hasMore
     */
    public function syncSince(int $since): SyncResultDto
    {
        // Query database for categories modified since timestamp
        $categoryModels = $this->categoriesRepository->findModifiedSince($since);

        // Transform models to DTOs
        $categories = $categoryModels->map(fn($model) => $this->transform($model))->values();

        // Get latest timestamp for cursor (for next sync)
        $cursor = $categoryModels->isNotEmpty()
            ? $categoryModels->last()->updated_at->format('Y-m-d\TH:i:s\Z')
            : date('Y-m-d\TH:i:s\Z');

        return new SyncResultDto(
            items: $categories->all(),
            cursor: $cursor,
            hasMore: false,
        );
    }

    /**
     * List all categories for admin (with product count).
     *
     * Used for admin category management page.
     * Includes both active and inactive categories.
     *
     * @return array Categories with product_count
     */
    public function listCategories(): array
    {
        $categories = $this->categoriesRepository->getWithProductCount();

        return $categories->map(function ($model) {
            $dto = $this->transform($model);
            // Add product count from relationship
            return array_merge($dto->toArray(), [
                'product_count' => $model->products_count ?? 0,
            ]);
        })->toArray();
    }

    /**
     * Create new category.
     *
     * Validates names, auto-assigns display_order, logs audit entry.
     *
     * @param array $validated Validated request data: names
     * @return CategoryDto Created category
     */
    public function createCategory(array $validated): CategoryDto
    {
        // Auto-assign next display_order
        $displayOrder = $this->categoriesRepository->getNextDisplayOrder();

        // Create category
        $model = $this->categoriesRepository->create([
            'names' => $validated['names'],
            'display_order' => $displayOrder,
            'is_active' => true,
            'icon_name' => $validated['icon_name'] ?? null,
        ]);

        // Log audit entry
        $this->auditService->log(
            action: AuditAction::CREATE,
            entityType: EntityType::CATEGORY,
            entityId: $model->id,
            newValues: [
                'names' => $model->names,
                'display_order' => $model->display_order,
                'is_active' => $model->is_active,
                'icon_name' => $model->icon_name,
            ],
        );

        return $this->transform($model);
    }

    /**
     * Update category (names and/or display_order).
     *
     * Logs changes via audit service.
     * If display_order changes, reorders affected categories.
     *
     * @param string $categoryId
     * @param array $validated Validated request data: names, display_order
     * @return CategoryDto Updated category
     * @throws \RuntimeException If category not found
     */
    public function updateCategory(string $categoryId, array $validated): CategoryDto
    {
        $model = $this->categoriesRepository->findById($categoryId);

        if (!$model) {
            throw new \RuntimeException("Category {$categoryId} not found");
        }

        // Prepare changes
        $changes = [];
        if (isset($validated['names']) && $validated['names'] !== $model->names) {
            $changes['names'] = [
                'old' => $model->names,
                'new' => $validated['names'],
            ];
        }

        if (isset($validated['display_order']) && $validated['display_order'] !== $model->display_order) {
            $changes['display_order'] = [
                'old' => $model->display_order,
                'new' => $validated['display_order'],
            ];
        }

        if (isset($validated['icon_name']) && $validated['icon_name'] !== ($model->icon_name ?? null)) {
            $changes['icon_name'] = [
                'old' => $model->icon_name,
                'new' => $validated['icon_name'],
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
                entityType: EntityType::CATEGORY,
                entityId: $model->id,
                oldValues: $oldValues,
                newValues: $newValues,
            );
        }

        return $this->transform($model);
    }

    /**
     * Toggle category status (active ↔ inactive).
     *
     * Used for quick deactivate/activate from admin list.
     * Logs status change via audit service.
     *
     * @param string $categoryId
     * @param bool $isActive New status
     * @return CategoryDto Updated category
     * @throws \RuntimeException If category not found
     */
    public function toggleStatus(string $categoryId, bool $isActive): CategoryDto
    {
        $model = $this->categoriesRepository->findById($categoryId);

        if (!$model) {
            throw new \RuntimeException("Category {$categoryId} not found");
        }

        $oldStatus = $model->is_active;
        $model->update(['is_active' => $isActive]);

        // Log audit entry
        $this->auditService->log(
            action: $isActive ? AuditAction::ACTIVATE : AuditAction::DEACTIVATE,
            entityType: EntityType::CATEGORY,
            entityId: $model->id,
            oldValues: ['is_active' => $oldStatus],
            newValues: ['is_active' => $isActive],
        );

        return $this->transform($model);
    }

    /**
     * Delete category if it has no products.
     *
     * Prevents deletion of categories with products.
     * Logs deletion via audit service.
     *
     * @param string $categoryId
     * @return bool Success
     * @throws \RuntimeException If category has products
     */
    public function deleteCategory(string $categoryId): bool
    {
        if ($this->categoriesRepository->hasProducts($categoryId)) {
            throw new \RuntimeException("Cannot delete category with products");
        }

        $model = $this->categoriesRepository->findById($categoryId);

        if (!$model) {
            return false;
        }

        // Log deletion before deleting
        $this->auditService->log(
            action: AuditAction::DELETE,
            entityType: EntityType::CATEGORY,
            entityId: $model->id,
            oldValues: [
                'names' => $model->names,
                'display_order' => $model->display_order,
                'is_active' => $model->is_active,
                'icon_name' => $model->icon_name,
            ],
        );

        return $this->categoriesRepository->deleteById($categoryId);
    }

    /**
     * Reorder categories (drag-and-drop).
     *
     * Updates display_order for all provided categories.
     * Assumes array is in desired order.
     *
     * @param array $categoryIds Category IDs in desired order
     * @return void
     */
    public function reorderCategories(array $categoryIds): void
    {
        $this->categoriesRepository->reorder($categoryIds);

        // Log reorder action
        $this->auditService->log(
            action: AuditAction::REORDER,
            entityType: EntityType::CATEGORY,
            entityId: 'batch',
            newValues: [
                'new_order' => $categoryIds,
            ],
        );
    }

    /**
     * Transform Category model to DTO.
     *
     * Implements abstract method from BaseService.
     *
     * @param \App\Models\Category $entity
     * @return CategoryDto
     */
    protected function transform($entity): CategoryDto
    {
        return new CategoryDto(
            id: $entity->id,
            names: $entity->names,
            displayOrder: $entity->display_order,
            isActive: $entity->is_active,
            createdAt: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $entity->created_at->format('Y-m-d H:i:s')),
            updatedAt: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $entity->updated_at->format('Y-m-d H:i:s')),
            iconName: $entity->icon_name,
        );
    }
}
