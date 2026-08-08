import { test, expect, APIRequestContext } from '@playwright/test';
import { TEST_CREDENTIALS } from '../../config/test-credentials';

/**
 * The re-mint loop, closed (#78, ruling #145).
 *
 * What made the 2FA bypass exploitable was not the missing limiter alone: an
 * attacker holding the password burned the MFA session's guesses, logged in
 * again for a fresh 300s window, and repeated — because password success wiped
 * the attempt counter and MFA failures were never recorded at all. This file
 * pins the fix from the outside: five wrong codes now cost the *account* its
 * allowance, and re-authenticating runs into a 429 instead of a new window.
 *
 * Rate limit under test: 5 failed attempts per 15-minute window, counted per
 * source IP and per account, windowed, never a permanent lock. (The per-account
 * dimension needs requests from six distinct source IPs, which a single-host
 * test environment cannot produce; it is pinned by RateLimitMiddlewareTest.)
 *
 * IMPORTANT: These tests require special setup to run:
 *
 *   1. Remove DISABLE_LOGIN_RATE_LIMITING from docker-compose.yml, then restart:
 *        docker compose up -d --force-recreate backend
 *
 *   2. Truncate the attempts table:
 *        docker compose exec database mysql -uclubbar -pclubbar clubbar \
 *          -e "TRUNCATE login_attempts;"
 *
 *   3. Run them on their own — they leave the IP locked out for 15 minutes,
 *      which would fail every other login in the suite:
 *        npm run test:rate-limit -- --grep "outlive"
 *
 * They live in the `rate-limit` project, which no default run includes.
 */

test.describe.configure({ mode: 'serial' });

const API_BASE = 'http://localhost:8080/api';
const MAX_ATTEMPTS = 5;
const WRONG_CODE = '000000';

function login(request: APIRequestContext, password = TEST_CREDENTIALS.admin.password) {
  return request.post(`${API_BASE}/auth/login`, {
    data: { email: TEST_CREDENTIALS.admin.email, password },
  });
}

function sessionCookie(headers: Record<string, string>): string {
  const setCookie = headers['set-cookie'];
  return (Array.isArray(setCookie) ? setCookie[0] : setCookie || '').split(';')[0];
}

test.describe('MFA failures outlive the session they were made in', () => {
  test('five wrong codes are spent against the account, not just the session', async ({ request }) => {
    const started = await login(request);
    expect(started.status()).toBe(200);
    expect(await started.json()).toHaveProperty('requiresMfa', true);

    const cookie = sessionCookie(started.headers());

    for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
      const response = await request.post(`${API_BASE}/auth/mfa`, {
        data: { code: WRONG_CODE },
        headers: { cookie },
      });
      expect(response.status()).toBe(401);
      // The last one also destroys the pending session.
      expect((await response.json()).error).toBe(
        attempt === MAX_ATTEMPTS ? 'mfa_attempts_exceeded' : 'invalid_credentials',
      );
    }
  });

  test('re-authenticating with the correct password no longer mints a fresh window', async ({ request }) => {
    const response = await login(request);

    // Before the fix this returned 200 with a brand-new 300s MFA window, and the
    // attacker went back to guessing. The password is right; the budget is spent.
    expect(response.status()).toBe(429);

    const body = await response.json();
    expect(body.error).toBe('too_many_attempts');
    expect(body.message).toContain('Too many failed authentication attempts');
    expect(body.retry_after_seconds).toBe(900);
    expect(response.headers()['retry-after']).toBe('900');
  });
});
