import { test, expect } from '../../fixtures/auth.fixture';

/**
 * Admin Members List endpoint tests
 *
 * Tests the GET /api/admin/members endpoint per Admin API spec.
 * Returns paginated list of members with optional filters.
 * Response shape: { data: MemberListItem[], pagination: { page, per_page, total, total_pages } }
 */

test.describe('Admin Members List Endpoint', () => {
  test('GET /api/admin/members returns paginated member list', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members');

    expect(response.ok()).toBeTruthy();
    expect(response.status()).toBe(200);

    const body = await response.json();

    // Validate OAS-compliant response structure
    expect(body.data).toBeDefined();
    expect(Array.isArray(body.data)).toBeTruthy();
    expect(body.data.length).toBeGreaterThan(0);
    expect(body.pagination).toBeDefined();
    expect(typeof body.pagination.total).toBe('number');
    expect(typeof body.pagination.page).toBe('number');
    expect(typeof body.pagination.per_page).toBe('number');
    expect(typeof body.pagination.total_pages).toBe('number');
  });

  test('GET /api/admin/members returns member objects with admin fields', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members');
    const body = await response.json();

    const member = body.data[0];

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

  test('GET /api/admin/members respects per_page parameter', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members', {
      params: { per_page: 1 },
    });

    const body = await response.json();

    expect(body.pagination.per_page).toBe(1);
    expect(body.data.length).toBeLessThanOrEqual(1);
  });

  test('GET /api/admin/members respects page parameter', async ({ authenticatedRequest }) => {
    // Use stable sort by id to ensure consistent ordering across paginated requests.
    const params = { per_page: 1, sort: 'id', order: 'asc' };

    // Get first member (page 1)
    const response1 = await authenticatedRequest.get('/api/admin/members', {
      params: { ...params, page: 1 },
    });

    const body1 = await response1.json();
    const firstMemberId = body1.data[0].id;

    // Get second member (page 2)
    const response2 = await authenticatedRequest.get('/api/admin/members', {
      params: { ...params, page: 2 },
    });

    const body2 = await response2.json();

    // They should be different if multiple members exist
    if (body1.pagination.total > 1) {
      expect(body2.data[0].id).not.toBe(firstMemberId);
    }
  });

  test('GET /api/admin/members rejects per_page greater than 100', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members', {
      params: { per_page: 500 },
    });

    expect(response.status()).toBe(400);

    const body = await response.json();

    expect(body.error).toBe('invalid_request');
    expect(body.messages).toBeDefined();
    expect(body.messages.per_page).toBeDefined();
  });

  test('GET /api/admin/members pagination totals are consistent', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members');
    const body = await response.json();

    const { total, per_page, total_pages } = body.pagination;
    expect(total_pages).toBe(Math.ceil(total / per_page));
  });

  test('GET /api/admin/members filters by is_active', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members', {
      params: { 'filters[is_active]': 'true' },
    });

    const body = await response.json();

    // All returned members should be active
    for (const member of body.data) {
      expect(member.is_active).toBe(true);
    }
  });

  test('GET /api/admin/members filters by language', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members', {
      params: { 'filters[language]': 'de' },
    });

    const body = await response.json();

    // All returned members should have German language preference
    for (const member of body.data) {
      expect(member.preferred_language).toBe('de');
    }
  });

  test('GET /api/admin/members returns valid ISO 8601 timestamps', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members');
    const body = await response.json();

    const member = body.data[0];

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

  test('GET /api/admin/members with invalid per_page returns 400', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members', {
      params: { per_page: 'invalid' },
    });

    expect(response.status()).toBe(400);
  });
});
