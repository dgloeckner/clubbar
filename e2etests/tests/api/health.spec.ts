import { test, expect } from '@playwright/test';

/**
 * Health endpoint tests
 *
 * Tests the GET /api/health endpoint per Terminal API spec (api/terminal.yaml).
 * This endpoint requires no authentication and is used by terminals
 * to verify backend connectivity.
 */

test.describe('Health Endpoint', () => {
  test('GET /api/health returns ok status', async ({ request }) => {
    const response = await request.get('/api/health');

    expect(response.ok()).toBeTruthy();
    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(body.status).toBe('ok');
    expect(body.timestamp).toBeDefined();
  });

  test('GET /api/health returns valid ISO 8601 timestamp', async ({ request }) => {
    const response = await request.get('/api/health');
    const body = await response.json();

    // Validate timestamp is ISO 8601 format
    const timestamp = new Date(body.timestamp);
    expect(timestamp.toString()).not.toBe('Invalid Date');

    // Timestamp should be recent (within last minute)
    const now = Date.now();
    const responseTime = timestamp.getTime();
    expect(Math.abs(now - responseTime)).toBeLessThan(60000);
  });

  test('GET /api/health returns JSON content type', async ({ request }) => {
    const response = await request.get('/api/health');

    const contentType = response.headers()['content-type'];
    expect(contentType).toContain('application/json');
  });
});
