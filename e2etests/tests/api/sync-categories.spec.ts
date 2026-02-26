import { test, expect } from '../../fixtures/auth.fixture';

/**
 * Sync Categories endpoint tests
 *
 * Tests the GET /api/sync/categories endpoint per Terminal API spec (api/terminal.yaml).
 * Returns categories modified since the `since` timestamp (delta sync).
 */

test.describe('Sync Categories Endpoint', () => {
  test('GET /api/sync/categories returns category delta response', async ({ authenticatedTerminalRequest }) => {
    const response = await authenticatedTerminalRequest.get('/api/sync/categories', {
      params: { since: '1970-01-01T00:00:00Z' },
    });

    expect(response.ok()).toBeTruthy();
    expect(response.status()).toBe(200);

    const body = await response.json();

    // Validate response structure per OAS CategoryDeltaResponse schema
    expect(body.categories).toBeDefined();
    expect(Array.isArray(body.categories)).toBeTruthy();
    expect(body.cursor).toBeDefined();
    expect(typeof body.count).toBe('number');
    expect(typeof body.has_more).toBe('boolean');
  });

  test('GET /api/sync/categories returns valid category objects', async ({ authenticatedTerminalRequest }) => {
    const response = await authenticatedTerminalRequest.get('/api/sync/categories', {
      params: { since: '1970-01-01T00:00:00Z' },
    });

    const body = await response.json();
    expect(body.categories.length).toBeGreaterThan(0);

    const category = body.categories[0];

    // Validate category structure per OAS Category schema
    expect(category.id).toBeDefined();
    expect(category.names).toBeDefined();
    expect(typeof category.names).toBe('object');
    expect(typeof category.is_active).toBe('boolean');
    expect(category.created_at).toBeDefined();
    expect(category.updated_at).toBeDefined();
  });

  test('GET /api/sync/categories returns multilingual names', async ({ authenticatedTerminalRequest }) => {
    const response = await authenticatedTerminalRequest.get('/api/sync/categories', {
      params: { since: '1970-01-01T00:00:00Z' },
    });

    const body = await response.json();
    const category = body.categories[0];

    // Categories should have at least one language translation
    const languages = Object.keys(category.names);
    expect(languages.length).toBeGreaterThan(0);

    // Each translation should be a string
    for (const lang of languages) {
      expect(typeof category.names[lang]).toBe('string');
    }
  });

  test('GET /api/sync/categories count matches categories array length', async ({ authenticatedTerminalRequest }) => {
    const response = await authenticatedTerminalRequest.get('/api/sync/categories', {
      params: { since: '1970-01-01T00:00:00Z' },
    });

    const body = await response.json();
    expect(body.count).toBe(body.categories.length);
  });

  test('GET /api/sync/categories returns JSON content type', async ({ authenticatedTerminalRequest }) => {
    const response = await authenticatedTerminalRequest.get('/api/sync/categories', {
      params: { since: '1970-01-01T00:00:00Z' },
    });

    const contentType = response.headers()['content-type'];
    expect(contentType).toContain('application/json');
  });
});
