import type { APIRequestContext } from '@playwright/test';
import { test, expect } from '../../fixtures/auth.fixture';

/**
 * Audit log ordering (#125).
 *
 * The audit screen has always had a sort control on its timestamp column, and
 * the endpoint behind it had `ORDER BY created_at DESC` hard-coded — the arrow
 * flipped, the rows did not. These tests drive the real endpoint end to end:
 * member writes produce the audit entries, then the list is read back in both
 * directions.
 *
 * Every entry a test asserts on belongs to a member that test created, and the
 * request is scoped with `entity_id`, so nothing here depends on what else the
 * audit trail holds or on what runs beside it (Patterns 001 and 004).
 */
test.describe('Admin Audit Log Sorting', () => {
  interface AuditEntry {
    id: number
    action: string
  }

  async function auditEntriesFor(
    request: APIRequestContext,
    entityId: string,
    sortBy?: string,
  ): Promise<AuditEntry[]> {
    const params: Record<string, string | number> = { entity_id: entityId, per_page: 20 };
    if (sortBy) params.sort_by = sortBy;

    const response = await request.get('/api/admin/audit-log', { params });
    expect(response.status()).toBe(200);

    return (await response.json()).data;
  }

  /** Create a member and change it twice, leaving three entries on one entity. */
  async function memberWithAuditTrail(request: APIRequestContext): Promise<string> {
    const token = `Aud${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;

    const created = await request.post('/api/admin/members', {
      data: {
        first_name: 'Audit',
        last_name: token,
        email: `${token}@test.com`,
        preferred_language: 'de',
      },
    });
    expect(created.status()).toBe(201);
    const memberId = (await created.json()).id;

    for (const firstName of ['AuditTwo', 'AuditThree']) {
      const updated = await request.patch(`/api/admin/members/${memberId}`, {
        data: { first_name: firstName },
      });
      expect(updated.status()).toBe(200);
    }

    return memberId;
  }

  test('GET /api/admin/audit-log defaults to newest first', async ({ authenticatedRequest }) => {
    const memberId = await memberWithAuditTrail(authenticatedRequest);

    const entries = await auditEntriesFor(authenticatedRequest, memberId);

    expect(entries).toHaveLength(3);
    expect(entries.map((e: AuditEntry) => e.action)).toEqual(['update', 'update', 'create']);
  });

  test('GET /api/admin/audit-log orders oldest first for created_at_asc', async ({ authenticatedRequest }) => {
    const memberId = await memberWithAuditTrail(authenticatedRequest);

    const ascending = await auditEntriesFor(authenticatedRequest, memberId, 'created_at_asc');

    // The creation is the oldest entry, so it leads — this is exactly what the
    // sort control claimed to do and did not.
    expect(ascending.map((e: AuditEntry) => e.action)).toEqual(['create', 'update', 'update']);

    const descending = await auditEntriesFor(authenticatedRequest, memberId, 'created_at_desc');
    expect(descending.map((e: AuditEntry) => e.id)).toEqual([...ascending.map((e: AuditEntry) => e.id)].reverse());
  });

  test('GET /api/admin/audit-log pages stay disjoint under a shared timestamp', async ({ authenticatedRequest }) => {
    // All three entries are written within the same second, so `created_at`
    // alone cannot order them and a row could otherwise appear on two pages.
    const memberId = await memberWithAuditTrail(authenticatedRequest);

    const seen: number[] = [];
    for (const page of [1, 2, 3]) {
      const response = await authenticatedRequest.get('/api/admin/audit-log', {
        params: { entity_id: memberId, per_page: 1, page, sort_by: 'created_at_desc' },
      });
      expect(response.status()).toBe(200);
      const body = await response.json();
      expect(body.data).toHaveLength(1);
      seen.push(body.data[0].id);
    }

    expect(new Set(seen).size).toBe(3);
  });

  test('GET /api/admin/audit-log refuses a column it cannot sort by', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/audit-log', {
      params: { sort: 'ip_address', order: 'asc' },
    });

    expect(response.status()).toBe(422);
  });
});
