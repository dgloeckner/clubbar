import { test, expect } from '../../fixtures/auth.fixture';
import { TEST_CREDENTIALS } from '../../config/test-credentials';
import { stepUp } from '../../fixtures/stepUp';

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
 * The lifetime is now 365 days and rotation *overlaps* (#395): a replacement is
 * staged alongside the token in the field, and the first authentication with it
 * promotes it and retires the old one. The rotation tests below are what pin
 * that both tokens work in between, and that exactly one does afterwards.
 *
 * The expired terminal is seeded (backend/db/seed.sql) rather than created
 * here: no API can produce a token that is already past its expiry, which is
 * exactly why the state has to come from the seed.
 */

const SYNC_MEMBERS = '/api/sync/members';
const SINCE = { since: '1970-01-01T00:00:00Z' };

/** The shared credential cryptoperiod (ADR-0036), in days. */
const LIFETIME_DAYS = 365;

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
      data: { ...stepUp(), name: `Expiry Create ${timestamp}`, device_id: `device-expiry-create-${timestamp}` },
    });

    expect(createResponse.status()).toBe(201);
    const created = await createResponse.json();

    expect(created.terminal.token_issued_at).toBeTruthy();
    expect(created.terminal.token_expires_at).toBeTruthy();
    expect(daysBetween(created.terminal.token_issued_at, created.terminal.token_expires_at)).toBe(
      LIFETIME_DAYS
    );

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
      data: { ...stepUp(), name: `Expiry List ${timestamp}`, device_id: `device-expiry-list-${timestamp}` },
    });
    const created = await createResponse.json();

    const showResponse = await authenticatedRequest.get(`/api/admin/terminals/${created.terminal.id}`);
    expect(showResponse.status()).toBe(200);

    const { terminal } = await showResponse.json();
    expect(terminal.token_expires_at).toBe(created.terminal.token_expires_at);
    expect(new Date(terminal.token_expires_at).getTime()).toBeGreaterThan(Date.now());
  });

  /**
   * The whole point of overlap rotation (#395): an admin can prepare the
   * replacement from a desk, and the till keeps selling until somebody walks
   * over. A rotation that killed the old token on the spot could only be done
   * standing at the terminal.
   */
  test('a rotated token is staged and both tokens work until the new one is used', async ({
    authenticatedRequest,
    request,
  }) => {
    const timestamp = Date.now();
    const createResponse = await authenticatedRequest.post('/api/admin/terminals', {
      data: { ...stepUp(), name: `Expiry Rotate ${timestamp}`, device_id: `device-expiry-rotate-${timestamp}` },
    });
    const created = await createResponse.json();

    const rotateResponse = await authenticatedRequest.post(
      `/api/admin/terminals/${created.terminal.id}/rotate-token`,
      { data: stepUp() }
    );
    expect(rotateResponse.status()).toBe(200);
    const rotated = await rotateResponse.json();

    expect(rotated.api_token).not.toBe(created.api_token);
    expect(rotated.terminal.has_pending_token).toBe(true);
    expect(
      daysBetween(created.terminal.token_issued_at, rotated.terminal.pending_token_expires_at)
    ).toBe(LIFETIME_DAYS);
    // The credential in the field is untouched while the rotation is pending.
    expect(rotated.terminal.token_expires_at).toBe(created.terminal.token_expires_at);

    const withOldTokenDuringOverlap = await request.get(SYNC_MEMBERS, {
      headers: { Authorization: `Bearer ${created.api_token}` },
      params: SINCE,
    });
    expect(withOldTokenDuringOverlap.status()).toBe(200);

    // First use of the new token is what installs it.
    const withNewToken = await request.get(SYNC_MEMBERS, {
      headers: { Authorization: `Bearer ${rotated.api_token}` },
      params: SINCE,
    });
    expect(withNewToken.status()).toBe(200);

    const afterPromotion = await authenticatedRequest.get(
      `/api/admin/terminals/${created.terminal.id}`
    );
    const { terminal } = await afterPromotion.json();
    expect(terminal.has_pending_token).toBe(false);
    expect(terminal.pending_token_expires_at).toBeNull();
    expect(daysBetween(terminal.token_issued_at, terminal.token_expires_at)).toBe(LIFETIME_DAYS);

    // …and the token it replaced stops working at that same moment.
    const withOldTokenAfterPromotion = await request.get(SYNC_MEMBERS, {
      headers: { Authorization: `Bearer ${created.api_token}` },
      params: SINCE,
    });
    expect(withOldTokenAfterPromotion.status()).toBe(401);
  });

  // The recovery path — rotating a terminal whose token has already aged out —
  // is pinned at the repository level instead
  // (TerminalsRepositoryTest::test_a_pending_token_recovers_a_terminal_whose_token_expired).
  // The only expired terminal that exists here is the seeded one, and rotating
  // it would promote a new hash over the very token the four specs above
  // present, so an e2e version of this check would quietly break them under
  // parallel execution (E2E Pattern 001).

  test('the terminal list carries the shared warning tier for each token', async ({
    authenticatedRequest,
  }) => {
    const timestamp = Date.now();
    const createResponse = await authenticatedRequest.post('/api/admin/terminals', {
      data: { ...stepUp(), name: `Expiry Tier ${timestamp}`, device_id: `device-expiry-tier-${timestamp}` },
    });
    const created = await createResponse.json();

    const showResponse = await authenticatedRequest.get(
      `/api/admin/terminals/${created.terminal.id}`
    );
    const { terminal } = await showResponse.json();

    // A token issued seconds ago has its whole year ahead of it, so it sits
    // above every warning threshold.
    expect(terminal.lifecycle_state).toBe('ok');
    expect(terminal.days_until_expiry).toBeGreaterThan(90);
  });

  test('an expired token is reported at the expired tier', async ({ authenticatedRequest }) => {
    const showResponse = await authenticatedRequest.get(
      `/api/admin/terminals/${TEST_CREDENTIALS.expiredTerminal.id}`
    );
    const { terminal } = await showResponse.json();

    expect(terminal.lifecycle_state).toBe('expired');
    expect(terminal.days_until_expiry).toBeLessThan(0);
  });

  test('issuing a terminal token requires a step-up credential', async ({ authenticatedRequest }) => {
    const timestamp = Date.now();

    // A session alone is not enough — the credential this mints reads the
    // member roster and writes transactions (#395).
    const noCredential = await authenticatedRequest.post('/api/admin/terminals', {
      data: { name: `No Step-Up ${timestamp}`, device_id: `device-no-stepup-${timestamp}` },
    });
    expect(noCredential.status()).toBe(422);

    const wrongPassword = await authenticatedRequest.post('/api/admin/terminals', {
      data: {
        ...stepUp({ password: 'not-the-admin-password' }),
        name: `Wrong Step-Up ${timestamp}`,
        device_id: `device-wrong-stepup-${timestamp}`,
      },
    });
    expect(wrongPassword.status()).toBe(401);
    expect((await wrongPassword.json()).error).toBe('invalid_credentials');
  });

  test('rotating a terminal token requires a step-up credential', async ({
    authenticatedRequest,
  }) => {
    const timestamp = Date.now();
    const createResponse = await authenticatedRequest.post('/api/admin/terminals', {
      data: {
        ...stepUp(),
        name: `Rotate Step-Up ${timestamp}`,
        device_id: `device-rotate-stepup-${timestamp}`,
      },
    });
    const created = await createResponse.json();

    const wrongPassword = await authenticatedRequest.post(
      `/api/admin/terminals/${created.terminal.id}/rotate-token`,
      { data: stepUp({ password: 'not-the-admin-password' }) }
    );
    expect(wrongPassword.status()).toBe(401);

    // …and nothing was staged, so the terminal is exactly as it was.
    const showResponse = await authenticatedRequest.get(
      `/api/admin/terminals/${created.terminal.id}`
    );
    const { terminal } = await showResponse.json();
    expect(terminal.has_pending_token).toBe(false);
  });

  test('revoking access clears the token lifetime', async ({ authenticatedRequest, request }) => {
    const timestamp = Date.now();
    const createResponse = await authenticatedRequest.post('/api/admin/terminals', {
      data: { ...stepUp(), name: `Expiry Revoke ${timestamp}`, device_id: `device-expiry-revoke-${timestamp}` },
    });
    const created = await createResponse.json();

    // Stage a replacement first: revoking has to take that with it, or a
    // revoked terminal walks back in with the token an admin had prepared.
    const rotateResponse = await authenticatedRequest.post(
      `/api/admin/terminals/${created.terminal.id}/rotate-token`,
      { data: stepUp() }
    );
    const staged = await rotateResponse.json();

    const revokeResponse = await authenticatedRequest.post(
      `/api/admin/terminals/${created.terminal.id}/revoke`
    );
    expect(revokeResponse.status()).toBe(200);

    const showResponse = await authenticatedRequest.get(`/api/admin/terminals/${created.terminal.id}`);
    const { terminal } = await showResponse.json();

    expect(terminal.is_active).toBe(false);
    expect(terminal.token_issued_at).toBeNull();
    expect(terminal.token_expires_at).toBeNull();
    expect(terminal.has_pending_token).toBe(false);

    const withStagedToken = await request.get(SYNC_MEMBERS, {
      headers: { Authorization: `Bearer ${staged.api_token}` },
      params: SINCE,
    });
    expect(withStagedToken.status()).toBe(401);
  });
});
