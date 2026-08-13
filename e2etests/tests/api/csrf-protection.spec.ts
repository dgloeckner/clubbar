import { test, expect } from '../../fixtures/auth.fixture';
import { TEST_CREDENTIALS } from '../../config/test-credentials';
import { submitTotpWithRetry } from '../../utils/totp';

/**
 * Serial within this file: these tests perform full password+MFA logins as the
 * shared seeded admin, and `totp_last_timestep` (#338) accepts one TOTP code
 * per 30-second step per account. Run 4-wide, the second login in a step is
 * correctly rejected as a replay and the test fails for a reason that has
 * nothing to do with what it is testing. E2E Pattern 004: a test that cannot
 * be made independent of a shared resource is run in order instead.
 */
test.describe.configure({ mode: 'default' })

const API_BASE = 'http://localhost:8080/api';

test.describe('CSRF Protection', () => {
  test('POST to admin endpoint without CSRF token returns 403', async ({ playwright }) => {
    // Retries against a same-window replay collision on the shared admin secret (#338).
    test.setTimeout(360_000)
    // Login fresh to get session, completing TOTP MFA to obtain a fully-authenticated session
    const freshRequest = await playwright.request.newContext({
      baseURL: API_BASE,
      storageState: { cookies: [], origins: [] },
    });

    const loginResponse = await freshRequest.post(`${API_BASE}/auth/login`, {
      data: { email: TEST_CREDENTIALS.admin.email, password: TEST_CREDENTIALS.admin.password },
    });
    expect(loginResponse.ok()).toBeTruthy();

    let loginCookieHeader = loginResponse.headersArray().find(h => h.name.toLowerCase() === 'set-cookie');
    let cookieString = loginCookieHeader?.value.split(';')[0] || '';
    const loginData = await loginResponse.json();

    // Complete MFA if required (seeded admin is enrolled)
    if (loginData.requiresMfa) {
      const mfaResponse = await submitTotpWithRetry(TEST_CREDENTIALS.totp.adminSecret, (code) =>
        freshRequest.post(`${API_BASE}/auth/mfa`, {
          data: { code },
          headers: { cookie: cookieString },
        })
      );
      // Session is regenerated after MFA — capture updated cookie
      const mfaSetCookie = mfaResponse.headers()['set-cookie'];
      if (mfaSetCookie) {
        const newCookie = (Array.isArray(mfaSetCookie) ? mfaSetCookie[0] : mfaSetCookie).split(';')[0];
        if (newCookie) cookieString = newCookie;
      }
    }

    // POST without CSRF token should fail
    const response = await freshRequest.post(`${API_BASE}/admin/categories`, {
      data: { names: { de: 'CSRFTest' }, icon_name: 'generic' },
      headers: { cookie: cookieString },
    });
    expect(response.status()).toBe(403);
    const body = await response.json();
    expect(body.error).toBe('csrf_validation_failed');

    await freshRequest.dispose();
  });

  test('GET requests work without CSRF token', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get(`${API_BASE}/admin/members`);
    expect(response.ok()).toBeTruthy();
  });

  test('Terminal API is not affected by CSRF', async ({ authenticatedTerminalRequest }) => {
    const response = await authenticatedTerminalRequest.get(`${API_BASE}/sync/members`);
    expect(response.ok()).toBeTruthy();
  });

  test('Login endpoint is exempt from CSRF', async ({ playwright }) => {
    const freshRequest = await playwright.request.newContext({
      baseURL: API_BASE,
      storageState: { cookies: [], origins: [] },
    });
    const response = await freshRequest.post(`${API_BASE}/auth/login`, {
      data: { email: 'admin@example.com', password: 'password123' },
    });
    expect(response.status()).toBe(200);
    await freshRequest.dispose();
  });

  test('POST with valid CSRF token succeeds', async ({ authenticatedRequest }) => {
    const timestamp = Date.now();
    const response = await authenticatedRequest.post(`${API_BASE}/admin/categories`, {
      data: { names: { de: `CSRFValid${timestamp}` }, icon_name: 'generic' },
    });
    expect(response.status()).toBe(201);
  });
});
