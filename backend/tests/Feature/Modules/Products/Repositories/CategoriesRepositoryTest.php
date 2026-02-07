<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Products\Repositories;

use App\Modules\Products\Repositories\CategoriesRepository;
use Tests\Feature\DatabaseTestCase;

class CategoriesRepositoryTest extends DatabaseTestCase
{
    private CategoriesRepository $categoriesRepository;
    private array $testCategoryIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->categoriesRepository = new CategoriesRepository($this->db, $this->logger);
    }

    protected function tearDown(): void
    {
        // Clean up test categories
        $this->cleanupTestData('categories', $this->testCategoryIds);
        parent::tearDown();
    }

    public function test_findModifiedSince_accepts_milliseconds_and_converts_correctly(): void
    {
        // Create test category with known timestamp
        $testCategoryId = $this->generateUuid();
        $testCategory = [
            'id' => $testCategoryId,
            'names' => ['de' => 'Millisekunden Test', 'en' => 'Milliseconds Test'],
            'display_order' => 999,
            'is_active' => true,
            'icon_name' => 'test',
        ];

        $this->testCategoryIds[] = $testCategoryId;
        $this->categoriesRepository->create($testCategory);

        // Get category's updated_at timestamp
        $category = $this->categoriesRepository->findById($testCategoryId);
        $this->assertNotNull($category, 'Test category should be created');

        $updatedAt = new \DateTime($category['updated_at']);

        // Convert to milliseconds and subtract 1 second (to ensure we query before the category's timestamp)
        $sinceMs = ($updatedAt->getTimestamp() - 1) * 1000;

        // Query with milliseconds
        $results = $this->categoriesRepository->findModifiedSince($sinceMs);

        // Should find the test category (and possibly others created during other tests)
        $found = false;
        foreach ($results as $result) {
            if ($result['id'] === $testCategoryId) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, "Test category {$testCategoryId} should be found in results when querying with milliseconds");
    }

    public function test_findModifiedSince_returns_empty_for_timestamp_in_future(): void
    {
        // Create test category
        $testCategoryId = $this->generateUuid();
        $testCategory = [
            'id' => $testCategoryId,
            'names' => ['de' => 'Zukunft Test', 'en' => 'Future Test'],
            'display_order' => 998,
            'is_active' => true,
        ];

        $this->testCategoryIds[] = $testCategoryId;
        $this->categoriesRepository->create($testCategory);

        // Query with timestamp 1 day in the future (in milliseconds)
        $futureMs = (time() + 86400) * 1000;
        $results = $this->categoriesRepository->findModifiedSince($futureMs);

        // Should not find the test category (created now, but query is for future)
        $found = false;
        foreach ($results as $result) {
            if ($result['id'] === $testCategoryId) {
                $found = true;
                break;
            }
        }

        $this->assertFalse($found, "Test category should not be found when querying with future timestamp");
    }

    public function test_findModifiedSince_does_not_return_year_57123_bug(): void
    {
        // This test verifies Bug #2 from timestamp protocol analysis is fixed.
        // Before fix: passing milliseconds to date() causes year 57123 (interprets ms as seconds)
        // After fix: milliseconds are divided by 1000 before date(), producing correct year

        // Create test category
        $testCategoryId = $this->generateUuid();
        $testCategory = [
            'id' => $testCategoryId,
            'names' => ['de' => 'Bug Test', 'en' => 'Bug Test'],
            'display_order' => 997,
            'is_active' => true,
        ];

        $this->testCategoryIds[] = $testCategoryId;
        $this->categoriesRepository->create($testCategory);

        // Get category timestamp
        $category = $this->categoriesRepository->findById($testCategoryId);
        $this->assertNotNull($category);

        $updatedAt = new \DateTime($category['updated_at']);
        $sinceMs = ($updatedAt->getTimestamp() - 1) * 1000;

        // Query with milliseconds
        $results = $this->categoriesRepository->findModifiedSince($sinceMs);

        // If bug exists, date() would generate '57123-06-16 00:13:20' (from treating 1738872000000 as seconds)
        // This would never match any real records, so results would be empty
        // After fix, we should find our category

        $found = false;
        foreach ($results as $result) {
            if ($result['id'] === $testCategoryId) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, "Bug #2: Category should be found after milliseconds-to-seconds conversion fix (year 57123 bug)");
    }
}
