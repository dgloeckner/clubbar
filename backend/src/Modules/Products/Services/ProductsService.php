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

class ProductsService
{
    public function __construct(
        private ProductsRepository $productsRepository,
        private CategoriesRepository $categoriesRepository,
        private AuditService $auditService,
    ) {}

    public function syncSince(int $since): SyncResultDto
    {
        $rows = $this->productsRepository->findModifiedSince($since);
        $products = array_map(fn($row) => ProductDto::fromRow($row), $rows);

        $cursor = !empty($rows) ? end($rows)['updated_at'] : date('Y-m-d\TH:i:s\Z');
        return new SyncResultDto(items: $products, cursor: $cursor, hasMore: false);
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
        if (!$category) throw new \RuntimeException('Category not found');
        if (!(bool) $category['is_active']) throw new \RuntimeException('Category is inactive');

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
        if (!$old) throw new \RuntimeException("Product not found: $productId");

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
        if (!$old) throw new \RuntimeException("Product not found: $productId");

        $this->auditService->log(
            action: AuditAction::DELETE,
            entityType: EntityType::PRODUCT,
            entityId: $productId,
            oldValues: ['names' => $old['names']],
            adminUserId: $adminUserId,
        );

        return $this->productsRepository->deleteById($productId);
    }

    public function toggleStatus(string $productId, bool $isActive, ?string $adminUserId = null): ProductDto
    {
        $row = $this->productsRepository->updateById($productId, ['is_active' => $isActive]);
        if (!$row) throw new \RuntimeException("Product not found: $productId");

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
