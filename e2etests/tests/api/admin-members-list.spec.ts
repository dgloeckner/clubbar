import type { APIRequestContext } from '@playwright/test';
import { test, expect } from '../../fixtures/auth.fixture';

/**
 * Admin Members List endpoint tests
 *
 * Tests the GET /api/admin/members endpoint per Admin API spec.
 * Returns paginated list of members with optional filters.
 * Response shape: { data: MemberListItem[], pagination: { page, per_page, total, total_pages } }
 */

test.describe('Admin Members List Endpoint', () => {
  test('GET /api/admin/members returns paginated member list', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members');

    expect(response.ok()).toBeTruthy();
    expect(response.status()).toBe(200);

    const body = await response.json();

    // Validate OAS-compliant response structure
    expect(body.data).toBeDefined();
    expect(Array.isArray(body.data)).toBeTruthy();
    expect(body.data.length).toBeGreaterThan(0);
    expect(body.pagination).toBeDefined();
    expect(typeof body.pagination.total).toBe('number');
    expect(typeof body.pagination.page).toBe('number');
    expect(typeof body.pagination.per_page).toBe('number');
    expect(typeof body.pagination.total_pages).toBe('number');
  });

  test('GET /api/admin/members returns member objects with admin fields', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members');
    const body = await response.json();

    const member = body.data[0];

    // Verify all required admin fields
    expect(member.id).toBeDefined();
    expect(member.first_name).toBeDefined();
    expect(member.last_name).toBeDefined();
    expect(member.email).toBeDefined();
    expect(member.phone).toBeDefined();
    expect(member.preferred_language).toBeDefined();
    expect(member.is_active).toBeDefined();
    expect(member.is_sepa_valid).toBeDefined();
    expect(member.iban_masked).toBeDefined();
    expect(member.created_at).toBeDefined();
    expect(member.updated_at).toBeDefined();
  });

  test('GET /api/admin/members respects per_page parameter', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members', {
      params: { per_page: 1 },
    });

    const body = await response.json();

    expect(body.pagination.per_page).toBe(1);
    expect(body.data.length).toBeLessThanOrEqual(1);
  });

  test('GET /api/admin/members respects page parameter', async ({ authenticatedRequest }) => {
    // Use stable sort by id to ensure consistent ordering across paginated requests.
    const params = { per_page: 1, sort: 'id', order: 'asc' };

    // Get first member (page 1)
    const response1 = await authenticatedRequest.get('/api/admin/members', {
      params: { ...params, page: 1 },
    });

    const body1 = await response1.json();
    const firstMemberId = body1.data[0].id;

    // Get second member (page 2)
    const response2 = await authenticatedRequest.get('/api/admin/members', {
      params: { ...params, page: 2 },
    });

    const body2 = await response2.json();

    // They should be different if multiple members exist
    if (body1.pagination.total > 1) {
      expect(body2.data[0].id).not.toBe(firstMemberId);
    }
  });

  test('GET /api/admin/members rejects per_page greater than 100', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members', {
      params: { per_page: 500 },
    });

    expect(response.status()).toBe(400);

    const body = await response.json();

    expect(body.error).toBe('invalid_request');
    expect(body.messages).toBeDefined();
    expect(body.messages.per_page).toBeDefined();
  });

  test('GET /api/admin/members pagination totals are consistent', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members');
    const body = await response.json();

    const { total, per_page, total_pages } = body.pagination;
    expect(total_pages).toBe(Math.ceil(total / per_page));
  });

  test('GET /api/admin/members filters by is_active', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members', {
      params: { 'filters[is_active]': 'true' },
    });

    const body = await response.json();

    // All returned members should be active
    for (const member of body.data) {
      expect(member.is_active).toBe(true);
    }
  });

  test('GET /api/admin/members filters by language', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members', {
      params: { 'filters[language]': 'de' },
    });

    const body = await response.json();

    // All returned members should have German language preference
    for (const member of body.data) {
      expect(member.preferred_language).toBe('de');
    }
  });

  test('GET /api/admin/members returns valid ISO 8601 timestamps', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members');
    const body = await response.json();

    const member = body.data[0];

    // Validate ISO 8601 format (YYYY-MM-DDTHH:mm:ssZ)
    const iso8601Regex = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;
    expect(member.created_at).toMatch(iso8601Regex);
    expect(member.updated_at).toMatch(iso8601Regex);
  });

  test('GET /api/admin/members returns JSON content type', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members');

    const contentType = response.headers()['content-type'];
    expect(contentType).toContain('application/json');
  });

  test('GET /api/admin/members with invalid per_page returns 400', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members', {
      params: { per_page: 'invalid' },
    });

    expect(response.status()).toBe(400);
  });

  /**
   * Sorting (#125).
   *
   * The admin list offers a Name, a Card-UID and a Member-since column. Every
   * one of them has to reach the query: the members endpoint used to answer
   * `card_uid_asc` and `created_at_asc` with the newest-first order, so the
   * header arrow and the rows disagreed.
   *
   * Each test creates its own members and scopes the request with `search`,
   * so the assertions hold no matter what else the database holds or what
   * runs in parallel (Patterns 001 and 004).
   */
  test.describe('Sorting', () => {
    /** A token unique to one test, used as both a name prefix and the search scope. */
    function sortToken(): string {
      return `Srt${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`.replace(/[^A-Za-z0-9]/g, '')
    }

    async function createMember(
      request: APIRequestContext,
      fields: Record<string, unknown>,
    ): Promise<string> {
      const response = await request.post('/api/admin/members', {
        data: { preferred_language: 'de', ...fields },
      });
      expect(response.status()).toBe(201);

      return (await response.json()).id;
    }

    test('GET /api/admin/members sorts by name, last name before first name', async ({ authenticatedRequest }) => {
      const token = sortToken();
      const bravo = await createMember(authenticatedRequest, {
        first_name: 'Zoe', last_name: `${token}Bravo`, email: `${token}-zoe@test.com`,
      });
      const alphaBea = await createMember(authenticatedRequest, {
        first_name: 'Bea', last_name: `${token}Alpha`, email: `${token}-bea@test.com`,
      });
      const alphaAnn = await createMember(authenticatedRequest, {
        first_name: 'Ann', last_name: `${token}Alpha`, email: `${token}-ann@test.com`,
      });

      const ascending = await authenticatedRequest.get('/api/admin/members', {
        params: { search: token, sort_by: 'name_asc' },
      });
      expect(ascending.status()).toBe(200);
      const ascendingIds = (await ascending.json()).data.map((m: { id: string }) => m.id);
      expect(ascendingIds).toEqual([alphaAnn, alphaBea, bravo]);

      const descending = await authenticatedRequest.get('/api/admin/members', {
        params: { search: token, sort_by: 'name_desc' },
      });
      const descendingIds = (await descending.json()).data.map((m: { id: string }) => m.id);
      expect(descendingIds).toEqual([bravo, alphaBea, alphaAnn]);
    });

    test('GET /api/admin/members sorts by card UID, cardless members last', async ({ authenticatedRequest }) => {
      const token = sortToken();
      const hex = Date.now().toString(16).toUpperCase().slice(-8);
      const withoutCard = await createMember(authenticatedRequest, {
        first_name: 'NoCard', last_name: token, email: `${token}-none@test.com`,
      });
      const highCard = await createMember(authenticatedRequest, {
        first_name: 'HighCard', last_name: token, email: `${token}-high@test.com`,
        card_uid: `FFFF${hex}`,
      });
      const lowCard = await createMember(authenticatedRequest, {
        first_name: 'LowCard', last_name: token, email: `${token}-low@test.com`,
        card_uid: `0000${hex}`,
      });

      const ascending = await authenticatedRequest.get('/api/admin/members', {
        params: { search: token, sort_by: 'card_uid_asc' },
      });
      expect(ascending.status()).toBe(200);
      const ascendingIds = (await ascending.json()).data.map((m: { id: string }) => m.id);
      expect(ascendingIds).toEqual([lowCard, highCard, withoutCard]);

      // Most members have no card at all, so they stay at the end in both
      // directions rather than burying the cards behind them.
      const descending = await authenticatedRequest.get('/api/admin/members', {
        params: { search: token, sort_by: 'card_uid_desc' },
      });
      const descendingIds = (await descending.json()).data.map((m: { id: string }) => m.id);
      expect(descendingIds).toEqual([highCard, lowCard, withoutCard]);
    });

    test('GET /api/admin/members sorts oldest first when asked for created_at_asc', async ({ authenticatedRequest }) => {
      const ascending = await authenticatedRequest.get('/api/admin/members', {
        params: { sort_by: 'created_at_asc', per_page: 50 },
      });
      expect(ascending.status()).toBe(200);
      const ascendingDates = (await ascending.json()).data.map((m: { created_at: string }) => m.created_at);
      expect(ascendingDates.length).toBeGreaterThan(1);
      expect([...ascendingDates].sort()).toEqual(ascendingDates);

      const descending = await authenticatedRequest.get('/api/admin/members', {
        params: { sort_by: 'created_at_desc', per_page: 50 },
      });
      const descendingDates = (await descending.json()).data.map((m: { created_at: string }) => m.created_at);
      expect([...descendingDates].sort().reverse()).toEqual(descendingDates);
    });

    test('GET /api/admin/members refuses a column it cannot sort by', async ({ authenticatedRequest }) => {
      const response = await authenticatedRequest.get('/api/admin/members', {
        params: { sort: 'iban', order: 'asc' },
      });

      expect(response.status()).toBe(422);
    });

    /**
     * Search terms carrying a LIKE wildcard.
     *
     * `SafeQuery::escapeLike` escaped `%` and `_` before the backslash and so
     * re-escaped its own output; the pattern that reached the database matched
     * nothing, and searching for a name with an underscore in it came back
     * empty while the member sat in the list.
     */
    test('GET /api/admin/members finds a name containing a LIKE wildcard', async ({ authenticatedRequest }) => {
      const token = sortToken();
      const underscore = await createMember(authenticatedRequest, {
        first_name: `Jean${token}_Luc`, last_name: 'Picard', email: `${token}-jl@test.com`,
      });
      const percent = await createMember(authenticatedRequest, {
        first_name: `Cent${token}100%`, last_name: 'Proof', email: `${token}-pct@test.com`,
      });

      const underscoreHit = await authenticatedRequest.get('/api/admin/members', {
        params: { search: `Jean${token}_Luc` },
      });
      expect(underscoreHit.status()).toBe(200);
      expect((await underscoreHit.json()).data.map((m: { id: string }) => m.id)).toEqual([underscore]);

      const percentHit = await authenticatedRequest.get('/api/admin/members', {
        params: { search: `Cent${token}100%` },
      });
      expect(percentHit.status()).toBe(200);
      expect((await percentHit.json()).data.map((m: { id: string }) => m.id)).toEqual([percent]);
    });

    test('GET /api/admin/members reads a searched wildcard as a character, not a wildcard', async ({
      authenticatedRequest,
    }) => {
      // The other half of the escape's job: a `_` the admin typed must not
      // quietly match every other character in that position.
      const token = sortToken();
      const typed = await createMember(authenticatedRequest, {
        first_name: `Wild${token}_x`, last_name: 'Escape', email: `${token}-typed@test.com`,
      });
      await createMember(authenticatedRequest, {
        first_name: `Wild${token}Zx`, last_name: 'Escape', email: `${token}-decoy@test.com`,
      });

      const response = await authenticatedRequest.get('/api/admin/members', {
        params: { search: `Wild${token}_x` },
      });
      expect(response.status()).toBe(200);
      expect((await response.json()).data.map((m: { id: string }) => m.id)).toEqual([typed]);
    });
  });
});
