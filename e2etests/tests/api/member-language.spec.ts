import { test, expect } from '../../fixtures/auth.fixture';

/**
 * Member Language Update endpoint tests
 *
 * Tests the PATCH /api/sync/members/{memberId}/language endpoint per Terminal API spec.
 * Updates a member's preferred language setting.
 */

test.describe('Member Language Update Endpoint', () => {
  test('PATCH /api/sync/members/{memberId}/language updates language successfully', async ({
    authenticatedTerminalRequest,
    testTransactions,
  }) => {
    const member = await testTransactions.createMember('LangUpdate', 'Test');
    const response = await authenticatedTerminalRequest.patch(`/api/sync/members/${member.id}/language`, {
      data: { preferred_language: 'de' },
    });

    expect(response.ok()).toBeTruthy();
    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(body.id).toBe(member.id);
    expect(body.preferred_language).toBe('de');
    expect(body.updated_at).toBeDefined();
  });

  test('PATCH /api/sync/members/{memberId}/language accepts different languages', async ({
    authenticatedTerminalRequest,
    testTransactions,
  }) => {
    const member = await testTransactions.createMember('LangAccept', 'Test');
    const languages = ['de', 'en', 'fr'];

    for (const lang of languages) {
      const response = await authenticatedTerminalRequest.patch(`/api/sync/members/${member.id}/language`, {
        data: { preferred_language: lang },
      });

      expect(response.ok()).toBeTruthy();

      const body = await response.json();
      expect(body.preferred_language).toBe(lang);
    }
  });

  test('PATCH /api/sync/members/{memberId}/language rejects invalid language code', async ({
    authenticatedTerminalRequest,
  }) => {
    // Use a valid UUID format - validation fires before DB lookup
    const response = await authenticatedTerminalRequest.patch(
      `/api/sync/members/00000000-0000-0000-0000-000000000001/language`,
      {
        data: { preferred_language: 'xx' },
      },
    );

    expect(response.status()).toBe(422);

    const body = await response.json();
    expect(body.error).toBe('validation_failed');
    expect(body.messages.preferred_language[0]).toContain('must be one of');
  });

  test('PATCH /api/sync/members/{memberId}/language rejects missing language', async ({
    authenticatedTerminalRequest,
  }) => {
    const response = await authenticatedTerminalRequest.patch(
      `/api/sync/members/00000000-0000-0000-0000-000000000001/language`,
      {
        data: {},
      },
    );

    expect(response.status()).toBe(422);

    const body = await response.json();
    expect(body.error).toBe('validation_failed');
    expect(body.messages.preferred_language).toBeDefined();
  });

  test('PATCH /api/sync/members/{memberId}/language returns 404 for invalid UUID', async ({
    authenticatedTerminalRequest,
  }) => {
    const response = await authenticatedTerminalRequest.patch('/api/sync/members/invalid-uuid/language', {
      data: { preferred_language: 'de' },
    });

    expect(response.status()).toBe(404);

    const body = await response.json();
    expect(body.error).toBe('not_found');
  });

  test('PATCH /api/sync/members/{memberId}/language returns JSON content type', async ({
    authenticatedTerminalRequest,
    testTransactions,
  }) => {
    const member = await testTransactions.createMember('LangContent', 'Test');
    const response = await authenticatedTerminalRequest.patch(`/api/sync/members/${member.id}/language`, {
      data: { preferred_language: 'en' },
    });

    const contentType = response.headers()['content-type'];
    expect(contentType).toContain('application/json');
  });

  test('PATCH /api/sync/members/{memberId}/language returns valid ISO 8601 timestamp', async ({
    authenticatedTerminalRequest,
    testTransactions,
  }) => {
    const member = await testTransactions.createMember('LangTimestamp', 'Test');
    const response = await authenticatedTerminalRequest.patch(`/api/sync/members/${member.id}/language`, {
      data: { preferred_language: 'de' },
    });

    const body = await response.json();

    // Validate timestamp is ISO 8601 format
    const timestamp = new Date(body.updated_at);
    expect(timestamp.toString()).not.toBe('Invalid Date');
  });
});
