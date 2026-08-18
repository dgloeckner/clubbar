import { test, expect } from '../../fixtures/auth.fixture';

/**
 * Sync Members endpoint tests
 *
 * Tests the GET /api/sync/members endpoint per Terminal API spec (api/terminal.yaml).
 * Returns members modified since the `since` timestamp (delta sync).
 */

test.describe('Sync Members Endpoint', () => {
  test('GET /api/sync/members returns member delta response', async ({ authenticatedTerminalRequest }) => {
    const response = await authenticatedTerminalRequest.get('/api/sync/members', {
      params: { since: '1970-01-01T00:00:00Z' },
    });

    expect(response.ok()).toBeTruthy();
    expect(response.status()).toBe(200);

    const body = await response.json();

    // Validate response structure per OAS MemberDeltaResponse schema
    expect(body.members).toBeDefined();
    expect(Array.isArray(body.members)).toBeTruthy();
    expect(body.cursor).toBeDefined();
    expect(typeof body.count).toBe('number');
    // Regression guard: findModifiedSince has no LIMIT, so there is never a
    // "more" to report — has_more must not reappear as a hand-set flag.
    expect(body).not.toHaveProperty('has_more');
  });

  test('GET /api/sync/members returns valid member objects', async ({ authenticatedTerminalRequest }) => {
    const response = await authenticatedTerminalRequest.get('/api/sync/members', {
      params: { since: '1970-01-01T00:00:00Z' },
    });

    const body = await response.json();
    expect(body.members.length).toBeGreaterThan(0);

    const member = body.members[0];

    // Validate member structure per OAS Member schema
    expect(member.id).toBeDefined();
    expect(member.card_uid).toBeDefined();
    expect(typeof member.first_name === 'string' || member.first_name === null).toBeTruthy();
    expect(typeof member.last_name === 'string' || member.last_name === null).toBeTruthy();
    expect(member.preferred_language).toBeDefined();
    expect(typeof member.is_active).toBe('boolean');
    expect(typeof member.is_sepa_valid).toBe('boolean');
    expect(member.created_at).toBeDefined();
    expect(member.updated_at).toBeDefined();
  });

  test('GET /api/sync/members count matches members array length', async ({ authenticatedTerminalRequest }) => {
    const response = await authenticatedTerminalRequest.get('/api/sync/members', {
      params: { since: '1970-01-01T00:00:00Z' },
    });

    const body = await response.json();
    expect(body.count).toBe(body.members.length);
  });

  test('GET /api/sync/members returns JSON content type', async ({ authenticatedTerminalRequest }) => {
    const response = await authenticatedTerminalRequest.get('/api/sync/members', {
      params: { since: '1970-01-01T00:00:00Z' },
    });

    const contentType = response.headers()['content-type'];
    expect(contentType).toContain('application/json');
  });
});
