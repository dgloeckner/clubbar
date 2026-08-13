<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Products\Services;

use App\Modules\Products\Services\CategoriesService;
use App\Modules\Products\Repositories\CategoriesRepository;
use App\Modules\Products\Repositories\ProductsRepository;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Services\AuditService;
use App\Modules\AuditLog\Repositories\AuditLogRepository;
use Tests\Feature\DatabaseTestCase;

class CategoriesServiceTest extends DatabaseTestCase
{
    private CategoriesService $categoriesService;
    private CategoriesRepository $categoriesRepository;
    private ProductsRepository $productsRepository;
    private AuditService $auditService;
    private AuditLogRepository $auditLogRepository;
    private array $testCategoryIds = [];
    private array $testProductIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->categoriesRepository = new CategoriesRepository($this->db, $this->logger);
        $this->productsRepository = new ProductsRepository($this->db, $this->logger);
        $this->auditLogRepository = new AuditLogRepository($this->db, $this->logger);
        $this->auditService = new AuditService($this->auditLogRepository);
        $this->categoriesService = new CategoriesService(
            $this->categoriesRepository,
            $this->auditService
        );
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData('products', $this->testProductIds);
        $this->cleanupTestData('categories', $this->testCategoryIds);
        parent::tearDown();
    }

    public function test_deleteCategory_succeeds_once_its_products_are_soft_deleted(): void
    {
        // #367: a category whose products were all soft-deleted was
        // permanently undeletable because hasProducts() ignored deleted_at.
        $testCategoryId = $this->generateUuid();
        $this->testCategoryIds[] = $testCategoryId;
        $this->categoriesRepository->create([
            'id' => $testCategoryId,
            'names' => ['de' => 'Kategorie', 'en' => 'Category'],
            'is_active' => true,
        ]);

        $testProductId = $this->generateUuid();
        $this->testProductIds[] = $testProductId;
        $this->productsRepository->create([
            'id' => $testProductId,
            'category_id' => $testCategoryId,
            'names' => ['de' => 'Produkt', 'en' => 'Product'],
            'price_cents' => 100,
            'is_active' => true,
        ]);

        // Soft-delete the only product in the category
        $this->productsRepository->updateById($testProductId, ['deleted_at' => date('Y-m-d H:i:s')]);

        $result = $this->categoriesService->deleteCategory($testCategoryId);

        $this->assertTrue($result);

        $row = $this->db->prepare('SELECT deleted_at FROM categories WHERE id = ?');
        $row->execute([$testCategoryId]);
        $deletedAt = $row->fetchColumn();
        $this->assertNotNull($deletedAt, 'Category should be soft-deleted');
    }

    public function test_deleteCategory_still_throws_when_category_has_active_products(): void
    {
        $testCategoryId = $this->generateUuid();
        $this->testCategoryIds[] = $testCategoryId;
        $this->categoriesRepository->create([
            'id' => $testCategoryId,
            'names' => ['de' => 'Kategorie', 'en' => 'Category'],
            'is_active' => true,
        ]);

        $testProductId = $this->generateUuid();
        $this->testProductIds[] = $testProductId;
        $this->productsRepository->create([
            'id' => $testProductId,
            'category_id' => $testCategoryId,
            'names' => ['de' => 'Produkt', 'en' => 'Product'],
            'price_cents' => 100,
            'is_active' => true,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot delete category with products');

        $this->categoriesService->deleteCategory($testCategoryId);
    }

    public function test_deleteCategory_throws_NotFound_when_already_deleted(): void
    {
        // #416: findById() ignoring deleted_at made an already-deleted
        // category re-deletable through the admin API.
        $testCategoryId = $this->generateUuid();
        $this->testCategoryIds[] = $testCategoryId;
        $this->categoriesRepository->create([
            'id' => $testCategoryId,
            'names' => ['de' => 'Kategorie', 'en' => 'Category'],
            'is_active' => true,
        ]);

        $this->categoriesService->deleteCategory($testCategoryId);

        $this->expectException(NotFoundException::class);
        $this->categoriesService->deleteCategory($testCategoryId);
    }

    public function test_listCategories_excludes_soft_deleted_categories(): void
    {
        $testCategoryId = $this->generateUuid();
        $this->testCategoryIds[] = $testCategoryId;
        $this->categoriesRepository->create([
            'id' => $testCategoryId,
            'names' => ['de' => 'Sichtbare Kategorie', 'en' => 'Visible Category'],
            'is_active' => true,
        ]);

        $this->categoriesService->deleteCategory($testCategoryId);

        $categories = $this->categoriesService->listCategories();

        $this->assertNotContains(
            $testCategoryId,
            array_column($categories, 'id'),
            'Deleted category should disappear from the admin category list'
        );
    }

    public function test_listCategories_product_count_excludes_deleted_products(): void
    {
        $testCategoryId = $this->generateUuid();
        $this->testCategoryIds[] = $testCategoryId;
        $this->categoriesRepository->create([
            'id' => $testCategoryId,
            'names' => ['de' => 'Zählkategorie', 'en' => 'Count Category'],
            'is_active' => true,
        ]);

        $testProductId = $this->generateUuid();
        $this->testProductIds[] = $testProductId;
        $this->productsRepository->create([
            'id' => $testProductId,
            'category_id' => $testCategoryId,
            'names' => ['de' => 'Produkt', 'en' => 'Product'],
            'price_cents' => 100,
            'is_active' => true,
        ]);

        $this->productsRepository->updateById($testProductId, ['deleted_at' => date('Y-m-d H:i:s')]);

        $categories = $this->categoriesService->listCategories();
        $category = null;
        foreach ($categories as $candidate) {
            if ($candidate['id'] === $testCategoryId) {
                $category = $candidate;
                break;
            }
        }

        $this->assertNotNull($category, 'Category should still be listed');
        $this->assertSame(0, $category['product_count']);
    }
}
