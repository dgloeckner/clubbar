import { test, expect } from '../../fixtures/auth.fixture';
import { APIRequestContext } from '@playwright/test';
import { randomUUID } from 'crypto';

/**
 * Categories API Tests
 *
 * Tests the product categories management endpoints.
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

// Helper to create valid category data
function createValidCategory(overrides = {}) {
  return {
    names: {
      de: `Kategorie ${randomUUID().substring(0, 8)}`,
      en: `Category ${randomUUID().substring(0, 8)}`,
    },
    ...overrides,
  };
}

// Test: List Categories
test.describe('Categories API - List', () => {
  test('GET /api/admin/categories returns list including created category', async ({ authenticatedRequest }) => {
    const created = await authenticatedRequest.post('/api/admin/categories', {
      data: createValidCategory(),
    }).then(r => r.json());

    const response = await authenticatedRequest.get('/api/admin/categories');
    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(Array.isArray(body.data)).toBeTruthy();

    const found = body.data.find((c: any) => c.id === created.id);
    expect(found).toBeDefined();
    expect(found.names).toEqual(created.names);
    expect(typeof found.product_count).toBe('number');
    expect(typeof found.is_active).toBe('boolean');
  });
});

// Test: Create Category
test.describe('Categories API - Create', () => {
  test('POST /api/admin/categories creates valid category', async ({ authenticatedRequest }) => {
    const categoryData = createValidCategory();

    const response = await authenticatedRequest.post('/api/admin/categories', {
      data: categoryData,
    });

    expect(response.status()).toBe(201);

    const body = await response.json();
    expect(body.id).toBeDefined();
    expect(body.names).toEqual(categoryData.names);
    expect(body.is_active).toBe(true);
  });

  test('POST /api/admin/categories creates with single language', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post('/api/admin/categories', {
      data: {
        names: {
          de: 'Nur Deutsch',
        },
      },
    });

    expect(response.status()).toBe(201);
    const body = await response.json();
    expect(body.names.de).toBe('Nur Deutsch');
  });

  test('POST /api/admin/categories rejects empty names', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post('/api/admin/categories', {
      data: {
        names: {},
      },
    });

    expect(response.status()).toBe(422);
  });

  test('POST /api/admin/categories rejects missing names', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post('/api/admin/categories', {
      data: {},
    });

    expect(response.status()).toBe(422);
  });

  test('POST /api/admin/categories returns created_at timestamp', async ({ authenticatedRequest }) => {
    const categoryData = createValidCategory();

    const response = await authenticatedRequest.post('/api/admin/categories', {
      data: categoryData,
    });

    const body = await response.json();
    expect(body.created_at).toBeDefined();
    expect(body.updated_at).toBeDefined();
  });

  test('POST /api/admin/categories new category is active by default', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post('/api/admin/categories', {
      data: createValidCategory(),
    });

    const body = await response.json();
    expect(body.is_active).toBe(true);
  });
});

// Test: Update Category
test.describe('Categories API - Update', () => {
  test('PATCH /api/admin/categories/{id} updates names', async ({ authenticatedRequest }) => {
    const createResponse = await authenticatedRequest.post('/api/admin/categories', {
      data: createValidCategory(),
    });
    const createdCategory = await createResponse.json();
    const categoryId = createdCategory.id;

    const updateResponse = await authenticatedRequest.patch(`/api/admin/categories/${categoryId}`, {
      data: {
        names: {
          de: 'Aktualisiert DE',
          en: 'Updated EN',
        },
      },
    });

    expect(updateResponse.ok()).toBeTruthy();
    const body = await updateResponse.json();
    expect(body.names.de).toBe('Aktualisiert DE');
    expect(body.names.en).toBe('Updated EN');
  });

  test('PATCH /api/admin/categories/{id} preserves created_at', async ({ authenticatedRequest }) => {
    const createResponse = await authenticatedRequest.post('/api/admin/categories', {
      data: createValidCategory(),
    });
    const createdCategory = await createResponse.json();
    const originalCreatedAt = createdCategory.created_at;

    const updateResponse = await authenticatedRequest.patch(`/api/admin/categories/${createdCategory.id}`, {
      data: {
        names: {
          de: 'Updated',
        },
      },
    });

    const body = await updateResponse.json();
    expect(body.created_at).toBe(originalCreatedAt);
  });

  test('PATCH /api/admin/categories/{id} returns 404 for non-existent category', async ({ authenticatedRequest }) => {
    const fakeId = randomUUID();

    const response = await authenticatedRequest.patch(`/api/admin/categories/${fakeId}`, {
      data: {
        names: { de: 'Updated' },
      },
    });

    expect(response.status()).toBe(404);
  });

  test('PATCH /api/admin/categories/{id} rejects empty names', async ({ authenticatedRequest }) => {
    const createResponse = await authenticatedRequest.post('/api/admin/categories', {
      data: createValidCategory(),
    });
    const category = await createResponse.json();

    const response = await authenticatedRequest.patch(`/api/admin/categories/${category.id}`, {
      data: {
        names: {},
      },
    });

    expect(response.status()).toBe(422);
  });
});

// Test: Toggle Status
test.describe('Categories API - Toggle Status', () => {
  test('PATCH /api/admin/categories/{id}/status deactivates category', async ({ authenticatedRequest }) => {
    const createResponse = await authenticatedRequest.post('/api/admin/categories', {
      data: createValidCategory(),
    });
    const category = await createResponse.json();

    const response = await authenticatedRequest.patch(`/api/admin/categories/${category.id}/status`, {
      data: { is_active: false },
    });

    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.is_active).toBe(false);
  });

  test('PATCH /api/admin/categories/{id}/status activates category', async ({ authenticatedRequest }) => {
    const createResponse = await authenticatedRequest.post('/api/admin/categories', {
      data: createValidCategory(),
    });
    const category = await createResponse.json();

    await authenticatedRequest.patch(`/api/admin/categories/${category.id}/status`, {
      data: { is_active: false },
    });

    const response = await authenticatedRequest.patch(`/api/admin/categories/${category.id}/status`, {
      data: { is_active: true },
    });

    const body = await response.json();
    expect(body.is_active).toBe(true);
  });

  test('PATCH /api/admin/categories/{id}/status returns 404 for non-existent category', async ({ authenticatedRequest }) => {
    const fakeId = randomUUID();

    const response = await authenticatedRequest.patch(`/api/admin/categories/${fakeId}/status`, {
      data: { is_active: false },
    });

    expect(response.status()).toBe(404);
  });
});

// Test: Delete Category
test.describe('Categories API - Delete', () => {
  test('DELETE /api/admin/categories/{id} deletes empty category', async ({ authenticatedRequest }) => {
    const createResponse = await authenticatedRequest.post('/api/admin/categories', {
      data: createValidCategory(),
    });
    const category = await createResponse.json();

    const response = await authenticatedRequest.delete(`/api/admin/categories/${category.id}`);

    expect(response.status()).toBe(204);
  });

  test('DELETE /api/admin/categories/{id} returns 404 for non-existent category', async ({ authenticatedRequest }) => {
    const fakeId = randomUUID();

    const response = await authenticatedRequest.delete(`/api/admin/categories/${fakeId}`);

    expect(response.status()).toBe(404);
  });

  test('DELETE /api/admin/categories/{id} returns 404 for already-deleted category', async ({ authenticatedRequest }) => {
    const createResponse = await authenticatedRequest.post('/api/admin/categories', {
      data: createValidCategory(),
    });
    const category = await createResponse.json();

    await authenticatedRequest.delete(`/api/admin/categories/${category.id}`);
    const response = await authenticatedRequest.delete(`/api/admin/categories/${category.id}`);

    expect(response.status()).toBe(404);
  });

  test('DELETE /api/admin/categories/{id} refuses category with active products', async ({ authenticatedRequest }) => {
    const category = await authenticatedRequest.post('/api/admin/categories', {
      data: createValidCategory(),
    }).then((r) => r.json());

    await authenticatedRequest.post('/api/admin/products', {
      data: {
        names: { de: `Produkt ${randomUUID().substring(0, 8)}` },
        price_cents: 100,
        category_id: category.id,
      },
    });

    const response = await authenticatedRequest.delete(`/api/admin/categories/${category.id}`);

    expect(response.status()).toBe(500);
  });

  // #367: hasProducts() ignored deleted_at, so a category whose products were
  // all soft-deleted was permanently undeletable ("Cannot delete category
  // with products" for a category with no visible products).
  test('DELETE /api/admin/categories/{id} succeeds once all its products are soft-deleted', async ({ authenticatedRequest }) => {
    const category = await authenticatedRequest.post('/api/admin/categories', {
      data: createValidCategory(),
    }).then((r) => r.json());

    const product = await authenticatedRequest.post('/api/admin/products', {
      data: {
        names: { de: `Produkt ${randomUUID().substring(0, 8)}` },
        price_cents: 100,
        category_id: category.id,
      },
    }).then((r) => r.json());

    const deleteProductResponse = await authenticatedRequest.delete(`/api/admin/products/${product.id}`);
    expect(deleteProductResponse.status()).toBe(204);

    const deleteCategoryResponse = await authenticatedRequest.delete(`/api/admin/categories/${category.id}`);
    expect(deleteCategoryResponse.status()).toBe(204);
  });

  // #416: getWithProductCount() applied no deleted_at filter, so a deleted
  // category stayed in the admin list and deleted products stayed counted.
  test('DELETE /api/admin/categories/{id} removes it from GET /api/admin/categories', async ({ authenticatedRequest }) => {
    const category = await authenticatedRequest.post('/api/admin/categories', {
      data: createValidCategory(),
    }).then((r) => r.json());

    await authenticatedRequest.delete(`/api/admin/categories/${category.id}`);

    const listResponse = await authenticatedRequest.get('/api/admin/categories');
    const body = await listResponse.json();

    expect(body.data.find((c: any) => c.id === category.id)).toBeUndefined();
  });

  test('GET /api/admin/categories product_count excludes soft-deleted products', async ({ authenticatedRequest }) => {
    const category = await authenticatedRequest.post('/api/admin/categories', {
      data: createValidCategory(),
    }).then((r) => r.json());

    const product = await authenticatedRequest.post('/api/admin/products', {
      data: {
        names: { de: `Produkt ${randomUUID().substring(0, 8)}` },
        price_cents: 100,
        category_id: category.id,
      },
    }).then((r) => r.json());

    await authenticatedRequest.delete(`/api/admin/products/${product.id}`);

    const listResponse = await authenticatedRequest.get('/api/admin/categories');
    const body = await listResponse.json();
    const found = body.data.find((c: any) => c.id === category.id);

    expect(found).toBeDefined();
    expect(found.product_count).toBe(0);
  });
});

// Test: Terminal Sync
test.describe('Categories API - Terminal Sync', () => {
  test('GET /api/sync/categories includes created category', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const created = await authenticatedRequest.post('/api/admin/categories', {
      data: createValidCategory(),
    }).then(r => r.json());

    const response = await authenticatedTerminalRequest.get('/api/sync/categories');
    expect(response.ok()).toBeTruthy();

    const body = await response.json();
    expect(typeof body.cursor).toBe('number');
    expect(typeof body.count).toBe('number');

    // Sync keeps its own cursor envelope, keyed by entity — the admin list's
    // `data` envelope is a different contract and does not apply here.
    const found = body.categories.find((c: any) => c.id === created.id);
    expect(found).toBeDefined();
  });

  test('GET /api/sync/categories returns all categories including inactive', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    // Create and deactivate a category
    const createResponse = await authenticatedRequest.post('/api/admin/categories', {
      data: createValidCategory(),
    });
    const category = await createResponse.json();

    await authenticatedRequest.patch(`/api/admin/categories/${category.id}/status`, {
      data: { is_active: false },
    });

    const response = await authenticatedTerminalRequest.get('/api/sync/categories');
    const body = await response.json();

    // Sync should return ALL categories (both active and inactive) so UI can track state
    const inactiveInResponse = body.categories.find((c: any) => c.id === category.id);

    expect(inactiveInResponse).toBeDefined();
    expect(inactiveInResponse.is_active).toBe(false);
  });

  test('GET /api/sync/categories since parameter filters correctly', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    // MySQL DATETIME has second precision — wait 1.1s for timestamp boundary
    await new Promise((r) => setTimeout(r, 1100));
    const sinceTs = Math.floor(Date.now() / 1000);

    const created = await authenticatedRequest.post('/api/admin/categories', {
      data: createValidCategory(),
    }).then(r => r.json());

    const response = await authenticatedTerminalRequest.get(`/api/sync/categories?since=${sinceTs}`);
    expect(response.ok()).toBeTruthy();

    const found = (await response.json()).categories.find((c: any) => c.id === created.id);
    expect(found).toBeDefined();
  });

  test('GET /api/sync/categories cursor is a number', async ({ authenticatedTerminalRequest }) => {
    const body = await authenticatedTerminalRequest.get('/api/sync/categories').then(r => r.json());
    expect(typeof body.cursor).toBe('number');
  });
});

// Test: Icon Name Support
test.describe('Categories API - Icon Support', () => {
  test('POST /api/admin/categories accepts icon_name', async ({ authenticatedRequest }) => {
    const categoryData = createValidCategory({
      icon_name: 'CategoryIcon',
    });

    const response = await authenticatedRequest.post('/api/admin/categories', {
      data: categoryData,
    });

    expect(response.status()).toBe(201);
    const body = await response.json();
    expect(body.icon_name).toBe('CategoryIcon');
  });

  test('POST /api/admin/categories allows null icon_name', async ({ authenticatedRequest }) => {
    const categoryData = createValidCategory({
      icon_name: null,
    });

    const response = await authenticatedRequest.post('/api/admin/categories', {
      data: categoryData,
    });

    expect(response.status()).toBe(201);
    const body = await response.json();
    expect(body.icon_name).toBeNull();
  });

  test('POST /api/admin/categories returns icon_name in response', async ({ authenticatedRequest }) => {
    const categoryData = createValidCategory({
      icon_name: 'CategoryTagsIcon',
    });

    const response = await authenticatedRequest.post('/api/admin/categories', {
      data: categoryData,
    });

    const body = await response.json();
    expect(body.icon_name).toBeDefined();
    expect(body.icon_name).toBe('CategoryTagsIcon');
  });

  test('PATCH /api/admin/categories/{id} updates icon_name', async ({ authenticatedRequest }) => {
    const createResponse = await authenticatedRequest.post('/api/admin/categories', {
      data: createValidCategory({
        icon_name: 'CategoryIcon',
      }),
    });

    const category = await createResponse.json();

    const updateResponse = await authenticatedRequest.patch(
      `/api/admin/categories/${category.id}`,
      {
        data: { icon_name: 'CategoryTagsIcon' },
      }
    );

    const updatedCategory = await updateResponse.json();
    expect(updatedCategory.icon_name).toBe('CategoryTagsIcon');
  });

  test('PATCH /api/admin/categories/{id} allows clearing icon_name', async ({ authenticatedRequest }) => {
    const createResponse = await authenticatedRequest.post('/api/admin/categories', {
      data: createValidCategory({
        icon_name: 'CategoryIcon',
      }),
    });

    const category = await createResponse.json();

    const updateResponse = await authenticatedRequest.patch(
      `/api/admin/categories/${category.id}`,
      {
        data: { icon_name: null },
      }
    );

    const updatedCategory = await updateResponse.json();
    expect(updatedCategory.icon_name).toBeNull();
  });

  test('GET /api/sync/categories includes icon_name for category with icon', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const created = await authenticatedRequest.post('/api/admin/categories', {
      data: createValidCategory({ icon_name: 'CategoryLayersIcon' }),
    }).then(r => r.json());

    const response = await authenticatedTerminalRequest.get('/api/sync/categories', {
      params: { since: '1970-01-01T00:00:00Z' },
    });
    const found = (await response.json()).categories.find((c: any) => c.id === created.id);
    expect(found).toBeDefined();
    expect(found.icon_name).toBe('CategoryLayersIcon');
  });

  test('POST /api/admin/categories rejects invalid icon_name length', async ({ authenticatedRequest }) => {
    const categoryData = createValidCategory({
      icon_name: 'a'.repeat(51), // Exceeds 50 char limit
    });

    const response = await authenticatedRequest.post('/api/admin/categories', {
      data: categoryData,
    });

    expect(response.status()).toBe(422);
  });
});
