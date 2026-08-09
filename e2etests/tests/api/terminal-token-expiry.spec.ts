import { test, expect } from '../../fixtures/auth.fixture';
import { TEST_CREDENTIALS } from '../../config/test-credentials';

/**
 * Terminal API token expiry (#106)
 *
 * `API_TOKEN_TTL_DAYS` used to be read into config and never enforced: a token
 * lifted from a decommissioned or stolen POS device kept the member roster and
 * the transaction-write endpoints open until an admin revoked that one
 * terminal by hand.
 *
 * These tests exercise the whole stack — admin API issues a token, terminal API
 * authenticates with it, the database decides whether it is still alive.
 *
 * The expired terminal is seeded (backend/db/seed.sql) rather than created
 * here: no API can produce a token that is already past its expiry, which is
 * exactly why the state has to come from the seed.
 */

const SYNC_MEMBERS = '/api/sync/members';
const SINCE = { since: '1970-01-01T00:00:00Z' };

/** Whole days between two ISO timestamps, rounded to the nearest day. */
function daysBetween(fromIso: string, toIso: string): number {
  const ms = new Date(toIso).getTime() - new Date(fromIso).getTime();
  return Math.round(ms / 86_400_000);
}

test.describe('Terminal token expiry', () => {
  test('an expired token is refused with terminal_token_expired', async ({ request }) => {
    const response = await request.get(SYNC_MEMBERS, {
      headers: { Authorization: `Bearer ${TEST_CREDENTIALS.expiredTerminal.token}` },
      params: SINCE,
    });

    expect(response.status()).toBe(401);
    const body = await response.json();
    expect(body.error).toBe('terminal_token_expired');
    // The 401 has to say what to do about it — an admin rotates the token.
    expect(body.message).toContain('expired');
  });

  test('an expired token cannot write transactions either', async ({ request }) => {
    const response = await request.post('/api/sync/transactions', {
      headers: { Authorization: `Bearer ${TEST_CREDENTIALS.expiredTerminal.token}` },
      data: { transactions: [] },
    });

    expect(response.status()).toBe(401);
    expect((await response.json()).error).toBe('terminal_token_expired');
  });

  test('an expired token is refused on the transaction history endpoint', async ({ request }) => {
    const response = await request.get('/api/terminal/transactions/44e4567-e89b-12d3-a456-426614174000', {
      headers: { Authorization: `Bearer ${TEST_CREDENTIALS.expiredTerminal.token}` },
    });

    expect(response.status()).toBe(401);
    expect((await response.json()).error).toBe('terminal_token_expired');
  });

  test('an unknown token is still invalid_terminal_token, not expired', async ({ request }) => {
    const response = await request.get(SYNC_MEMBERS, {
      headers: { Authorization: 'Bearer never-issued-to-anyone-0123456789abcdef' },
      params: SINCE,
    });

    expect(response.status()).toBe(401);
    expect((await response.json()).error).toBe('invalid_terminal_token');
  });

  test('the seeded terminal token is within its lifetime and still syncs', async ({ request }) => {
    const response = await request.get(SYNC_MEMBERS, {
      headers: { Authorization: `Bearer ${TEST_CREDENTIALS.terminal.token}` },
      params: SINCE,
    });

    expect(response.status()).toBe(200);
  });

  test('creating a terminal issues a token with a lifetime, and that token works', async ({
    authenticatedRequest,
    request,
  }) => {
    const timestamp = Date.now();
    const createResponse = await authenticatedRequest.post('/api/admin/terminals', {
      data: { name: `Expiry Create ${timestamp}`, device_id: `device-expiry-create-${timestamp}` },
    });

    expect(createResponse.status()).toBe(201);
    const created = await createResponse.json();

    expect(created.terminal.token_issued_at).toBeTruthy();
    expect(created.terminal.token_expires_at).toBeTruthy();
    expect(daysBetween(created.terminal.token_issued_at, created.terminal.token_expires_at)).toBe(90);

    // The lifetime is not decoration: the fresh token authenticates.
    const syncResponse = await request.get(SYNC_MEMBERS, {
      headers: { Authorization: `Bearer ${created.api_token}` },
      params: SINCE,
    });
    expect(syncResponse.status()).toBe(200);
  });

  test('the terminal list reports when each token expires', async ({ authenticatedRequest }) => {
    const timestamp = Date.now();
    const createResponse = await authenticatedRequest.post('/api/admin/terminals', {
      data: { name: `Expiry List ${timestamp}`, device_id: `device-expiry-list-${timestamp}` },
    });
    const created = await createResponse.json();

    const showResponse = await authenticatedRequest.get(`/api/admin/terminals/${created.terminal.id}`);
    expect(showResponse.status()).toBe(200);

    const { terminal } = await showResponse.json();
    expect(terminal.token_expires_at).toBe(created.terminal.token_expires_at);
    expect(new Date(terminal.token_expires_at).getTime()).toBeGreaterThan(Date.now());
  });

  test('rotating a token restarts the lifetime and retires the old token', async ({
    authenticatedRequest,
    request,
  }) => {
    const timestamp = Date.now();
    const createResponse = await authenticatedRequest.post('/api/admin/terminals', {
      data: { name: `Expiry Rotate ${timestamp}`, device_id: `device-expiry-rotate-${timestamp}` },
    });
    const created = await createResponse.json();

    const rotateResponse = await authenticatedRequest.post(
      `/api/admin/terminals/${created.terminal.id}/rotate-token`
    );
    expect(rotateResponse.status()).toBe(200);
    const rotated = await rotateResponse.json();

    expect(rotated.api_token).not.toBe(created.api_token);
    expect(daysBetween(rotated.terminal.token_issued_at, rotated.terminal.token_expires_at)).toBe(90);
    expect(new Date(rotated.terminal.token_expires_at).getTime()).toBeGreaterThanOrEqual(
      new Date(created.terminal.token_expires_at).getTime()
    );

    const withNewToken = await request.get(SYNC_MEMBERS, {
      headers: { Authorization: `Bearer ${rotated.api_token}` },
      params: SINCE,
    });
    expect(withNewToken.status()).toBe(200);

    const withOldToken = await request.get(SYNC_MEMBERS, {
      headers: { Authorization: `Bearer ${created.api_token}` },
      params: SINCE,
    });
    expect(withOldToken.status()).toBe(401);
  });

  test('revoking access clears the token lifetime', async ({ authenticatedRequest }) => {
    const timestamp = Date.now();
    const createResponse = await authenticatedRequest.post('/api/admin/terminals', {
      data: { name: `Expiry Revoke ${timestamp}`, device_id: `device-expiry-revoke-${timestamp}` },
    });
    const created = await createResponse.json();

    const revokeResponse = await authenticatedRequest.post(
      `/api/admin/terminals/${created.terminal.id}/revoke`
    );
    expect(revokeResponse.status()).toBe(200);

    const showResponse = await authenticatedRequest.get(`/api/admin/terminals/${created.terminal.id}`);
    const { terminal } = await showResponse.json();

    expect(terminal.is_active).toBe(false);
    expect(terminal.token_issued_at).toBeNull();
    expect(terminal.token_expires_at).toBeNull();
  });
});
