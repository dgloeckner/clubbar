import { randomUUID } from 'crypto';
import { test, expect } from '../../fixtures/auth.fixture';

/**
 * Deletion Protocol (Tombstones) Tests
 *
 * Verifies the soft-delete (tombstone) implementation for delta sync.
 *
 * Key behaviors tested per ADR-0012:
 * 1. Deleted items appear in sync results with `deleted_at` set (tombstones)
 * 2. Tombstones allow terminals to remove deleted items from local cache
 * 3. Cursor advances correctly when tombstones are returned
 * 4. A tombstone stops being re-sent once its second is behind the cursor
 * 5. Sync returns both updated items and tombstones in same response
 *
 * Protocol:
 * - Backend: Sets deleted_at timestamp on DELETE (soft delete)
 * - Sync query: WHERE updated_at >= ? OR (deleted_at >= ? AND deleted_at IS NOT NULL)
 * - Frontend: Filters tombstones (deleted_at != null) and removes from cache
 *
 * The lower bound is inclusive because the columns have second precision: a row
 * written later in the cursor's own second would otherwise be lost for good
 * (#84). The cursor only steps past a second once that second is over, so a
 * tombstone repeats at most until then — which is why the tests below let the
 * deletion's second close before taking the cursor they re-sync from.
 */

/** Convert to Unix timestamp (milliseconds) for API calls */
const toUnixTimestamp = (date: Date): number => date.getTime();

