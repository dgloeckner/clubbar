import { test, expect } from '@playwright/test';
import { randomUUID } from 'crypto';

/**
 * Products API Tests
 *
 * Tests the product catalog management endpoints.
 * Covers CRUD operations for admin API and delta sync for terminal API.
 *
 * Uses E2E Pattern 001: Test Data Isolation
 * - Each test creates unique test data
 * - Tests are independent and can run in parallel
 * - No shared or mutated state between tests
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

// Helper to create category first
async function createCategory(request) {
  const response = await request.post('/api/admin/categories', {
    data: {
      names: {
        de: `Kategorie ${randomUUID().substring(0, 8)}`,
        en: `Category ${randomUUID().substring(0, 8)}`,
      },
    },
  });
  return await response.json();
}

// Test: List Products
test.describe('Products API - List', () => {
  test('GET /api/admin/products returns product list', async ({ request }) => {
    const response = await request.get('/api/admin/products');

    expect(response.ok()).toBeTruthy();
    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(body.products).toBeDefined();
    expect(Array.isArray(body.products)).toBeTruthy();
    expect(body.pagination).toBeDefined();
  });

  test('GET /api/admin/products includes pagination info', async ({ request }) => {
    const response = await request.get('/api/admin/products');
    const body = await response.json();

    expect(body.pagination.total).toBeDefined();
    expect(body.pagination.per_page).toBeDefined();
    expect(body.pagination.current_page).toBeDefined();
    expect(body.pagination.last_page).toBeDefined();
  });

  test('GET /api/admin/products returns product fields', async ({ request }) => {
    const response = await request.get('/api/admin/products');
    const body = await response.json();

    if (body.products.length > 0) {
      const product = body.products[0];
      expect(product.id).toBeDefined();
      expect(product.names).toBeDefined();
      expect(product.price_cents).toBeDefined();
      expect(product.category_id).toBeDefined();
      expect(product.is_active).toBeDefined();
    }
  });

  test('GET /api/admin/products supports page parameter', async ({ request }) => {
    const response = await request.get('/api/admin/products?page=1');
    const body = await response.json();

    expect(body.pagination.current_page).toBe(1);
  });

  test('GET /api/admin/products supports per_page parameter', async ({ request }) => {
    const response = await request.get('/api/admin/products?per_page=10');
    const body = await response.json();

    expect(body.pagination.per_page).toBe(10);
  });

  test('GET /api/admin/products limits per_page to maximum 100', async ({ request }) => {
    const response = await request.get('/api/admin/products?per_page=500');
    const body = await response.json();

    expect(body.pagination.per_page).toBeLessThanOrEqual(100);
  });

  test('GET /api/admin/products supports status filter', async ({ request }) => {
    const response = await request.get('/api/admin/products?status=active');
    expect(response.ok()).toBeTruthy();
  });

  test('GET /api/admin/products rejects invalid status filter', async ({ request }) => {
    const response = await request.get('/api/admin/products?status=invalid');
    expect(response.status()).toBe(422);
  });

  test('GET /api/admin/products supports sort parameter', async ({ request }) => {
    const response = await request.get('/api/admin/products?sort=name');
    expect(response.ok()).toBeTruthy();
  });

  test('GET /api/admin/products supports order parameter', async ({ request }) => {
    const response = await request.get('/api/admin/products?order=desc');
    expect(response.ok()).toBeTruthy();
  });
});

// Test: Create Product
test.describe('Products API - Create', () => {
  test('POST /api/admin/products creates valid product', async ({ request }) => {
    const category = await createCategory(request);
    const productData = createValidProduct(category.id);

    const response = await request.post('/api/admin/products', {
      data: productData,
    });

    expect(response.status()).toBe(201);

    const body = await response.json();
    expect(body.id).toBeDefined();
    expect(body.names).toEqual(productData.names);
    expect(body.price_cents).toBe(productData.price_cents);
    expect(body.category_id).toBe(category.id);
    expect(body.is_active).toBe(true);
  });

  test('POST /api/admin/products new product is active by default', async ({ request }) => {
    const category = await createCategory(request);

    const response = await request.post('/api/admin/products', {
      data: createValidProduct(category.id),
    });

    const body = await response.json();
    expect(body.is_active).toBe(true);
  });

  test('POST /api/admin/products creates with minimal data', async ({ request }) => {
    const category = await createCategory(request);

    const response = await request.post('/api/admin/products', {
      data: {
        names: { de: 'Minimal Product' },
        price_cents: 100,
        category_id: category.id,
      },
    });

    expect(response.status()).toBe(201);
  });

  test('POST /api/admin/products rejects missing name', async ({ request }) => {
    const category = await createCategory(request);

    const response = await request.post('/api/admin/products', {
      data: {
        names: {},
        price_cents: 100,
        category_id: category.id,
      },
    });

    expect(response.status()).toBe(422);
  });

  test('POST /api/admin/products rejects zero price', async ({ request }) => {
    const category = await createCategory(request);

    const response = await request.post('/api/admin/products', {
      data: createValidProduct(category.id, { price_cents: 0 }),
    });

    expect(response.status()).toBe(422);
  });

  test('POST /api/admin/products rejects negative price', async ({ request }) => {
    const category = await createCategory(request);

    const response = await request.post('/api/admin/products', {
      data: createValidProduct(category.id, { price_cents: -100 }),
    });

    expect(response.status()).toBe(422);
  });

  test('POST /api/admin/products rejects invalid category_id', async ({ request }) => {
    const response = await request.post('/api/admin/products', {
      data: createValidProduct(randomUUID()),
    });

    expect(response.status()).toBe(422);
  });

  test('POST /api/admin/products rejects inactive category', async ({ request }) => {
    const category = await createCategory(request);

    // Deactivate category
    await request.patch(`/api/admin/categories/${category.id}/status`, {
      data: { is_active: false },
    });

    const response = await request.post('/api/admin/products', {
      data: createValidProduct(category.id),
    });

    expect(response.status()).toBe(400);
  });

  test('POST /api/admin/products stores descriptions', async ({ request }) => {
    const category = await createCategory(request);

    const response = await request.post('/api/admin/products', {
      data: createValidProduct(category.id),
    });

    const body = await response.json();
    expect(body.descriptions).toBeDefined();
    expect(body.descriptions.de).toBe('Deutsche Beschreibung');
  });
});

// Test: Update Product
test.describe('Products API - Update', () => {
  test('PATCH /api/admin/products/{id} updates name', async ({ request }) => {
    const category = await createCategory(request);
    const createResponse = await request.post('/api/admin/products', {
      data: createValidProduct(category.id),
    });
    const product = await createResponse.json();

    const response = await request.patch(`/api/admin/products/${product.id}`, {
      data: {
        names: {
          de: 'Neuer Name',
          en: 'New Name',
        },
      },
    });

    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.names.de).toBe('Neuer Name');
  });

  test('PATCH /api/admin/products/{id} updates price', async ({ request }) => {
    const category = await createCategory(request);
    const createResponse = await request.post('/api/admin/products', {
      data: createValidProduct(category.id),
    });
    const product = await createResponse.json();

    const response = await request.patch(`/api/admin/products/${product.id}`, {
      data: { price_cents: 450 },
    });

    const body = await response.json();
    expect(body.price_cents).toBe(450);
  });

  test('PATCH /api/admin/products/{id} updates category', async ({ request }) => {
    const category1 = await createCategory(request);
    const category2 = await createCategory(request);

    const createResponse = await request.post('/api/admin/products', {
      data: createValidProduct(category1.id),
    });
    const product = await createResponse.json();

    const response = await request.patch(`/api/admin/products/${product.id}`, {
      data: { category_id: category2.id },
    });

    const body = await response.json();
    expect(body.category_id).toBe(category2.id);
  });

  test('PATCH /api/admin/products/{id} preserves created_at', async ({ request }) => {
    const category = await createCategory(request);
    const createResponse = await request.post('/api/admin/products', {
      data: createValidProduct(category.id),
    });
    const product = await createResponse.json();
    const originalCreatedAt = product.created_at;

    const response = await request.patch(`/api/admin/products/${product.id}`, {
      data: { names: { de: 'Updated' } },
    });

    const body = await response.json();
    expect(body.created_at).toBe(originalCreatedAt);
  });

  test('PATCH /api/admin/products/{id} returns 404 for non-existent product', async ({ request }) => {
    const response = await request.patch(`/api/admin/products/${randomUUID()}`, {
      data: { names: { de: 'Updated' } },
    });

    expect(response.status()).toBe(404);
  });

  test('PATCH /api/admin/products/{id} rejects zero price', async ({ request }) => {
    const category = await createCategory(request);
    const createResponse = await request.post('/api/admin/products', {
      data: createValidProduct(category.id),
    });
    const product = await createResponse.json();

    const response = await request.patch(`/api/admin/products/${product.id}`, {
      data: { price_cents: 0 },
    });

    expect(response.status()).toBe(422);
  });

  test('PATCH /api/admin/products/{id} rejects invalid category', async ({ request }) => {
    const category = await createCategory(request);
    const createResponse = await request.post('/api/admin/products', {
      data: createValidProduct(category.id),
    });
    const product = await createResponse.json();

    const response = await request.patch(`/api/admin/products/${product.id}`, {
      data: { category_id: randomUUID() },
    });

    expect(response.status()).toBe(404);
  });
});

// Test: Toggle Status
test.describe('Products API - Toggle Status', () => {
  test('PATCH /api/admin/products/{id}/status deactivates product', async ({ request }) => {
    const category = await createCategory(request);
    const createResponse = await request.post('/api/admin/products', {
      data: createValidProduct(category.id),
    });
    const product = await createResponse.json();

    const response = await request.patch(`/api/admin/products/${product.id}/status`, {
      data: { is_active: false },
    });

    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.is_active).toBe(false);
  });

  test('PATCH /api/admin/products/{id}/status activates product', async ({ request }) => {
    const category = await createCategory(request);
    const createResponse = await request.post('/api/admin/products', {
      data: createValidProduct(category.id),
    });
    const product = await createResponse.json();

    // Deactivate first
    await request.patch(`/api/admin/products/${product.id}/status`, {
      data: { is_active: false },
    });

    // Then reactivate
    const response = await request.patch(`/api/admin/products/${product.id}/status`, {
      data: { is_active: true },
    });

    const body = await response.json();
    expect(body.is_active).toBe(true);
  });

  test('PATCH /api/admin/products/{id}/status returns 404 for non-existent product', async ({ request }) => {
    const response = await request.patch(`/api/admin/products/${randomUUID()}/status`, {
      data: { is_active: false },
    });

    expect(response.status()).toBe(404);
  });
});

// Test: Terminal Sync
test.describe('Products API - Terminal Sync', () => {
  test('GET /api/sync/products returns product list', async ({ request }) => {
    const response = await request.get('/api/sync/products');

    expect(response.ok()).toBeTruthy();

    const body = await response.json();
    expect(body.products).toBeDefined();
    expect(Array.isArray(body.products)).toBeTruthy();
    expect(body.cursor).toBeDefined();
    expect(body.count).toBeDefined();
    expect(body.has_more).toBeDefined();
  });

  test('GET /api/sync/products returns only active products', async ({ request }) => {
    const category = await createCategory(request);

    // Create active product
    const activeProduct = await (
      await request.post('/api/admin/products', {
        data: createValidProduct(category.id),
      })
    ).json();

    // Create and deactivate a product
    const inactiveProduct = await (
      await request.post('/api/admin/products', {
        data: createValidProduct(category.id),
      })
    ).json();

    await request.patch(`/api/admin/products/${inactiveProduct.id}/status`, {
      data: { is_active: false },
    });

    const response = await request.get('/api/sync/products');
    const body = await response.json();

    // All returned products should be active
    for (const product of body.products) {
      expect(product.is_active).toBe(true);
    }
  });

  test('GET /api/sync/products returns only products from active categories', async ({ request }) => {
    const activeCategory = await createCategory(request);

    const inactiveCategory = await createCategory(request);
    await request.patch(`/api/admin/categories/${inactiveCategory.id}/status`, {
      data: { is_active: false },
    });

    // Create product in inactive category
    const productInInactiveCategory = await (
      await request.post('/api/admin/products', {
        data: createValidProduct(inactiveCategory.id),
      })
    ).json();

    const response = await request.get('/api/sync/products');
    const body = await response.json();

    // Check that product from inactive category is not returned
    const productIds = body.products.map((p) => p.id);
    expect(productIds).not.toContain(productInInactiveCategory.id);
  });

  test('GET /api/sync/products respects since parameter', async ({ request }) => {
    const oldTimestamp = Math.floor(Date.now() / 1000) - 3600; // 1 hour ago

    const response = await request.get(`/api/sync/products?since=${oldTimestamp}`);

    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.products).toBeDefined();
  });

  test('GET /api/sync/products returns cursor for pagination', async ({ request }) => {
    const response = await request.get('/api/sync/products');
    const body = await response.json();

    expect(body.cursor).toBeDefined();
    expect(typeof body.cursor).toBe('string');
  });

  test('GET /api/sync/products returns product names in all languages', async ({ request }) => {
    const category = await createCategory(request);

    await request.post('/api/admin/products', {
      data: createValidProduct(category.id, {
        names: {
          de: 'Deutsch',
          en: 'English',
          fr: 'Français',
        },
      }),
    });

    const response = await request.get('/api/sync/products');
    const body = await response.json();

    if (body.products.length > 0) {
      const product = body.products[0];
      expect(product.names).toBeDefined();
      expect(typeof product.names).toBe('object');
    }
  });

  test('GET /api/sync/products includes category_id', async ({ request }) => {
    const category = await createCategory(request);

    await request.post('/api/admin/products', {
      data: createValidProduct(category.id),
    });

    const response = await request.get('/api/sync/products');
    const body = await response.json();

    if (body.products.length > 0) {
      const product = body.products[0];
      expect(product.category_id).toBeDefined();
    }
  });
});
