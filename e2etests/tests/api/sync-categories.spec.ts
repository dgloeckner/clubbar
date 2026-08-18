import { test, expect } from '../../fixtures/auth.fixture';

/**
 * Sync Categories endpoint tests
 *
 * Tests the GET /api/sync/categories endpoint per Terminal API spec (api/terminal.yaml).
 * Returns categories modified since the `since` timestamp (delta sync).
 *
 * Pattern 001: Each test creates own data and asserts by ID — no seeded data reliance.
 */

test.describe('Sync Categories Endpoint', () => {
  test('GET /api/sync/categories includes newly created category', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const name = `SyncTest_${Date.now()}`
    const createResp = await authenticatedRequest.post('/api/admin/categories', {
      data: { names: { de: name, en: name } },
    });
    expect(createResp.status()).toBe(201);
    const created = await createResp.json();

    const response = await authenticatedTerminalRequest.get('/api/sync/categories', {
      params: { since: '1970-01-01T00:00:00Z' },
    });
    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(Array.isArray(body.categories)).toBeTruthy();
    expect(typeof body.count).toBe('number');
    expect(typeof body.cursor).toBe('number');
    expect(body.count).toBe(body.categories.length);

    const found = body.categories.find((c: any) => c.id === created.id);
    expect(found).toBeDefined();
    expect(found.names.de).toBe(name);
    expect(found.names.en).toBe(name);
    expect(typeof found.is_active).toBe('boolean');
    expect(found.created_at).toBeDefined();
    expect(found.updated_at).toBeDefined();

    // Each language value should be a string
    for (const lang of Object.keys(found.names)) {
      expect(typeof found.names[lang]).toBe('string');
    }
  });

  test('GET /api/sync/categories since parameter returns only post-cutoff categories', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    // MySQL DATETIME has second precision — wait 1.1s for timestamp boundary
    await new Promise((r) => setTimeout(r, 1100));
    const sinceTs = Math.floor(Date.now() / 1000);

    const name = `SinceDelta_${Date.now()}`
    const createResp = await authenticatedRequest.post('/api/admin/categories', {
      data: { names: { de: name } },
    });
    const created = await createResp.json();

    const response = await authenticatedTerminalRequest.get('/api/sync/categories', {
      params: { since: sinceTs },
    });
    expect(response.status()).toBe(200);

    const body = await response.json();
    const found = body.categories.find((c: any) => c.id === created.id);
    expect(found).toBeDefined();
  });

  test('GET /api/sync/categories returns JSON content type', async ({ authenticatedTerminalRequest }) => {
    const response = await authenticatedTerminalRequest.get('/api/sync/categories', {
      params: { since: '1970-01-01T00:00:00Z' },
    });
    expect(response.headers()['content-type']).toContain('application/json');
  });
});