test.describe('Deletion Protocol - Members', () => {
  test('soft-deleted member appears in sync as tombstone', async ({
    authenticatedTerminalRequest,
    authenticatedRequest,
    testTransactions,
  }) => {
    // 1. Create a member
    const member = await testTransactions.createMember('TombstoneTest', 'Member');

    // 2. Verify member exists (sync with since=0 to get all items)
    const syncBefore = await authenticatedTerminalRequest.get('/api/sync/members', {
      params: { since: 0 },
    });
    expect(syncBefore.ok()).toBeTruthy();
    const bodyBefore = await syncBefore.json();
    const foundBefore = bodyBefore.members.find((m: any) => m.id === member.id);
    expect(foundBefore).toBeDefined();
    expect(foundBefore.deleted_at).toBeNull();

    // 3. Capture cursor before deletion
    const cursorBeforeDelete = bodyBefore.cursor;

    // Delay 1+ second to ensure deletion timestamp > cursor (MySQL second precision)
    await new Promise((resolve) => setTimeout(resolve, 1100));

    // 4. Delete the member (soft delete via admin endpoint)
    const deleteResponse = await authenticatedRequest.delete(
      `http://localhost:8080/api/admin/members/${member.id}`
    );
    expect(deleteResponse.ok()).toBeTruthy();

    // 5. Sync with cursor from before deletion - should return tombstone
    const syncAfter = await authenticatedTerminalRequest.get('/api/sync/members', {
      params: { since: cursorBeforeDelete },
    });
    expect(syncAfter.ok()).toBeTruthy();
    const bodyAfter = await syncAfter.json();

    // 6. Verify tombstone appears (deleted_at is set)
    const tombstone = bodyAfter.members.find((m: any) => m.id === member.id);
    expect(tombstone).toBeDefined();
    expect(tombstone.deleted_at).not.toBeNull();
    expect(tombstone.deleted_at).toBeTruthy();

    // 7. Verify cursor advanced (tombstone was returned)
    expect(bodyAfter.cursor).toBeGreaterThan(cursorBeforeDelete);
  });

  test('deleted member does not reappear in subsequent syncs', async ({
    authenticatedTerminalRequest,
    authenticatedRequest,
    testTransactions,
  }) => {
    // Create and delete a member
    const member = await testTransactions.createMember('DeleteOnce', 'Member');
    const deleteResponse = await authenticatedRequest.delete(
      `http://localhost:8080/api/admin/members/${member.id}`
    );
    expect(deleteResponse.ok()).toBeTruthy();

    // Let the deletion's second close, so the first sync's cursor moves past it
    // instead of holding inside a second that may still take writes
    await new Promise((resolve) => setTimeout(resolve, 1100));

    // First sync (since=0 to get all) - should return tombstone
    const firstSync = await authenticatedTerminalRequest.get('/api/sync/members', {
      params: { since: 0 },
    });
    const firstBody = await firstSync.json();
    const firstTombstone = firstBody.members.find((m: any) => m.id === member.id);
    expect(firstTombstone).toBeDefined();
    expect(firstTombstone.deleted_at).not.toBeNull();

    // Second sync using cursor from first sync - should NOT return tombstone again
    const secondSync = await authenticatedTerminalRequest.get('/api/sync/members', {
      params: { since: firstBody.cursor },
    });
    const secondBody = await secondSync.json();
    const secondTombstone = secondBody.members.find((m: any) => m.id === member.id);
    expect(secondTombstone).toBeUndefined(); // Not included (already processed)
  });

  test('sync returns both updated and deleted members together', async ({
    authenticatedTerminalRequest,
    authenticatedRequest,
    testTransactions,
  }) => {
    // Create two members
    const memberToUpdate = await testTransactions.createMember('UpdateMe', 'Member');
    const memberToDelete = await testTransactions.createMember('DeleteMe', 'Member');

    // Sync to get cursor
    const syncBefore = await authenticatedTerminalRequest.get('/api/sync/members', {
      params: { since: 0 },
    });
    const beforeChanges = (await syncBefore.json()).cursor;

    // Small delay to ensure update/delete timestamps > cursor
    await new Promise((resolve) => setTimeout(resolve, 1100));

    // Update one member
    const updateResponse = await authenticatedRequest.patch(
      `http://localhost:8080/api/admin/members/${memberToUpdate.id}`,
      {
        data: { first_name: 'UpdatedName' },
      }
    );
    expect(updateResponse.ok()).toBeTruthy();

    // Delete the other member
    const deleteResponse = await authenticatedRequest.delete(
      `http://localhost:8080/api/admin/members/${memberToDelete.id}`
    );
    expect(deleteResponse.ok()).toBeTruthy();

    await new Promise((resolve) => setTimeout(resolve, 1100));

    // Sync - should return BOTH the updated member and the tombstone
    const syncResponse = await authenticatedTerminalRequest.get('/api/sync/members', {
      params: { since: beforeChanges },
    });
    expect(syncResponse.ok()).toBeTruthy();
    const body = await syncResponse.json();

    // Verify updated member appears (deleted_at is null)
    const updated = body.members.find((m: any) => m.id === memberToUpdate.id);
    expect(updated).toBeDefined();
    expect(updated.first_name).toBe('UpdatedName');
    expect(updated.deleted_at).toBeNull();

    // Verify deleted member appears as tombstone (deleted_at is set)
    const deleted = body.members.find((m: any) => m.id === memberToDelete.id);
    expect(deleted).toBeDefined();
    expect(deleted.deleted_at).not.toBeNull();
  });
});

