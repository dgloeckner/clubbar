import { test, expect } from '../../fixtures/auth.fixture';

/**
 * Delta Sync Behavior Tests
 *
 * Verifies that the `since` parameter correctly filters sync results.
 * These tests ensure delta sync actually works (not just that the API accepts the parameter).
 *
 * Key behaviors tested:
 * 1. Items created AFTER `since` timestamp ARE returned
 * 2. Items NOT modified after `since` timestamp are NOT returned
 * 3. Cursor from response can be used directly as next `since` parameter (both are Unix timestamps)
 */

/** Convert to Unix timestamp (milliseconds) for API calls */
const toUnixTimestamp = (date: Date): number => date.getTime();

test.describe('Delta Sync Behavior - Members', () => {
  test('returns newly created member when since is before creation', async ({
    authenticatedTerminalRequest,
    testTransactions,
  }) => {
    // Get current Unix timestamp (before creating member)
    const beforeCreate = toUnixTimestamp(new Date());

    // Small delay to ensure time difference
    await new Promise((resolve) => setTimeout(resolve, 1100)); // MySQL DATETIME has second precision

    // Create a new member
    const member = await testTransactions.createMember('DeltaTest', 'Member');

    // Query with timestamp from before creation - should include new member
    const response = await authenticatedTerminalRequest.get('/api/sync/members', {
      params: { since: beforeCreate },
    });

    expect(response.ok()).toBeTruthy();
    const body = await response.json();

    // Find the created member in results
    const foundMember = body.members.find((m: any) => m.id === member.id);
    expect(foundMember).toBeDefined();
    expect(foundMember.first_name).toContain('DeltaTest');
  });

  test('does not return member when since is in the future', async ({
    authenticatedTerminalRequest,
    testTransactions,
  }) => {
    // Create a member first
    const member = await testTransactions.createMember('DeltaFuture', 'Member');

    // Query with timestamp in the future - should NOT include any members
    const futureTimestamp = toUnixTimestamp(new Date(Date.now() + 86400000)); // +1 day
    const response = await authenticatedTerminalRequest.get('/api/sync/members', {
      params: { since: futureTimestamp },
    });

    expect(response.ok()).toBeTruthy();
    const body = await response.json();

    // Should not find the member (or any recently created members)
    const foundMember = body.members.find((m: any) => m.id === member.id);
    expect(foundMember).toBeUndefined();
  });

  test('cursor from response can be used directly as next since parameter', async ({
    authenticatedTerminalRequest,
    testTransactions,
  }) => {
    // First sync - get all members and capture cursor (now a Unix timestamp)
    const initialResponse = await authenticatedTerminalRequest.get('/api/sync/members', {
      params: { since: 0 },
    });

    expect(initialResponse.ok()).toBeTruthy();
    const initialBody = await initialResponse.json();
    const cursor = initialBody.cursor;
    expect(cursor).toBeDefined();
    expect(typeof cursor).toBe('number'); // Cursor is a Unix timestamp in milliseconds

    // Small delay
    await new Promise((resolve) => setTimeout(resolve, 1100)); // MySQL DATETIME has second precision

    // Create a new member after capturing cursor
    const newMember = await testTransactions.createMember('DeltaCursor', 'Member');

    // Second sync using cursor directly - no conversion needed!
    const deltaResponse = await authenticatedTerminalRequest.get('/api/sync/members', {
      params: { since: cursor },
    });

    expect(deltaResponse.ok()).toBeTruthy();
    const deltaBody = await deltaResponse.json();

    // New member should be in delta response
    const foundNewMember = deltaBody.members.find((m: any) => m.id === newMember.id);
    expect(foundNewMember).toBeDefined();

    // Delta count should be much smaller than full sync
    expect(deltaBody.count).toBeLessThan(initialBody.count);
  });
});

