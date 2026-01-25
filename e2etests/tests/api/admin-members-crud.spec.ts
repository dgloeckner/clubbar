import { test, expect } from '../../fixtures/auth.fixture';

/**
 * Admin Members CRUD endpoint tests
 *
 * Tests POST/GET/PATCH/DELETE /api/admin/members endpoints for create, read, update, delete operations.
 *
 * Note: Mock service uses fixed test member IDs.
 * Creating new members returns mock IDs that cannot be retrieved (by design in M4 mock).
 * This is acceptable for verifying API structure during Milestone 4.
 */

// Known mock member IDs for testing GET/PATCH/DELETE
const MOCK_MEMBER_ID_1 = '123e4567-e89b-12d3-a456-426614174000';
const MOCK_MEMBER_ID_2 = '223e4567-e89b-12d3-a456-426614174001';

test.describe('Admin Members CRUD Endpoints', () => {
  // POST - Create Member
  test('POST /api/admin/members creates new member with valid data', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post('/api/admin/members', {
      data: {
        first_name: 'Test',
        last_name: 'Member',
        email: 'test@example.com',
        phone: '+41791234567',
        preferred_language: 'en',
      },
    });

    expect(response.ok()).toBeTruthy();
    expect(response.status()).toBe(201);

    const body = await response.json();

    expect(body.id).toBeDefined();
    expect(body.first_name).toBe('Test');
    expect(body.last_name).toBe('Member');
    expect(body.email).toBe('test@example.com');
    expect(body.phone).toBe('+41791234567');
    expect(body.preferred_language).toBe('en');
    expect(body.is_active).toBe(true);
    expect(body.created_at).toBeDefined();
    expect(body.updated_at).toBeDefined();
  });

  test('POST /api/admin/members validates required fields', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post('/api/admin/members', {
      data: {
        first_name: 'Test',
        // missing last_name and email
        preferred_language: 'en',
      },
    });

    expect(response.status()).toBe(422);

    const body = await response.json();

    expect(body.error).toBe('validation_failed');
    expect(body.messages).toBeDefined();
    expect(body.messages.last_name).toBeDefined();
    expect(body.messages.email).toBeDefined();
  });

  test('POST /api/admin/members validates email format', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post('/api/admin/members', {
      data: {
        first_name: 'Test',
        last_name: 'Member',
        email: 'invalid-email',
        preferred_language: 'en',
      },
    });

    expect(response.status()).toBe(422);

    const body = await response.json();
    expect(body.messages.email).toBeDefined();
  });

  test('POST /api/admin/members validates language enum', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post('/api/admin/members', {
      data: {
        first_name: 'Test',
        last_name: 'Member',
        email: 'test@example.com',
        preferred_language: 'invalid',
      },
    });

    expect(response.status()).toBe(422);

    const body = await response.json();
    expect(body.messages.preferred_language).toBeDefined();
  });

  test('POST /api/admin/members allows optional phone field', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post('/api/admin/members', {
      data: {
        first_name: 'Test',
        last_name: 'Member',
        email: 'test@example.com',
        preferred_language: 'en',
        // phone is optional
      },
    });

    expect(response.status()).toBe(201);

    const body = await response.json();
    expect(body.phone).toBeNull();
  });

  // GET Single - Show Member
  test('GET /api/admin/members/{id} returns member details', async ({ authenticatedRequest }) => {
    // Create test member to avoid relying on seeded data that might be modified
    const createResponse = await authenticatedRequest.post('/api/admin/members', {
      data: {
        first_name: 'GetTest',
        last_name: 'Member',
        email: 'gettest@example.com',
        preferred_language: 'en',
      },
    });

    expect(createResponse.ok()).toBeTruthy();
    const createdMember = await createResponse.json();

    // Now retrieve it
    const response = await authenticatedRequest.get(`/api/admin/members/${createdMember.id}`);

    expect(response.ok()).toBeTruthy();
    expect(response.status()).toBe(200);

    const body = await response.json();

    expect(body.id).toBe(createdMember.id);
    expect(body.first_name).toBe('GetTest');
    expect(body.last_name).toBe('Member');
    expect(body.email).toBe('gettest@example.com');
    expect(body.preferred_language).toBe('en');
  });

  test('GET /api/admin/members/{id} returns 404 for non-existent member', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members/nonexistent-id');

    expect(response.status()).toBe(404);

    const body = await response.json();

    expect(body.error).toBe('not_found');
    expect(body.message).toContain('not found');
  });

  // PATCH - Update Member
  test('PATCH /api/admin/members/{id} updates member fields', async ({ authenticatedRequest }) => {
    // Create test member to avoid relying on seeded data that might be modified
    const createResponse = await authenticatedRequest.post('/api/admin/members', {
      data: {
        first_name: 'PatchTest',
        last_name: 'Member',
        email: 'patchtest@example.com',
        preferred_language: 'en',
      },
    });

    expect(createResponse.ok()).toBeTruthy();
    const createdMember = await createResponse.json();

    // Now update it
    const updateResponse = await authenticatedRequest.patch(`/api/admin/members/${createdMember.id}`, {
      data: {
        preferred_language: 'fr',
        phone: '+41798765432',
      },
    });

    expect(updateResponse.ok()).toBeTruthy();
    expect(updateResponse.status()).toBe(200);

    const body = await updateResponse.json();

    expect(body.id).toBe(createdMember.id);
    expect(body.preferred_language).toBe('fr');
    expect(body.phone).toBe('+41798765432');
    // Unchanged fields should remain
    expect(body.first_name).toBe('PatchTest');
    expect(body.email).toBe('patchtest@example.com');
  });

  test('PATCH /api/admin/members/{id} returns 404 for non-existent member', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.patch('/api/admin/members/nonexistent-id', {
      data: {
        phone: '+41791234567',
      },
    });

    expect(response.status()).toBe(404);

    const body = await response.json();
    expect(body.error).toBe('not_found');
  });

  test('PATCH /api/admin/members/{id} validates language if provided', async ({ authenticatedRequest }) => {
    // Create test member to avoid relying on seeded data
    const createResponse = await authenticatedRequest.post('/api/admin/members', {
      data: {
        first_name: 'LanguageTest',
        last_name: 'Member',
        email: 'langtest@example.com',
        preferred_language: 'en',
      },
    });

    expect(createResponse.ok()).toBeTruthy();
    const createdMember = await createResponse.json();

    // Try to update with invalid language
    const response = await authenticatedRequest.patch(`/api/admin/members/${createdMember.id}`, {
      data: {
        preferred_language: 'xx',
      },
    });

    expect(response.status()).toBe(422);

    const body = await response.json();
    expect(body.messages.preferred_language).toBeDefined();
  });

  // DELETE - Delete Member
  test('DELETE /api/admin/members/{id} deletes member', async ({ authenticatedRequest }) => {
    // Create a new member to delete (don't delete the seeded test members)
    const createResponse = await authenticatedRequest.post('/api/admin/members', {
      data: {
        first_name: 'ToDelete',
        last_name: 'Member',
        email: 'todelete@example.com',
        preferred_language: 'en',
      },
    });
    const memberId = (await createResponse.json()).id;

    const deleteResponse = await authenticatedRequest.delete(`/api/admin/members/${memberId}`);

    expect(deleteResponse.ok()).toBeTruthy();
    expect(deleteResponse.status()).toBe(200);

    const body = await deleteResponse.json();
    expect(body.message).toContain('deleted');
  });

  test('DELETE /api/admin/members/{id} returns 404 for non-existent member', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.delete('/api/admin/members/nonexistent-id');

    expect(response.status()).toBe(404);

    const body = await response.json();
    expect(body.error).toBe('not_found');
  });

  test('All CRUD endpoints return JSON content type', async ({ authenticatedRequest }) => {
    const data = {
      first_name: 'JsonTest',
      last_name: 'Member',
      email: 'jsontest@example.com',
      preferred_language: 'en',
    };

    const createResponse = await authenticatedRequest.post('/api/admin/members', { data });
    expect(createResponse.headers()['content-type']).toContain('application/json');
    const createdId = (await createResponse.json()).id;

    const getResponse = await authenticatedRequest.get(`/api/admin/members/${createdId}`);
    expect(getResponse.headers()['content-type']).toContain('application/json');

    const patchResponse = await authenticatedRequest.patch(`/api/admin/members/${createdId}`, {
      data: { phone: '+41791111111' },
    });
    expect(patchResponse.headers()['content-type']).toContain('application/json');

    const deleteResponse = await authenticatedRequest.delete(`/api/admin/members/${createdId}`);
    expect(deleteResponse.headers()['content-type']).toContain('application/json');
  });
});
