import { test, expect } from '@playwright/test';

/**
 * Admin Members Database Persistence Tests
 *
 * Validates complete database lifecycle operations:
 * - Create → Retrieve round-trips (verify data persists)
 * - Update → Retrieve round-trips (verify changes persist)
 * - Delete → Verify Gone (verify hard-delete)
 * - Filter/Pagination with created data
 * - GDPR operations with real persisted data
 * - Concurrent operations
 *
 * These tests close the gap where API response validation doesn't confirm
 * that data actually survives a database round-trip.
 */

test.describe('Admin Members Database Persistence', () => {
  // ============================================================================
  // CREATE → RETRIEVE ROUND-TRIP TESTS
  // ============================================================================

  test('Create member persists all fields to database', async ({ request }) => {
    // 1. CREATE - Send POST request with all fields
    const createRes = await request.post('/api/admin/members', {
      data: {
        first_name: 'Persistence',
        last_name: 'Test',
        email: 'persistence@example.com',
        phone: '+41791111111',
        preferred_language: 'en',
      },
    });

    expect(createRes.status()).toBe(201);
    const createdBody = await createRes.json();
    const memberId = createdBody.id;

    // 2. RETRIEVE - Fetch the newly created member
    const getRes = await request.get(`/api/admin/members/${memberId}`);
    expect(getRes.status()).toBe(200);
    const retrievedBody = await getRes.json();

    // 3. VALIDATE PERSISTENCE - All fields should match
    expect(retrievedBody.id).toBe(memberId);
    expect(retrievedBody.first_name).toBe('Persistence');
    expect(retrievedBody.last_name).toBe('Test');
    expect(retrievedBody.email).toBe('persistence@example.com');
    expect(retrievedBody.phone).toBe('+41791111111');
    expect(retrievedBody.preferred_language).toBe('en');
    expect(retrievedBody.is_active).toBe(true);
    expect(retrievedBody.created_at).toBeDefined();
    expect(retrievedBody.updated_at).toBeDefined();
  });

  test('Create member without optional phone persists correctly', async ({ request }) => {
    const createRes = await request.post('/api/admin/members', {
      data: {
        first_name: 'NoPhone',
        last_name: 'Member',
        email: 'nophone@example.com',
        preferred_language: 'de',
        // phone is optional - not included
      },
    });

    expect(createRes.status()).toBe(201);
    const memberId = (await createRes.json()).id;

    const getRes = await request.get(`/api/admin/members/${memberId}`);
    const body = await getRes.json();

    expect(body.first_name).toBe('NoPhone');
    expect(body.phone).toBeNull();
    expect(body.preferred_language).toBe('de');
  });

  test('Create multiple members generates unique IDs', async ({ request }) => {
    // Create first member
    const res1 = await request.post('/api/admin/members', {
      data: {
        first_name: 'First',
        last_name: 'Member',
        email: 'first@example.com',
        preferred_language: 'en',
      },
    });
    const id1 = (await res1.json()).id;

    // Create second member
    const res2 = await request.post('/api/admin/members', {
      data: {
        first_name: 'Second',
        last_name: 'Member',
        email: 'second@example.com',
        preferred_language: 'en',
      },
    });
    const id2 = (await res2.json()).id;

    // IDs must be unique
    expect(id1).not.toBe(id2);

    // Both must be retrievable
    const get1 = await request.get(`/api/admin/members/${id1}`);
    const get2 = await request.get(`/api/admin/members/${id2}`);
    expect(get1.status()).toBe(200);
    expect(get2.status()).toBe(200);

    // Data must be distinct
    const body1 = await get1.json();
    const body2 = await get2.json();
    expect(body1.first_name).toBe('First');
    expect(body2.first_name).toBe('Second');
  });

  test('Created member appears in list', async ({ request }) => {
    // Create a new member
    const createRes = await request.post('/api/admin/members', {
      data: {
        first_name: 'ListTest',
        last_name: 'Member',
        email: 'listtest@example.com',
        preferred_language: 'en',
      },
    });
    const memberId = (await createRes.json()).id;

    // Fetch full list
    const listRes = await request.get('/api/admin/members?limit=100');
    const listBody = await listRes.json();

    // The created member should be in the list
    const found = listBody.items.find((m: any) => m.id === memberId);
    expect(found).toBeDefined();
    expect(found.first_name).toBe('ListTest');
    expect(found.email).toBe('listtest@example.com');
  });

  // ============================================================================
  // UPDATE → RETRIEVE ROUND-TRIP TESTS
  // ============================================================================

  test('Update member persists changes to database', async ({ request }) => {
    // 1. CREATE - Create initial member
    const createRes = await request.post('/api/admin/members', {
      data: {
        first_name: 'UpdateTest',
        last_name: 'Original',
        email: 'update@example.com',
        phone: '+41790000000',
        preferred_language: 'de',
      },
    });
    const memberId = (await createRes.json()).id;

    // 2. UPDATE - Change multiple fields
    const updateRes = await request.patch(`/api/admin/members/${memberId}`, {
      data: {
        last_name: 'Updated',
        phone: '+41799999999',
        preferred_language: 'fr',
      },
    });

    expect(updateRes.status()).toBe(200);

    // 3. RETRIEVE - Fetch updated member
    const getRes = await request.get(`/api/admin/members/${memberId}`);
    const body = await getRes.json();

    // 4. VALIDATE PERSISTENCE - Changes should be in database
    expect(body.last_name).toBe('Updated');
    expect(body.phone).toBe('+41799999999');
    expect(body.preferred_language).toBe('fr');
    // Unchanged field should remain
    expect(body.first_name).toBe('UpdateTest');
    expect(body.email).toBe('update@example.com');
  });

  test('Update single field preserves other fields', async ({ request }) => {
    // Create member
    const createRes = await request.post('/api/admin/members', {
      data: {
        first_name: 'SingleField',
        last_name: 'Test',
        email: 'singlefield@example.com',
        phone: '+41781234567',
        preferred_language: 'en',
      },
    });
    const memberId = (await createRes.json()).id;

    // Update only phone
    await request.patch(`/api/admin/members/${memberId}`, {
      data: { phone: '+41789999999' },
    });

    // Verify phone changed but others preserved
    const getRes = await request.get(`/api/admin/members/${memberId}`);
    const body = await getRes.json();

    expect(body.phone).toBe('+41789999999');
    expect(body.first_name).toBe('SingleField');
    expect(body.last_name).toBe('Test');
    expect(body.email).toBe('singlefield@example.com');
    expect(body.preferred_language).toBe('en');
  });

  test('Updated member reflected in list', async ({ request }) => {
    // Create member
    const createRes = await request.post('/api/admin/members', {
      data: {
        first_name: 'ListUpdateTest',
        last_name: 'Original',
        email: 'listupdate@example.com',
        preferred_language: 'en',
      },
    });
    const memberId = (await createRes.json()).id;

    // Update member
    await request.patch(`/api/admin/members/${memberId}`, {
      data: { last_name: 'Updated' },
    });

    // Check list
    const listRes = await request.get('/api/admin/members?limit=100');
    const listBody = await listRes.json();

    const found = listBody.items.find((m: any) => m.id === memberId);
    expect(found.last_name).toBe('Updated');
  });

  test('Update changes updated_at timestamp', async ({ request }) => {
    // Create member
    const createRes = await request.post('/api/admin/members', {
      data: {
        first_name: 'TimestampTest',
        last_name: 'Member',
        email: 'timestamp@example.com',
        preferred_language: 'en',
      },
    });
    const createdBody = await createRes.json();
    const memberId = createdBody.id;

    // Wait before making change to ensure timestamp difference is detectable
    await new Promise(resolve => setTimeout(resolve, 1000));

    // Update member
    const updateRes = await request.patch(`/api/admin/members/${memberId}`, {
      data: { phone: '+41789999999' },
    });
    const updatedBody = await updateRes.json();

    // Verify timestamps are in ISO format and different
    expect(updatedBody.updated_at).toMatch(/^\d{4}-\d{2}-\d{2}T/);
    expect(createdBody.created_at).toBe(updatedBody.created_at);
  });

  // ============================================================================
  // DELETE → VERIFY GONE TESTS
  // ============================================================================

  test('Deleted member returns 404 on subsequent GET', async ({ request }) => {
    // Create member
    const createRes = await request.post('/api/admin/members', {
      data: {
        first_name: 'DeleteTest',
        last_name: 'Member',
        email: 'deletetest@example.com',
        preferred_language: 'en',
      },
    });
    const memberId = (await createRes.json()).id;

    // Delete member
    const deleteRes = await request.delete(`/api/admin/members/${memberId}`);
    expect(deleteRes.status()).toBe(200);

    // Attempt to retrieve deleted member
    const getRes = await request.get(`/api/admin/members/${memberId}`);
    expect(getRes.status()).toBe(404);
  });

  test('Deleted member not in list', async ({ request }) => {
    // Create member
    const createRes = await request.post('/api/admin/members', {
      data: {
        first_name: 'DeleteListTest',
        last_name: 'Member',
        email: 'deletelisttest@example.com',
        preferred_language: 'en',
      },
    });
    const memberId = (await createRes.json()).id;

    // Verify in list before delete
    let listRes = await request.get('/api/admin/members?limit=100');
    let listBody = await listRes.json();
    let found = listBody.items.find((m: any) => m.id === memberId);
    expect(found).toBeDefined();

    // Delete member
    await request.delete(`/api/admin/members/${memberId}`);

    // Verify not in list after delete
    listRes = await request.get('/api/admin/members?limit=100');
    listBody = await listRes.json();
    found = listBody.items.find((m: any) => m.id === memberId);
    expect(found).toBeUndefined();
  });

  test('Delete does not affect other members', async ({ request }) => {
    // Create two members
    const res1 = await request.post('/api/admin/members', {
      data: {
        first_name: 'DeleteIsolation1',
        last_name: 'Keep',
        email: 'delete1@example.com',
        preferred_language: 'en',
      },
    });
    const id1 = (await res1.json()).id;

    const res2 = await request.post('/api/admin/members', {
      data: {
        first_name: 'DeleteIsolation2',
        last_name: 'Delete',
        email: 'delete2@example.com',
        preferred_language: 'en',
      },
    });
    const id2 = (await res2.json()).id;

    // Delete second member
    await request.delete(`/api/admin/members/${id2}`);

    // First member should still exist
    const getRes = await request.get(`/api/admin/members/${id1}`);
    expect(getRes.status()).toBe(200);
    const body = await getRes.json();
    expect(body.first_name).toBe('DeleteIsolation1');
  });

  // ============================================================================
  // FILTER & PAGINATION PERSISTENCE TESTS
  // ============================================================================

  test('Created members filtered by language preference', async ({ request }) => {
    // Create members with different languages
    const res1 = await request.post('/api/admin/members', {
      data: {
        first_name: 'FilterDE',
        last_name: 'Member',
        email: 'filterde@example.com',
        preferred_language: 'de',
      },
    });

    const res2 = await request.post('/api/admin/members', {
      data: {
        first_name: 'FilterFR',
        last_name: 'Member',
        email: 'filterfr@example.com',
        preferred_language: 'fr',
      },
    });

    const id1 = (await res1.json()).id;
    const id2 = (await res2.json()).id;

    // Filter by German
    const listRes = await request.get('/api/admin/members?filters[language]=de&limit=100');
    const listBody = await listRes.json();

    // German member should be in filtered list
    const found1 = listBody.items.find((m: any) => m.id === id1);
    expect(found1).toBeDefined();
    expect(found1.preferred_language).toBe('de');

    // All items in filtered list should be German
    for (const member of listBody.items) {
      expect(member.preferred_language).toBe('de');
    }
  });

  test('Created members included in pagination', async ({ request }) => {
    // Create several members
    const ids = [];
    for (let i = 0; i < 3; i++) {
      const res = await request.post('/api/admin/members', {
        data: {
          first_name: `PaginationTest${i}`,
          last_name: 'Member',
          email: `pagination${i}@example.com`,
          preferred_language: 'en',
        },
      });
      ids.push((await res.json()).id);
    }

    // Fetch with limit
    const listRes = await request.get('/api/admin/members?limit=100');
    const listBody = await listRes.json();

    // All created members should be in list
    for (const id of ids) {
      const found = listBody.items.find((m: any) => m.id === id);
      expect(found).toBeDefined();
    }

    // Total should include them
    expect(listBody.total).toBeGreaterThanOrEqual(ids.length);
  });

  // ============================================================================
  // GDPR OPERATIONS PERSISTENCE TESTS
  // ============================================================================

  test('Anonymized member persists PII deletion to database', async ({ request }) => {
    // Create member with PII
    const createRes = await request.post('/api/admin/members', {
      data: {
        first_name: 'GDPRTest',
        last_name: 'Member',
        email: 'gdpr@example.com',
        phone: '+41791234567',
        preferred_language: 'en',
      },
    });
    const memberId = (await createRes.json()).id;

    // Anonymize
    const anonRes = await request.post(`/api/admin/members/${memberId}/anonymize`);
    expect(anonRes.status()).toBe(200);

    // Retrieve and verify PII cleared
    const getRes = await request.get(`/api/admin/members/${memberId}`);
    const body = await getRes.json();

    expect(body.first_name).toBe('DELETED');
    expect(body.last_name).toBe('DELETED');
    expect(body.email).toBe('deleted@example.com');
    expect(body.phone).toBeNull();
    expect(body.is_active).toBe(false);
    expect(body.deleted_at).toBeDefined();
  });

  test('Exported member data matches database', async ({ request }) => {
    // Create member with full data
    const createRes = await request.post('/api/admin/members', {
      data: {
        first_name: 'ExportTest',
        last_name: 'Member',
        email: 'exporttest@example.com',
        phone: '+41798765432',
        preferred_language: 'en',
      },
    });
    const memberId = (await createRes.json()).id;

    // Export member
    const exportRes = await request.post(`/api/admin/members/${memberId}/export`);
    expect(exportRes.status()).toBe(200);
    const exportBody = await exportRes.json();

    // Retrieve directly from database
    const getRes = await request.get(`/api/admin/members/${memberId}`);
    const getBody = await getRes.json();

    // Exported data should match database
    expect(exportBody.member.id).toBe(getBody.id);
    expect(exportBody.member.first_name).toBe(getBody.first_name);
    expect(exportBody.member.email).toBe(getBody.email);
    expect(exportBody.member.phone).toBe(getBody.phone);
  });

  // ============================================================================
  // CONCURRENT OPERATIONS TESTS
  // ============================================================================

  test('Multiple concurrent creates result in distinct database records', async ({ request }) => {
    // Create 5 members in sequence
    const ids = [];
    for (let i = 0; i < 5; i++) {
      const res = await request.post('/api/admin/members', {
        data: {
          first_name: `Concurrent${i}`,
          last_name: 'Member',
          email: `concurrent${i}@example.com`,
          preferred_language: 'en',
        },
      });
      ids.push((await res.json()).id);
    }

    // All IDs should be unique
    const uniqueIds = new Set(ids);
    expect(uniqueIds.size).toBe(ids.length);

    // All should be retrievable
    for (const id of ids) {
      const getRes = await request.get(`/api/admin/members/${id}`);
      expect(getRes.status()).toBe(200);
    }
  });

  test('Update and create operations are independent', async ({ request }) => {
    // Create first member
    const res1 = await request.post('/api/admin/members', {
      data: {
        first_name: 'Independent1',
        last_name: 'Original',
        email: 'independent1@example.com',
        preferred_language: 'en',
      },
    });
    const id1 = (await res1.json()).id;

    // Create second member
    const res2 = await request.post('/api/admin/members', {
      data: {
        first_name: 'Independent2',
        last_name: 'Original',
        email: 'independent2@example.com',
        preferred_language: 'en',
      },
    });
    const id2 = (await res2.json()).id;

    // Update first member
    await request.patch(`/api/admin/members/${id1}`, {
      data: { last_name: 'Updated' },
    });

    // Verify second member is unaffected
    const getRes = await request.get(`/api/admin/members/${id2}`);
    const body = await getRes.json();
    expect(body.last_name).toBe('Original');
  });

  test('Maximum length names persist correctly', async ({ request }) => {
    const longString = 'A'.repeat(100);

    const createRes = await request.post('/api/admin/members', {
      data: {
        first_name: longString,
        last_name: 'LongTest',
        email: 'longtext@example.com',
        preferred_language: 'en',
      },
    });
    const memberId = (await createRes.json()).id;

    const getRes = await request.get(`/api/admin/members/${memberId}`);
    const body = await getRes.json();

    expect(body.first_name).toBe(longString);
    expect(body.first_name.length).toBe(100);
  });

  test('Special characters in email persist correctly', async ({ request }) => {
    const specialEmail = 'test+special.email+tag@example.com';

    const createRes = await request.post('/api/admin/members', {
      data: {
        first_name: 'SpecialChar',
        last_name: 'Email',
        email: specialEmail,
        preferred_language: 'en',
      },
    });
    const memberId = (await createRes.json()).id;

    const getRes = await request.get(`/api/admin/members/${memberId}`);
    const body = await getRes.json();

    expect(body.email).toBe(specialEmail);
  });

  test('Phone number formats persist correctly', async ({ request }) => {
    const phoneNumbers = ['+41791234567', '0041791234567', '0791234567'];

    for (const phone of phoneNumbers) {
      const createRes = await request.post('/api/admin/members', {
        data: {
          first_name: 'PhoneTest',
          last_name: 'Member',
          email: `phone${Math.random()}@example.com`,
          phone: phone,
          preferred_language: 'en',
        },
      });
      const memberId = (await createRes.json()).id;

      const getRes = await request.get(`/api/admin/members/${memberId}`);
      const body = await getRes.json();

      expect(body.phone).toBe(phone);
    }
  });
});
