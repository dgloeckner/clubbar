import { randomUUID } from 'crypto';
import { test, expect } from '../../fixtures/auth.fixture';
import type { APIRequestContext } from '@playwright/test';

/**
 * Admin Transactions — the journal's type filter (#110).
 *
 * The list wrote the filter under `transaction_type` while the repository read
 * `type`, so `?type=storno` returned *every* transaction with nothing to
 * distinguish it from a filter that had been applied. The export, which used
 * `type` all along, answered the same query differently.
 *
 * These tests pin the filter by what it must *exclude*, not only by what it
 * returns: a filtered list that equals the unfiltered one is the bug.
 *
 * Uses E2E Pattern 001: Test Data Isolation (own member per test)
 * Uses E2E Pattern 002: Authentication Isolation
 * Uses E2E Pattern 003: Database-Agnostic Assertions (find by ID)
 * Uses E2E Pattern 004: Parallel Execution Safety (every query scoped to the
 *   test's own member, so a concurrent worker's rows cannot change a count)
 */

async function createMember(authenticatedRequest: APIRequestContext) {
  const uniqueId = randomUUID().substring(0, 8);

  const response = await authenticatedRequest.post('/api/admin/members', {
    data: {
      first_name: `TypeFilter${uniqueId}`,
      last_name: `Member${uniqueId}`,
      email: `typefilter${uniqueId}@example.com`,
      iban: 'DE89370400440532013000',
      mandate_signed_at: new Date().toISOString().slice(0, 10),
      preferred_language: 'de',
    },
  });

  if (!response.ok()) {
    throw new Error(`Failed to create member: ${response.status()} - ${await response.text()}`);
  }

  return await response.json();
}

async function createProduct(authenticatedRequest: APIRequestContext) {
  const uniqueId = randomUUID().substring(0, 8);

  const category = await authenticatedRequest.post('/api/admin/categories', {
    data: { names: { de: `Kategorie ${uniqueId}`, en: `Category ${uniqueId}` } },
  });

  if (!category.ok()) {
    throw new Error(`Failed to create category: ${category.status()} - ${await category.text()}`);
  }

  const response = await authenticatedRequest.post('/api/admin/products', {
    data: {
      names: { de: `Produkt ${uniqueId}`, en: `Product ${uniqueId}` },
      price_cents: 350,
      category_id: (await category.json()).id,
    },
  });

  if (!response.ok()) {
    throw new Error(`Failed to create product: ${response.status()} - ${await response.text()}`);
  }

  return await response.json();
}

/**
 * A member with exactly two transactions: one purchase and the storno that
 * reverses it — one row of each type, so a filter that does nothing is
 * immediately visible as a count of two.
 */
async function createPurchaseAndStorno(
  authenticatedRequest: APIRequestContext,
  authenticatedTerminalRequest: APIRequestContext,
) {
  const member = await createMember(authenticatedRequest);
  const product = await createProduct(authenticatedRequest);

  const purchaseId = randomUUID();
  const upload = await authenticatedTerminalRequest.post('/api/sync/transactions', {
    data: {
      transactions: [
        {
          id: purchaseId,
          member_id: member.id,
          product_id: product.id,
          amount_cents: 350,
          created_at: new Date().toISOString(),
        },
      ],
    },
  });

  if (!upload.ok()) {
    throw new Error(`Failed to upload purchase: ${upload.status()} - ${await upload.text()}`);
  }

  const storno = await authenticatedRequest.post(`/api/admin/transactions/${purchaseId}/storno`, {
    data: { reason: 'Type filter test' },
  });

  if (!storno.ok()) {
    throw new Error(`Failed to storno purchase: ${storno.status()} - ${await storno.text()}`);
  }

  return { member, purchaseId, stornoId: (await storno.json()).id };
}

async function listForMember(
  authenticatedRequest: APIRequestContext,
  memberId: string,
  params: Record<string, string> = {},
) {
  const response = await authenticatedRequest.get('/api/admin/transactions', {
    params: { member_id: memberId, ...params },
  });

  expect(response.ok()).toBeTruthy();

  return await response.json();
}

test.describe('Admin Transactions — type filter', () => {
  test('GET /api/admin/transactions?type= actually narrows the list', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const { member, purchaseId, stornoId } = await createPurchaseAndStorno(
      authenticatedRequest,
      authenticatedTerminalRequest,
    );

    const unfiltered = await listForMember(authenticatedRequest, member.id);
    expect(unfiltered.pagination.total).toBe(2);

    const stornosOnly = await listForMember(authenticatedRequest, member.id, { type: 'storno' });

    // The regression this test exists for: the filtered list came back equal to
    // the unfiltered one.
    expect(stornosOnly.pagination.total).toBeLessThan(unfiltered.pagination.total);
    expect(stornosOnly.pagination.total).toBe(1);

    const ids = stornosOnly.data.map((tx: any) => tx.id);
    expect(ids).toContain(stornoId);
    expect(ids).not.toContain(purchaseId);
    expect(stornosOnly.data.every((tx: any) => tx.type === 'storno')).toBeTruthy();
  });

  test('GET /api/admin/transactions?type=purchase excludes the storno', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const { member, purchaseId, stornoId } = await createPurchaseAndStorno(
      authenticatedRequest,
      authenticatedTerminalRequest,
    );

    const purchasesOnly = await listForMember(authenticatedRequest, member.id, { type: 'purchase' });

    expect(purchasesOnly.pagination.total).toBe(1);

    const ids = purchasesOnly.data.map((tx: any) => tx.id);
    expect(ids).toContain(purchaseId);
    expect(ids).not.toContain(stornoId);
  });

  test('GET /api/admin/transactions?type=all returns every type', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const { member, purchaseId, stornoId } = await createPurchaseAndStorno(
      authenticatedRequest,
      authenticatedTerminalRequest,
    );

    const all = await listForMember(authenticatedRequest, member.id, { type: 'all' });

    expect(all.pagination.total).toBe(2);

    const ids = all.data.map((tx: any) => tx.id);
    expect(ids).toContain(purchaseId);
    expect(ids).toContain(stornoId);
  });

  test('the list and the CSV export agree on the same type filter', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const { member, purchaseId } = await createPurchaseAndStorno(
      authenticatedRequest,
      authenticatedTerminalRequest,
    );

    const list = await listForMember(authenticatedRequest, member.id, { type: 'purchase' });

    const exportResponse = await authenticatedRequest.get('/api/admin/transactions/export', {
      params: { member_id: member.id, type: 'purchase' },
    });

    expect(exportResponse.ok()).toBeTruthy();

    // Header plus one row per exported transaction; the export must hold as
    // many rows as the list claims, and only purchases.
    const rows = (await exportResponse.text()).trim().split('\n').slice(1);

    expect(rows).toHaveLength(list.pagination.total);
    expect(rows).toHaveLength(1);
    expect(rows[0]).toContain('purchase');
    expect(rows[0]).not.toContain('storno');
    expect(list.data.map((tx: any) => tx.id)).toContain(purchaseId);
  });
});