test.describe('Delta Sync Behavior - Categories', () => {
  test('returns newly created category when since is before creation', async ({
    authenticatedTerminalRequest,
    authenticatedRequest,
  }) => {
    // Get current Unix timestamp (before creating category)
    const beforeCreate = toUnixTimestamp(new Date());

    // Small delay to ensure time difference
    await new Promise((resolve) => setTimeout(resolve, 1100)); // MySQL DATETIME has second precision

    // Create a new category
    const uniqueName = `DeltaCat_${Date.now()}`;
    const catResponse = await authenticatedRequest.post(
      'http://localhost:8080/api/admin/categories',
      {
        data: {
          names: { de: uniqueName, en: `${uniqueName}_EN` },
        },
      }
    );

    expect(catResponse.status()).toBe(201);
    const category = await catResponse.json();

    // Query with timestamp from before creation - should include new category
    const response = await authenticatedTerminalRequest.get('/api/sync/categories', {
      params: { since: beforeCreate },
    });

    expect(response.ok()).toBeTruthy();
    const body = await response.json();

    // Find the created category in results
    const foundCategory = body.categories.find((c: any) => c.id === category.id);
    expect(foundCategory).toBeDefined();
    expect(foundCategory.names.de).toBe(uniqueName);
  });

  test('does not return category when since is in the future', async ({
    authenticatedTerminalRequest,
    authenticatedRequest,
  }) => {
    // Create a category first
    const uniqueName = `DeltaCatFuture_${Date.now()}`;
    const catResponse = await authenticatedRequest.post(
      'http://localhost:8080/api/admin/categories',
      {
        data: {
          names: { de: uniqueName, en: `${uniqueName}_EN` },
        },
      }
    );

    expect(catResponse.status()).toBe(201);
    const category = await catResponse.json();

    // Query with timestamp in the future - should NOT include the category
    const futureTimestamp = toUnixTimestamp(new Date(Date.now() + 86400000)); // +1 day
    const response = await authenticatedTerminalRequest.get('/api/sync/categories', {
      params: { since: futureTimestamp },
    });

    expect(response.ok()).toBeTruthy();
    const body = await response.json();

    // Should not find the category
    const foundCategory = body.categories.find((c: any) => c.id === category.id);
    expect(foundCategory).toBeUndefined();
  });

  test('cursor from response can be used directly as next since parameter', async ({
    authenticatedTerminalRequest,
    authenticatedRequest,
  }) => {
    // First sync - get all categories and capture cursor
    const initialResponse = await authenticatedTerminalRequest.get('/api/sync/categories', {
      params: { since: 0 },
    });

    expect(initialResponse.ok()).toBeTruthy();
    const initialBody = await initialResponse.json();
    const cursor = initialBody.cursor;
    expect(cursor).toBeDefined();
    expect(typeof cursor).toBe('number'); // Cursor is a Unix timestamp in milliseconds

    // Small delay
    await new Promise((resolve) => setTimeout(resolve, 1100)); // MySQL DATETIME has second precision

    // Create a new category after capturing cursor
    const uniqueName = `DeltaCatCursor_${Date.now()}`;
    const catResponse = await authenticatedRequest.post(
      'http://localhost:8080/api/admin/categories',
      {
        data: {
          names: { de: uniqueName, en: `${uniqueName}_EN` },
        },
      }
    );

    expect(catResponse.status()).toBe(201);
    const newCategory = await catResponse.json();

    // Second sync using cursor directly - no conversion needed!
    const deltaResponse = await authenticatedTerminalRequest.get('/api/sync/categories', {
      params: { since: cursor },
    });

    expect(deltaResponse.ok()).toBeTruthy();
    const deltaBody = await deltaResponse.json();

    // New category should be in delta response
    const foundNewCategory = deltaBody.categories.find((c: any) => c.id === newCategory.id);
    expect(foundNewCategory).toBeDefined();

    // Delta count should be smaller than or equal to full sync
    expect(deltaBody.count).toBeLessThanOrEqual(initialBody.count);
  });
});

