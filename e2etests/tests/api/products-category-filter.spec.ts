import { test, expect } from '../../fixtures/auth.fixture';
import { randomUUID } from 'crypto';
import type { APIRequestContext } from '@playwright/test';

/**
 * Products API - Category Filtering Tests
 *
 * Tests the category_id query parameter filtering for product lists.
 * Verifies that products are correctly filtered by category.
 *
 * Uses E2E Pattern 001: Test Data Isolation
 * - Each test creates unique test data (categories + products)
 * - Tests are independent and can run in parallel
 * - No shared or mutated state between tests
 *
 * Uses E2E Pattern 002: Authentication Isolation
 * - Uses authenticatedRequest fixture for admin API
 *
 * Uses E2E Pattern 003: Database-Agnostic Assertions
 * - Searches by ID, not by position
 * - Verifies specific test data appears/disappears correctly
 *
 * NOTE: The scenario mentioned "available_only" parameter, but backend code
 * review (AdminController.php line 136-150) shows only category_id is implemented.
 * Tests focus on the implemented category_id filtering.
 */

// Helper to create valid product data
function createValidProduct(categoryId: string, overrides = {}) {
  return {
    names: {
      de: `Produkt ${randomUUID().substring(0, 8)}`,
      en: `Product ${randomUUID().substring(0, 8)}`,
    },
    descriptions: {
      de: 'Deutsche Beschreibung',
      en: 'English Description',
    },
    price_cents: 350, // €3.50
    category_id: categoryId,
    ...overrides,
  };
}

// Helper to create category
async function createCategory(authenticatedRequest: APIRequestContext, nameSuffix: string = '') {
  const response = await authenticatedRequest.post('/api/admin/categories', {
    data: {
      names: {
        de: `Kategorie ${nameSuffix} ${randomUUID().substring(0, 8)}`,
        en: `Category ${nameSuffix} ${randomUUID().substring(0, 8)}`,
      },
    },
  });

  if (!response.ok()) {
    throw new Error(`Failed to create category: ${response.status()} - ${await response.text()}`);
  }

  return await response.json();
}

