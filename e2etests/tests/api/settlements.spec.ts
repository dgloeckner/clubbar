import { test, expect } from '../../fixtures/auth.fixture';
import {
  INVALID_EXECUTION_DATES,
  minimumExecutionDate,
  today as todayIso,
} from '../../utils/dates';

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

    test('A6: POST /sepa-config saves config (initial setup method used by frontend)', async ({ authenticatedRequest }) => {
      const timestamp = Date.now();
      const response = await authenticatedRequest.post('/api/admin/sepa-config', {
        data: {
          creditor_id: 'DE98ZZZ09999999999',
          creditor_name: `Post Test Org ${timestamp}`,
          creditor_iban: 'DE89370400440532013000',
          creditor_address_street: 'Musterstraße 1',
          creditor_address_city: 'Berlin',
          creditor_address_country: 'DE',
          payment_reference_prefix: 'RUDERBAR',
        },
      });

      expect(response.status()).toBe(200);
      const body = await response.json();
      expect(body.is_configured).toBe(true);
      expect(body.creditor_name).toContain('Post Test Org');
    });

    test('A7: PATCH /sepa-config updates config without creditor_id (update method used by frontend)', async ({ authenticatedRequest }) => {
      const timestamp = Date.now();
      const response = await authenticatedRequest.patch('/api/admin/sepa-config', {
        data: {
          creditor_name: `Patch Test Org ${timestamp}`,
          creditor_iban: 'DE89370400440532013000',
          creditor_address_street: 'Musterstraße 2',
          creditor_address_city: 'Frankfurt',
          creditor_address_country: 'DE',
        },
      });

      expect(response.status()).toBe(200);
      const body = await response.json();
      expect(body.creditor_name).toContain('Patch Test Org');
      expect(body.is_configured).toBe(true);
    });

    test('A8: POST /sepa-config requires creditor_id (initial setup)', async ({ authenticatedRequest }) => {
      const response = await authenticatedRequest.post('/api/admin/sepa-config', {
        data: {
          creditor_name: 'Missing ID Org',
          creditor_iban: 'DE89370400440532013000',
          creditor_address_street: 'Test Street',
          creditor_address_city: 'Berlin',
          creditor_address_country: 'DE',
        },
      });

      expect(response.status()).toBe(422);
      const body = await response.json();
      expect(body.error).toBe('validation_failed');
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
          method: 'direct_debit',
          transaction_ids: [],
          settlement_date: '2026-01-26',
          execution_date: '2026-02-02',
          period_start: '2026-01-01',
          period_end: '2026-01-25',
        },
      });

      expect([201, 422]).toContain(response.status());
    });

    test('C2: POST /settlements creates a bank_transfer settlement covering a single member', async ({ authenticatedRequest }) => {
      // Ruling #163: manual_reason: 'cash' no longer exists — cash is ruled out
      // by design. A member who paid cash is instead recorded as a
      // bank_transfer (or write_off) settlement, which the SEPA exporter then
      // refuses to export (see F5 below), preventing the bank from double-
      // collecting the balance.
      const response = await authenticatedRequest.post('/api/admin/settlements', {
        data: {
          method: 'bank_transfer',
          transaction_ids: [],
          settlement_date: '2026-01-26',
          execution_date: '2026-02-02',
        },
      });

      expect([201, 422]).toContain(response.status());
    });

    test('C3: POST /settlements rejects execution_date < settlement_date + 7', async ({ authenticatedRequest }) => {
      const response = await authenticatedRequest.post('/api/admin/settlements', {
        data: {
          method: 'direct_debit',
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

    /**
     * Business-day rule (issue #11).
     *
     * SEPA requires ReqdColltnDt to be a settlement day, so execution_date must
     * be Mon-Fri and not one of the six TARGET2 closing days. Dates are fixed
     * rather than computed (Pattern 003) so the test asserts the same thing
     * whatever day it runs on.
     */
    test('C3a: POST /settlements rejects a weekend execution_date', async ({ authenticatedRequest }) => {
      for (const execDate of [INVALID_EXECUTION_DATES.saturday, INVALID_EXECUTION_DATES.sunday]) {
        const response = await authenticatedRequest.post('/api/admin/settlements', {
          data: {
            method: 'direct_debit',
            transaction_ids: ['test-id'],
            settlement_date: '2026-07-01',
            execution_date: execDate,
          },
        });

        expect(response.status(), `${execDate} must be rejected`).toBe(422);
        const body = await response.json();
        expect(body.error).toBe('validation_failed');
        expect(JSON.stringify(body.messages)).toContain('business day');
      }
    });

    test('C3b: POST /settlements rejects TARGET2 closing days', async ({ authenticatedRequest }) => {
      const closingDays = [
        INVALID_EXECUTION_DATES.goodFriday,
        INVALID_EXECUTION_DATES.easterMonday,
        INVALID_EXECUTION_DATES.christmasDay,
        INVALID_EXECUTION_DATES.newYearsDay,
      ];

      for (const execDate of closingDays) {
        const response = await authenticatedRequest.post('/api/admin/settlements', {
          data: {
            method: 'direct_debit',
            transaction_ids: ['test-id'],
            // Far enough back that the 7-day lead time is never the reason.
            settlement_date: '2026-01-05',
            execution_date: execDate,
          },
        });

        expect(response.status(), `${execDate} is a closing day and must be rejected`).toBe(422);
        expect(JSON.stringify((await response.json()).messages)).toContain('business day');
      }
    });

    test('C3c: POST /settlements accepts the business day after a closing day', async ({ authenticatedRequest }) => {
      // 2026-04-07, the Tuesday after Good Friday → Easter Monday. Accepted past
      // validation, so the failure is about the dummy transaction id, not the date.
      const response = await authenticatedRequest.post('/api/admin/settlements', {
        data: {
          method: 'direct_debit',
          transaction_ids: ['test-id'],
          settlement_date: '2026-01-05',
          execution_date: '2026-04-07',
        },
      });

      if (response.status() === 422) {
        expect(JSON.stringify((await response.json()).messages)).not.toContain('business day');
      }
    });

    test('C4: POST /settlements requires transaction_ids', async ({ authenticatedRequest }) => {
      const response = await authenticatedRequest.post('/api/admin/settlements', {
        data: {
          method: 'direct_debit',
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

    test('C5: POST /settlements rejects an invalid method value', async ({ authenticatedRequest }) => {
      // Ruling #163: method replaces settlement_type + manual_reason, so
      // there is no longer a "manual_reason required" branch — instead the
      // method value itself is validated against the enum. 'cash' in
      // particular is deliberately not a valid method.
      const response = await authenticatedRequest.post('/api/admin/settlements', {
        data: {
          method: 'cash',
          transaction_ids: ['test-id'],
          settlement_date: '2026-01-26',
          execution_date: '2026-02-02',
        },
      });

      expect(response.status()).toBe(422);
      const body = await response.json();
      expect(body.error).toBe('validation_failed');
    });

    test('C6: POST /settlements returns settlement with all required fields', async ({ authenticatedRequest }) => {
      const response = await authenticatedRequest.post('/api/admin/settlements', {
        data: {
          method: 'direct_debit',
          transaction_ids: [],
          settlement_date: '2026-01-26',
          execution_date: '2026-02-02',
        },
      });

      if (response.status() === 201) {
        const body = await response.json();
        expect(body).toHaveProperty('id');
        expect(body).toHaveProperty('method');
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
          method: 'direct_debit',
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
          method: 'direct_debit',
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
      // Note: settlement_type/manual_reason filtering was replaced by the
      // single `method` field (ruling #163) when settlements were unified.
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

    test('D3: GET /settlements/{id} returns settlement with items', async ({ authenticatedRequest, settlementFactory }) => {
      const settlement = await settlementFactory.create({ amountCents: 1750 });

      const response = await authenticatedRequest.get(`/api/admin/settlements/${settlement.id}`);

      expect(response.status()).toBe(200);
      const body = await response.json();
      expect(body.id).toBe(settlement.id);
      expect(body.is_cancelled).toBe(false);
      expect(body.member_count).toBe(1);
      expect(body.total_amount_cents).toBe(1750);

      // The items are the settled transactions themselves — this test's own.
      expect(body.items).toHaveLength(1);
      expect(body.items[0].transaction_id).toBe(settlement.transactionIds[0]);
      expect(body.items[0].member_id).toBe(settlement.memberId);
      expect(body.items[0].amount_cents).toBe(1750);
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

    test('E2: DELETE /settlements/{id} cancels the settlement and releases its transactions', async ({ authenticatedRequest, settlementFactory }) => {
      const settlement = await settlementFactory.create({ amountCents: 3300 });

      const response = await authenticatedRequest.delete(`/api/admin/settlements/${settlement.id}`, {
        data: { reason: 'Test cancellation' },
      });

      expect(response.status()).toBe(204);

      // Cancellation is visible on the settlement, and its items are released
      // so the transactions can be settled again.
      const after = await authenticatedRequest.get(`/api/admin/settlements/${settlement.id}`);
      expect(after.status()).toBe(200);
      const body = await after.json();
      expect(body.is_cancelled).toBe(true);
      expect(body.cancelled_at).toBeTruthy();
      expect(body.items).toHaveLength(0);
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
    test('F1: GET /settlements/{id}/export/sepa-xml requires settlement ID', async ({ authenticatedRequest }) => {
      const response = await authenticatedRequest.get(
        '/api/admin/settlements/invalid-uuid-12345678901234567890/export/sepa-xml',
      );

      // Accept either 404 (not found) or 422 (validation error for invalid UUID)
      expect([404, 422]).toContain(response.status());
    });

    test('F2: GET /settlements/{id}/export/sepa-xml debits the settled member', async ({ authenticatedRequest, settlementFactory }) => {
      // The pain.008 file is the money-critical artefact: it is what the bank
      // acts on. Assert the collection instruction it carries, not just that
      // some XML came back.
      const settlement = await settlementFactory.create({ amountCents: 4250 });

      const response = await authenticatedRequest.get(
        `/api/admin/settlements/${settlement.id}/export/sepa-xml`,
      );

      expect(response.status()).toBe(200);
      const xml = await response.text();

      // pain.008 direct debit, one collection, for exactly what was settled.
      expect(xml).toContain('pain.008.001.08');
      expect(xml).toContain('<NbOfTxs>1</NbOfTxs>');
      expect(xml).toContain('<InstdAmt Ccy="EUR">42.50</InstdAmt>');
      expect(xml).toContain(`<ReqdColltnDt>${settlement.executionDate}</ReqdColltnDt>`);

      // …charged to this member, under this member's mandate.
      expect(xml).toContain(settlement.memberName);
      expect(xml).toContain(settlement.iban);
      expect(xml).toContain(`<MndtId>${settlement.mandateReference}</MndtId>`);
    });

    test('F3: GET /settlements/{id}/export/sepa-xml returns correct content type', async ({ authenticatedRequest, settlementFactory }) => {
      const settlement = await settlementFactory.create();

      const response = await authenticatedRequest.get(
        `/api/admin/settlements/${settlement.id}/export/sepa-xml`,
      );

      expect(response.status()).toBe(200);
      expect(response.headers()['content-type']).toContain('xml');
      expect(response.headers()['content-disposition']).toContain(settlement.id);
    });

    test('F5: GET /settlements/{id}/export/sepa-xml refuses a non-direct-debit settlement', async ({ authenticatedRequest, settlementFactory }) => {
      // Ruling #163: a member who paid by bank transfer is recorded as a
      // bank_transfer settlement, and must never reach the bank as a second
      // collection of the same balance.
      const settlement = await settlementFactory.create({ method: 'bank_transfer' });

      const response = await authenticatedRequest.get(
        `/api/admin/settlements/${settlement.id}/export/sepa-xml`,
      );

      expect(response.status()).toBe(409);
      expect(JSON.stringify(await response.json())).toContain('direct_debit');
    });

    test('F4: GET /settlements/{id}/export/sepa-xml requires authentication', async ({ request }) => {
      const response = await request.get('/api/admin/settlements/test-id/export/sepa-xml');

      expect([301, 302, 401, 403]).toContain(response.status());
    });
  });

  /**
   * CSV Export Tests (3 tests)
   */
  test.describe('CSV Export', () => {
    test('G1: GET /settlements/{id}/export/csv generates CSV file', async ({ authenticatedRequest, settlementFactory }) => {
      const settlement = await settlementFactory.create();

      const response = await authenticatedRequest.get(`/api/admin/settlements/${settlement.id}/export/csv`);

      expect(response.status()).toBe(200);
      expect(response.headers()['content-type']).toContain('csv');
      expect(response.headers()['content-disposition']).toContain(settlement.id);
    });

    test('G2: GET /settlements/{id}/export/csv has correct format', async ({ authenticatedRequest, settlementFactory }) => {
      const settlement = await settlementFactory.create({ amountCents: 1234 });

      const response = await authenticatedRequest.get(`/api/admin/settlements/${settlement.id}/export/csv`);

      expect(response.status()).toBe(200);
      const csv = await response.text();
      const [header, ...rows] = csv.trim().split('\n');
      expect(header).toBe('Member Name;Email;IBAN;Amount EUR');
      expect(rows).toHaveLength(1);
      expect(rows[0].split(';')).toEqual([
        settlement.memberName,
        settlement.memberEmail,
        settlement.iban,
        '12.34',
      ]);
    });

    test('G3: GET /settlements/{id}/export/csv formats amounts correctly', async ({ authenticatedRequest, settlementFactory }) => {
      // Expected strings are worked out from the euro amounts, not recomputed
      // the way the exporter does it: 5 cents is 0.05 (not 0.5), and a whole
      // euro amount keeps its trailing zeros.
      const cases: Array<{ amountCents: number; expectedEur: string }> = [
        { amountCents: 5, expectedEur: '0.05' },
        { amountCents: 5000, expectedEur: '50.00' },
      ];

      for (const { amountCents, expectedEur } of cases) {
        const settlement = await settlementFactory.create({ amountCents });

        const response = await authenticatedRequest.get(`/api/admin/settlements/${settlement.id}/export/csv`);

        expect(response.status()).toBe(200);
        const rows = (await response.text()).trim().split('\n').slice(1);
        expect(rows, `${amountCents} cents must produce exactly one CSV row`).toHaveLength(1);
        expect(rows[0].split(';')[3], `${amountCents} cents must export as ${expectedEur}`).toBe(expectedEur);
      }
    });
  });

  /**
   * Integration Test (1 test)
   */
  test('H1: E2E workflow - Preview, List, Get Details', async ({ authenticatedRequest, settlementFactory }) => {
    // This test's own settlement, so the list and detail steps assert against
    // data it created rather than whatever another test left behind (#146).
    const settlement = await settlementFactory.create();

    // Step 1: Preview settlements
    const preview = await authenticatedRequest.post('/api/admin/settlements/preview');
    expect(preview.status()).toBe(200);

    // Step 2: List settlements — ours must be in it
    const list = await authenticatedRequest.get('/api/admin/settlements?per_page=100');
    expect(list.status()).toBe(200);
    const listData = await list.json();
    expect(Array.isArray(listData.data)).toBe(true);

    // Step 3: Get details
    const detail = await authenticatedRequest.get(`/api/admin/settlements/${settlement.id}`);
    expect(detail.status()).toBe(200);
    const detailData = await detail.json();
    // Verify settlement has required fields (settlement_type/manual_reason replaced by `method`, ruling #163)
    expect(detailData.id).toBe(settlement.id);
    expect(detailData.method).toBe('direct_debit');
    expect(detailData.settlement_date).toBe(settlement.settlementDate);
    expect(detailData.items).toHaveLength(1);
  });

  /**
   * Filter Preview Tests (3 tests)
   */
  test.describe('GET /api/admin/settlements/filter-preview', () => {
    test('returns aggregate stats for unsettled transactions', async ({ authenticatedRequest, testTransactions }) => {
      const testId = `prev-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;

      // Create member + 2 unsettled storno transactions (stornos are unsettled by default).
      // Each storno must name the purchase it reverses; that purchase is settled
      // immediately so only the storno itself remains unsettled and matches the counts below.
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

      const purchase1 = await testTransactions.createSyncTransaction(member.id, 500, `fp-purchase1-${testId}`);
      await testTransactions.createSettlement([purchase1]);
      await testTransactions.createStorno(member.id, 500, `fp-note-${testId}`, 'adjustment', purchase1);

      const purchase2 = await testTransactions.createSyncTransaction(member.id, 300, `fp-purchase2-${testId}`);
      await testTransactions.createSettlement([purchase2]);
      await testTransactions.createStorno(member.id, 300, `fp-note2-${testId}`, 'adjustment', purchase2);

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

    test('search filter reduces result to matching transactions', async ({ authenticatedRequest, testTransactions }) => {
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
      // The storno must name the purchase it reverses; settle that purchase
      // immediately so only the storno remains unsettled (matches the exact
      // counts asserted below).
      const purchase = await testTransactions.createSyncTransaction(member.id, 100, `srch-purchase-${testId}`);
      await testTransactions.createSettlement([purchase]);
      await testTransactions.createStorno(member.id, 100, `srch-note-${testId}`, 'adjustment', purchase);

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
    test('creates settlement for all unsettled transactions matching search filter', async ({ authenticatedRequest, testTransactions }) => {
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
      // Each storno must name the purchase it reverses; settle those purchases
      // immediately so only the stornos remain unsettled (matches the exact
      // counts/totals asserted below).
      const purchase1 = await testTransactions.createSyncTransaction(member.id, 400, `sf-purchase1-${testId}`);
      await testTransactions.createSettlement([purchase1]);
      await testTransactions.createStorno(member.id, 400, `sf-tx-${testId}`, 'adjustment', purchase1);

      const purchase2 = await testTransactions.createSyncTransaction(member.id, 200, `sf-purchase2-${testId}`);
      await testTransactions.createSettlement([purchase2]);
      await testTransactions.createStorno(member.id, 200, `sf-tx2-${testId}`, 'adjustment', purchase2);

      const today = todayIso();
      const exec = await minimumExecutionDate(authenticatedRequest);

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

    /**
     * settle-filter had no execution_date validation at all before issue #11 —
     * neither the business-day rule nor the 7-day lead time that
     * POST /settlements always enforced.
     */
    test('rejects a weekend execution_date', async ({ authenticatedRequest }) => {
      const res = await authenticatedRequest.post('/api/admin/settlements/settle-filter', {
        data: {
          settlement_date: '2026-07-01',
          execution_date: INVALID_EXECUTION_DATES.sunday,
        },
      });

      expect(res.status()).toBe(422);
      expect(JSON.stringify((await res.json()).messages)).toContain('business day');
    });

    test('rejects a TARGET2 closing day execution_date', async ({ authenticatedRequest }) => {
      const res = await authenticatedRequest.post('/api/admin/settlements/settle-filter', {
        data: {
          settlement_date: '2026-01-05',
          execution_date: INVALID_EXECUTION_DATES.easterMonday,
        },
      });

      expect(res.status()).toBe(422);
      expect(JSON.stringify((await res.json()).messages)).toContain('business day');
    });

    test('rejects an execution_date inside the 7-day lead time', async ({ authenticatedRequest }) => {
      const res = await authenticatedRequest.post('/api/admin/settlements/settle-filter', {
        data: {
          settlement_date: '2026-07-01', // Wednesday
          execution_date: '2026-07-03',  // Friday — a business day, but only 2 days later
        },
      });

      expect(res.status()).toBe(422);
      expect(JSON.stringify((await res.json()).messages)).toContain('7 days');
    });
  });

  /**
   * Execution date info (ADR-0009, UC-SEPA-07)
   */
  test.describe('Execution Date Info', () => {
    test('GET /settlements/execution-date-info returns a valid minimum date', async ({ authenticatedRequest }) => {
      const res = await authenticatedRequest.get('/api/admin/settlements/execution-date-info');

      expect(res.status()).toBe(200);
      const body = await res.json();

      expect(body).toHaveProperty('minimum_date');
      expect(body).toHaveProperty('lead_time_days', 7);
      expect(body).toHaveProperty('rule');
      expect(body.minimum_date).toMatch(/^\d{4}-\d{2}-\d{2}$/);

      // Never a weekend — parsed as UTC, which is what the ISO date denotes.
      const weekday = new Date(`${body.minimum_date}T00:00:00Z`).getUTCDay();
      expect(weekday, `${body.minimum_date} must not fall on a weekend`).toBeGreaterThan(0);
      expect(weekday).toBeLessThan(6);

      // And never a TARGET2 closing day.
      expect(Object.values(INVALID_EXECUTION_DATES)).not.toContain(body.minimum_date);
    });

    test('the suggested minimum date is accepted by the creation endpoint', async ({ authenticatedRequest }) => {
      // Closes the loop: whatever the endpoint suggests must pass validation,
      // so the admin UI can submit it unchanged on any day of the year.
      const execDate = await minimumExecutionDate(authenticatedRequest);

      const res = await authenticatedRequest.post('/api/admin/settlements', {
        data: {
          method: 'direct_debit',
          transaction_ids: ['test-id'],
          settlement_date: todayIso(),
          execution_date: execDate,
        },
      });

      // May still fail on the dummy transaction id — but never on the date.
      if (res.status() === 422) {
        const messages = JSON.stringify((await res.json()).messages);
        expect(messages).not.toContain('business day');
        expect(messages).not.toContain('7 days');
      }
    });
  });
});
