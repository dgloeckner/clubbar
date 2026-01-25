import { test, expect } from '../../fixtures/auth.fixture';
import { APIRequestContext } from '@playwright/test';
import { randomUUID } from 'crypto';

/**
 * Categories API Tests
 *
 * Tests the product categories management endpoints.
 * Covers CRUD operations for admin API and delta sync for terminal API.
 *
 * Admin Endpoints: Uses auth.fixture for authenticated requests (admin session)
 * Terminal Endpoints: Uses TEST_TERMINAL_TOKEN environment variable
 *
 * Uses E2E Pattern 001: Test Data Isolation
 * - Each test creates unique test data
 * - Tests are independent and can run in parallel
 * - No shared or mutated state between tests
 */

const TERMINAL_TOKEN = process.env.TEST_TERMINAL_TOKEN;

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

// Helper for making authenticated terminal API requests
async function terminalRequest(context: APIRequestContext, method: string, path: string, options?: any) {
  if (!TERMINAL_TOKEN) {
    throw new Error('TEST_TERMINAL_TOKEN not set');
  }

  const headers = {
    ...options?.headers,
    'Authorization': `Bearer ${TERMINAL_TOKEN}`,
  };

  const requestOptions = { ...options, headers };

  switch (method) {
    case 'GET':
      return context.get(path, requestOptions);
    case 'POST':
      return context.post(path, requestOptions);
    case 'PATCH':
      return context.patch(path, requestOptions);
    case 'DELETE':
      return context.delete(path, requestOptions);
    default:
      throw new Error(`Unknown method: ${method}`);
  }
}

// Test: List Categories
test.describe('Categories API - List', () => {
  test('GET /api/admin/categories returns category list', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/categories');

    expect(response.ok()).toBeTruthy();
    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(body.categories).toBeDefined();
    expect(Array.isArray(body.categories)).toBeTruthy();
  });

  test('GET /api/admin/categories includes product count', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/categories');
    const body = await response.json();

    if (body.categories.length > 0) {
      const category = body.categories[0];
      expect(category.product_count).toBeDefined();
      expect(typeof category.product_count).toBe('number');
    }
  });

  test('GET /api/admin/categories returns category with names', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/categories');
    const body = await response.json();

    if (body.categories.length > 0) {
      const category = body.categories[0];
      expect(category.names).toBeDefined();
      expect(typeof category.names).toBe('object');
    }
  });

  test('GET /api/admin/categories returns category with is_active flag', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/categories');
    const body = await response.json();

    if (body.categories.length > 0) {
      const category = body.categories[0];
      expect(category.is_active).toBeDefined();
      expect(typeof category.is_active).toBe('boolean');
    }
  });

  test('GET /api/admin/categories returns category with display_order', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/categories');
    const body = await response.json();

    if (body.categories.length > 0) {
      const category = body.categories[0];
      expect(category.display_order).toBeDefined();
      expect(typeof category.display_order).toBe('number');
    }
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
    expect(body.display_order).toBeDefined();
  });

  test('POST /api/admin/categories auto-assigns display_order', async ({ authenticatedRequest }) => {
    const categoryData = createValidCategory();

    const response = await authenticatedRequest.post('/api/admin/categories', {
      data: categoryData,
    });

    const body = await response.json();
    expect(body.display_order).toBeGreaterThan(0);
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
});

// Test: Terminal Sync
test.describe('Categories API - Terminal Sync', () => {
  test('GET /api/sync/categories returns category list', async ({ request }) => {
    if (!TERMINAL_TOKEN) {
      test.skip(true, 'TEST_TERMINAL_TOKEN not set');
    }

    const response = await terminalRequest(request, 'GET', '/api/sync/categories');

    expect(response.ok()).toBeTruthy();

    const body = await response.json();
    expect(body.categories).toBeDefined();
    expect(Array.isArray(body.categories)).toBeTruthy();
    expect(body.cursor).toBeDefined();
    expect(body.count).toBeDefined();
    expect(body.has_more).toBeDefined();
  });

  test('GET /api/sync/categories returns only active categories', async ({ authenticatedRequest, request }) => {
    if (!TERMINAL_TOKEN) {
      test.skip(true, 'TEST_TERMINAL_TOKEN not set');
    }

    // Create and deactivate a category
    const createResponse = await authenticatedRequest.post('/api/admin/categories', {
      data: createValidCategory(),
    });
    const category = await createResponse.json();

    await authenticatedRequest.patch(`/api/admin/categories/${category.id}/status`, {
      data: { is_active: false },
    });

    const response = await terminalRequest(request, 'GET', '/api/sync/categories');
    const body = await response.json();

    // All returned categories should be active
    for (const cat of body.categories) {
      expect(cat.is_active).toBe(true);
    }
  });

  test('GET /api/sync/categories respects since parameter', async ({ request }) => {
    if (!TERMINAL_TOKEN) {
      test.skip(true, 'TEST_TERMINAL_TOKEN not set');
    }

    const oldTimestamp = Math.floor(Date.now() / 1000) - 3600; // 1 hour ago

    const response = await terminalRequest(request, 'GET', `/api/sync/categories?since=${oldTimestamp}`);

    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.categories).toBeDefined();
  });

  test('GET /api/sync/categories returns cursor for pagination', async ({ request }) => {
    if (!TERMINAL_TOKEN) {
      test.skip(true, 'TEST_TERMINAL_TOKEN not set');
    }

    const response = await terminalRequest(request, 'GET', '/api/sync/categories');
    const body = await response.json();

    expect(body.cursor).toBeDefined();
    expect(typeof body.cursor).toBe('string');
  });
});
