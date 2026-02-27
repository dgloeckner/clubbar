import { test, expect } from '../../fixtures/auth.fixture';
import { APIRequestContext } from '@playwright/test';
import { randomUUID } from 'crypto';

/**
 * Products API Tests
 *
 * Tests the product catalog management endpoints.
 * Covers CRUD operations for admin API and delta sync for terminal API.
 *
 * Admin Endpoints: Uses authenticatedRequest from auth.fixture (admin session)
 * Terminal Endpoints: Uses authenticatedTerminalRequest from auth.fixture (bearer token)
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
async function createCategory(authenticatedRequest) {
  const response = await authenticatedRequest.post('/api/admin/categories', {
    data: {
      names: {
        de: `Kategorie ${randomUUID().substring(0, 8)}`,
        en: `Category ${randomUUID().substring(0, 8)}`,
      },
    },
  });

  if (!response.ok()) {
    throw new Error(`Failed to create category: ${response.status()} - ${await response.text()}`);
  }

  return await response.json();
}

// Test: List Products
test.describe('Products API - List', () => {
  test('GET /api/admin/products returns product list', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/products');

    expect(response.ok()).toBeTruthy();
    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(body.items).toBeDefined();
    expect(Array.isArray(body.items)).toBeTruthy();
    expect(body.total).toBeDefined();
    expect(body.limit).toBeDefined();
    expect(body.offset).toBeDefined();
    expect(body.has_more).toBeDefined();
  });

  test('GET /api/admin/products includes pagination info', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/products');
    const body = await response.json();

    expect(body.total).toBeDefined();
    expect(typeof body.total).toBe('number');
    expect(body.limit).toBeDefined();
    expect(typeof body.limit).toBe('number');
    expect(body.offset).toBeDefined();
    expect(typeof body.offset).toBe('number');
    expect(body.has_more).toBeDefined();
    expect(typeof body.has_more).toBe('boolean');
  });

  test('GET /api/admin/products returns product fields', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/products');
    const body = await response.json();

    if (body.items.length > 0) {
      const product = body.items[0];
      expect(product.id).toBeDefined();
      expect(product.names).toBeDefined();
      expect(product.price_cents).toBeDefined();
      expect(product.category_id).toBeDefined();
      expect(product.is_active).toBeDefined();
    }
  });

  test('GET /api/admin/products supports page parameter', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/products?page=1');
    const body = await response.json();

    expect(response.ok()).toBeTruthy();
    expect(body.offset).toBe(0); // page 1 = offset 0
  });

  test('GET /api/admin/products supports per_page parameter', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/products?per_page=10');
    const body = await response.json();

    expect(response.ok()).toBeTruthy();
    expect(body.limit).toBe(10);
  });

  test('GET /api/admin/products limits per_page to maximum 100', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/products?per_page=500');
    const body = await response.json();

    expect(body.limit).toBeLessThanOrEqual(100);
  });

  test('GET /api/admin/products supports status filter', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/products?status=active');
    expect(response.ok()).toBeTruthy();
  });

  test('GET /api/admin/products rejects invalid status filter', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/products?status=invalid');
    expect(response.status()).toBe(422);
  });

  test('GET /api/admin/products supports sort parameter', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/products?sort=name');
    expect(response.ok()).toBeTruthy();
  });

  test('GET /api/admin/products supports order parameter', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/products?order=desc');
    expect(response.ok()).toBeTruthy();
  });
});

// Test: Create Product
test.describe('Products API - Create', () => {
  test('POST /api/admin/products creates valid product', async ({ authenticatedRequest }) => {
    const category = await createCategory(authenticatedRequest);
    const productData = createValidProduct(category.id);

    const response = await authenticatedRequest.post('/api/admin/products', {
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

  test('POST /api/admin/products new product is active by default', async ({ authenticatedRequest }) => {
    const category = await createCategory(authenticatedRequest);

    const response = await authenticatedRequest.post('/api/admin/products', {
      data: createValidProduct(category.id),
    });

    const body = await response.json();
    expect(body.is_active).toBe(true);
  });

  test('POST /api/admin/products creates with minimal data', async ({ authenticatedRequest }) => {
    const category = await createCategory(authenticatedRequest);

    const response = await authenticatedRequest.post('/api/admin/products', {
      data: {
        names: { de: 'Minimal Product' },
        price_cents: 100,
        category_id: category.id,
      },
    });

    expect(response.status()).toBe(201);
  });

  test('POST /api/admin/products rejects missing name', async ({ authenticatedRequest }) => {
    const category = await createCategory(authenticatedRequest);

    const response = await authenticatedRequest.post('/api/admin/products', {
      data: {
        names: {},
        price_cents: 100,
        category_id: category.id,
      },
    });

    expect(response.status()).toBe(422);
  });

  test('POST /api/admin/products rejects zero price', async ({ authenticatedRequest }) => {
    const category = await createCategory(authenticatedRequest);

    const response = await authenticatedRequest.post('/api/admin/products', {
      data: createValidProduct(category.id, { price_cents: 0 }),
    });

    expect(response.status()).toBe(422);
  });

  test('POST /api/admin/products rejects negative price', async ({ authenticatedRequest }) => {
    const category = await createCategory(authenticatedRequest);

    const response = await authenticatedRequest.post('/api/admin/products', {
      data: createValidProduct(category.id, { price_cents: -100 }),
    });

    expect(response.status()).toBe(422);
  });

  test('POST /api/admin/products rejects invalid category_id', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post('/api/admin/products', {
      data: createValidProduct(randomUUID()),
    });

    expect(response.status()).toBe(422);
  });

  test('POST /api/admin/products rejects inactive category', async ({ authenticatedRequest }) => {
    const category = await createCategory(authenticatedRequest);

    // Deactivate category
    await authenticatedRequest.patch(`/api/admin/categories/${category.id}/status`, {
      data: { is_active: false },
    });

    const response = await authenticatedRequest.post('/api/admin/products', {
      data: createValidProduct(category.id),
    });

    expect(response.status()).toBe(409);
  });

  test('POST /api/admin/products stores descriptions', async ({ authenticatedRequest }) => {
    const category = await createCategory(authenticatedRequest);

    const response = await authenticatedRequest.post('/api/admin/products', {
      data: createValidProduct(category.id),
    });

    const body = await response.json();
    expect(body.descriptions).toBeDefined();
    expect(body.descriptions.de).toBe('Deutsche Beschreibung');
  });
});

// Test: Update Product
test.describe('Products API - Update', () => {
  test('PATCH /api/admin/products/{id} updates name', async ({ authenticatedRequest }) => {
    const category = await createCategory(authenticatedRequest);
    const createResponse = await authenticatedRequest.post('/api/admin/products', {
      data: createValidProduct(category.id),
    });
    const product = await createResponse.json();

    const response = await authenticatedRequest.patch(`/api/admin/products/${product.id}`, {
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

  test('PATCH /api/admin/products/{id} updates price', async ({ authenticatedRequest }) => {
    const category = await createCategory(authenticatedRequest);
    const createResponse = await authenticatedRequest.post('/api/admin/products', {
      data: createValidProduct(category.id),
    });
    const product = await createResponse.json();

    const response = await authenticatedRequest.patch(`/api/admin/products/${product.id}`, {
      data: { price_cents: 450 },
    });

    const body = await response.json();
    expect(body.price_cents).toBe(450);
  });

  test('PATCH /api/admin/products/{id} updates category', async ({ authenticatedRequest }) => {
    const category1 = await createCategory(authenticatedRequest);
    const category2 = await createCategory(authenticatedRequest);

    const createResponse = await authenticatedRequest.post('/api/admin/products', {
      data: createValidProduct(category1.id),
    });
    const product = await createResponse.json();

    const response = await authenticatedRequest.patch(`/api/admin/products/${product.id}`, {
      data: { category_id: category2.id },
    });

    const body = await response.json();
    expect(body.category_id).toBe(category2.id);
  });

  test('PATCH /api/admin/products/{id} preserves created_at', async ({ authenticatedRequest }) => {
    const category = await createCategory(authenticatedRequest);
    const createResponse = await authenticatedRequest.post('/api/admin/products', {
      data: createValidProduct(category.id),
    });
    const product = await createResponse.json();
    const originalCreatedAt = product.created_at;

    const response = await authenticatedRequest.patch(`/api/admin/products/${product.id}`, {
      data: { names: { de: 'Updated' } },
    });

    const body = await response.json();
    expect(body.created_at).toBe(originalCreatedAt);
  });

  test('PATCH /api/admin/products/{id} returns 404 for non-existent product', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.patch(`/api/admin/products/${randomUUID()}`, {
      data: { names: { de: 'Updated' } },
    });

    expect(response.status()).toBe(404);
  });

  test('PATCH /api/admin/products/{id} rejects zero price', async ({ authenticatedRequest }) => {
    const category = await createCategory(authenticatedRequest);
    const createResponse = await authenticatedRequest.post('/api/admin/products', {
      data: createValidProduct(category.id),
    });

    expect(createResponse.ok()).toBeTruthy();
    const product = await createResponse.json();
    expect(product.id).toBeDefined();

    const response = await authenticatedRequest.patch(`/api/admin/products/${product.id}`, {
      data: { price_cents: 0 },
    });

    expect(response.status()).toBe(422);
  });

  test('PATCH /api/admin/products/{id} rejects invalid category', async ({ authenticatedRequest }) => {
    const category = await createCategory(authenticatedRequest);
    const createResponse = await authenticatedRequest.post('/api/admin/products', {
      data: createValidProduct(category.id),
    });
    const product = await createResponse.json();

    const response = await authenticatedRequest.patch(`/api/admin/products/${product.id}`, {
      data: { category_id: randomUUID() },
    });

    expect(response.status()).toBe(404);
  });
});

// Test: Toggle Status
test.describe('Products API - Toggle Status', () => {
  test('PATCH /api/admin/products/{id}/status deactivates product', async ({ authenticatedRequest }) => {
    const category = await createCategory(authenticatedRequest);
    const createResponse = await authenticatedRequest.post('/api/admin/products', {
      data: createValidProduct(category.id),
    });
    const product = await createResponse.json();

    const response = await authenticatedRequest.patch(`/api/admin/products/${product.id}/status`, {
      data: { is_active: false },
    });

    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.is_active).toBe(false);
  });

  test('PATCH /api/admin/products/{id}/status activates product', async ({ authenticatedRequest }) => {
    const category = await createCategory(authenticatedRequest);
    const createResponse = await authenticatedRequest.post('/api/admin/products', {
      data: createValidProduct(category.id),
    });
    const product = await createResponse.json();

    // Deactivate first
    await authenticatedRequest.patch(`/api/admin/products/${product.id}/status`, {
      data: { is_active: false },
    });

    // Then reactivate
    const response = await authenticatedRequest.patch(`/api/admin/products/${product.id}/status`, {
      data: { is_active: true },
    });

    const body = await response.json();
    expect(body.is_active).toBe(true);
  });

  test('PATCH /api/admin/products/{id}/status returns 404 for non-existent product', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.patch(`/api/admin/products/${randomUUID()}/status`, {
      data: { is_active: false },
    });

    expect(response.status()).toBe(404);
  });
});

// Test: Delete Product
test.describe('Products API - Delete', () => {
  test('DELETE /api/admin/products/{id} deletes product', async ({ authenticatedRequest }) => {
    const category = await createCategory(authenticatedRequest);
    const createResponse = await authenticatedRequest.post('/api/admin/products', {
      data: createValidProduct(category.id),
    });
    const product = await createResponse.json();
    const productId = product.id;

    // Delete the product
    const response = await authenticatedRequest.delete(`/api/admin/products/${productId}`);

    // Expect 204 No Content
    expect(response.status()).toBe(204);

    // Verify product is deleted by trying to fetch it
    // It should either 404 or not appear in the list
    const listResponse = await authenticatedRequest.get('/api/admin/products');
    const listBody = await listResponse.json();
    const stillExists = listBody.items.some((p: any) => p.id === productId);
    expect(stillExists).toBe(false);
  });

  test('DELETE /api/admin/products/{id} returns 404 for non-existent product', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.delete(`/api/admin/products/${randomUUID()}`);

    expect(response.status()).toBe(404);
  });

  test('DELETE /api/admin/products/{id} prevents recovery', async ({ authenticatedRequest }) => {
    const category = await createCategory(authenticatedRequest);
    const createResponse = await authenticatedRequest.post('/api/admin/products', {
      data: createValidProduct(category.id),
    });
    const product = await createResponse.json();
    const productId = product.id;

    // Get initial count
    const beforeDelete = await authenticatedRequest.get('/api/admin/products?status=all');
    const beforeBody = await beforeDelete.json();
    const beforeCount = beforeBody.total;

    // Delete the product
    const deleteResponse = await authenticatedRequest.delete(`/api/admin/products/${productId}`);
    expect(deleteResponse.status()).toBe(204);

    // Verify product is gone from all products list
    const afterDelete = await authenticatedRequest.get('/api/admin/products?status=all');
    const afterBody = await afterDelete.json();
    const afterCount = afterBody.total;

    expect(afterCount).toBe(beforeCount - 1);

    // Verify deleted product doesn't appear in list
    const deletedProduct = afterBody.items.find((p: any) => p.id === productId);
    expect(deletedProduct).toBeUndefined();
  });

  test('DELETE /api/admin/products/{id} is different from status toggle', async ({ authenticatedRequest }) => {
    const category = await createCategory(authenticatedRequest);

    // Create product - for testing
    const createResponse = await authenticatedRequest.post('/api/admin/products', {
      data: createValidProduct(category.id),
    });
    const product = await createResponse.json();
    const productId = product.id;

    // Status toggle returns 200 OK with product data
    const statusResponse = await authenticatedRequest.patch(`/api/admin/products/${productId}/status`, {
      data: { is_active: false },
    });
    expect(statusResponse.status()).toBe(200); // Status toggle returns 200
    const statusBody = await statusResponse.json();
    expect(statusBody.is_active).toBe(false); // Returns updated product

    // DELETE returns 204 No Content (different response)
    const deleteResponse = await authenticatedRequest.delete(`/api/admin/products/${productId}`);
    expect(deleteResponse.status()).toBe(204); // DELETE returns 204
    // 204 means no content, don't parse body
  });
});

// Test: Terminal Sync
test.describe('Products API - Terminal Sync', () => {
  test('GET /api/sync/products returns product list', async ({ authenticatedTerminalRequest }) => {
    const response = await authenticatedTerminalRequest.get('/api/sync/products');

    expect(response.ok()).toBeTruthy();

    const body = await response.json();
    expect(body.products).toBeDefined();
    expect(Array.isArray(body.products)).toBeTruthy();
    expect(body.cursor).toBeDefined();
    expect(body.count).toBeDefined();
    expect(body.has_more).toBeDefined();
  });

  test('GET /api/sync/products returns all products including inactive', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {

    const category = await createCategory(authenticatedRequest);

    // Create active product
    const activeProduct = await (
      await authenticatedRequest.post('/api/admin/products', {
        data: createValidProduct(category.id),
      })
    ).json();

    // Create and deactivate a product
    const inactiveProduct = await (
      await authenticatedRequest.post('/api/admin/products', {
        data: createValidProduct(category.id),
      })
    ).json();

    await authenticatedRequest.patch(`/api/admin/products/${inactiveProduct.id}/status`, {
      data: { is_active: false },
    });

    const response = await authenticatedTerminalRequest.get('/api/sync/products');
    const body = await response.json();

    // Sync should return ALL products (both active and inactive) so UI can track state
    const activeInResponse = body.products.find((p: any) => p.id === activeProduct.id);
    const inactiveInResponse = body.products.find((p: any) => p.id === inactiveProduct.id);

    expect(activeInResponse).toBeDefined();
    expect(activeInResponse.is_active).toBe(true);

    expect(inactiveInResponse).toBeDefined();
    expect(inactiveInResponse.is_active).toBe(false);
  });

  test('GET /api/sync/products returns only products from active categories', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {

    const activeCategory = await createCategory(authenticatedRequest);

    const inactiveCategory = await createCategory(authenticatedRequest);
    await authenticatedRequest.patch(`/api/admin/categories/${inactiveCategory.id}/status`, {
      data: { is_active: false },
    });

    // Create product in inactive category
    const productInInactiveCategory = await (
      await authenticatedRequest.post('/api/admin/products', {
        data: createValidProduct(inactiveCategory.id),
      })
    ).json();

    const response = await authenticatedTerminalRequest.get('/api/sync/products');
    const body = await response.json();

    // Check that product from inactive category is not returned
    const productIds = body.products.map((p) => p.id);
    expect(productIds).not.toContain(productInInactiveCategory.id);
  });

  test('GET /api/sync/products respects since parameter', async ({ authenticatedTerminalRequest }) => {
    const oldTimestamp = Math.floor(Date.now() / 1000) - 3600; // 1 hour ago

    const response = await authenticatedTerminalRequest.get(`/api/sync/products?since=${oldTimestamp}`);

    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.products).toBeDefined();
  });

  test('GET /api/sync/products returns cursor for pagination', async ({ authenticatedTerminalRequest }) => {
    const response = await authenticatedTerminalRequest.get('/api/sync/products');
    const body = await response.json();

    expect(body.cursor).toBeDefined();
    expect(typeof body.cursor).toBe('string');
  });

  test('GET /api/sync/products returns product names in all languages', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {

    const category = await createCategory(authenticatedRequest);

    await authenticatedRequest.post('/api/admin/products', {
      data: createValidProduct(category.id, {
        names: {
          de: 'Deutsch',
          en: 'English',
          fr: 'Français',
        },
      }),
    });

    const response = await authenticatedTerminalRequest.get('/api/sync/products');
    const body = await response.json();

    if (body.products.length > 0) {
      const product = body.products[0];
      expect(product.names).toBeDefined();
      expect(typeof product.names).toBe('object');
    }
  });

  test('GET /api/sync/products includes category_id', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {

    const category = await createCategory(authenticatedRequest);

    await authenticatedRequest.post('/api/admin/products', {
      data: createValidProduct(category.id),
    });

    const response = await authenticatedTerminalRequest.get('/api/sync/products');
    const body = await response.json();

    if (body.products.length > 0) {
      const product = body.products[0];
      expect(product.category_id).toBeDefined();
    }
  });
});

// Test: Icon Name Support
test.describe('Products API - Icon Support', () => {
  test('POST /api/admin/products accepts icon_name', async ({ authenticatedRequest }) => {
    const category = await createCategory(authenticatedRequest);
    const productData = createValidProduct(category.id, {
      icon_name: 'beer-pils',
    });

    const response = await authenticatedRequest.post('/api/admin/products', {
      data: productData,
    });

    expect(response.status()).toBe(201);
    const body = await response.json();
    expect(body.icon_name).toBe('beer-pils');
  });

  test('POST /api/admin/products allows null icon_name', async ({ authenticatedRequest }) => {
    const category = await createCategory(authenticatedRequest);
    const productData = createValidProduct(category.id, {
      icon_name: null,
    });

    const response = await authenticatedRequest.post('/api/admin/products', {
      data: productData,
    });

    expect(response.status()).toBe(201);
    const body = await response.json();
    expect(body.icon_name).toBeNull();
  });

  test('POST /api/admin/products returns icon_name in response', async ({ authenticatedRequest }) => {
    const category = await createCategory(authenticatedRequest);
    const productData = createValidProduct(category.id, {
      icon_name: 'beer-weizen',
    });

    const response = await authenticatedRequest.post('/api/admin/products', {
      data: productData,
    });

    const body = await response.json();
    expect(body.icon_name).toBeDefined();
    expect(body.icon_name).toBe('beer-weizen');
  });

  test('PATCH /api/admin/products/{id} updates icon_name', async ({ authenticatedRequest }) => {
    const category = await createCategory(authenticatedRequest);
    const createResponse = await authenticatedRequest.post('/api/admin/products', {
      data: createValidProduct(category.id, {
        icon_name: 'beer-pils',
      }),
    });

    const product = await createResponse.json();

    const updateResponse = await authenticatedRequest.patch(
      `/api/admin/products/${product.id}`,
      {
        data: { icon_name: 'beer-weizen' },
      }
    );

    const updatedProduct = await updateResponse.json();
    expect(updatedProduct.icon_name).toBe('beer-weizen');
  });

  test('PATCH /api/admin/products/{id} allows clearing icon_name', async ({ authenticatedRequest }) => {
    const category = await createCategory(authenticatedRequest);
    const createResponse = await authenticatedRequest.post('/api/admin/products', {
      data: createValidProduct(category.id, {
        icon_name: 'beer-pils',
      }),
    });

    const product = await createResponse.json();

    const updateResponse = await authenticatedRequest.patch(
      `/api/admin/products/${product.id}`,
      {
        data: { icon_name: null },
      }
    );

    const updatedProduct = await updateResponse.json();
    expect(updatedProduct.icon_name).toBeNull();
  });

  test('GET /api/sync/products includes icon_name', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const category = await createCategory(authenticatedRequest);
    await authenticatedRequest.post('/api/admin/products', {
      data: createValidProduct(category.id, {
        icon_name: 'beer-alcohol-free',
      }),
    });

    const response = await authenticatedTerminalRequest.get('/api/sync/products');
    const body = await response.json();

    if (body.products.length > 0) {
      const product = body.products[0];
      expect(product.icon_name).toBeDefined();
    }
  });

  test('POST /api/admin/products rejects invalid icon_name length', async ({ authenticatedRequest }) => {
    const category = await createCategory(authenticatedRequest);
    const productData = createValidProduct(category.id, {
      icon_name: 'a'.repeat(51), // Exceeds 50 char limit
    });

    const response = await authenticatedRequest.post('/api/admin/products', {
      data: productData,
    });

    expect(response.status()).toBe(422);
  });
});

// Test: Price Sorting
test.describe('Products API - Price Sorting', () => {
  test('GET /api/admin/products?sort_by=price_asc returns products sorted by price ascending', async ({ authenticatedRequest }) => {
    const category = await createCategory(authenticatedRequest);

    // Create products with different prices
    const prices = [500, 100, 300, 200]; // €5.00, €1.00, €3.00, €2.00
    const productIds: string[] = [];

    for (const price of prices) {
      const response = await authenticatedRequest.post('/api/admin/products', {
        data: createValidProduct(category.id, {
          price_cents: price,
          names: { de: `Product ${price}`, en: `Product ${price}` },
        }),
      });
      const product = await response.json();
      productIds.push(product.id);
    }

    console.log('Created products with IDs:', productIds);

    // Query with price_asc sort filtered to this category
    const response = await authenticatedRequest.get(`/api/admin/products?sort_by=price_asc&category_id=${category.id}`);
    const body = await response.json();

    console.log('Total items:', body.total);
    console.log('Items returned:', body.items.length);

    expect(response.ok()).toBeTruthy();

    // Find our test products in the response
    const testProducts = body.items.filter((p: any) => productIds.includes(p.id));
    console.log('Found test products:', testProducts.length);
    console.log('Test product prices (asc):', testProducts.map((p: any) => p.price_cents));

    expect(testProducts.length).toBe(4);

    // Verify they are sorted by price ascending
    for (let i = 1; i < testProducts.length; i++) {
      expect(testProducts[i].price_cents).toBeGreaterThanOrEqual(testProducts[i - 1].price_cents);
    }

    // Verify the exact order: 100, 200, 300, 500
    const sortedPrices = testProducts.map((p: any) => p.price_cents);
    expect(sortedPrices).toEqual([100, 200, 300, 500]);
  });

  test('GET /api/admin/products?sort_by=price_desc returns products sorted by price descending', async ({ authenticatedRequest }) => {
    const category = await createCategory(authenticatedRequest);

    // Create products with different prices
    const prices = [500, 100, 300, 200]; // €5.00, €1.00, €3.00, €2.00
    const productIds: string[] = [];

    for (const price of prices) {
      const response = await authenticatedRequest.post('/api/admin/products', {
        data: createValidProduct(category.id, {
          price_cents: price,
          names: { de: `Product ${price}`, en: `Product ${price}` },
        }),
      });
      const product = await response.json();
      productIds.push(product.id);
    }

    // Query with price_desc sort filtered to this category
    const response = await authenticatedRequest.get(`/api/admin/products?sort_by=price_desc&category_id=${category.id}`);
    const body = await response.json();

    expect(response.ok()).toBeTruthy();

    // Find our test products in the response
    const testProducts = body.items.filter((p: any) => productIds.includes(p.id));
    console.log('Found test products (desc):', testProducts.length);
    console.log('Test product prices (desc):', testProducts.map((p: any) => p.price_cents));
    expect(testProducts.length).toBe(4);

    // Verify they are sorted by price descending
    for (let i = 1; i < testProducts.length; i++) {
      expect(testProducts[i].price_cents).toBeLessThanOrEqual(testProducts[i - 1].price_cents);
    }

    // Verify the exact order: 500, 300, 200, 100
    const sortedPrices = testProducts.map((p: any) => p.price_cents);
    expect(sortedPrices).toEqual([500, 300, 200, 100]);
  });

  test('GET /api/admin/products price_asc and price_desc return different order', async ({ authenticatedRequest }) => {
    const category = await createCategory(authenticatedRequest);

    // Create 2 products with distinct prices
    const cheapProduct = await (
      await authenticatedRequest.post('/api/admin/products', {
        data: createValidProduct(category.id, {
          price_cents: 150,
          names: { de: 'Cheap Product', en: 'Cheap Product' },
        }),
      })
    ).json();

    const expensiveProduct = await (
      await authenticatedRequest.post('/api/admin/products', {
        data: createValidProduct(category.id, {
          price_cents: 950,
          names: { de: 'Expensive Product', en: 'Expensive Product' },
        }),
      })
    ).json();

    // Query with price_asc - cheap should come first (filtered to category)
    const ascResponse = await authenticatedRequest.get(`/api/admin/products?sort_by=price_asc&category_id=${category.id}`);
    const ascBody = await ascResponse.json();
    const ascProducts = ascBody.items.filter((p: any) => [cheapProduct.id, expensiveProduct.id].includes(p.id));

    // Query with price_desc - expensive should come first (filtered to category)
    const descResponse = await authenticatedRequest.get(`/api/admin/products?sort_by=price_desc&category_id=${category.id}`);
    const descBody = await descResponse.json();
    const descProducts = descBody.items.filter((p: any) => [cheapProduct.id, expensiveProduct.id].includes(p.id));

    console.log('Asc products:', ascProducts.map((p: any) => ({ id: p.id, price: p.price_cents })));
    console.log('Desc products:', descProducts.map((p: any) => ({ id: p.id, price: p.price_cents })));

    // Verify asc: cheap first (150)
    expect(ascProducts.length).toBe(2);
    expect(ascProducts[0].price_cents).toBe(150);
    expect(ascProducts[1].price_cents).toBe(950);

    // Verify desc: expensive first (950)
    expect(descProducts.length).toBe(2);
    expect(descProducts[0].price_cents).toBe(950);
    expect(descProducts[1].price_cents).toBe(150);

    // Verify they're different
    expect(ascProducts[0].id).not.toBe(descProducts[0].id);
  });
});