test.describe('Delta Sync Behavior - Products', () => {
  test('returns newly created product when since is before creation', async ({
    authenticatedTerminalRequest,
    testTransactions,
  }) => {
    // Get current Unix timestamp (before creating product)
    const beforeCreate = toUnixTimestamp(new Date());

    // Small delay to ensure time difference
    await new Promise((resolve) => setTimeout(resolve, 1100)); // MySQL DATETIME has second precision

    // Create a new product (this also creates a category)
    const uniqueName = `DeltaProd_${Date.now()}`;
    const product = await testTransactions.createProduct(uniqueName, 250);

    // Query with timestamp from before creation - should include new product
    const response = await authenticatedTerminalRequest.get('/api/sync/products', {
      params: { since: beforeCreate },
    });

    expect(response.ok()).toBeTruthy();
    const body = await response.json();

    // Find the created product in results
    const foundProduct = body.products.find((p: any) => p.id === product.id);
    expect(foundProduct).toBeDefined();
    expect(foundProduct.names.de).toBe(uniqueName);
  });

  test('does not return product when since is in the future', async ({
    authenticatedTerminalRequest,
    testTransactions,
  }) => {
    // Create a product first
    const uniqueName = `DeltaProdFuture_${Date.now()}`;
    const product = await testTransactions.createProduct(uniqueName, 350);

    // Query with timestamp in the future - should NOT include the product
    const futureTimestamp = toUnixTimestamp(new Date(Date.now() + 86400000)); // +1 day
    const response = await authenticatedTerminalRequest.get('/api/sync/products', {
      params: { since: futureTimestamp },
    });

    expect(response.ok()).toBeTruthy();
    const body = await response.json();

    // Should not find the product
    const foundProduct = body.products.find((p: any) => p.id === product.id);
    expect(foundProduct).toBeUndefined();
  });

  test('cursor from response can be used directly as next since parameter', async ({
    authenticatedTerminalRequest,
    testTransactions,
  }) => {
    // First sync - get all products and capture cursor
    const initialResponse = await authenticatedTerminalRequest.get('/api/sync/products', {
      params: { since: 0 },
    });

    expect(initialResponse.ok()).toBeTruthy();
    const initialBody = await initialResponse.json();
    const cursor = initialBody.cursor;
    expect(cursor).toBeDefined();
    expect(typeof cursor).toBe('number'); // Cursor is a Unix timestamp in milliseconds

    // Small delay
    await new Promise((resolve) => setTimeout(resolve, 1100)); // MySQL DATETIME has second precision

    // Create a new product after capturing cursor
    const uniqueName = `DeltaProdCursor_${Date.now()}`;
    const newProduct = await testTransactions.createProduct(uniqueName, 450);

    // Second sync using cursor directly - no conversion needed!
    const deltaResponse = await authenticatedTerminalRequest.get('/api/sync/products', {
      params: { since: cursor },
    });

    expect(deltaResponse.ok()).toBeTruthy();
    const deltaBody = await deltaResponse.json();

    // New product should be in delta response
    const foundNewProduct = deltaBody.products.find((p: any) => p.id === newProduct.id);
    expect(foundNewProduct).toBeDefined();

    // Delta count should be smaller than or equal to full sync
    expect(deltaBody.count).toBeLessThanOrEqual(initialBody.count);
  });

  test('returns updated product when since is before update time', async ({
    authenticatedTerminalRequest,
    authenticatedRequest,
    testTransactions,
  }) => {
    // Create a product first
    const uniqueName = `DeltaProdUpdate_${Date.now()}`;
    const product = await testTransactions.createProduct(uniqueName, 500);

    // Small delay then capture Unix timestamp
    await new Promise((resolve) => setTimeout(resolve, 1100)); // MySQL DATETIME has second precision
    const beforeUpdate = toUnixTimestamp(new Date());
    await new Promise((resolve) => setTimeout(resolve, 1100)); // MySQL DATETIME has second precision

    // Update the product
    const updatedName = `${uniqueName}_Updated`;
    const updateResponse = await authenticatedRequest.patch(
      `http://localhost:8080/api/admin/products/${product.id}`,
      {
        data: {
          names: { de: updatedName },
          price_cents: 550,
        },
      }
    );

    expect(updateResponse.ok()).toBeTruthy();

    // Query with timestamp from before update - should include the updated product
    const response = await authenticatedTerminalRequest.get('/api/sync/products', {
      params: { since: beforeUpdate },
    });

    expect(response.ok()).toBeTruthy();
    const body = await response.json();

    // Find the updated product in results
    const foundProduct = body.products.find((p: any) => p.id === product.id);
    expect(foundProduct).toBeDefined();
    expect(foundProduct.names.de).toBe(updatedName);
    expect(foundProduct.price_cents).toBe(550);
  });
});

