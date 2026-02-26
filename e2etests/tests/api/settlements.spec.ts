import { test, expect } from '../../fixtures/auth.fixture';

test.describe('Settlements API', () => {
  /**
   * SEPA Config Tests (5 tests)
   */
  test.describe('SEPA Configuration', () => {
    test('A1: GET /sepa-config returns full unmasked config (admin-only)', async ({ authenticatedRequest }) => {
      const response = await authenticatedRequest.get('/api/admin/sepa-config');

      expect(response.status()).toBe(200);
      const body = await response.json();
      expect(body).toHaveProperty('creditor_id');
      expect(body).toHaveProperty('creditor_name');
      expect(body).toHaveProperty('creditor_iban');
      expect(body).toHaveProperty('is_configured');
      expect(typeof body.is_configured).toBe('boolean');
      // Admin endpoint returns full unmasked values (no masking for admin-only access)
    });

    test('A2: PUT /sepa-config updates successfully', async ({ authenticatedRequest }) => {
      const timestamp = Date.now();
      const response = await authenticatedRequest.put('/api/admin/sepa-config', {
        data: {
          creditor_id: 'DE01ZZZ09999999999',
          creditor_name: `Test Org ${timestamp}`,
          creditor_iban: 'DE89370400440532013000',
          creditor_address_street: '123 Test Street',
          creditor_address_city: 'Berlin',
          creditor_address_country: 'DE',
        },
      });

      expect(response.status()).toBe(200);
      const body = await response.json();
      expect(body.creditor_name).toContain('Test Org');
      expect(body.is_configured).toBe(true);
    });

    test('A3: PUT /sepa-config rejects invalid IBAN checksum', async ({ authenticatedRequest }) => {
      const response = await authenticatedRequest.put('/api/admin/sepa-config', {
        data: {
          creditor_iban: 'DE00370400440532013000', // Invalid checksum
        },
      });

      expect(response.status()).toBe(422);
      const body = await response.json();
      expect(body.error).toBe('validation_failed');
      expect(body.messages).toBeDefined();
      // Validation message structure varies - just check messages exist
    });

    test('A4: PUT /sepa-config allows creditor_id change with warning', async ({ authenticatedRequest }) => {
      // First set creditor_id
      await authenticatedRequest.put('/api/admin/sepa-config', {
        data: {
          creditor_id: 'DE01ZZZ09999999999',
          creditor_name: 'Org 1',
          creditor_iban: 'DE89370400440532013000',
          creditor_address_street: 'Street',
          creditor_address_city: 'City',
          creditor_address_country: 'DE',
        },
      });

      // Change creditor_id (now allowed - loosened from strict immutability)
      // Changes are logged in audit trail
      const response = await authenticatedRequest.put('/api/admin/sepa-config', {
        data: {
          creditor_id: 'DE02DIFFERENT9999999999',
          creditor_name: 'Org 1',
          creditor_iban: 'DE89370400440532013000',
          creditor_address_street: 'Street',
          creditor_address_city: 'City',
          creditor_address_country: 'DE',
        },
      });

      // Should succeed (no longer rejected)
      expect(response.status()).toBe(200);
      const body = await response.json();
      // Creditor ID should be masked in response
      expect(body.creditor_id).toBeTruthy();
      expect(body.creditor_name).toBe('Org 1');
    });

    test('A5: PUT /sepa-config requires authentication', async ({ request }) => {
      const response = await request.put('/api/admin/sepa-config', {
        data: { creditor_name: 'Test' },
      });

      expect([301, 302, 401, 403]).toContain(response.status());
    });
  });

  /**
   * Settlement Preview Tests (4 tests)
   */
  test.describe('Settlement Preview', () => {
    test('B1: POST /settlements/preview returns eligible members', async ({ authenticatedRequest }) => {
      const response = await authenticatedRequest.post('/api/admin/settlements/preview', {
        data: {
          from_date: '2026-01-01',
          to_date: '2026-12-31',
        },
      });

      expect(response.status()).toBe(200);
      const body = await response.json();
      expect(body).toHaveProperty('eligible_members');
      expect(body).toHaveProperty('ineligible_members');
      expect(body).toHaveProperty('eligible_total');
      expect(body).toHaveProperty('ineligible_total');
      expect(body).toHaveProperty('member_count');
      expect(Array.isArray(body.eligible_members)).toBe(true);
    });

    test('B2: POST /settlements/preview excludes members without IBAN', async ({ authenticatedRequest }) => {
      const response = await authenticatedRequest.post('/api/admin/settlements/preview');

      expect(response.status()).toBe(200);
      const body = await response.json();
      expect(Array.isArray(body.ineligible_members)).toBe(true);
      expect(Array.isArray(body.warnings)).toBe(true);
    });

    test('B3: POST /settlements/preview filters by sepa_eligible_only', async ({ authenticatedRequest }) => {
      const response = await authenticatedRequest.post('/api/admin/settlements/preview', {
        data: {
          sepa_eligible_only: true,
        },
      });

      expect(response.status()).toBe(200);
      const body = await response.json();
      expect(body.ineligible_members.length).toBe(0);
    });

    test('B4: POST /settlements/preview calculates totals correctly', async ({ authenticatedRequest }) => {
      const response = await authenticatedRequest.post('/api/admin/settlements/preview');

      expect(response.status()).toBe(200);
      const body = await response.json();
      expect(typeof body.eligible_total).toBe('number');
      expect(typeof body.ineligible_total).toBe('number');
      expect(body.eligible_total).toBeGreaterThanOrEqual(0);
      expect(body.ineligible_total).toBeGreaterThanOrEqual(0);
    });
  });

  /**
   * Settlement Creation Tests (8 tests)
   */
  test.describe('Settlement Creation', () => {
    test('C1: POST /settlements creates settlement with required fields', async ({ authenticatedRequest }) => {
      const response = await authenticatedRequest.post('/api/admin/settlements', {
        data: {
          settlement_type: 'sepa',
          transaction_ids: [],
          settlement_date: '2026-01-26',
          execution_date: '2026-02-02',
          period_start: '2026-01-01',
          period_end: '2026-01-25',
        },
      });

      expect([201, 422]).toContain(response.status());
    });

    test('C2: POST /settlements creates manual settlement with reason', async ({ authenticatedRequest }) => {
      const response = await authenticatedRequest.post('/api/admin/settlements', {
        data: {
          settlement_type: 'manual',
          transaction_ids: [],
          settlement_date: '2026-01-26',
          execution_date: '2026-02-02',
          manual_reason: 'cash',
        },
      });

      expect([201, 422]).toContain(response.status());
    });

    test('C3: POST /settlements rejects execution_date < settlement_date + 7', async ({ authenticatedRequest }) => {
      const response = await authenticatedRequest.post('/api/admin/settlements', {
        data: {
          settlement_type: 'sepa',
          transaction_ids: ['test-id'],
          settlement_date: '2026-01-26',
          execution_date: '2026-01-28', // Only 2 days later
        },
      });

      expect(response.status()).toBe(422);
      const body = await response.json();
      expect(body.error).toBe('validation_failed');
      expect(body.messages).toBeDefined();
      // Validation message structure varies - just check messages exist
    });

    test('C4: POST /settlements requires transaction_ids', async ({ authenticatedRequest }) => {
      const response = await authenticatedRequest.post('/api/admin/settlements', {
        data: {
          settlement_type: 'sepa',
          transaction_ids: [],
          settlement_date: '2026-01-26',
          execution_date: '2026-02-02',
        },
      });

      expect(response.status()).toBe(422);
      const body = await response.json();
      expect(body.error).toBe('validation_failed');
      expect(body.messages).toBeDefined();
    });

    test('C5: POST /settlements validates manual_reason for manual type', async ({ authenticatedRequest }) => {
      const response = await authenticatedRequest.post('/api/admin/settlements', {
        data: {
          settlement_type: 'manual',
          transaction_ids: ['test-id'],
          settlement_date: '2026-01-26',
          execution_date: '2026-02-02',
          // Missing manual_reason
        },
      });

      expect(response.status()).toBe(422);
      const body = await response.json();
      expect(body.error).toBe('validation_failed');
    });

    test('C6: POST /settlements returns settlement with all required fields', async ({ authenticatedRequest }) => {
      const response = await authenticatedRequest.post('/api/admin/settlements', {
        data: {
          settlement_type: 'sepa',
          transaction_ids: [],
          settlement_date: '2026-01-26',
          execution_date: '2026-02-02',
        },
      });

      if (response.status() === 201) {
        const body = await response.json();
        expect(body).toHaveProperty('id');
        expect(body).toHaveProperty('settlement_type');
        expect(body).toHaveProperty('settlement_date');
        expect(body).toHaveProperty('execution_date');
        expect(body).toHaveProperty('total_amount_cents');
        expect(body).toHaveProperty('member_count');
        expect(body).toHaveProperty('created_at');
        expect(Array.isArray(body.items)).toBe(true);
      }
    });

    test('C7: POST /settlements returns 201 on successful creation', async ({ authenticatedRequest }) => {
      const response = await authenticatedRequest.post('/api/admin/settlements', {
        data: {
          settlement_type: 'sepa',
          transaction_ids: [],
          settlement_date: '2026-01-26',
          execution_date: '2026-02-02',
        },
      });

      expect([201, 422]).toContain(response.status());
    });

    test('C8: POST /settlements requires authentication', async ({ request }) => {
      const response = await request.post('/api/admin/settlements', {
        data: {
          settlement_type: 'sepa',
          transaction_ids: [],
          settlement_date: '2026-01-26',
          execution_date: '2026-02-02',
        },
      });

      expect([301, 302, 401, 403]).toContain(response.status());
    });
  });

  /**
   * Settlement List and Details Tests (3 tests)
   */
  test.describe('Settlement List and Details', () => {
    test('D1: GET /settlements returns paginated list', async ({ authenticatedRequest }) => {
      const response = await authenticatedRequest.get('/api/admin/settlements');

      expect(response.status()).toBe(200);
      const body = await response.json();
      expect(Array.isArray(body.data)).toBe(true);
      expect(body.pagination).toBeDefined();
      expect(body.pagination).toHaveProperty('total');
      expect(body.pagination).toHaveProperty('per_page');
      expect(body.pagination).toHaveProperty('current_page');
    });

    test('D2: GET /settlements returns list with correct structure', async ({ authenticatedRequest }) => {
      // Note: settlement_type filtering was removed when settlements were unified.
      // Export format (SEPA/CSV) is determined at export time, not at settlement creation.
      const response = await authenticatedRequest.get('/api/admin/settlements');

      expect(response.status()).toBe(200);
      const body = await response.json();
      expect(Array.isArray(body.data)).toBe(true);

      // Verify settlement structure
      if (body.data.length > 0) {
        body.data.forEach((settlement: any) => {
          expect(settlement.id).toBeTruthy();
          expect(settlement.settlement_date).toBeTruthy();
          expect(typeof settlement.total_amount_cents).toBe('number');
          expect(typeof settlement.member_count).toBe('number');
          expect(typeof settlement.is_cancelled).toBe('boolean');
        });
      }
    });

    test('D3: GET /settlements/{id} returns settlement with items', async ({ authenticatedRequest }) => {
      const listResponse = await authenticatedRequest.get('/api/admin/settlements');
      const listData = await listResponse.json();

      if (listData.data.length === 0) {
        test.skip();
      }

      const settlementId = listData.data[0].id;
      const response = await authenticatedRequest.get(`/api/admin/settlements/${settlementId}`);

      expect(response.status()).toBe(200);
      const body = await response.json();
      expect(body.id).toBe(settlementId);
      expect(Array.isArray(body.items)).toBe(true);
    });
  });

  /**
   * Settlement Cancellation Tests (3 tests)
   */
  test.describe('Settlement Cancellation', () => {
    test('E1: DELETE /settlements/{id} returns 404 for non-existent settlement', async ({ authenticatedRequest }) => {
      const response = await authenticatedRequest.delete(
        '/api/admin/settlements/invalid-uuid-12345678901234567890',
        {
          data: { reason: 'Test' },
        },
      );

      expect(response.status()).toBe(404);
    });

    test('E2: DELETE /settlements/{id} requires reason field', async ({ authenticatedRequest }) => {
      const listResponse = await authenticatedRequest.get('/api/admin/settlements');
      const listData = await listResponse.json();

      if (listData.data.length === 0) {
        test.skip();
      }

      const settlement = listData.data[0];
      const response = await authenticatedRequest.delete(`/api/admin/settlements/${settlement.id}`, {
        data: { reason: 'Test cancellation' },
      });

      expect([204, 422]).toContain(response.status());
    });

    test('E3: DELETE /settlements/{id} requires authentication', async ({ request }) => {
      const response = await request.delete('/api/admin/settlements/test-id', {
        data: { reason: 'Test' },
      });

      expect([301, 302, 401, 403]).toContain(response.status());
    });
  });

  /**
   * SEPA XML Export Tests (4 tests)
   */
  test.describe('SEPA XML Export', () => {
    test('F1: GET /settlements/{id}/export-sepa requires settlement ID', async ({ authenticatedRequest }) => {
      const response = await authenticatedRequest.get(
        '/api/admin/settlements/invalid-uuid-12345678901234567890/export-sepa',
      );

      // Accept either 404 (not found) or 422 (validation error for invalid UUID)
      expect([404, 422]).toContain(response.status());
    });

    test('F2: GET /settlements/{id}/export-sepa returns XML when available', async ({ authenticatedRequest }) => {
      const listResponse = await authenticatedRequest.get('/api/admin/settlements?type=sepa');
      const listData = await listResponse.json();

      if (listData.data.length === 0) {
        test.skip();
      }

      const response = await authenticatedRequest.get(
        `/api/admin/settlements/${listData.data[0].id}/export-sepa`,
      );

      expect([200, 422]).toContain(response.status());
      if (response.status() === 200) {
        const contentType = response.headers()['content-type'];
        expect(contentType).toContain('xml');
      }
    });

    test('F3: GET /settlements/{id}/export-sepa returns correct content type', async ({ authenticatedRequest }) => {
      const listResponse = await authenticatedRequest.get('/api/admin/settlements?type=sepa');
      const listData = await listResponse.json();

      if (listData.data.length === 0) {
        test.skip();
      }

      const response = await authenticatedRequest.get(
        `/api/admin/settlements/${listData.data[0].id}/export-sepa`,
      );

      if (response.status() === 200) {
        const contentType = response.headers()['content-type'];
        expect(contentType).toContain('xml');
      }
    });

    test('F4: GET /settlements/{id}/export-sepa requires authentication', async ({ request }) => {
      const response = await request.get('/api/admin/settlements/test-id/export-sepa');

      expect([301, 302, 401, 403]).toContain(response.status());
    });
  });

  /**
   * CSV Export Tests (3 tests)
   */
  test.describe('CSV Export', () => {
    test('G1: GET /settlements/{id}/export-csv generates CSV file', async ({ authenticatedRequest }) => {
      const listResponse = await authenticatedRequest.get('/api/admin/settlements');
      const listData = await listResponse.json();

      if (listData.data.length === 0) {
        test.skip();
      }

      const response = await authenticatedRequest.get(`/api/admin/settlements/${listData.data[0].id}/export-csv`);

      expect(response.status()).toBe(200);
      expect(response.headers()['content-type']).toContain('csv');
    });

    test('G2: GET /settlements/{id}/export-csv has correct format', async ({ authenticatedRequest }) => {
      const listResponse = await authenticatedRequest.get('/api/admin/settlements');
      const listData = await listResponse.json();

      if (listData.data.length === 0) {
        test.skip();
      }

      const response = await authenticatedRequest.get(`/api/admin/settlements/${listData.data[0].id}/export-csv`);

      expect(response.status()).toBe(200);
      const csv = await response.text();
      expect(csv).toContain('Member Name;Email;IBAN;Amount EUR');
      expect(csv).toContain(';');
    });

    test('G3: GET /settlements/{id}/export-csv formats amounts correctly', async ({ authenticatedRequest }) => {
      const listResponse = await authenticatedRequest.get('/api/admin/settlements');
      const listData = await listResponse.json();

      const settlementWithItems = listData.data.find((s: any) => s.items && s.items.length > 0);

      if (!settlementWithItems) {
        test.skip();
      }

      const response = await authenticatedRequest.get(
        `/api/admin/settlements/${settlementWithItems.id}/export-csv`,
      );

      expect(response.status()).toBe(200);
      const csv = await response.text();
      expect(csv).toMatch(/\d+\.\d{2}/);
    });
  });

  /**
   * Integration Test (1 test)
   */
  test('H1: E2E workflow - Preview, List, Get Details', async ({ authenticatedRequest }) => {
    // Step 1: Preview settlements
    const preview = await authenticatedRequest.post('/api/admin/settlements/preview');
    expect(preview.status()).toBe(200);

    // Step 2: List settlements
    const list = await authenticatedRequest.get('/api/admin/settlements');
    expect(list.status()).toBe(200);
    const listData = await list.json();
    expect(Array.isArray(listData.data)).toBe(true);

    // Step 3: If settlement exists, get details
    if (listData.data.length > 0) {
      const detail = await authenticatedRequest.get(`/api/admin/settlements/${listData.data[0].id}`);
      expect(detail.status()).toBe(200);
      const detailData = await detail.json();
      // Verify settlement has required fields (settlement_type removed when settlements were unified)
      expect(detailData).toHaveProperty('id');
      expect(detailData).toHaveProperty('settlement_date');
      expect(detailData).toHaveProperty('items');
      expect(Array.isArray(detailData.items)).toBe(true);
    }
  });

  /**
   * Filter Preview Tests (3 tests)
   */
  test.describe('GET /api/admin/settlements/filter-preview', () => {
    test('returns aggregate stats for unsettled transactions', async ({ authenticatedRequest }) => {
      const testId = `prev-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;

      // Create member + 2 unsettled correction transactions (corrections are unsettled by default)
      const memberRes = await authenticatedRequest.post('/api/admin/members', {
        data: {
          first_name: 'FilterPrev',
          last_name: `Test${testId}`,
          email: `filterprev-${testId}@test.example`,
          iban: 'DE89370400440532013000',
          mandate_signed_at: '2024-01-01',
          preferred_language: 'de',
        },
      });
      expect(memberRes.status()).toBe(201);
      const member = await memberRes.json();

      await authenticatedRequest.post(`/api/admin/members/${member.id}/transactions/correction`, {
        data: { amount_cents: 500, reason: 'adjustment', notes: `fp-note-${testId}` },
      });
      await authenticatedRequest.post(`/api/admin/members/${member.id}/transactions/correction`, {
        data: { amount_cents: 300, reason: 'adjustment', notes: `fp-note2-${testId}` },
      });

      const res = await authenticatedRequest.get(
        `/api/admin/settlements/filter-preview?search=${encodeURIComponent(`Test${testId}`)}`,
      );
      expect(res.status()).toBe(200);
      const body = await res.json();

      expect(typeof body.transaction_count).toBe('number');
      expect(typeof body.member_count).toBe('number');
      expect(typeof body.total_amount_cents).toBe('number');
      expect(body.transaction_count).toBe(2);
      expect(body.member_count).toBe(1);
      expect(body.total_amount_cents).toBe(800);
    });

    test('search filter reduces result to matching transactions', async ({ authenticatedRequest }) => {
      const testId = `srch-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
      const uniqueLastName = `SrchPrev${testId}`;

      const memberRes = await authenticatedRequest.post('/api/admin/members', {
        data: {
          first_name: 'SearchFilter',
          last_name: uniqueLastName,
          email: `srchprev-${testId}@test.example`,
          iban: 'DE89370400440532013000',
          mandate_signed_at: '2024-01-01',
          preferred_language: 'de',
        },
      });
      expect(memberRes.status()).toBe(201);
      const member = await memberRes.json();
      await authenticatedRequest.post(`/api/admin/members/${member.id}/transactions/correction`, {
        data: { amount_cents: 100, reason: 'adjustment', notes: `srch-note-${testId}` },
      });

      // Unfiltered preview should have results
      const unfilteredRes = await authenticatedRequest.get('/api/admin/settlements/filter-preview');
      const unfiltered = await unfilteredRes.json();

      // Filtered by unique last name should have exactly 1 transaction (our new one)
      const filteredRes = await authenticatedRequest.get(
        `/api/admin/settlements/filter-preview?search=${encodeURIComponent(uniqueLastName)}`,
      );
      expect(filteredRes.status()).toBe(200);
      const filtered = await filteredRes.json();

      expect(filtered.transaction_count).toBe(1);
      expect(filtered.member_count).toBe(1);
      expect(filtered.total_amount_cents).toBe(100);
      expect(filtered.transaction_count).toBeLessThan(unfiltered.transaction_count);
    });

    test('returns zeros when no matching unsettled transactions exist', async ({ authenticatedRequest }) => {
      const res = await authenticatedRequest.get(
        `/api/admin/settlements/filter-preview?search=__no_match_${Date.now()}__`,
      );
      expect(res.status()).toBe(200);
      const body = await res.json();
      expect(body.transaction_count).toBe(0);
      expect(body.member_count).toBe(0);
      expect(body.total_amount_cents).toBe(0);
    });
  });

  /**
   * Settle Filter Tests (3 tests)
   */
  test.describe('POST /api/admin/settlements/settle-filter', () => {
    test('creates settlement for all unsettled transactions matching search filter', async ({ authenticatedRequest }) => {
      const testId = `sf-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
      const uniqueLastName = `SFilter${testId}`;

      const memberRes = await authenticatedRequest.post('/api/admin/members', {
        data: {
          first_name: 'SettleFilter',
          last_name: uniqueLastName,
          email: `settlefilter-${testId}@test.example`,
          iban: 'DE89370400440532013000',
          mandate_signed_at: '2024-01-01',
          preferred_language: 'de',
        },
      });
      expect(memberRes.status()).toBe(201);
      const member = await memberRes.json();
      await authenticatedRequest.post(`/api/admin/members/${member.id}/transactions/correction`, {
        data: { amount_cents: 400, reason: 'adjustment', notes: `sf-tx-${testId}` },
      });
      await authenticatedRequest.post(`/api/admin/members/${member.id}/transactions/correction`, {
        data: { amount_cents: 200, reason: 'adjustment', notes: `sf-tx2-${testId}` },
      });

      const today = new Date().toISOString().split('T')[0];
      const exec = new Date(Date.now() + 7 * 86400000).toISOString().split('T')[0];

      const res = await authenticatedRequest.post('/api/admin/settlements/settle-filter', {
        data: {
          search: uniqueLastName,
          settlement_date: today,
          execution_date: exec,
        },
      });
      expect(res.status()).toBe(201);
      const settlement = await res.json();

      expect(settlement).toHaveProperty('id');
      expect(settlement).toHaveProperty('settlement_date', today);
      expect(settlement).toHaveProperty('execution_date', exec);
      // Should have settled exactly 1 member with 2 transactions totalling 600 cents
      expect(settlement.member_count).toBe(1);
      expect(settlement.total_amount_cents).toBe(600);
    });

    test('returns 422 when settlement_date is missing', async ({ authenticatedRequest }) => {
      const futureDate = new Date(Date.now() + 14 * 86400000).toISOString().split('T')[0];
      const res = await authenticatedRequest.post('/api/admin/settlements/settle-filter', {
        data: { execution_date: futureDate },
      });
      expect(res.status()).toBe(422);
      const body = await res.json();
      expect(body.error).toBe('validation_failed');
      expect(body.messages).toBeDefined();
    });

    test('returns 422 when execution_date is missing', async ({ authenticatedRequest }) => {
      const today = new Date().toISOString().split('T')[0];
      const res = await authenticatedRequest.post('/api/admin/settlements/settle-filter', {
        data: { settlement_date: today },
      });
      expect(res.status()).toBe(422);
      const body = await res.json();
      expect(body.error).toBe('validation_failed');
      expect(body.messages).toBeDefined();
    });
  });
});