test.describe('Products API - Category Filtering', () => {
  test('GET /api/admin/products?category_id=X returns only products from that category', async ({ authenticatedRequest }) => {
    // Pattern 001: Create unique test data
    const category1 = await createCategory(authenticatedRequest, 'FilterTest1');
    const category2 = await createCategory(authenticatedRequest, 'FilterTest2');

    // Create products in category1
    const product1 = await (
      await authenticatedRequest.post('/api/admin/products', {
        data: createValidProduct(category1.id, {
          names: { de: 'Product in Category 1', en: 'Product in Category 1' },
        }),
      })
    ).json();

    const product2 = await (
      await authenticatedRequest.post('/api/admin/products', {
        data: createValidProduct(category1.id, {
          names: { de: 'Another Product in Category 1', en: 'Another Product in Category 1' },
        }),
      })
    ).json();

    // Create product in category2 (should NOT appear in filtered results)
    const product3 = await (
      await authenticatedRequest.post('/api/admin/products', {
        data: createValidProduct(category2.id, {
          names: { de: 'Product in Category 2', en: 'Product in Category 2' },
        }),
      })
    ).json();

    // Query with category_id filter for category1
    const response = await authenticatedRequest.get(`/api/admin/products?category_id=${category1.id}`);

    expect(response.ok()).toBeTruthy();
    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(body.items).toBeDefined();
    expect(Array.isArray(body.items)).toBeTruthy();

    // Pattern 003: Search by ID, not position
    const foundProduct1 = body.items.find((p: any) => p.id === product1.id);
    const foundProduct2 = body.items.find((p: any) => p.id === product2.id);
    const foundProduct3 = body.items.find((p: any) => p.id === product3.id);

    // E2E verification: Products from category1 appear
    expect(foundProduct1).toBeDefined();
    expect(foundProduct1.category_id).toBe(category1.id);

    expect(foundProduct2).toBeDefined();
    expect(foundProduct2.category_id).toBe(category1.id);

    // E2E verification: Product from category2 does NOT appear
    expect(foundProduct3).toBeUndefined();
  });

  test('GET /api/admin/products?category_id=X returns empty list if no products exist', async ({ authenticatedRequest }) => {
    // Pattern 001: Create unique category with no products
    const emptyCategory = await createCategory(authenticatedRequest, 'EmptyCategory');

    // Query with category_id for empty category
    const response = await authenticatedRequest.get(`/api/admin/products?category_id=${emptyCategory.id}`);

    expect(response.ok()).toBeTruthy();
    const body = await response.json();

    expect(body.items).toBeDefined();
    expect(Array.isArray(body.items)).toBeTruthy();
    expect(body.items.length).toBe(0);
    expect(body.total).toBe(0);
  });

  test('GET /api/admin/products?category_id=INVALID returns 422 validation error', async ({ authenticatedRequest }) => {
    // Invalid UUID format should fail validation
    const response = await authenticatedRequest.get('/api/admin/products?category_id=not-a-uuid');

    expect(response.status()).toBe(422);

    const body = await response.json();
    expect(body.error).toBe('validation_failed');
    expect(body.messages).toBeDefined();
  });

  test('GET /api/admin/products?category_id=NON_EXISTENT_UUID returns empty list', async ({ authenticatedRequest }) => {
    // Valid UUID format but category doesn't exist
    const nonExistentUuid = randomUUID();
    const response = await authenticatedRequest.get(`/api/admin/products?category_id=${nonExistentUuid}`);

    expect(response.ok()).toBeTruthy();
    const body = await response.json();

    // Backend returns empty list (not 404) for non-existent category filter
    expect(body.items).toBeDefined();
    expect(Array.isArray(body.items)).toBeTruthy();
    expect(body.items.length).toBe(0);
  });

  test('GET /api/admin/products?category_id=X includes both active and inactive products', async ({ authenticatedRequest }) => {
    // Pattern 001: Create unique test data
    const category = await createCategory(authenticatedRequest, 'StatusTest');

    // Create active product
    const activeProduct = await (
      await authenticatedRequest.post('/api/admin/products', {
        data: createValidProduct(category.id, {
          names: { de: 'Active Product', en: 'Active Product' },
        }),
      })
    ).json();

    // Create and deactivate product
    const inactiveProduct = await (
      await authenticatedRequest.post('/api/admin/products', {
        data: createValidProduct(category.id, {
          names: { de: 'Inactive Product', en: 'Inactive Product' },
        }),
      })
    ).json();

    await authenticatedRequest.patch(`/api/admin/products/${inactiveProduct.id}/status`, {
      data: { is_active: false },
    });

    // Query with category_id filter (default status=all or no status param)
    const response = await authenticatedRequest.get(`/api/admin/products?category_id=${category.id}`);
    const body = await response.json();

    // Pattern 003: Search by ID
    const foundActive = body.items.find((p: any) => p.id === activeProduct.id);
    const foundInactive = body.items.find((p: any) => p.id === inactiveProduct.id);

    // E2E verification: Both active and inactive products appear by default
    expect(foundActive).toBeDefined();
    expect(foundActive.is_active).toBe(true);

    expect(foundInactive).toBeDefined();
    expect(foundInactive.is_active).toBe(false);
  });

  test('GET /api/admin/products?category_id=X&status=active filters by both category and status', async ({ authenticatedRequest }) => {
    // Pattern 001: Create unique test data
    const category = await createCategory(authenticatedRequest, 'CombinedFilter');

    // Create active product
    const activeProduct = await (
      await authenticatedRequest.post('/api/admin/products', {
        data: createValidProduct(category.id, {
          names: { de: 'Active Product Combined', en: 'Active Product Combined' },
        }),
      })
    ).json();

    // Create and deactivate product
    const inactiveProduct = await (
      await authenticatedRequest.post('/api/admin/products', {
        data: createValidProduct(category.id, {
          names: { de: 'Inactive Product Combined', en: 'Inactive Product Combined' },
        }),
      })
    ).json();

    await authenticatedRequest.patch(`/api/admin/products/${inactiveProduct.id}/status`, {
      data: { is_active: false },
    });

    // Query with both category_id AND status=active
    const response = await authenticatedRequest.get(`/api/admin/products?category_id=${category.id}&status=active`);
    const body = await response.json();

    // Pattern 003: Search by ID
    const foundActive = body.items.find((p: any) => p.id === activeProduct.id);
    const foundInactive = body.items.find((p: any) => p.id === inactiveProduct.id);

    // E2E verification: Only active product from this category appears
    expect(foundActive).toBeDefined();
    expect(foundActive.is_active).toBe(true);
    expect(foundActive.category_id).toBe(category.id);

    // Inactive product should NOT appear
    expect(foundInactive).toBeUndefined();
  });
});
