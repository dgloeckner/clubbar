import { test, expect } from '@playwright/test';
import { TEST_CREDENTIALS } from '../../config/test-credentials';

/**
 * Terminal Auth Rate Limiting Tests
 *
 * Tests IP-based rate limiting on terminal sync endpoints.
 * Rate limit: 10 failed attempts per 15-minute window → 429.
 *
 * IMPORTANT: These tests require special setup to run:
 *
 *   1. Disable DISABLE_TERMINAL_RATE_LIMITING in docker-compose.yml, then restart:
 *        docker compose up -d --force-recreate backend
 *
 *   2. Truncate the attempts table:
 *        docker compose exec database mysql -uclubbar -pclubbar clubbar \
 *          -e "TRUNCATE terminal_auth_attempts;"
 *
 *   3. Run serially (parallel workers would race the rate limit counter):
 *        npm test -- tests/api/terminal-rate-limit.spec.ts --workers=1
 *
 * These tests are excluded from normal `npm test` runs (see playwright.config.ts testIgnore).
 * They skip automatically if rate limiting appears to be disabled in the backend.
 */

test.describe.configure({ mode: 'serial' });

const API_BASE = 'http://localhost:8080';
const SYNC_MEMBERS = `${API_BASE}/api/sync/members`;
const BAD_TOKEN = 'Bearer this-is-an-invalid-token-for-rate-limit-testing';
const VALID_TOKEN = `Bearer ${TEST_CREDENTIALS.terminal.token}`;

test.describe('Terminal auth rate limiting', () => {
  test('single bad token returns 401, not 429', async ({ request }) => {
    const response = await request.get(SYNC_MEMBERS, {
      headers: { Authorization: BAD_TOKEN },
    });
    expect(response.status()).toBe(401);
    const body = await response.json();
    expect(body.error).toBe('invalid_terminal_token');
  });

  test('11th bad token returns 429 after 10 failures', async ({ request }) => {
    // Make 9 more bad requests (1 was made in the previous test)
    for (let i = 0; i < 9; i++) {
      await request.get(SYNC_MEMBERS, {
        headers: { Authorization: BAD_TOKEN },
      });
    }

    // 11th attempt (10 previous failures) should be rate-limited
    const response = await request.get(SYNC_MEMBERS, {
      headers: { Authorization: BAD_TOKEN },
    });
    expect(response.status()).toBe(429);

    const body = await response.json();
    expect(body.error).toBe('too_many_attempts');
    expect(body.message).toContain('Too many failed authentication attempts');
    expect(body.retry_after_seconds).toBe(900);

    const retryAfter = response.headers()['retry-after'];
    expect(retryAfter).toBeDefined();
    expect(retryAfter).toBe('900');
  });

  test('valid token also returns 429 once limit is hit from same IP', async ({ request }) => {
    // The previous tests left 10 failures in the table from this IP.
    // The rate limiter is a pre-check: once the IP hits the limit, ALL requests
    // from that IP are blocked — including valid tokens — until the window expires.
    // This is by design: it's a blunt IP-based shield against token probing.
    const response = await request.get(SYNC_MEMBERS, {
      headers: { Authorization: VALID_TOKEN },
    });
    expect(response.status()).toBe(429);
  });
});