test.describe('Deletion Protocol - Categories', () => {
  test('soft-deleted category appears in sync as tombstone', async ({
    authenticatedTerminalRequest,
    authenticatedRequest,
  }) => {
    // 1. Create a category
    const uniqueName = `TombCat_${Date.now()}`;
    const createResponse = await authenticatedRequest.post(
      'http://localhost:8080/api/admin/categories',
      {
        data: {
          names: { de: uniqueName, en: `${uniqueName}_EN` },
        },
      }
    );
    expect(createResponse.status()).toBe(201);
    const category = await createResponse.json();

    // 2. Verify category exists in sync (since=0 to get all items)
    const syncBefore = await authenticatedTerminalRequest.get('/api/sync/categories', {
      params: { since: 0 },
    });
    const bodyBefore = await syncBefore.json();
    const foundBefore = bodyBefore.categories.find((c: any) => c.id === category.id);
    expect(foundBefore).toBeDefined();
    expect(foundBefore.deleted_at).toBeNull(); // Null for active items

    // 3. Capture cursor
    const cursorBeforeDelete = bodyBefore.cursor;

    // Small delay to ensure deletion timestamp > cursor
    await new Promise((resolve) => setTimeout(resolve, 1100));

    // 4. Delete the category
    const deleteResponse = await authenticatedRequest.delete(
      `http://localhost:8080/api/admin/categories/${category.id}`
    );
    expect(deleteResponse.ok()).toBeTruthy();

    // 5. Sync with cursor from before deletion - should return tombstone
    const syncAfter = await authenticatedTerminalRequest.get('/api/sync/categories', {
      params: { since: cursorBeforeDelete },
    });
    const bodyAfter = await syncAfter.json();

    // 6. Verify tombstone appears
    const tombstone = bodyAfter.categories.find((c: any) => c.id === category.id);
    expect(tombstone).toBeDefined();
    expect(tombstone.deleted_at).toBeTruthy();

    // 7. Verify cursor advanced
    expect(bodyAfter.cursor).toBeGreaterThan(cursorBeforeDelete);
  });

  test('deleted category does not reappear in subsequent syncs', async ({
    authenticatedTerminalRequest,
    authenticatedRequest,
  }) => {
    // Create and delete a category
    const uniqueName = `DeleteOnceCat_${Date.now()}`;
    const createResponse = await authenticatedRequest.post(
      'http://localhost:8080/api/admin/categories',
      {
        data: {
          names: { de: uniqueName, en: `${uniqueName}_EN` },
        },
      }
    );
    const category = await createResponse.json();

    const deleteResponse = await authenticatedRequest.delete(
      `http://localhost:8080/api/admin/categories/${category.id}`
    );
    expect(deleteResponse.ok()).toBeTruthy();

    // Let the deletion's second close, so the first sync's cursor moves past it
    await new Promise((resolve) => setTimeout(resolve, 1100));

    // First sync (since=0 to get all) - should return tombstone
    const firstSync = await authenticatedTerminalRequest.get('/api/sync/categories', {
      params: { since: 0 },
    });
    const firstBody = await firstSync.json();
    const firstTombstone = firstBody.categories.find((c: any) => c.id === category.id);
    expect(firstTombstone).toBeDefined();
    expect(firstTombstone.deleted_at).toBeTruthy();

    // Second sync - the tombstone's second is behind the cursor now, so it is
    // not repeated
    const secondSync = await authenticatedTerminalRequest.get('/api/sync/categories', {
      params: { since: firstBody.cursor },
    });
    const secondBody = await secondSync.json();
    const secondTombstone = secondBody.categories.find((c: any) => c.id === category.id);
    expect(secondTombstone).toBeUndefined();
  });
});

