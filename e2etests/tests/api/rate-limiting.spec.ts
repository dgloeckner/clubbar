import { test, expect } from '@playwright/test';

const API_BASE = 'http://localhost:8080/api';

test.describe('Login Rate Limiting', () => {
  test.describe.configure({ mode: 'serial' });

  // Clean up login_attempts before tests to ensure clean state
  test.beforeAll(async ({ request }) => {
    // Login successfully to clear any existing attempts for this IP
    await request.post(`${API_BASE}/auth/login`, {
      data: { email: 'admin@example.com', password: 'password123' },
    });
  });

  test.afterAll(async ({ request }) => {
    // Clean up: login successfully to clear rate limit counter for this IP
    // Note: if we're rate-limited, this will fail silently (429), which is acceptable.
    await request.post(`${API_BASE}/auth/login`, {
      data: { email: 'admin@example.com', password: 'password123' },
    });
  });

  test('blocks login after 5 failed attempts and returns correct 429 format', async ({ request }) => {
    // First, ensure clean state by doing a successful login to clear attempts
    const clearResp = await request.post(`${API_BASE}/auth/login`, {
      data: { email: 'admin@example.com', password: 'password123' },
    });
    expect(clearResp.status()).toBe(200);

    // Make 5 failed login attempts with unique emails
    for (let i = 0; i < 5; i++) {
      const response = await request.post(`${API_BASE}/auth/login`, {
        data: { email: `ratelimit${i}-${Date.now()}@test.com`, password: 'wrongpassword' },
      });
      expect(response.status()).toBe(401);
    }

    // 6th attempt should be rate-limited (429) - even with correct credentials
    const blocked = await request.post(`${API_BASE}/auth/login`, {
      data: { email: 'admin@example.com', password: 'password123' },
    });
    expect(blocked.status()).toBe(429);
    const body = await blocked.json();
    expect(body.error).toBe('too_many_attempts');
    expect(body.message).toBe('Too many login attempts. Please try again later.');
    expect(body.retry_after_seconds).toBe(900); // 15 minutes = 900 seconds

    // Verify Retry-After header is present
    const retryAfter = blocked.headers()['retry-after'];
    expect(retryAfter).toBe('900');

    // Verify subsequent attempts are also blocked with same format
    const blocked2 = await request.post(`${API_BASE}/auth/login`, {
      data: { email: 'another@test.com', password: 'wrong' },
    });
    expect(blocked2.status()).toBe(429);
    const body2 = await blocked2.json();
    expect(body2.error).toBe('too_many_attempts');
    expect(body2).toHaveProperty('retry_after_seconds');

    // Clean up: we can't login to clear since we're blocked.
    // The afterAll hook will attempt to clear. If running in parallel with other tests,
    // other successful logins from the same IP will also clear the counter.
  });
});
