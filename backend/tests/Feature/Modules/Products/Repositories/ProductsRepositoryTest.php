<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Products\Repositories;

use App\Modules\Products\Repositories\ProductsRepository;
use App\Modules\Products\Repositories\CategoriesRepository;
use Tests\Feature\DatabaseTestCase;

class ProductsRepositoryTest extends DatabaseTestCase
{
    private ProductsRepository $productsRepository;
    private CategoriesRepository $categoriesRepository;
    private array $testProductIds = [];
    private array $testCategoryIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->productsRepository = new ProductsRepository($this->db, $this->logger);
        $this->categoriesRepository = new CategoriesRepository($this->db, $this->logger);
    }

    protected function tearDown(): void
    {
        // Clean up test products and categories
        $this->cleanupTestData('products', $this->testProductIds);
        $this->cleanupTestData('categories', $this->testCategoryIds);
        parent::tearDown();
    }

    public function test_findModifiedSince_accepts_milliseconds_and_converts_correctly(): void
    {
        // Create test category first (products require a category)
        $testCategoryId = $this->generateUuid();
        $testCategory = [
            'id' => $testCategoryId,
            'names' => ['de' => 'Test Kategorie', 'en' => 'Test Category'],
            'display_order' => 999,
            'is_active' => true,
        ];
        $this->testCategoryIds[] = $testCategoryId;
        $this->categoriesRepository->create($testCategory);

        // Create test product with known timestamp
        $testProductId = $this->generateUuid();
        $testProduct = [
            'id' => $testProductId,
            'category_id' => $testCategoryId,
            'names' => ['de' => 'Millisekunden Test', 'en' => 'Milliseconds Test'],
            'descriptions' => ['de' => 'Test Beschreibung', 'en' => 'Test Description'],
            'price_cents' => 250,
            'is_active' => true,
            'icon_name' => 'test',
        ];

        $this->testProductIds[] = $testProductId;
        $this->productsRepository->create($testProduct);

        // Get product's updated_at timestamp
        $product = $this->productsRepository->findById($testProductId);
        $this->assertNotNull($product, 'Test product should be created');

        $updatedAt = new \DateTime($product['updated_at']);

        // Convert to milliseconds and subtract 1 second (to ensure we query before the product's timestamp)
        $sinceMs = ($updatedAt->getTimestamp() - 1) * 1000;

        // Query with milliseconds
        $results = $this->productsRepository->findModifiedSince($sinceMs);

        // Should find the test product (and possibly others created during other tests)
        $found = false;
        foreach ($results as $result) {
            if ($result['id'] === $testProductId) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, "Test product {$testProductId} should be found in results when querying with milliseconds");
    }

    public function test_findModifiedSince_returns_empty_for_timestamp_in_future(): void
    {
        // Create test category first
        $testCategoryId = $this->generateUuid();
        $testCategory = [
            'id' => $testCategoryId,
            'names' => ['de' => 'Zukunft Kategorie', 'en' => 'Future Category'],
            'display_order' => 998,
            'is_active' => true,
        ];
        $this->testCategoryIds[] = $testCategoryId;
        $this->categoriesRepository->create($testCategory);

        // Create test product
        $testProductId = $this->generateUuid();
        $testProduct = [
            'id' => $testProductId,
            'category_id' => $testCategoryId,
            'names' => ['de' => 'Zukunft Test', 'en' => 'Future Test'],
            'price_cents' => 300,
            'is_active' => true,
        ];

        $this->testProductIds[] = $testProductId;
        $this->productsRepository->create($testProduct);

        // Query with timestamp 1 day in the future (in milliseconds)
        $futureMs = (time() + 86400) * 1000;
        $results = $this->productsRepository->findModifiedSince($futureMs);

        // Should not find the test product (created now, but query is for future)
        $found = false;
        foreach ($results as $result) {
            if ($result['id'] === $testProductId) {
                $found = true;
                break;
            }
        }

        $this->assertFalse($found, "Test product should not be found when querying with future timestamp");
    }

    public function test_findModifiedSince_does_not_return_year_57123_bug(): void
    {
        // This test verifies Bug #2 from timestamp protocol analysis is fixed.
        // Before fix: passing milliseconds to date() causes year 57123 (interprets ms as seconds)
        // After fix: milliseconds are divided by 1000 before date(), producing correct year

        // Create test category first
        $testCategoryId = $this->generateUuid();
        $testCategory = [
            'id' => $testCategoryId,
            'names' => ['de' => 'Bug Kategorie', 'en' => 'Bug Category'],
            'display_order' => 997,
            'is_active' => true,
        ];
        $this->testCategoryIds[] = $testCategoryId;
        $this->categoriesRepository->create($testCategory);

        // Create test product
        $testProductId = $this->generateUuid();
        $testProduct = [
            'id' => $testProductId,
            'category_id' => $testCategoryId,
            'names' => ['de' => 'Bug Test', 'en' => 'Bug Test'],
            'price_cents' => 150,
            'is_active' => true,
        ];

        $this->testProductIds[] = $testProductId;
        $this->productsRepository->create($testProduct);

        // Get product timestamp
        $product = $this->productsRepository->findById($testProductId);
        $this->assertNotNull($product);

        $updatedAt = new \DateTime($product['updated_at']);
        $sinceMs = ($updatedAt->getTimestamp() - 1) * 1000;

        // Query with milliseconds
        $results = $this->productsRepository->findModifiedSince($sinceMs);

        // If bug exists, date() would generate '57123-06-16 00:13:20' (from treating 1738872000000 as seconds)
        // This would never match any real records, so results would be empty
        // After fix, we should find our product

        $found = false;
        foreach ($results as $result) {
            if ($result['id'] === $testProductId) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, "Bug #2: Product should be found after milliseconds-to-seconds conversion fix (year 57123 bug)");
    }
}
