import { test, expect } from '@playwright/test';

/**
 * Member Language Update endpoint tests
 *
 * Tests the PATCH /api/sync/members/{memberId}/language endpoint per Terminal API spec.
 * Updates a member's preferred language setting.
 */

const validToken = process.env.TEST_TERMINAL_TOKEN;
const authHeaders = validToken ? { 'Authorization': `Bearer ${validToken}` } : {};

test.describe('Member Language Update Endpoint', () => {
  const validMemberId = '123e4567-e89b-12d3-a456-426614174000';

  test('PATCH /api/sync/members/{memberId}/language updates language successfully', async ({ request }) => {
    const response = await request.patch(`/api/sync/members/${validMemberId}/language`, {
      headers: authHeaders,
      data: { preferred_language: 'de' },
    });

    expect(response.ok()).toBeTruthy();
    expect(response.status()).toBe(200);

    const body = await response.json();

    // Validate response structure
    expect(body.id).toBe(validMemberId);
    expect(body.preferred_language).toBe('de');
    expect(body.updated_at).toBeDefined();
  });

  test('PATCH /api/sync/members/{memberId}/language accepts different languages', async ({ request }) => {
    const languages = ['de', 'en', 'fr'];

    for (const lang of languages) {
      const response = await request.patch(`/api/sync/members/${validMemberId}/language`, {
        headers: authHeaders,
        data: { preferred_language: lang },
      });

      expect(response.ok()).toBeTruthy();

      const body = await response.json();
      expect(body.preferred_language).toBe(lang);
    }
  });

  test('PATCH /api/sync/members/{memberId}/language rejects invalid language code', async ({ request }) => {
    const response = await request.patch(`/api/sync/members/${validMemberId}/language`, {
      headers: authHeaders,
      data: { preferred_language: 'xx' },
    });

    expect(response.status()).toBe(400);

    const body = await response.json();
    expect(body.error).toBe('invalid_request');
    expect(body.message).toContain('xx');
  });

  test('PATCH /api/sync/members/{memberId}/language rejects missing language', async ({ request }) => {
    const response = await request.patch(`/api/sync/members/${validMemberId}/language`, {
      headers: authHeaders,
      data: {},
    });

    expect(response.status()).toBe(400);

    const body = await response.json();
    expect(body.error).toBe('invalid_request');
  });

  test('PATCH /api/sync/members/{memberId}/language returns 404 for invalid UUID', async ({ request }) => {
    const response = await request.patch('/api/sync/members/invalid-uuid/language', {
      headers: authHeaders,
      data: { preferred_language: 'de' },
    });

    expect(response.status()).toBe(404);

    const body = await response.json();
    expect(body.error).toBe('not_found');
  });

  test('PATCH /api/sync/members/{memberId}/language returns JSON content type', async ({ request }) => {
    const response = await request.patch(`/api/sync/members/${validMemberId}/language`, {
      headers: authHeaders,
      data: { preferred_language: 'en' },
    });

    const contentType = response.headers()['content-type'];
    expect(contentType).toContain('application/json');
  });

  test('PATCH /api/sync/members/{memberId}/language returns valid ISO 8601 timestamp', async ({ request }) => {
    const response = await request.patch(`/api/sync/members/${validMemberId}/language`, {
      headers: authHeaders,
      data: { preferred_language: 'de' },
    });

    const body = await response.json();

    // Validate timestamp is ISO 8601 format
    const timestamp = new Date(body.updated_at);
    expect(timestamp.toString()).not.toBe('Invalid Date');
  });
});
