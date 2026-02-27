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

  test('members sync cursor uses consistent millisecond format', async ({ authenticatedTerminalRequest }) => {
    // Sync 1: First sync with since=0
    const response1 = await authenticatedTerminalRequest.get(`${API_BASE}/sync/members?since=0`);
    expect(response1.ok()).toBeTruthy();
    const data1 = await response1.json();

    // Cursor is returned as a string; convert to number for range checks
    const cursor1 = Number(data1.cursor);

    // Verify cursor is in milliseconds (between 2023 and 2033)
    expect(cursor1).toBeGreaterThan(1700000000000); // After 2023-11-14
    expect(cursor1).toBeLessThan(2000000000000);   // Before 2033-05-18

    // Sync 2: Use cursor from Sync 1
    const response2 = await authenticatedTerminalRequest.get(`${API_BASE}/sync/members?since=${data1.cursor}`);
    expect(response2.ok()).toBeTruthy();
    const data2 = await response2.json();

    const cursor2 = Number(data2.cursor);

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

    // Cursor is returned as a string; convert to number for range checks
    const cursor = Number(data.cursor);

    // Verify cursor is in milliseconds regardless of result count (Bug #1 fix)
    expect(cursor).toBeGreaterThan(1700000000000);
    expect(cursor).toBeLessThan(2000000000000);
  });

  test('products sync does not trigger year 57123 bug', async ({ authenticatedTerminalRequest }) => {
    // Get initial cursor
    const response1 = await authenticatedTerminalRequest.get(`${API_BASE}/sync/products?since=0`);
    expect(response1.ok()).toBeTruthy();
    const data1 = await response1.json();

    const validCursor = data1.cursor;
    expect(Number(validCursor)).toBeGreaterThan(1700000000000);

    // Sync 2: Use valid cursor (milliseconds) - should work without year 57123 bug
    const response2 = await authenticatedTerminalRequest.get(`${API_BASE}/sync/products?since=${validCursor}`);
    expect(response2.ok()).toBeTruthy();
    const data2 = await response2.json();

    const cursor2 = Number(data2.cursor);

    // Verify response is valid (not 500 error from year 57123)
    expect(cursor2).toBeGreaterThan(1700000000000);
    expect(cursor2).toBeLessThan(2000000000000);

    // Verify products array exists (may be empty if no changes)
    expect(data2.products).toBeDefined();
  });
});