test.describe('Deletion Protocol - Products', () => {
  test('soft-deleted product appears in sync as tombstone', async ({
    authenticatedTerminalRequest,
    testTransactions,
    authenticatedRequest,
  }) => {
    // 1. Create a product
    const uniqueName = `TombProd_${Date.now()}`;
    const product = await testTransactions.createProduct(uniqueName, 300);

    // 2. Verify product exists in sync (since=0 to get all items)
    const syncBefore = await authenticatedTerminalRequest.get('/api/sync/products', {
      params: { since: 0 },
    });
    const bodyBefore = await syncBefore.json();
    const foundBefore = bodyBefore.products.find((p: any) => p.id === product.id);
    expect(foundBefore).toBeDefined();
    expect(foundBefore.deleted_at).toBeNull(); // Null for active items

    // 3. Capture cursor
    const cursorBeforeDelete = bodyBefore.cursor;

    // Small delay to ensure deletion timestamp > cursor
    await new Promise((resolve) => setTimeout(resolve, 1100));

    // 4. Delete the product
    const deleteResponse = await authenticatedRequest.delete(
      `http://localhost:8080/api/admin/products/${product.id}`
    );
    expect(deleteResponse.ok()).toBeTruthy();

    // 5. Sync with cursor from before deletion - should return tombstone
    const syncAfter = await authenticatedTerminalRequest.get('/api/sync/products', {
      params: { since: cursorBeforeDelete },
    });
    const bodyAfter = await syncAfter.json();

    // 6. Verify tombstone appears
    const tombstone = bodyAfter.products.find((p: any) => p.id === product.id);
    expect(tombstone).toBeDefined();
    expect(tombstone.deleted_at).toBeTruthy();

    // 7. Verify cursor advanced
    expect(bodyAfter.cursor).toBeGreaterThan(cursorBeforeDelete);
  });

  test('deleted product does not reappear in subsequent syncs', async ({
    authenticatedTerminalRequest,
    testTransactions,
    authenticatedRequest,
  }) => {
    // Create and delete a product
    const uniqueName = `DeleteOnceProd_${Date.now()}`;
    const product = await testTransactions.createProduct(uniqueName, 400);

    const deleteResponse = await authenticatedRequest.delete(
      `http://localhost:8080/api/admin/products/${product.id}`
    );
    expect(deleteResponse.ok()).toBeTruthy();

    // Let the deletion's second close, so the first sync's cursor moves past it
    await new Promise((resolve) => setTimeout(resolve, 1100));

    // First sync (since=0 to get all) - should return tombstone
    const firstSync = await authenticatedTerminalRequest.get('/api/sync/products', {
      params: { since: 0 },
    });
    const firstBody = await firstSync.json();
    const firstTombstone = firstBody.products.find((p: any) => p.id === product.id);
    expect(firstTombstone).toBeDefined();
    expect(firstTombstone.deleted_at).toBeTruthy();

    // Second sync - the tombstone's second is behind the cursor now, so it is
    // not repeated
    const secondSync = await authenticatedTerminalRequest.get('/api/sync/products', {
      params: { since: firstBody.cursor },
    });
    const secondBody = await secondSync.json();
    const secondTombstone = secondBody.products.find((p: any) => p.id === product.id);
    expect(secondTombstone).toBeUndefined();
  });

  test('sync returns both updated and deleted products together', async ({
    authenticatedTerminalRequest,
    authenticatedRequest,
    testTransactions,
  }) => {
    // Create two products
    const productToUpdate = await testTransactions.createProduct('UpdateProd', 500);
    const productToDelete = await testTransactions.createProduct('DeleteProd', 600);

    // Sync to get cursor
    const syncBefore = await authenticatedTerminalRequest.get('/api/sync/products', {
      params: { since: 0 },
    });
    const beforeChanges = (await syncBefore.json()).cursor;

    // Small delay to ensure update/delete timestamps > cursor
    await new Promise((resolve) => setTimeout(resolve, 1100));

    // Update one product
    const updateResponse = await authenticatedRequest.patch(
      `http://localhost:8080/api/admin/products/${productToUpdate.id}`,
      {
        data: {
          names: { de: 'UpdatedProduct' },
          price_cents: 550,
        },
      }
    );
    expect(updateResponse.ok()).toBeTruthy();

    // Delete the other product
    const deleteResponse = await authenticatedRequest.delete(
      `http://localhost:8080/api/admin/products/${productToDelete.id}`
    );
    expect(deleteResponse.ok()).toBeTruthy();

    await new Promise((resolve) => setTimeout(resolve, 1100));

    // Sync - should return BOTH updated product and tombstone
    const syncResponse = await authenticatedTerminalRequest.get('/api/sync/products', {
      params: { since: beforeChanges },
    });
    const body = await syncResponse.json();

    // Verify updated product appears (no deleted_at)
    const updated = body.products.find((p: any) => p.id === productToUpdate.id);
    expect(updated).toBeDefined();
    expect(updated.names.de).toBe('UpdatedProduct');
    expect(updated.price_cents).toBe(550);
    expect(updated.deleted_at).toBeNull();

    // Verify deleted product appears as tombstone
    const deleted = body.products.find((p: any) => p.id === productToDelete.id);
    expect(deleted).toBeDefined();
    expect(deleted.deleted_at).toBeTruthy();
  });
});

