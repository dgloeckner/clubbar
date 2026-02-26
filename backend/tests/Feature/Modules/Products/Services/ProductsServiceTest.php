<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Products\Services;

use App\Modules\Products\Services\ProductsService;
use App\Modules\Products\Repositories\ProductsRepository;
use App\Modules\Products\Repositories\CategoriesRepository;
use App\Shared\Services\AuditService;
use App\Modules\AuditLog\Repositories\AuditLogRepository;
use Tests\Feature\DatabaseTestCase;

class ProductsServiceTest extends DatabaseTestCase
{
    private ProductsService $productsService;
    private ProductsRepository $productsRepository;
    private CategoriesRepository $categoriesRepository;
    private AuditService $auditService;
    private AuditLogRepository $auditLogRepository;
    private array $testProductIds = [];
    private array $testCategoryIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->productsRepository = new ProductsRepository($this->db, $this->logger);
        $this->categoriesRepository = new CategoriesRepository($this->db, $this->logger);
        $this->auditLogRepository = new AuditLogRepository($this->db, $this->logger);
        $this->auditService = new AuditService($this->auditLogRepository);
        $this->productsService = new ProductsService(
            $this->productsRepository,
            $this->categoriesRepository,
            $this->auditService
        );
    }

    protected function tearDown(): void
    {
        // Clean up test products and categories
        $this->cleanupTestData('products', $this->testProductIds);
        $this->cleanupTestData('categories', $this->testCategoryIds);
        parent::tearDown();
    }

    public function test_create_product_with_requires_dispenser_flag_true(): void
    {
        // Create test category first
        $testCategoryId = $this->generateUuid();
        $testCategory = [
            'id' => $testCategoryId,
            'names' => ['de' => 'Test Kategorie', 'en' => 'Test Category'],
            'is_active' => true,
        ];
        $this->testCategoryIds[] = $testCategoryId;
        $this->categoriesRepository->create($testCategory);

        // Create product with requires_dispenser = true
        $data = [
            'category_id' => $testCategoryId,
            'names' => ['de' => 'Sauna-Token', 'en' => 'Sauna Token'],
            'descriptions' => ['de' => '1 Token für Sauna', 'en' => '1 token for sauna'],
            'price_cents' => 300,
            'is_active' => true,
            'requires_dispenser' => true, // NEW FIELD
        ];

        $product = $this->productsService->createProduct($data);
        $this->testProductIds[] = $product->id;

        // Assert the field was saved correctly
        $this->assertTrue($product->requiresDispenser, 'Product should have requiresDispenser = true');

        // Verify in database
        $row = $this->productsRepository->findById($product->id);
        $this->assertNotNull($row);
        $this->assertEquals(1, $row['requires_dispenser'], 'Database should store requires_dispenser as 1 (true)');
    }

    public function test_create_product_with_requires_dispenser_flag_false(): void
    {
        // Create test category first
        $testCategoryId = $this->generateUuid();
        $testCategory = [
            'id' => $testCategoryId,
            'names' => ['de' => 'Test Kategorie', 'en' => 'Test Category'],
            'is_active' => true,
        ];
        $this->testCategoryIds[] = $testCategoryId;
        $this->categoriesRepository->create($testCategory);

        // Create product with requires_dispenser = false
        $data = [
            'category_id' => $testCategoryId,
            'names' => ['de' => 'Bier', 'en' => 'Beer'],
            'price_cents' => 250,
            'is_active' => true,
            'requires_dispenser' => false,
        ];

        $product = $this->productsService->createProduct($data);
        $this->testProductIds[] = $product->id;

        $this->assertFalse($product->requiresDispenser, 'Product should have requiresDispenser = false');

        // Verify in database
        $row = $this->productsRepository->findById($product->id);
        $this->assertNotNull($row);
        $this->assertEquals(0, $row['requires_dispenser'], 'Database should store requires_dispenser as 0 (false)');
    }

    public function test_create_product_defaults_requires_dispenser_to_false_when_not_provided(): void
    {
        // Create test category first
        $testCategoryId = $this->generateUuid();
        $testCategory = [
            'id' => $testCategoryId,
            'names' => ['de' => 'Test Kategorie', 'en' => 'Test Category'],
            'is_active' => true,
        ];
        $this->testCategoryIds[] = $testCategoryId;
        $this->categoriesRepository->create($testCategory);

        // Create product WITHOUT requires_dispenser field
        $data = [
            'category_id' => $testCategoryId,
            'names' => ['de' => 'Wasser', 'en' => 'Water'],
            'price_cents' => 150,
            'is_active' => true,
            // requires_dispenser NOT provided
        ];

        $product = $this->productsService->createProduct($data);
        $this->testProductIds[] = $product->id;

        $this->assertFalse($product->requiresDispenser, 'Product should default requiresDispenser to false');

        // Verify in database
        $row = $this->productsRepository->findById($product->id);
        $this->assertNotNull($row);
        $this->assertEquals(0, $row['requires_dispenser'], 'Database should default requires_dispenser to 0 (false)');
    }

    public function test_update_product_can_set_requires_dispenser_to_true(): void
    {
        // Create test category and product
        $testCategoryId = $this->generateUuid();
        $testCategory = [
            'id' => $testCategoryId,
            'names' => ['de' => 'Test Kategorie', 'en' => 'Test Category'],
            'is_active' => true,
        ];
        $this->testCategoryIds[] = $testCategoryId;
        $this->categoriesRepository->create($testCategory);

        $testProductId = $this->generateUuid();
        $testProduct = [
            'id' => $testProductId,
            'category_id' => $testCategoryId,
            'names' => ['de' => 'Original', 'en' => 'Original'],
            'price_cents' => 200,
            'is_active' => true,
            'requires_dispenser' => false,
        ];
        $this->testProductIds[] = $testProductId;
        $this->productsRepository->create($testProduct);

        // Update to set requires_dispenser = true
        $updateData = [
            'requires_dispenser' => true,
        ];

        $updatedProduct = $this->productsService->updateProduct($testProductId, $updateData);

        $this->assertTrue($updatedProduct->requiresDispenser, 'Updated product should have requiresDispenser = true');

        // Verify in database
        $row = $this->productsRepository->findById($testProductId);
        $this->assertEquals(1, $row['requires_dispenser']);
    }

    public function test_update_product_can_set_requires_dispenser_to_false(): void
    {
        // Create test category and product
        $testCategoryId = $this->generateUuid();
        $testCategory = [
            'id' => $testCategoryId,
            'names' => ['de' => 'Test Kategorie', 'en' => 'Test Category'],
            'is_active' => true,
        ];
        $this->testCategoryIds[] = $testCategoryId;
        $this->categoriesRepository->create($testCategory);

        $testProductId = $this->generateUuid();
        $testProduct = [
            'id' => $testProductId,
            'category_id' => $testCategoryId,
            'names' => ['de' => 'Original', 'en' => 'Original'],
            'price_cents' => 200,
            'is_active' => true,
            'requires_dispenser' => true, // Start with true
        ];
        $this->testProductIds[] = $testProductId;
        $this->productsRepository->create($testProduct);

        // Update to set requires_dispenser = false
        $updateData = [
            'requires_dispenser' => false,
        ];

        $updatedProduct = $this->productsService->updateProduct($testProductId, $updateData);

        $this->assertFalse($updatedProduct->requiresDispenser, 'Updated product should have requiresDispenser = false');

        // Verify in database
        $row = $this->productsRepository->findById($testProductId);
        $this->assertEquals(0, $row['requires_dispenser']);
    }

    public function test_update_product_preserves_requires_dispenser_when_not_provided(): void
    {
        // Create test category and product
        $testCategoryId = $this->generateUuid();
        $testCategory = [
            'id' => $testCategoryId,
            'names' => ['de' => 'Test Kategorie', 'en' => 'Test Category'],
            'is_active' => true,
        ];
        $this->testCategoryIds[] = $testCategoryId;
        $this->categoriesRepository->create($testCategory);

        $testProductId = $this->generateUuid();
        $testProduct = [
            'id' => $testProductId,
            'category_id' => $testCategoryId,
            'names' => ['de' => 'Original', 'en' => 'Original'],
            'price_cents' => 200,
            'is_active' => true,
            'requires_dispenser' => true,
        ];
        $this->testProductIds[] = $testProductId;
        $this->productsRepository->create($testProduct);

        // Update other fields, but NOT requires_dispenser
        $updateData = [
            'price_cents' => 250,
        ];

        $updatedProduct = $this->productsService->updateProduct($testProductId, $updateData);

        // Should preserve the original value (true)
        $this->assertTrue($updatedProduct->requiresDispenser, 'Updated product should preserve requiresDispenser = true');

        // Verify in database
        $row = $this->productsRepository->findById($testProductId);
        $this->assertEquals(1, $row['requires_dispenser']);
    }
}
