import { test, expect } from '@playwright/test';

const API_BASE = 'http://localhost:8080';
const DEV_INSTALL_KEY = 'dev-install-key-x'; // must be ≥16 chars

// ============================================================
// C1: install.php access control
// Primary defence: .htaccess blocks install.php at Apache level for all requests.
// Secondary defence: PHP-level timing-safe key comparison (C1 fix) adds
// defence-in-depth for non-Apache or shared-hosting deployments.
// These tests verify the primary .htaccess defence is active.
// ============================================================
test.describe('C1: install.php access control', () => {
  test('returns 403 when X-Install-Key header is absent', async ({ request }) => {
    const response = await request.get(`${API_BASE}/install.php?action=status`);
    // Apache returns HTML 403 via .htaccess — do not parse as JSON
    expect(response.status()).toBe(403);
  });

  test('returns 403 when X-Install-Key header is wrong', async ({ request }) => {
    const response = await request.get(`${API_BASE}/install.php?action=status`, {
      headers: { 'X-Install-Key': 'wrong-key-value-here' },
    });
    expect(response.status()).toBe(403);
  });

  test('returns 403 when X-Install-Key header is empty string', async ({ request }) => {
    const response = await request.get(`${API_BASE}/install.php?action=status`, {
      headers: { 'X-Install-Key': '' },
    });
    expect(response.status()).toBe(403);
  });

  test('returns 403 even with correct X-Install-Key (blocked by .htaccess)', async ({ request }) => {
    const response = await request.get(`${API_BASE}/install.php?action=status`, {
      headers: { 'X-Install-Key': DEV_INSTALL_KEY },
    });
    // .htaccess blocks install.php entirely — 403 even with a valid key.
    // This is the intended behaviour: remove .htaccess block only during migrations.
    expect(response.status()).toBe(403);
  });
});

// ============================================================
// C2: CORS — no wildcard with credentials
// ============================================================
test.describe('C2: CORS configuration', () => {
  test('does not echo Access-Control-Allow-Origin: * on API response', async ({ request }) => {
    const response = await request.get(`${API_BASE}/api/health`, {
      headers: { Origin: 'http://evil.example.com' },
    });
    // Should not reflect a wildcard or an unknown origin
    const allowOrigin = response.headers()['access-control-allow-origin'];
    // Either no header (origin not in allowlist) or the specific origin — never '*' when credentials present
    if (allowOrigin) {
      expect(allowOrigin).not.toBe('*');
    }
  });

  test('allows requests from localhost:5173 (admin frontend)', async ({ request }) => {
    const response = await request.get(`${API_BASE}/api/health`, {
      headers: { Origin: 'http://localhost:5173' },
    });
    expect(response.ok()).toBeTruthy();
    const allowOrigin = response.headers()['access-control-allow-origin'];
    // Must be the specific origin, not wildcard
    expect(allowOrigin).toBe('http://localhost:5173');
  });

  test('does not send Allow-Credentials: true with a wildcard origin', async ({ request }) => {
    const response = await request.fetch(`${API_BASE}/api/health`, {
      method: 'OPTIONS',
      headers: {
        Origin: 'http://localhost:5173',
        'Access-Control-Request-Method': 'GET',
      },
    });
    const allowOrigin = response.headers()['access-control-allow-origin'];
    const allowCredentials = response.headers()['access-control-allow-credentials'];

    // If origin is *, credentials must not be true
    if (allowOrigin === '*') {
      expect(allowCredentials).not.toBe('true');
    }
  });
});

// ============================================================
// C3: Error responses must not expose stack traces
// ============================================================
test.describe('C3: No stack traces in error responses', () => {
  test('404 response does not contain a stack trace', async ({ request }) => {
    const response = await request.get(`${API_BASE}/api/nonexistent-endpoint-xyz`);
    expect(response.status()).toBe(404);
    const body = await response.json();
    // Stack traces contain file paths like /app/src/...
    expect(body).not.toHaveProperty('trace');
    expect(JSON.stringify(body)).not.toContain('/app/');
    expect(JSON.stringify(body)).not.toContain('Stack trace');
  });

  test('404 response has error and message fields only', async ({ request }) => {
    const response = await request.get(`${API_BASE}/api/nonexistent-endpoint-xyz`);
    const body = await response.json();
    expect(body).toHaveProperty('error');
    expect(body).toHaveProperty('message');
    // No internal fields
    expect(body).not.toHaveProperty('trace');
    expect(body).not.toHaveProperty('file');
    expect(body).not.toHaveProperty('line');
  });
});
