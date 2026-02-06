<?php

declare(strict_types=1);

namespace App\Modules\Products\Services;

use App\Modules\Products\DTOs\CategoryDto;
use App\Shared\DTOs\SyncResultDto;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Exceptions\NotFoundException;
use App\Modules\Products\Repositories\CategoriesRepository;
use App\Modules\Products\Repositories\ProductsRepository;
use App\Shared\Services\AuditService;

class CategoriesService
{
    public function __construct(
        private CategoriesRepository $categoriesRepository,
        private ProductsRepository $productsRepository,
        private AuditService $auditService,
    ) {}

    public function syncSince(int $since): SyncResultDto
    {
        $rows = $this->categoriesRepository->findModifiedSince($since);
        $categories = array_map(fn($row) => CategoryDto::fromRow($row), $rows);

        $cursor = !empty($rows)
            ? SyncResultDto::dateToTimestamp(end($rows)['updated_at'])
            : time();
        return new SyncResultDto(items: $categories, cursor: $cursor, hasMore: false);
    }

    public function listCategories(): array
    {
        $rows = $this->categoriesRepository->getWithProductCount();
        return array_map(fn($row) => CategoryDto::fromRow($row)->toArray(), $rows);
    }

    public function createCategory(array $validated, ?string $adminUserId = null): CategoryDto
    {
        $row = $this->categoriesRepository->create([
            'names' => $validated['names'],
            'is_active' => $validated['is_active'] ?? true,
            'icon_name' => $validated['icon_name'] ?? null,
        ]);

        $this->auditService->log(
            action: AuditAction::CREATE,
            entityType: EntityType::CATEGORY,
            entityId: $row['id'],
            newValues: ['names' => $validated['names']],
            adminUserId: $adminUserId,
        );

        return CategoryDto::fromRow($row);
    }

    public function updateCategory(string $categoryId, array $validated, ?string $adminUserId = null): CategoryDto
    {
        $old = $this->categoriesRepository->findById($categoryId);
        if (!$old) throw NotFoundException::forResource('Category', $categoryId);

        $row = $this->categoriesRepository->updateById($categoryId, $validated);

        $this->auditService->log(
            action: AuditAction::UPDATE,
            entityType: EntityType::CATEGORY,
            entityId: $categoryId,
            oldValues: ['names' => $old['names']],
            newValues: ['names' => $row['names']],
            adminUserId: $adminUserId,
        );

        return CategoryDto::fromRow($row);
    }

    public function toggleStatus(string $categoryId, bool $isActive, ?string $adminUserId = null): CategoryDto
    {
        $row = $this->categoriesRepository->updateById($categoryId, ['is_active' => $isActive]);
        if (!$row) throw NotFoundException::forResource('Category', $categoryId);

        $this->auditService->log(
            action: $isActive ? AuditAction::ACTIVATE : AuditAction::DEACTIVATE,
            entityType: EntityType::CATEGORY,
            entityId: $categoryId,
            newValues: ['is_active' => $isActive],
            adminUserId: $adminUserId,
        );

        return CategoryDto::fromRow($row);
    }

    public function deleteCategory(string $categoryId, ?string $adminUserId = null): bool
    {
        if ($this->categoriesRepository->hasProducts($categoryId)) {
            throw new \RuntimeException('Cannot delete category with products');
        }

        $old = $this->categoriesRepository->findById($categoryId);
        if (!$old) throw NotFoundException::forResource('Category', $categoryId);

        $this->auditService->log(
            action: AuditAction::DELETE,
            entityType: EntityType::CATEGORY,
            entityId: $categoryId,
            oldValues: ['names' => $old['names']],
            adminUserId: $adminUserId,
        );

        return $this->categoriesRepository->deleteById($categoryId);
    }

    public function reorderCategories(array $categoryIds, ?string $adminUserId = null): void
    {
        $this->categoriesRepository->reorder($categoryIds);

        $this->auditService->log(
            action: AuditAction::REORDER,
            entityType: EntityType::CATEGORY,
            entityId: 'batch',
            newValues: ['order' => $categoryIds],
            adminUserId: $adminUserId,
        );
    }
}
