import { test, expect } from '@playwright/test';

/**
 * Sync Products endpoint tests
 *
 * Tests the GET /api/sync/products endpoint per Terminal API spec (api/terminal.yaml).
 * Returns products modified since the `since` timestamp (delta sync).
 */

const validToken = process.env.TEST_TERMINAL_TOKEN;
const authHeaders = validToken ? { 'Authorization': `Bearer ${validToken}` } : {};

test.describe('Sync Products Endpoint', () => {
  test('GET /api/sync/products returns product delta response', async ({ request }) => {
    const response = await request.get('/api/sync/products', {
      headers: authHeaders,
      params: { since: '1970-01-01T00:00:00Z' },
    });

    expect(response.ok()).toBeTruthy();
    expect(response.status()).toBe(200);

    const body = await response.json();

    // Validate response structure per OAS ProductDeltaResponse schema
    expect(body.products).toBeDefined();
    expect(Array.isArray(body.products)).toBeTruthy();
    expect(body.cursor).toBeDefined();
    expect(typeof body.count).toBe('number');
    expect(typeof body.has_more).toBe('boolean');
  });

  test('GET /api/sync/products returns valid product objects', async ({ request }) => {
    const response = await request.get('/api/sync/products', {
      headers: authHeaders,
      params: { since: '1970-01-01T00:00:00Z' },
    });

    const body = await response.json();
    expect(body.products.length).toBeGreaterThan(0);

    const product = body.products[0];

    // Validate product structure per OAS Product schema
    expect(product.id).toBeDefined();
    expect(product.names).toBeDefined();
    expect(typeof product.names).toBe('object');
    expect(typeof product.price_cents).toBe('number');
    expect(product.price_cents).toBeGreaterThanOrEqual(0);
    expect(product.category_id).toBeDefined();
    expect(typeof product.is_active).toBe('boolean');
    expect(product.created_at).toBeDefined();
    expect(product.updated_at).toBeDefined();
  });

  test('GET /api/sync/products returns multilingual names and descriptions', async ({ request }) => {
    const response = await request.get('/api/sync/products', {
      headers: authHeaders,
      params: { since: '1970-01-01T00:00:00Z' },
    });

    const body = await response.json();
    const product = body.products[0];

    // Products should have at least one language translation for names
    const nameLanguages = Object.keys(product.names);
    expect(nameLanguages.length).toBeGreaterThan(0);

    // Each name translation should be a string
    for (const lang of nameLanguages) {
      expect(typeof product.names[lang]).toBe('string');
    }

    // Descriptions can be null or an object with translations
    if (product.descriptions !== null) {
      expect(typeof product.descriptions).toBe('object');
    }
  });

  test('GET /api/sync/products returns valid price in cents', async ({ request }) => {
    const response = await request.get('/api/sync/products', {
      headers: authHeaders,
      params: { since: '1970-01-01T00:00:00Z' },
    });

    const body = await response.json();

    for (const product of body.products) {
      // Price should be a non-negative integer (cents)
      expect(Number.isInteger(product.price_cents)).toBeTruthy();
      expect(product.price_cents).toBeGreaterThanOrEqual(0);
    }
  });

  test('GET /api/sync/products count matches products array length', async ({ request }) => {
    const response = await request.get('/api/sync/products', {
      headers: authHeaders,
      params: { since: '1970-01-01T00:00:00Z' },
    });

    const body = await response.json();
    expect(body.count).toBe(body.products.length);
  });

  test('GET /api/sync/products returns JSON content type', async ({ request }) => {
    const response = await request.get('/api/sync/products', {
      headers: authHeaders,
      params: { since: '1970-01-01T00:00:00Z' },
    });

    const contentType = response.headers()['content-type'];
    expect(contentType).toContain('application/json');
  });
});