/**
 * Regression guard for #84.
 *
 * MySQL TIMESTAMP has second precision, so a member or price written at
 * 12:00:00.8 is stored exactly like one written at 12:00:00.2. A terminal that
 * synced in between holds the cursor 12:00:00 — and if the next query asked for
 * `> 12:00:00`, the second write would never be delivered to that terminal
 * again. The lower bound is therefore inclusive: an item whose `updated_at`
 * falls exactly on the cursor is still returned.
 */
test.describe('Delta Sync - Cursor Second Boundary', () => {
  test('returns a member whose updated_at falls exactly on the since cursor', async ({
    authenticatedTerminalRequest,
    testTransactions,
  }) => {
    const member = await testTransactions.createMember('BoundaryTest', 'Member');

    // Full sync to learn the second the member was actually stored in
    const fullSync = await authenticatedTerminalRequest.get('/api/sync/members', {
      params: { since: 0 },
    });
    expect(fullSync.ok()).toBeTruthy();
    const stored = (await fullSync.json()).members.find((m: any) => m.id === member.id);
    expect(stored).toBeDefined();

    // Sync from exactly that second — the cursor a terminal would be holding
    const boundaryResponse = await authenticatedTerminalRequest.get('/api/sync/members', {
      params: { since: Date.parse(stored.updated_at) },
    });

    expect(boundaryResponse.ok()).toBeTruthy();
    const boundaryBody = await boundaryResponse.json();
    expect(boundaryBody.members.find((m: any) => m.id === member.id)).toBeDefined();
  });

  test('returns a product whose updated_at falls exactly on the since cursor', async ({
    authenticatedTerminalRequest,
    testTransactions,
  }) => {
    const uniqueName = `DeltaBoundary_${Date.now()}`;
    const product = await testTransactions.createProduct(uniqueName, 275);

    // Full sync to learn the second the product was actually stored in
    const fullSync = await authenticatedTerminalRequest.get('/api/sync/products', {
      params: { since: 0 },
    });
    expect(fullSync.ok()).toBeTruthy();
    const stored = (await fullSync.json()).products.find((p: any) => p.id === product.id);
    expect(stored).toBeDefined();

    // A price the terminal misses here is a price it keeps charging
    const boundaryResponse = await authenticatedTerminalRequest.get('/api/sync/products', {
      params: { since: Date.parse(stored.updated_at) },
    });

    expect(boundaryResponse.ok()).toBeTruthy();
    const boundaryBody = await boundaryResponse.json();
    const found = boundaryBody.products.find((p: any) => p.id === product.id);
    expect(found).toBeDefined();
    expect(found.price_cents).toBe(275);
  });
});

test.describe('Delta Sync - Empty Results', () => {
  test('returns empty array when no items modified since timestamp', async ({
    authenticatedTerminalRequest,
  }) => {
    // Use a timestamp far in the future (Unix timestamp)
    const futureTimestamp = toUnixTimestamp(new Date(Date.now() + 365 * 86400000)); // +1 year

    const membersResponse = await authenticatedTerminalRequest.get('/api/sync/members', {
      params: { since: futureTimestamp },
    });
    expect(membersResponse.ok()).toBeTruthy();
    const membersBody = await membersResponse.json();
    expect(membersBody.members).toEqual([]);
    expect(membersBody.count).toBe(0);

    const categoriesResponse = await authenticatedTerminalRequest.get('/api/sync/categories', {
      params: { since: futureTimestamp },
    });
    expect(categoriesResponse.ok()).toBeTruthy();
    const categoriesBody = await categoriesResponse.json();
    expect(categoriesBody.categories).toEqual([]);
    expect(categoriesBody.count).toBe(0);

    const productsResponse = await authenticatedTerminalRequest.get('/api/sync/products', {
      params: { since: futureTimestamp },
    });
    expect(productsResponse.ok()).toBeTruthy();
    const productsBody = await productsResponse.json();
    expect(productsBody.products).toEqual([]);
    expect(productsBody.count).toBe(0);
  });
});
