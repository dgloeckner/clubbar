import { test, expect } from '@playwright/test';

/**
 * Terminal API Authentication Tests
 *
 * Tests token-based authentication for Terminal API endpoints.
 * Ensures protected sync endpoints require valid Bearer tokens.
 *
 * Pattern 012: Terminal API Token Authentication
 */

test.describe('Terminal API Authentication', () => {
  const testMembersEndpoint = '/api/sync/members';
  const validToken = process.env.TEST_TERMINAL_TOKEN;

  test('GET /api/sync/members without Authorization header returns 401', async ({ request }) => {
    const response = await request.get(testMembersEndpoint, {
      params: { since: '1970-01-01T00:00:00Z' },
    });

    expect(response.status()).toBe(401);
    const body = await response.json();
    expect(body.error).toBe('authorization_header_missing');
  });

  test('GET /api/sync/members with invalid Authorization format returns 401', async ({ request }) => {
    const response = await request.get(testMembersEndpoint, {
      headers: { 'Authorization': 'InvalidFormat' },
      params: { since: '1970-01-01T00:00:00Z' },
    });

    expect(response.status()).toBe(401);
    const body = await response.json();
    expect(body.error).toBe('invalid_authorization_format');
  });

  test('GET /api/sync/members with invalid token returns 401', async ({ request }) => {
    const response = await request.get(testMembersEndpoint, {
      headers: { 'Authorization': 'Bearer invalid_token_1234567890abcdef' },
      params: { since: '1970-01-01T00:00:00Z' },
    });

    expect(response.status()).toBe(401);
    const body = await response.json();
    expect(body.error).toBe('invalid_terminal_token');
  });

  test('GET /api/sync/members with valid token returns 200', async ({ request }) => {
    if (!validToken) {
      test.skip(true, 'TEST_TERMINAL_TOKEN not set');
    }

    const response = await request.get(testMembersEndpoint, {
      headers: { 'Authorization': `Bearer ${validToken}` },
      params: { since: '1970-01-01T00:00:00Z' },
    });

    expect(response.status()).toBe(200);
    const body = await response.json();
    expect(body.members).toBeDefined();
  });

  test('GET /api/sync/members with valid token has correct content type', async ({ request }) => {
    if (!validToken) {
      test.skip(true, 'TEST_TERMINAL_TOKEN not set');
    }

    const response = await request.get(testMembersEndpoint, {
      headers: { 'Authorization': `Bearer ${validToken}` },
      params: { since: '1970-01-01T00:00:00Z' },
    });

    expect(response.status()).toBe(200);
    const contentType = response.headers()['content-type'];
    expect(contentType).toContain('application/json');
  });
});