/**
 * A terminal is offline for a weekend, sells a round, and an admin deletes one
 * of the products in the meantime. The queued sale still has to land.
 *
 * ADR-0033 §1 draws the line: a row is refused only when it is structurally
 * unstorable, never because a business rule changed after the drink was poured.
 * A deleted product is squarely on the "already happened" side — the member saw
 * the tile, accepted the price and drank it. Refusing the upload would not undo
 * the sale, it would only lose the record of one, and the terminal would
 * quarantine a row that can never become storable.
 *
 * This is also what lets the terminal keep a tombstoned product in its cache
 * rather than evicting it: the product_id stays meaningful on both sides.
 */
test.describe('Deletion Protocol - Sales queued against a deleted product', () => {
  test('a sale uploaded after its product was deleted is accepted', async ({
    authenticatedTerminalRequest,
    authenticatedRequest,
    testTransactions,
  }) => {
    const member = await testTransactions.createMember('PendingSale', 'Member');
    const product = await testTransactions.createProduct(
      `DeletedWhileQueued_${randomUUID().substring(0, 8)}`,
      350
    );

    const deleteResponse = await authenticatedRequest.delete(
      `http://localhost:8080/api/admin/products/${product.id}`
    );
    expect(deleteResponse.ok()).toBeTruthy();

    // The sale happened at the bar before the delete landed; only the upload is
    // after it.
    const transactionId = randomUUID();
    const upload = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: {
        transactions: [
          {
            id: transactionId,
            member_id: member.id,
            product_id: product.id,
            amount_cents: 350,
            created_at: new Date().toISOString(),
          },
        ],
      },
    });

    expect(upload.ok()).toBeTruthy();
    const body = await upload.json();
    expect(body.accepted_ids).toContain(transactionId);
    expect(body.rejected.count).toBe(0);
    expect(body.rejected.errors).toEqual([]);
  });

  test('the sale is still billable and still names its deleted product', async ({
    authenticatedTerminalRequest,
    authenticatedRequest,
    testTransactions,
  }) => {
    const member = await testTransactions.createMember('PendingSale', 'Billable');
    const productName = `DeletedButBilled_${randomUUID().substring(0, 8)}`;
    const product = await testTransactions.createProduct(productName, 275);

    const transactionId = randomUUID();
    const upload = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: {
        transactions: [
          {
            id: transactionId,
            member_id: member.id,
            product_id: product.id,
            amount_cents: 275,
            created_at: new Date().toISOString(),
          },
        ],
      },
    });
    expect(upload.ok()).toBeTruthy();

    const deleteResponse = await authenticatedRequest.delete(
      `http://localhost:8080/api/admin/products/${product.id}`
    );
    expect(deleteResponse.ok()).toBeTruthy();

    // Deleting the product must not take the sale, nor its provenance, with it:
    // the club is still owed the money and the member is entitled to see what
    // for. Resolving the name is what the soft delete buys — a hard delete
    // would leave the row pointing at nothing.
    const history = await authenticatedRequest.get(
      `http://localhost:8080/api/admin/members/${member.id}/transactions`
    );
    expect(history.ok()).toBeTruthy();
    const body = await history.json();

    const sale = body.transactions.find((t: any) => t.id === transactionId);
    expect(sale).toBeDefined();
    expect(sale.amount_cents).toBe(275);
    expect(body.current_balance_cents).toBe(275);

    // The history joins the product to name it. That join is the reason the
    // delete has to stay soft: a hard delete would leave every past sale of the
    // product pointing at nothing, on a ledger that is meant to be permanent.
    const names =
      typeof sale.product_names === 'string'
        ? JSON.parse(sale.product_names)
        : sale.product_names;
    expect(names?.de).toBe(productName);
  });

  // A retry of the same batch is what the terminal does after a dropped
  // connection. It must stay idempotent across the delete, or the member is
  // billed twice for one drink.
  test('re-uploading the batch after the delete creates no duplicate', async ({
    authenticatedTerminalRequest,
    authenticatedRequest,
    testTransactions,
  }) => {
    const member = await testTransactions.createMember('PendingSale', 'Retry');
    const product = await testTransactions.createProduct(
      `DeletedThenRetried_${randomUUID().substring(0, 8)}`,
      420
    );

    const transactionId = randomUUID();
    const batch = {
      transactions: [
        {
          id: transactionId,
          member_id: member.id,
          product_id: product.id,
          amount_cents: 420,
          created_at: new Date().toISOString(),
        },
      ],
    };

    const first = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: batch,
    });
    expect(first.ok()).toBeTruthy();

    const deleteResponse = await authenticatedRequest.delete(
      `http://localhost:8080/api/admin/products/${product.id}`
    );
    expect(deleteResponse.ok()).toBeTruthy();

    const retry = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: batch,
    });

    expect(retry.ok()).toBeTruthy();
    const retryBody = await retry.json();
    // A duplicate is reported as accepted: the terminal only needs to know it
    // may drop the row (ADR-0033 §5).
    expect(retryBody.accepted_ids).toContain(transactionId);
    expect(retryBody.rejected.count).toBe(0);

    const history = await authenticatedRequest.get(
      `http://localhost:8080/api/admin/members/${member.id}/transactions`
    );
    expect(history.ok()).toBeTruthy();
    const body = await history.json();
    expect(
      body.transactions.filter((t: any) => t.id === transactionId)
    ).toHaveLength(1);
    expect(body.current_balance_cents).toBe(420);
  });
});

