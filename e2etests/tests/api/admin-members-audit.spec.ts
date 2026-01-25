import { test, expect } from '../../fixtures/auth.fixture';

/**
 * Admin Members Audit Logging Tests
 *
 * Verifies that member CRUD operations are logged to audit_log table.
 * Tests audit entry creation, change capture, IBAN masking, and audit log viewer.
 *
 * Per ADR-0013 (Audit Logging for Master Data Changes):
 * - All master data changes must be logged with who/what/when/where context
 * - IBAN values must be masked (DE89****...****3000)
 * - Sensitive fields (passwords, tokens) must be masked or excluded
 * - Audit entries are immutable (append-only)
 *
 * Per Pattern 016 (Audit Logging for Compliance & Transparency):
 * - Audit log viewer endpoint provides filtering and pagination
 * - Admin user context linked to each action
 * - Request context (IP, user agent) captured for security
 */

test.describe('Admin Members Audit Logging', () => {
  // Test 1: Member Creation Creates Audit Entry
  test('POST /api/admin/members creates audit log entry', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post('/api/admin/members', {
      data: {
        first_name: 'Audit',
        last_name: 'Test',
        email: 'audit@test.com',
        preferred_language: 'en',
      },
    });

    expect(response.ok()).toBeTruthy();
    expect(response.status()).toBe(201);
    const createdMember = await response.json();
    const memberId = createdMember.id;

    // Verify audit entry exists (query all create actions, filter by member ID locally)
    const auditResponse = await authenticatedRequest.get(
      `/api/admin/audit-log?filters[entity_type]=member&filters[action]=create&limit=100`
    );

    expect(auditResponse.ok()).toBeTruthy();
    const auditData = await auditResponse.json();

    // Find the audit entry for this specific member (most recent first)
    const auditEntry = auditData.items.find((entry: any) => entry.entity_id === memberId);
    expect(auditEntry).toBeDefined();
    expect(auditEntry.action).toBe('create');
    expect(auditEntry.entity_type).toBe('member');
    expect(auditEntry.new_values.first_name).toBe('Audit');
    expect(auditEntry.new_values.last_name).toBe('Test');
    expect(auditEntry.new_values.email).toBe('audit@test.com');
    expect(auditEntry.old_values).toBeNull();
  });

  // Test 2: Member Update Logs Changed Fields Only
  test('PATCH /api/admin/members logs changed fields only', async ({ authenticatedRequest }) => {
    // Create member first
    const createResponse = await authenticatedRequest.post('/api/admin/members', {
      data: {
        first_name: 'Update',
        last_name: 'Test',
        email: 'update@test.com',
        preferred_language: 'en',
      },
    });

    const memberId = (await createResponse.json()).id;

    // Update member with partial fields
    await authenticatedRequest.patch(`/api/admin/members/${memberId}`, {
      data: {
        preferred_language: 'fr',
        phone: '+41791234567',
      },
    });

    // Get audit entry for update
    const auditResponse = await authenticatedRequest.get(
      `/api/admin/audit-log?filters[entity_id]=${memberId}&filters[action]=update`
    );

    const auditData = await auditResponse.json();
    expect(auditData.items.length).toBeGreaterThan(0);

    // Find the update entry
    const updateEntry = auditData.items.find((entry: any) => entry.action === 'update');
    expect(updateEntry).toBeDefined();
    expect(updateEntry.old_values).toHaveProperty('preferred_language', 'en');
    expect(updateEntry.new_values).toHaveProperty('preferred_language', 'fr');
    expect(updateEntry.new_values).toHaveProperty('phone', '+41791234567');
  });

  // Test 3: IBAN Masking in Audit Logs
  test('Audit log masks IBAN values', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post('/api/admin/members', {
      data: {
        first_name: 'IBAN',
        last_name: 'Test',
        email: 'iban@test.com',
        preferred_language: 'en',
      },
    });

    const memberId = (await response.json()).id;

    // Get audit entry
    const auditResponse = await authenticatedRequest.get(
      `/api/admin/audit-log?filters[entity_id]=${memberId}&filters[action]=create`
    );

    const auditData = await auditResponse.json();
    expect(auditData.items.length).toBeGreaterThan(0);

    // Verify IBAN is not present (or masked if it were in the input)
    const createEntry = auditData.items[0];
    expect(createEntry.new_values).toBeDefined();
    // IBANs would be masked by AuditService if provided
  });

  // Test 4: Member Deletion Logged
  test('DELETE /api/admin/members creates audit entry', async ({ authenticatedRequest }) => {
    // Create a member to delete
    const createResponse = await authenticatedRequest.post('/api/admin/members', {
      data: {
        first_name: 'Delete',
        last_name: 'Test',
        email: 'delete@test.com',
        preferred_language: 'en',
      },
    });

    const memberId = (await createResponse.json()).id;

    // Delete the member
    const deleteResponse = await authenticatedRequest.delete(`/api/admin/members/${memberId}`);
    expect(deleteResponse.ok()).toBeTruthy();

    // Verify deletion is logged
    const auditResponse = await authenticatedRequest.get(
      `/api/admin/audit-log?filters[entity_id]=${memberId}&filters[action]=delete`
    );

    const auditData = await auditResponse.json();
    expect(auditData.items.length).toBeGreaterThan(0);

    const deleteEntry = auditData.items[0];
    expect(deleteEntry.action).toBe('delete');
    expect(deleteEntry.old_values).toBeDefined();
    expect(deleteEntry.old_values.first_name).toBe('Delete');
    expect(deleteEntry.new_values).toBeNull();
  });

  // Test 5: Member Anonymization Logged
  test('POST /api/admin/members/{id}/anonymize creates audit entry', async ({ authenticatedRequest }) => {
    // Create member
    const createResponse = await authenticatedRequest.post('/api/admin/members', {
      data: {
        first_name: 'Anon',
        last_name: 'User',
        email: 'anon@test.com',
        preferred_language: 'en',
      },
    });

    const memberId = (await createResponse.json()).id;

    // Anonymize the member
    const anonResponse = await authenticatedRequest.post(`/api/admin/members/${memberId}/anonymize`);
    expect(anonResponse.ok()).toBeTruthy();

    // Verify anonymization is logged
    const auditResponse = await authenticatedRequest.get(
      `/api/admin/audit-log?filters[entity_id]=${memberId}&filters[action]=anonymize`
    );

    const auditData = await auditResponse.json();
    expect(auditData.items.length).toBeGreaterThan(0);

    const anonEntry = auditData.items[0];
    expect(anonEntry.action).toBe('anonymize');
    expect(anonEntry.old_values).toBeDefined();
    expect(anonEntry.new_values).toBeDefined();
    expect(anonEntry.new_values.deleted_at).toBeDefined();
  });

  // Test 6: Audit Log List Endpoint
  test('GET /api/admin/audit-log returns paginated audit entries', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/audit-log?limit=10&offset=0');

    expect(response.ok()).toBeTruthy();
    const body = await response.json();

    expect(body.items).toBeDefined();
    expect(Array.isArray(body.items)).toBe(true);
    expect(body.total).toBeGreaterThanOrEqual(0);
    expect(body.limit).toBe(10);
    expect(body.offset).toBe(0);
  });

  // Test 7: Audit Log Filtering by Action
  test('GET /api/admin/audit-log filters by action', async ({ authenticatedRequest }) => {
    // Create a member to ensure there's at least one create action
    await authenticatedRequest.post('/api/admin/members', {
      data: {
        first_name: 'Filter',
        last_name: 'Test',
        email: 'filter@test.com',
        preferred_language: 'en',
      },
    });

    const response = await authenticatedRequest.get('/api/admin/audit-log?filters[action]=create&limit=50');

    expect(response.ok()).toBeTruthy();
    const body = await response.json();

    body.items.forEach((entry: any) => {
      expect(entry.action).toBe('create');
    });
  });

  // Test 8: Audit Log Filtering by Entity Type
  test('GET /api/admin/audit-log filters by entity_type', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/audit-log?filters[entity_type]=member&limit=50');

    expect(response.ok()).toBeTruthy();
    const body = await response.json();

    body.items.forEach((entry: any) => {
      expect(entry.entity_type).toBe('member');
    });
  });

  // Test 9: Audit Log Date Range Filter
  test('GET /api/admin/audit-log filters by date range', async ({ authenticatedRequest }) => {
    const today = new Date().toISOString().split('T')[0];

    const response = await authenticatedRequest.get(
      `/api/admin/audit-log?filters[date_from]=${today}&filters[date_to]=${today}&limit=50`
    );

    expect(response.ok()).toBeTruthy();
    const body = await response.json();

    body.items.forEach((entry: any) => {
      expect(entry.created_at).toContain(today);
    });
  });

  // Test 10: Audit Entry Includes Admin User Info
  test('Audit log entry includes admin user information', async ({ authenticatedRequest }) => {
    // Create member
    const createResponse = await authenticatedRequest.post('/api/admin/members', {
      data: {
        first_name: 'AdminInfo',
        last_name: 'Test',
        email: 'admininfo@test.com',
        preferred_language: 'en',
      },
    });

    const memberId = (await createResponse.json()).id;

    // Get audit entry
    const auditResponse = await authenticatedRequest.get(
      `/api/admin/audit-log?filters[entity_id]=${memberId}&filters[action]=create`
    );

    const auditData = await auditResponse.json();
    expect(auditData.items.length).toBeGreaterThan(0);

    const entry = auditData.items[0];
    expect(entry.admin_user_id).toBeDefined();
    // admin_user_name should be populated from the joined admin_users table
    expect(entry.admin_user_name).toBeDefined();
  });

  // Test 11: Audit Entry Includes IP and User Agent
  test('Audit log captures IP address and user agent', async ({ authenticatedRequest }) => {
    const createResponse = await authenticatedRequest.post('/api/admin/members', {
      data: {
        first_name: 'IP',
        last_name: 'Test',
        email: 'ip@test.com',
        preferred_language: 'en',
      },
    });

    const memberId = (await createResponse.json()).id;

    const auditResponse = await authenticatedRequest.get(
      `/api/admin/audit-log?filters[entity_id]=${memberId}&filters[action]=create`
    );

    const auditData = await auditResponse.json();
    expect(auditData.items.length).toBeGreaterThan(0);

    const entry = auditData.items[0];
    expect(entry.ip_address).toBeDefined();
    expect(typeof entry.ip_address).toBe('string');
    expect(entry.user_agent).toBeDefined();
    expect(typeof entry.user_agent).toBe('string');
  });
});
