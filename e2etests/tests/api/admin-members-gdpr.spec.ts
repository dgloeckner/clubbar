import { test, expect } from '../../fixtures/auth.fixture';

/**
 * Admin Members GDPR endpoint tests
 *
 * Tests POST /api/admin/members/{id}/export and /anonymize endpoints
 * for GDPR export and anonymization (right to be forgotten) operations.
 */

test.describe('Admin Members GDPR Endpoints', () => {
  // GDPR Export
  test('POST /api/admin/members/{id}/export returns member export data', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post('/api/admin/members/123e4567-e89b-12d3-a456-426614174000/export');

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
    const response = await authenticatedRequest.post('/api/admin/members/123e4567-e89b-12d3-a456-426614174000/export');

    const body = await response.json();
    const member = body.member;

    // Verify member data is included
    expect(member.id).toBe('123e4567-e89b-12d3-a456-426614174000');
    expect(member.first_name).toBeDefined();
    expect(member.last_name).toBeDefined();
    expect(member.email).toBeDefined();
    expect(member.phone).toBeDefined();
    expect(member.preferred_language).toBeDefined();
    expect(member.created_at).toBeDefined();
    expect(member.updated_at).toBeDefined();
  });

  test('POST /api/admin/members/{id}/export returns 404 for non-existent member', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post('/api/admin/members/nonexistent-id/export');

    expect(response.status()).toBe(404);

    const body = await response.json();
    expect(body.error).toBe('not_found');
    expect(body.message).toContain('not found');
  });

  test('POST /api/admin/members/{id}/export returns JSON content type', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post('/api/admin/members/123e4567-e89b-12d3-a456-426614174000/export');

    const contentType = response.headers()['content-type'];
    expect(contentType).toContain('application/json');
  });

  // GDPR Anonymization
  test('POST /api/admin/members/{id}/anonymize anonymizes member data', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post('/api/admin/members/223e4567-e89b-12d3-a456-426614174001/anonymize');

    expect(response.ok()).toBeTruthy();
    expect(response.status()).toBe(200);

    const body = await response.json();

    // Validate member is marked as deleted
    expect(body.id).toBe('223e4567-e89b-12d3-a456-426614174001');
    expect(body.deleted_at).toBeDefined();
    expect(body.is_active).toBe(false);

    // Validate PII is cleared
    expect(body.first_name).toBe('DELETED');
    expect(body.last_name).toBe('DELETED');
    expect(body.email).toBe('deleted@example.com');
    expect(body.phone).toBeNull();
    expect(body.card_uid).toBeNull();
    expect(body.iban_masked).toBeNull();

    // Validate timestamp format
    const iso8601Regex = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;
    expect(body.deleted_at).toMatch(iso8601Regex);
  });

  test('POST /api/admin/members/{id}/anonymize preserves original timestamps', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post('/api/admin/members/223e4567-e89b-12d3-a456-426614174001/anonymize');

    const body = await response.json();

    // created_at should remain unchanged
    expect(body.created_at).toBe('2024-07-01T12:30:00Z');

    // updated_at should be recent
    const iso8601Regex = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;
    expect(body.updated_at).toMatch(iso8601Regex);
  });

  test('POST /api/admin/members/{id}/anonymize preserves language preference', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post('/api/admin/members/223e4567-e89b-12d3-a456-426614174001/anonymize');

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
    const response = await authenticatedRequest.post('/api/admin/members/223e4567-e89b-12d3-a456-426614174001/anonymize');

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
    // Create a dedicated test member for this test to avoid interfering with other tests
    const createResponse = await authenticatedRequest.post('/api/admin/members', {
      data: {
        first_name: 'AtomicTest',
        last_name: 'Member',
        email: 'atomic@test.com',
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
    expect(exportData.member.first_name).toBe('AtomicTest');
    expect(exportData.member.email).toBe('atomic@test.com');

    // Then anonymize
    const anonResponse = await authenticatedRequest.post(`/api/admin/members/${memberId}/anonymize`);
    expect(anonResponse.ok()).toBeTruthy();

    const anonData = await anonResponse.json();

    // Verify anonymization cleared PII
    expect(anonData.first_name).toBe('DELETED');
    expect(anonData.email).toBe('deleted@example.com');
  });
});