test.describe('Deletion Protocol - Cursor Behavior', () => {
  test('cursor stays unchanged when no results (race condition prevention)', async ({
    authenticatedTerminalRequest,
  }) => {
    // Use a timestamp in the future (no items modified after this)
    const futureTimestamp = toUnixTimestamp(new Date(Date.now() + 365 * 86400000)); // +1 year

    const response = await authenticatedTerminalRequest.get('/api/sync/members', {
      params: { since: futureTimestamp },
    });
    expect(response.ok()).toBeTruthy();
    const body = await response.json();

    // No results
    expect(body.members).toEqual([]);
    expect(body.count).toBe(0);

    // Cursor should equal input `since` (prevents race condition)
    // See ADR-0012: "When no changes: return input cursor to avoid race condition"
    expect(body.cursor).toBe(futureTimestamp);
  });

  test('cursor advances when tombstone is returned', async ({
    authenticatedTerminalRequest,
    authenticatedRequest,
    testTransactions,
  }) => {
    // Create a member
    const member = await testTransactions.createMember('CursorTest', 'Member');

    // Sync to get cursor
    const syncBefore = await authenticatedTerminalRequest.get('/api/sync/members', {
      params: { since: 0 },
    });
    const beforeDelete = (await syncBefore.json()).cursor;

    // Small delay to ensure deletion timestamp > cursor
    await new Promise((resolve) => setTimeout(resolve, 1100));

    // Delete member
    const deleteResponse = await authenticatedRequest.delete(
      `http://localhost:8080/api/admin/members/${member.id}`
    );
    expect(deleteResponse.ok()).toBeTruthy();

    await new Promise((resolve) => setTimeout(resolve, 1100));

    // Sync with cursor before deletion
    const syncResponse = await authenticatedTerminalRequest.get('/api/sync/members', {
      params: { since: beforeDelete },
    });
    const body = await syncResponse.json();

    // Tombstone returned
    const tombstone = body.members.find((m: any) => m.id === member.id);
    expect(tombstone).toBeDefined();

    // Cursor advanced (timestamp of last item in result set)
    expect(body.cursor).toBeGreaterThan(beforeDelete);
  });
});
