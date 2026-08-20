import type { APIRequestContext } from '@playwright/test';
import { test, expect } from '../../fixtures/auth.fixture';

/**
 * E2E Test: Timestamp Protocol Bug Fixes
 *
 * Verifies that the sync protocol correctly uses Unix timestamps in milliseconds
 * throughout the entire flow (frontend → API → backend → response).
 *
 * Tests verify fixes for:
 * - Bug #1: Backend fallback cursor now uses microtime(true) * 1000 (milliseconds)
 * - Bug #2: Repository queries correctly divide milliseconds by 1000 before date()
 *
 * Related files:
 * - docs/plans/2026-02-06-timestamp-protocol-bug-analysis.md
 * - docs/plans/2026-02-06-timestamp-protocol-bug-fixes.md
 */

test.describe('Sync Timestamp Protocol', () => {
  const API_BASE = 'http://localhost:8080/api';

  /**
   * A cursor is derived from the rows a sync returns; with none, the endpoint
   * correctly echoes the cursor it was given — `since=0` comes back as 0.
   *
   * `seed.sql` creates no members and no products, so a full-sync test that
   * asserts a millisecond cursor is really asserting that some *other* spec
   * has already written a row. That held until sharding put this file first,
   * and then `since=0` answered 0. Each test now writes its own row, so the
   * full sync it performs has something to derive a cursor from.
   */
  async function createSyncableMember(request: APIRequestContext): Promise<void> {
    const token = `Sync${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;
    const response = await request.post('/api/admin/members', {
      data: {
        first_name: 'Sync',
        last_name: token,
        email: `${token}@test.com`,
        date_of_birth: '1985-06-15',
        preferred_language: 'de',
      },
    });
    expect(response.status()).toBe(201);
  }

  async function createSyncableProduct(request: APIRequestContext): Promise<void> {
    const token = `Sync${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;

    const category = await request.post('/api/admin/categories', {
      data: { names: { de: `Kategorie ${token}`, en: `Category ${token}` } },
    });
    expect(category.status()).toBe(201);

    const product = await request.post('/api/admin/products', {
      data: { names: { de: `Produkt ${token}` }, price_cents: 350, category_id: (await category.json()).id },
    });
    expect(product.status()).toBe(201);
  }

  test('members sync cursor uses consistent millisecond format', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    await createSyncableMember(authenticatedRequest);

    // Sync 1: First sync with since=0
    const response1 = await authenticatedTerminalRequest.get(`${API_BASE}/sync/members?since=0`);
    expect(response1.ok()).toBeTruthy();
    const data1 = await response1.json();

    const cursor1 = data1.cursor;

    // Verify cursor is in milliseconds (between 2023 and 2033)
    expect(cursor1).toBeGreaterThan(1700000000000); // After 2023-11-14
    expect(cursor1).toBeLessThan(2000000000000);   // Before 2033-05-18

    // Sync 2: Use cursor from Sync 1
    const response2 = await authenticatedTerminalRequest.get(`${API_BASE}/sync/members?since=${data1.cursor}`);
    expect(response2.ok()).toBeTruthy();
    const data2 = await response2.json();

    const cursor2 = data2.cursor;

    // Verify cursor still in milliseconds and monotonically increasing
    expect(cursor2).toBeGreaterThanOrEqual(cursor1);
    expect(cursor2).toBeLessThan(2000000000000);
  });

  test('categories sync returns consistent cursor format', async ({ authenticatedTerminalRequest }) => {
    // Sync with timestamp far in future (may or may not return rows, doesn't matter)
    const futureTimestamp = Date.now() + 86400000; // +1 day
    const response = await authenticatedTerminalRequest.get(`${API_BASE}/sync/categories?since=${futureTimestamp}`);
    expect(response.ok()).toBeTruthy();
    const data = await response.json();

    const cursor = data.cursor;

    // Verify cursor is in milliseconds regardless of result count (Bug #1 fix)
    expect(cursor).toBeGreaterThan(1700000000000);
    expect(cursor).toBeLessThan(2000000000000);
  });

  test('products sync does not trigger year 57123 bug', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    await createSyncableProduct(authenticatedRequest);

    // Get initial cursor
    const response1 = await authenticatedTerminalRequest.get(`${API_BASE}/sync/products?since=0`);
    expect(response1.ok()).toBeTruthy();
    const data1 = await response1.json();

    const validCursor = data1.cursor;
    expect(validCursor).toBeGreaterThan(1700000000000);

    // Sync 2: Use valid cursor (milliseconds) - should work without year 57123 bug
    const response2 = await authenticatedTerminalRequest.get(`${API_BASE}/sync/products?since=${validCursor}`);
    expect(response2.ok()).toBeTruthy();
    const data2 = await response2.json();

    const cursor2 = data2.cursor;

    // Verify response is valid (not 500 error from year 57123)
    expect(cursor2).toBeGreaterThan(1700000000000);
    expect(cursor2).toBeLessThan(2000000000000);

    // Verify products array exists (may be empty if no changes)
    expect(data2.products).toBeDefined();
  });
});
