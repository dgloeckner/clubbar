import { test, expect } from '../../fixtures/auth.fixture';

/**
 * Admin Members List endpoint tests
 *
 * Tests the GET /api/admin/members endpoint per Admin API spec.
 * Returns paginated list of members with optional filters.
 */

test.describe('Admin Members List Endpoint', () => {
  test('GET /api/admin/members returns paginated member list', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members');

    expect(response.ok()).toBeTruthy();
    expect(response.status()).toBe(200);

    const body = await response.json();

    // Validate response structure
    expect(body.items).toBeDefined();
    expect(Array.isArray(body.items)).toBeTruthy();
    expect(body.items.length).toBeGreaterThan(0);
    expect(typeof body.total).toBe('number');
    expect(typeof body.limit).toBe('number');
    expect(typeof body.offset).toBe('number');
    expect(typeof body.has_more).toBe('boolean');
  });

  test('GET /api/admin/members returns member objects with admin fields', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members');
    const body = await response.json();

    const member = body.items[0];

    // Verify all required admin fields
    expect(member.id).toBeDefined();
    expect(member.first_name).toBeDefined();
    expect(member.last_name).toBeDefined();
    expect(member.email).toBeDefined();
    expect(member.phone).toBeDefined();
    expect(member.preferred_language).toBeDefined();
    expect(member.is_active).toBeDefined();
    expect(member.is_sepa_valid).toBeDefined();
    expect(member.iban_masked).toBeDefined();
    expect(member.created_at).toBeDefined();
    expect(member.updated_at).toBeDefined();
  });

  test('GET /api/admin/members respects limit parameter', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members', {
      params: { limit: 1 },
    });

    const body = await response.json();

    expect(body.limit).toBe(1);
    expect(body.items.length).toBeLessThanOrEqual(1);
  });

  test('GET /api/admin/members respects offset parameter', async ({ authenticatedRequest }) => {
    // Use stable sort by id to ensure consistent ordering across paginated requests.
    // Without an explicit sort, records with identical created_at timestamps (MySQL second
    // precision) return in non-deterministic order, causing offset pagination to return
    // the same record at different offsets.
    const params = { limit: 1, sort: 'id', order: 'asc' };

    // Get first member
    const response1 = await authenticatedRequest.get('/api/admin/members', {
      params: { ...params, offset: 0 },
    });

    const body1 = await response1.json();
    const firstMemberId = body1.items[0].id;

    // Get second member
    const response2 = await authenticatedRequest.get('/api/admin/members', {
      params: { ...params, offset: 1 },
    });

    const body2 = await response2.json();

    // They should be different if multiple members exist
    if (body1.total > 1) {
      expect(body2.items[0].id).not.toBe(firstMemberId);
    }
  });

  test('GET /api/admin/members rejects limit greater than 100', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members', {
      params: { limit: 500 },
    });

    expect(response.status()).toBe(400);

    const body = await response.json();

    expect(body.error).toBe('invalid_request');
    expect(body.messages).toBeDefined();
    expect(body.messages.limit).toBeDefined();
  });

  test('GET /api/admin/members calculates has_more correctly', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members');
    const body = await response.json();

    const hasMore = (body.offset + body.limit) < body.total;
    expect(body.has_more).toBe(hasMore);
  });

  test('GET /api/admin/members filters by is_active', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members', {
      params: { 'filters[is_active]': 'true' },
    });

    const body = await response.json();

    // All returned members should be active
    for (const member of body.items) {
      expect(member.is_active).toBe(true);
    }
  });

  test('GET /api/admin/members filters by language', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members', {
      params: { 'filters[language]': 'de' },
    });

    const body = await response.json();

    // All returned members should have German language preference
    for (const member of body.items) {
      expect(member.preferred_language).toBe('de');
    }
  });

  test('GET /api/admin/members returns valid ISO 8601 timestamps', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members');
    const body = await response.json();

    const member = body.items[0];

    // Validate ISO 8601 format (YYYY-MM-DDTHH:mm:ssZ)
    const iso8601Regex = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;
    expect(member.created_at).toMatch(iso8601Regex);
    expect(member.updated_at).toMatch(iso8601Regex);
  });

  test('GET /api/admin/members returns JSON content type', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members');

    const contentType = response.headers()['content-type'];
    expect(contentType).toContain('application/json');
  });

  test('GET /api/admin/members with invalid limit returns 400', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members', {
      params: { limit: 'invalid' },
    });

    expect(response.status()).toBe(400);
  });
});
