import path from 'path';
import { test, expect } from '../../fixtures/auth.fixture';

const API_BASE = 'http://localhost:8080/api';
// Same path auth.setup.ts writes its storageState to (see tests/auth.setup.ts).
const ADMIN_STORAGE_STATE_PATH = path.join('playwright', '.auth', 'admin.json');

test.describe('CSRF Protection', () => {
  test('POST to admin endpoint without CSRF token returns 403', async ({ playwright }) => {
    // Any authenticated session proves this — the test asserts on the missing
    // header, not on who is logged in — so it reuses the "setup auth" session
    // (#338) instead of performing its own fresh password+MFA login. A fresh
    // login here bought nothing but a real chance of racing another caller's
    // TOTP replay window for the shared admin's secret.
    const ctx = await playwright.request.newContext({
      baseURL: API_BASE,
      storageState: ADMIN_STORAGE_STATE_PATH,
    });

    // POST without CSRF token should fail
    const response = await ctx.post(`${API_BASE}/admin/categories`, {
      data: { names: { de: 'CSRFTest' }, icon_name: 'generic' },
    });
    expect(response.status()).toBe(403);
    const body = await response.json();
    expect(body.error).toBe('csrf_validation_failed');

    await ctx.dispose();
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
