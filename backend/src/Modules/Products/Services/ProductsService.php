<?php

declare(strict_types=1);

namespace App\Modules\Products\Services;

use App\Modules\Products\DTOs\ProductDto;
use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\DTOs\SyncResultDto;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Modules\Products\Repositories\ProductsRepository;
use App\Modules\Products\Repositories\CategoriesRepository;
use App\Modules\Products\Validators\IconNameValidator;
use App\Shared\Services\AuditService;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\BusinessRuleReason;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Sync\SyncCursor;

class ProductsService
{
    public function __construct(
        private ProductsRepository $productsRepository,
        private CategoriesRepository $categoriesRepository,
        private AuditService $auditService,
    ) {}

    public function syncSince(int $since): SyncResultDto
    {
        // Taken before the query, not after: it decides whether the newest
        // second the query saw was already complete when the query ran (#84).
        $queriedAt = time();
        $rows = $this->productsRepository->findModifiedSince($since);
        $products = array_map(fn($row) => ProductDto::fromRow($row), $rows);

        return new SyncResultDto(
            items: $products,
            cursor: SyncCursor::next($rows, $since, $queriedAt),
        );
    }


    public function listProducts(int $limit, int $offset, array $filters = [], string $sortBy = 'created_at', string $sortOrder = 'desc'): PaginatedResultDto
    {
        $result = $this->productsRepository->listPaginated($limit, $offset, $filters, $sortBy, $sortOrder);
        $items = array_map(fn($row) => ProductDto::fromRow($row)->toArray(), $result['items']);

        return new PaginatedResultDto(items: $items, total: $result['total'], limit: $limit, offset: $offset);
    }

    public function createProduct(array $validated, ?string $adminUserId = null): ProductDto
    {
        $category = $this->categoriesRepository->findById($validated['category_id']);
        if (!$category) throw new ValidationException('Invalid category_id', ['category_id' => ['Category not found']]);
        if (!(bool) $category['is_active']) {
            throw new BusinessRuleException(BusinessRuleReason::CATEGORY_INACTIVE, 'Category is inactive');
        }

        // Validate icon name against canonical registry (docs/icon-registry.md)
        if (isset($validated['icon_name'])) {
            try {
                IconNameValidator::validate($validated['icon_name']);
            } catch (\InvalidArgumentException $e) {
                throw new ValidationException($e->getMessage(), ['icon_name' => [$e->getMessage()]]);
            }
        }

        $row = $this->productsRepository->create($validated);

        $this->auditService->log(
            action: AuditAction::CREATE,
            entityType: EntityType::PRODUCT,
            entityId: $row['id'],
            newValues: ['names' => $validated['names'], 'price_cents' => $validated['price_cents']],
            adminUserId: $adminUserId,
        );

        return ProductDto::fromRow($row);
    }

    public function updateProduct(string $productId, array $validated, ?string $adminUserId = null): ProductDto
    {
        $old = $this->productsRepository->findById($productId);
        if (!$old) throw NotFoundException::forResource('Product', $productId);

        // Validate category_id if provided
        if (isset($validated['category_id'])) {
            $category = $this->categoriesRepository->findById($validated['category_id']);
            if (!$category) throw NotFoundException::forResource('Category', $validated['category_id']);
            if (!(bool) $category['is_active']) {
            throw new BusinessRuleException(BusinessRuleReason::CATEGORY_INACTIVE, 'Category is inactive');
        }
        }

        // Validate icon name against canonical registry (docs/icon-registry.md)
        if (isset($validated['icon_name'])) {
            try {
                IconNameValidator::validate($validated['icon_name']);
            } catch (\InvalidArgumentException $e) {
                throw new ValidationException($e->getMessage(), ['icon_name' => [$e->getMessage()]]);
            }
        }

        $row = $this->productsRepository->updateById($productId, $validated);

        $this->auditService->log(
            action: AuditAction::UPDATE,
            entityType: EntityType::PRODUCT,
            entityId: $productId,
            oldValues: ['names' => $old['names'], 'price_cents' => $old['price_cents']],
            newValues: ['names' => $row['names'], 'price_cents' => $row['price_cents']],
            adminUserId: $adminUserId,
        );

        return ProductDto::fromRow($row);
    }

    public function deleteProduct(string $productId, ?string $adminUserId = null): bool
    {
        $old = $this->productsRepository->findById($productId);
        if (!$old) throw NotFoundException::forResource('Product', $productId);

        // Soft delete: set deleted_at timestamp
        $this->productsRepository->updateById($productId, [
            'deleted_at' => date('Y-m-d H:i:s'),
            'deleted_by_admin_id' => $adminUserId,
        ]);

        $this->auditService->log(
            action: AuditAction::DELETE,
            entityType: EntityType::PRODUCT,
            entityId: $productId,
            oldValues: ['names' => $old['names']],
            adminUserId: $adminUserId,
        );

        return true;
    }

    public function toggleStatus(string $productId, bool $isActive, ?string $adminUserId = null): ProductDto
    {
        $row = $this->productsRepository->updateById($productId, ['is_active' => $isActive]);
        if (!$row) throw NotFoundException::forResource('Product', $productId);

        $this->auditService->log(
            action: $isActive ? AuditAction::ACTIVATE : AuditAction::DEACTIVATE,
            entityType: EntityType::PRODUCT,
            entityId: $productId,
            newValues: ['is_active' => $isActive],
            adminUserId: $adminUserId,
        );

        return ProductDto::fromRow($row);
    }
}
