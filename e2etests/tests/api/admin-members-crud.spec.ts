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
        date_of_birth: '1985-06-15',
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
    expect(body.preferred_language).toBe('en');
    expect(body.is_active).toBe(true);
    expect(body.created_at).toBeDefined();
    expect(body.updated_at).toBeDefined();
  });

  test('POST /api/admin/members validates required fields', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post('/api/admin/members', {
      data: {
        first_name: 'Test',
        date_of_birth: '1985-06-15',
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
        date_of_birth: '1985-06-15',
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
        date_of_birth: '1985-06-15',
        preferred_language: 'invalid',
      },
    });

    expect(response.status()).toBe(422);

    const body = await response.json();
    expect(body.messages.preferred_language).toBeDefined();
  });

  // GET Single - Show Member
  test('GET /api/admin/members/{id} returns member details', async ({ authenticatedRequest }) => {
    // Create test member to avoid relying on seeded data that might be modified
    const createResponse = await authenticatedRequest.post('/api/admin/members', {
      data: {
        first_name: 'GetTest',
        last_name: 'Member',
        email: 'gettest@example.com',
        date_of_birth: '1985-06-15',
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
        date_of_birth: '1985-06-15',
        preferred_language: 'en',
      },
    });

    expect(createResponse.ok()).toBeTruthy();
    const createdMember = await createResponse.json();

    // Now update it
    const updateResponse = await authenticatedRequest.patch(`/api/admin/members/${createdMember.id}`, {
      data: {
        preferred_language: 'fr',
        last_name: 'Corrected',
      },
    });

    expect(updateResponse.ok()).toBeTruthy();
    expect(updateResponse.status()).toBe(200);

    const body = await updateResponse.json();

    expect(body.id).toBe(createdMember.id);
    expect(body.preferred_language).toBe('fr');
    expect(body.last_name).toBe('Corrected');
    // Unchanged fields should remain
    expect(body.first_name).toBe('PatchTest');
    expect(body.email).toBe('patchtest@example.com');
  });

  test('PATCH /api/admin/members/{id} returns 404 for non-existent member', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.patch('/api/admin/members/nonexistent-id', {
      data: {
        preferred_language: 'fr',
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
        date_of_birth: '1985-06-15',
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
        date_of_birth: '1985-06-15',
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

  // Account Holder Name (divergent SEPA payer)
  test('POST /api/admin/members creates member with account_holder_name', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post('/api/admin/members', {
      data: {
        first_name: 'Anna',
        last_name: 'Klein',
        email: 'anna.klein@example.com',
        date_of_birth: '1985-06-15',
        preferred_language: 'de',
        iban: 'DE89370400440532013000',
        mandate_signed_at: '2025-01-15',
        account_holder_name: 'Maria Klein',
      },
    });

    expect(response.status()).toBe(201);

    const body = await response.json();
    expect(body.first_name).toBe('Anna');
    expect(body.last_name).toBe('Klein');
    expect(body.account_holder_name).toBe('Maria Klein');
  });

  test('POST /api/admin/members creates member without account_holder_name (null)', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post('/api/admin/members', {
      data: {
        first_name: 'Ben',
        last_name: 'Groß',
        email: 'ben.gross@example.com',
        date_of_birth: '1985-06-15',
        preferred_language: 'de',
      },
    });

    expect(response.status()).toBe(201);

    const body = await response.json();
    expect(body.account_holder_name).toBeNull();
  });

  test('PATCH /api/admin/members/{id} sets account_holder_name', async ({ authenticatedRequest }) => {
    // Create member without account_holder_name
    const createResponse = await authenticatedRequest.post('/api/admin/members', {
      data: {
        first_name: 'Chris',
        last_name: 'Müller',
        email: 'chris.mueller@example.com',
        date_of_birth: '1985-06-15',
        preferred_language: 'de',
      },
    });

    expect(createResponse.status()).toBe(201);
    const created = await createResponse.json();
    expect(created.account_holder_name).toBeNull();

    // Update with account_holder_name
    const updateResponse = await authenticatedRequest.patch(`/api/admin/members/${created.id}`, {
      data: {
        account_holder_name: 'Sabine Müller',
      },
    });

    expect(updateResponse.ok()).toBeTruthy();

    const updated = await updateResponse.json();
    expect(updated.account_holder_name).toBe('Sabine Müller');

    // Verify persistence via GET
    const getResponse = await authenticatedRequest.get(`/api/admin/members/${created.id}`);
    const fetched = await getResponse.json();
    expect(fetched.account_holder_name).toBe('Sabine Müller');
  });

  test('POST /api/admin/members validates account_holder_name max length', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post('/api/admin/members', {
      data: {
        first_name: 'MaxLen',
        last_name: 'Test',
        email: 'maxlen@example.com',
        date_of_birth: '1985-06-15',
        preferred_language: 'de',
        account_holder_name: 'A'.repeat(71), // 71 chars, exceeds 70 limit
      },
    });

    expect(response.status()).toBe(422);

    const body = await response.json();
    expect(body.messages.account_holder_name).toBeDefined();
  });

  // Bank change — the admin edit form round-trips the mandate reference, so a
  // plain "this member moved banks" resubmits the reference of the mandate it
  // is about to supersede.
  test('PATCH /api/admin/members/{id} changes the IBAN when the old mandate reference is resubmitted', async ({ authenticatedRequest }) => {
    const createResponse = await authenticatedRequest.post('/api/admin/members', {
      data: {
        first_name: 'BankChange',
        last_name: `Member${Date.now()}`,
        email: `bankchange-${Date.now()}@example.com`,
        date_of_birth: '1985-06-15',
        preferred_language: 'de',
        iban: 'DE89370400440532013000',
        mandate_signed_at: '2025-01-15',
      },
    });
    expect(createResponse.status()).toBe(201);
    const created = await createResponse.json();
    expect(created.mandate_reference).toBeTruthy();

    // Exactly what the form sends: the new IBAN plus every other field as it
    // was loaded, the old reference among them.
    const patchResponse = await authenticatedRequest.patch(`/api/admin/members/${created.id}`, {
      data: {
        iban: 'DE02120300000000202051',
        mandate_reference: created.mandate_reference,
        mandate_signed_at: '2025-01-15',
      },
    });

    expect(patchResponse.status()).toBe(200);

    const updated = await patchResponse.json();
    expect(updated.iban_last4).toBe('2051');
    expect(updated.mandate_reference).not.toBe(created.mandate_reference);
    expect(updated.mandate_reference).toBeTruthy();

    // The change is persisted, not just echoed.
    const reread = await authenticatedRequest.get(`/api/admin/members/${created.id}`);
    expect(reread.status()).toBe(200);
    const fresh = await reread.json();
    expect(fresh.iban_last4).toBe('2051');
    expect(fresh.mandate_reference).toBe(updated.mandate_reference);
  });

  test('PATCH /api/admin/members/{id} refuses a mandate reference another member holds without dropping the current mandate', async ({ authenticatedRequest }) => {
    const stamp = Date.now();
    const others = await authenticatedRequest.post('/api/admin/members', {
      data: {
        first_name: 'RefHolder',
        last_name: `Member${stamp}`,
        email: `refholder-${stamp}@example.com`,
        date_of_birth: '1985-06-15',
        preferred_language: 'de',
        iban: 'DE89370400440532013000',
        mandate_signed_at: '2025-01-15',
      },
    });
    expect(others.status()).toBe(201);
    const takenReference = (await others.json()).mandate_reference;

    const targetResponse = await authenticatedRequest.post('/api/admin/members', {
      data: {
        first_name: 'RefTaker',
        last_name: `Member${stamp}`,
        email: `reftaker-${stamp}@example.com`,
        date_of_birth: '1985-06-15',
        preferred_language: 'de',
        iban: 'DE89370400440532013000',
        mandate_signed_at: '2025-01-15',
      },
    });
    expect(targetResponse.status()).toBe(201);
    const target = await targetResponse.json();

    const patchResponse = await authenticatedRequest.patch(`/api/admin/members/${target.id}`, {
      data: { iban: 'DE02120300000000202051', mandate_reference: takenReference },
    });

    // A collision the admin can correct, so it must be a 422 naming it — not
    // the unactionable "internal server error" a raw PDOException produced.
    expect(patchResponse.status()).toBe(422);
    const body = await patchResponse.json();
    expect(body.message).toContain(takenReference);

    // And the refusal must leave the member's existing mandate intact rather
    // than stranding them SEPA-invalid.
    const reread = await authenticatedRequest.get(`/api/admin/members/${target.id}`);
    const fresh = await reread.json();
    expect(fresh.mandate_reference).toBe(target.mandate_reference);
    expect(fresh.iban_last4).toBe('3000');
    expect(fresh.is_sepa_valid).toBe(true);
  });

  test('All CRUD endpoints return JSON content type', async ({ authenticatedRequest }) => {
    const data = {
      first_name: 'JsonTest',
      last_name: 'Member',
      email: 'jsontest@example.com',
      date_of_birth: '1985-06-15',
      preferred_language: 'en',
    };

    const createResponse = await authenticatedRequest.post('/api/admin/members', { data });
    expect(createResponse.headers()['content-type']).toContain('application/json');
    const createdId = (await createResponse.json()).id;

    const getResponse = await authenticatedRequest.get(`/api/admin/members/${createdId}`);
    expect(getResponse.headers()['content-type']).toContain('application/json');

    const patchResponse = await authenticatedRequest.patch(`/api/admin/members/${createdId}`, {
      data: { preferred_language: 'fr' },
    });
    expect(patchResponse.headers()['content-type']).toContain('application/json');

    const deleteResponse = await authenticatedRequest.delete(`/api/admin/members/${createdId}`);
    expect(deleteResponse.headers()['content-type']).toContain('application/json');
  });
});
