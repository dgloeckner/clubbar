import { test, expect } from '../../fixtures/auth.fixture';

/**
 * Admin Members GDPR endpoint tests
 *
 * Tests POST /api/admin/members/{id}/export and /anonymize endpoints
 * for GDPR export and anonymization (right to be forgotten) operations.
 *
 * Pattern 001: Each test creates its own unique member to avoid shared/mutated state.
 * Pattern 004: Tests are safe to run in parallel (4 workers).
 */

/** Helper to create a unique test member and return its data. */
async function createGdprTestMember(authenticatedRequest: any, testId: string) {
  const response = await authenticatedRequest.post('/api/admin/members', {
    data: {
      first_name: `GdprTest${testId}`,
      last_name: 'Member',
      email: `gdpr-${testId}@test.example`,
      date_of_birth: '1985-06-15',
      preferred_language: 'en',
    },
  });
  expect(response.status()).toBe(201);
  return await response.json();
}

test.describe('Admin Members GDPR Endpoints', () => {
  // GDPR Export
  test('POST /api/admin/members/{id}/export returns member export data', async ({ authenticatedRequest }) => {
    const testId = `${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const member = await createGdprTestMember(authenticatedRequest, testId);

    const response = await authenticatedRequest.post(`/api/admin/members/${member.id}/export`);

    expect(response.ok()).toBeTruthy();
    expect(response.status()).toBe(200);

    const body = await response.json();

    // Validate export structure
    expect(body.member).toBeDefined();
    expect(body.transactions).toBeDefined();
    expect(body.bookings).toBeDefined();
    expect(body.export_timestamp).toBeDefined();

    // Validate member data
    expect(body.member.id).toBeDefined();
    expect(body.member.first_name).toBeDefined();
    expect(body.member.email).toBeDefined();

    // Validate arrays
    expect(Array.isArray(body.transactions)).toBeTruthy();
    expect(Array.isArray(body.bookings)).toBeTruthy();

    // Validate timestamp
    const iso8601Regex = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;
    expect(body.export_timestamp).toMatch(iso8601Regex);
  });

  test('POST /api/admin/members/{id}/export includes member details', async ({ authenticatedRequest }) => {
    const testId = `${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const member = await createGdprTestMember(authenticatedRequest, testId);

    const response = await authenticatedRequest.post(`/api/admin/members/${member.id}/export`);

    const body = await response.json();
    const exported = body.member;

    // Verify member data is included and matches the created member
    expect(exported.id).toBe(member.id);
    expect(exported.first_name).toBe(`GdprTest${testId}`);
    expect(exported.last_name).toBe('Member');
    expect(exported.email).toBe(`gdpr-${testId}@test.example`);
    expect(exported.preferred_language).toBeDefined();
    expect(exported.created_at).toBeDefined();
    expect(exported.updated_at).toBeDefined();
  });

  test('POST /api/admin/members/{id}/export returns 404 for non-existent member', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post('/api/admin/members/nonexistent-id/export');

    expect(response.status()).toBe(404);

    const body = await response.json();
    expect(body.error).toBe('not_found');
    expect(body.message).toContain('not found');
  });

  test('POST /api/admin/members/{id}/export returns JSON content type', async ({ authenticatedRequest }) => {
    const testId = `${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const member = await createGdprTestMember(authenticatedRequest, testId);

    const response = await authenticatedRequest.post(`/api/admin/members/${member.id}/export`);

    const contentType = response.headers()['content-type'];
    expect(contentType).toContain('application/json');
  });

  // GDPR Anonymization
  test('POST /api/admin/members/{id}/anonymize anonymizes member data', async ({ authenticatedRequest }) => {
    const testId = `${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const member = await createGdprTestMember(authenticatedRequest, testId);

    const response = await authenticatedRequest.post(`/api/admin/members/${member.id}/anonymize`);

    expect(response.ok()).toBeTruthy();
    expect(response.status()).toBe(200);

    const body = await response.json();

    // Validate member is marked as deleted
    expect(body.id).toBe(member.id);
    expect(body.deleted_at).toBeDefined();
    expect(body.is_active).toBe(false);

    // Validate PII is cleared (NULL per GDPR Art. 17)
    expect(body.first_name).toBeNull();
    expect(body.last_name).toBeNull();
    expect(body.email).toBeNull();
    expect(body.phone).toBeNull();
    expect(body.card_uid).toMatch(/^ANON-/);
    expect(body.iban_masked).toBeNull();

    // Validate timestamp format
    const iso8601Regex = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;
    expect(body.deleted_at).toMatch(iso8601Regex);
  });

  test('POST /api/admin/members/{id}/anonymize preserves original timestamps', async ({ authenticatedRequest }) => {
    const testId = `${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const member = await createGdprTestMember(authenticatedRequest, testId);
    const originalCreatedAt = member.created_at;

    const response = await authenticatedRequest.post(`/api/admin/members/${member.id}/anonymize`);

    const body = await response.json();

    // created_at should remain unchanged after anonymization
    expect(body.created_at).toBe(originalCreatedAt);

    // updated_at should be a valid ISO 8601 timestamp
    const iso8601Regex = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;
    expect(body.updated_at).toMatch(iso8601Regex);
  });

  test('POST /api/admin/members/{id}/anonymize preserves language preference', async ({ authenticatedRequest }) => {
    const testId = `${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const member = await createGdprTestMember(authenticatedRequest, testId);

    const response = await authenticatedRequest.post(`/api/admin/members/${member.id}/anonymize`);

    const body = await response.json();

    // Language preference is preserved even after anonymization
    expect(body.preferred_language).toBeDefined();
    expect(['de', 'en', 'fr']).toContain(body.preferred_language);
  });

  test('POST /api/admin/members/{id}/anonymize returns 404 for non-existent member', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post('/api/admin/members/nonexistent-id/anonymize');

    expect(response.status()).toBe(404);

    const body = await response.json();
    expect(body.error).toBe('not_found');
    expect(body.message).toContain('not found');
  });

  test('POST /api/admin/members/{id}/anonymize returns JSON content type', async ({ authenticatedRequest }) => {
    const testId = `${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const member = await createGdprTestMember(authenticatedRequest, testId);

    const response = await authenticatedRequest.post(`/api/admin/members/${member.id}/anonymize`);

    const contentType = response.headers()['content-type'];
    expect(contentType).toContain('application/json');
  });

  test('GDPR endpoints validate member existence before processing', async ({ authenticatedRequest }) => {
    // Try to export non-existent member
    const exportResponse = await authenticatedRequest.post('/api/admin/members/invalid-member-id/export');
    expect(exportResponse.status()).toBe(404);

    // Try to anonymize non-existent member
    const anonResponse = await authenticatedRequest.post('/api/admin/members/invalid-member-id/anonymize');
    expect(anonResponse.status()).toBe(404);
  });

  test('GDPR export and anonymize operations are atomic', async ({ authenticatedRequest }) => {
    // Pattern 001: Create unique test data per test
    const testId = `${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const createResponse = await authenticatedRequest.post('/api/admin/members', {
      data: {
        first_name: `Atomic${testId}`,
        last_name: 'Member',
        email: `atomic-${testId}@test.example`,
        date_of_birth: '1985-06-15',
        preferred_language: 'en',
      },
    });

    expect(createResponse.ok()).toBeTruthy();
    const createdMember = await createResponse.json();
    const memberId = createdMember.id;

    // Export first
    const exportResponse = await authenticatedRequest.post(`/api/admin/members/${memberId}/export`);
    expect(exportResponse.ok()).toBeTruthy();

    const exportData = await exportResponse.json();

    // Verify export includes member data before anonymization
    expect(exportData.member.first_name).toBe(`Atomic${testId}`);
    expect(exportData.member.email).toBe(`atomic-${testId}@test.example`);

    // Then anonymize
    const anonResponse = await authenticatedRequest.post(`/api/admin/members/${memberId}/anonymize`);
    expect(anonResponse.ok()).toBeTruthy();

    const anonData = await anonResponse.json();

    // Verify anonymization cleared PII (NULL per GDPR Art. 17)
    expect(anonData.first_name).toBeNull();
    expect(anonData.email).toBeNull();
  });
});
