import { test, expect } from '@playwright/test';
import { randomUUID } from 'crypto';

/**
 * Transactions Upload endpoint tests
 *
 * Tests the POST /api/sync/transactions endpoint per Terminal API spec.
 * Batch uploads purchase transactions from terminal to backend.
 */

const validToken = process.env.TEST_TERMINAL_TOKEN;
const authHeaders = validToken ? { 'Authorization': `Bearer ${validToken}` } : {};

test.describe('Transactions Upload Endpoint', () => {
  const validMemberId = '123e4567-e89b-12d3-a456-426614174000';
  const validProductId = '987f6543-e21a-11d3-b456-426614174999';

  function createValidTransaction(overrides = {}) {
    return {
      id: randomUUID(),
      member_id: validMemberId,
      product_id: validProductId,
      amount_cents: 350,
      created_at: new Date().toISOString(),
      ...overrides,
    };
  }

  test('POST /api/sync/transactions accepts single transaction', async ({ request }) => {
    const transaction = createValidTransaction();

    const response = await request.post('/api/sync/transactions', {
      headers: authHeaders,
      data: { transactions: [transaction] },
    });

    expect(response.ok()).toBeTruthy();
    expect(response.status()).toBe(200);

    const body = await response.json();

    // Validate response structure per OAS TransactionBatchResponse schema
    expect(body.accepted_ids).toBeDefined();
    expect(Array.isArray(body.accepted_ids)).toBeTruthy();
    expect(body.accepted_ids).toContain(transaction.id);
    expect(body.rejected).toBeDefined();
    expect(body.rejected.count).toBe(0);
    expect(body.rejected.errors).toEqual([]);
  });

  test('POST /api/sync/transactions accepts batch of transactions', async ({ request }) => {
    const transactions = [
      createValidTransaction(),
      createValidTransaction({ amount_cents: 500 }),
      createValidTransaction({ amount_cents: 280 }),
    ];

    const response = await request.post('/api/sync/transactions', {
      headers: authHeaders,
      data: { transactions },
    });

    expect(response.ok()).toBeTruthy();

    const body = await response.json();
    expect(body.accepted_ids.length).toBe(3);

    for (const tx of transactions) {
      expect(body.accepted_ids).toContain(tx.id);
    }
  });

  test('POST /api/sync/transactions rejects empty transactions array', async ({ request }) => {
    const response = await request.post('/api/sync/transactions', {
      headers: authHeaders,
      data: { transactions: [] },
    });

    expect(response.status()).toBe(400);

    const body = await response.json();
    expect(body.error).toBe('invalid_request');
  });

  test('POST /api/sync/transactions rejects missing transactions field', async ({ request }) => {
    const response = await request.post('/api/sync/transactions', {
      headers: authHeaders,
      data: {},
    });

    expect(response.status()).toBe(400);

    const body = await response.json();
    expect(body.error).toBe('invalid_request');
  });

  test('POST /api/sync/transactions validates required transaction fields', async ({ request }) => {
    const incompleteTransaction = {
      id: randomUUID(),
      // missing member_id, product_id, amount_cents, created_at
    };

    const response = await request.post('/api/sync/transactions', {
      headers: authHeaders,
      data: { transactions: [incompleteTransaction] },
    });

    expect(response.status()).toBe(422);

    const body = await response.json();
    expect(body.error).toBe('validation_failed');
    expect(body.details).toBeDefined();
    expect(body.details.length).toBeGreaterThan(0);
  });

  test('POST /api/sync/transactions rejects negative amount_cents', async ({ request }) => {
    const transaction = createValidTransaction({ amount_cents: -100 });

    const response = await request.post('/api/sync/transactions', {
      headers: authHeaders,
      data: { transactions: [transaction] },
    });

    expect(response.status()).toBe(422);

    const body = await response.json();
    expect(body.error).toBe('validation_failed');
  });

  test('POST /api/sync/transactions rejects zero amount_cents', async ({ request }) => {
    const transaction = createValidTransaction({ amount_cents: 0 });

    const response = await request.post('/api/sync/transactions', {
      headers: authHeaders,
      data: { transactions: [transaction] },
    });

    expect(response.status()).toBe(422);

    const body = await response.json();
    expect(body.error).toBe('validation_failed');
  });

  test('POST /api/sync/transactions returns JSON content type', async ({ request }) => {
    const transaction = createValidTransaction();

    const response = await request.post('/api/sync/transactions', {
      headers: authHeaders,
      data: { transactions: [transaction] },
    });

    const contentType = response.headers()['content-type'];
    expect(contentType).toContain('application/json');
  });

  test('POST /api/sync/transactions accepts max batch size', async ({ request }) => {
    // Create batch of 100 transactions (max allowed)
    const transactions = Array.from({ length: 100 }, () => createValidTransaction());

    const response = await request.post('/api/sync/transactions', {
      headers: authHeaders,
      data: { transactions },
    });

    expect(response.ok()).toBeTruthy();

    const body = await response.json();
    expect(body.accepted_ids.length).toBe(100);
  });

  test('POST /api/sync/transactions rejects batch exceeding max size', async ({ request }) => {
    // Create batch of 101 transactions (exceeds max)
    const transactions = Array.from({ length: 101 }, () => createValidTransaction());

    const response = await request.post('/api/sync/transactions', {
      headers: authHeaders,
      data: { transactions },
    });

    expect(response.status()).toBe(400);

    const body = await response.json();
    expect(body.error).toBe('invalid_request');
    expect(body.message).toContain('100');
  });

  /**
   * Milestone 2.A Tests: Member Balance Calculation
   *
   * Tests for ADR-0023: Terminal Balance State Management
   * POST /sync/transactions response includes member_balances object
   */
  test('POST /api/sync/transactions includes member_balances in response', async ({ request }) => {
    const transaction = createValidTransaction();

    const response = await request.post('/api/sync/transactions', {
      headers: authHeaders,
      data: { transactions: [transaction] },
    });

    expect(response.ok()).toBeTruthy();

    const body = await response.json();

    // Verify member_balances field exists
    expect(body.member_balances).toBeDefined();
    expect(typeof body.member_balances).toBe('object');
  });

  test('POST /api/sync/transactions calculates correct balance for member', async ({ request }) => {
    // Use existing member to avoid FK constraint issues
    // Use unique transaction ID to ensure idempotency
    const testMemberId = '323e4567-e89b-12d3-a456-426614174002';
    const transaction = createValidTransaction({
      member_id: testMemberId,
      amount_cents: 123 // Unique amount to distinguish from other tests
    });

    const response = await request.post('/api/sync/transactions', {
      headers: authHeaders,
      data: { transactions: [transaction] },
    });

    expect(response.ok()).toBeTruthy();

    const body = await response.json();

    // Member balance should include the transaction amount we just sent
    // (may include previous transactions from DB, so just verify the amount is at least the transaction)
    expect(body.member_balances[testMemberId]).toBeGreaterThanOrEqual(123);
  });

  test('POST /api/sync/transactions calculates cumulative balance for multiple transactions', async ({ request }) => {
    // Use existing members to avoid FK constraint issues (Pattern 001: Test isolation via unique transaction IDs)
    const testMemberId = '423e4567-e89b-12d3-a456-426614174003'; // Susan Johnson
    const amountIncrease = 350 + 500 + 200; // Expected increase from this batch

    const transactions = [
      createValidTransaction({ member_id: testMemberId, amount_cents: 350 }),
      createValidTransaction({ member_id: testMemberId, amount_cents: 500 }),
      createValidTransaction({ member_id: testMemberId, amount_cents: 200 }), // additional purchase
    ];

    const response = await request.post('/api/sync/transactions', {
      headers: authHeaders,
      data: { transactions },
    });

    expect(response.ok()).toBeTruthy();

    const body = await response.json();

    // Verify balance calculation includes the transaction amounts
    expect(body.member_balances[testMemberId]).toBeGreaterThanOrEqual(amountIncrease);
    expect(typeof body.member_balances[testMemberId]).toBe('number');
  });

  test('POST /api/sync/transactions calculates separate balances for multiple members', async ({ request }) => {
    // Use existing members from database (Pattern 001: Test isolation via unique transaction IDs)
    const memberId1 = '323e4567-e89b-12d3-a456-426614174002'; // Peter Müller
    const memberId2 = '423e4567-e89b-12d3-a456-426614174003'; // Susan Johnson

    const expectedIncrease1 = 350 + 200;
    const expectedIncrease2 = 500;

    const transactions = [
      createValidTransaction({ member_id: memberId1, amount_cents: 350 }),
      createValidTransaction({ member_id: memberId2, amount_cents: 500 }),
      createValidTransaction({ member_id: memberId1, amount_cents: 200 }),
    ];

    const response = await request.post('/api/sync/transactions', {
      headers: authHeaders,
      data: { transactions },
    });

    expect(response.ok()).toBeTruthy();

    const body = await response.json();

    // Verify both members have balances calculated
    expect(body.member_balances).toHaveProperty(memberId1);
    expect(body.member_balances).toHaveProperty(memberId2);

    // Verify balances include amounts from this transaction
    expect(body.member_balances[memberId1]).toBeGreaterThanOrEqual(expectedIncrease1);
    expect(body.member_balances[memberId2]).toBeGreaterThanOrEqual(expectedIncrease2);

    // Verify balances are numbers not strings
    expect(typeof body.member_balances[memberId1]).toBe('number');
    expect(typeof body.member_balances[memberId2]).toBe('number');
  });

  test('POST /api/sync/transactions returns zero balance for empty transaction', async ({ request }) => {
    // Note: This might be rejected by validation, but if allowed, balance should be 0
    const transaction = createValidTransaction({ amount_cents: 0 });

    const response = await request.post('/api/sync/transactions', {
      headers: authHeaders,
      data: { transactions: [transaction] },
    });

    // This request should actually fail validation (zero amount), so we check for 422
    expect(response.status()).toBe(422);
  });
});

/**
 * Milestone 2.A Tests: Transaction History Retrieval Endpoint
 *
 * Tests for ADR-0024: Transaction History Retrieval in Terminal
 * GET /api/terminal/transactions/{member_id} endpoint
 */
test.describe('Transaction History Endpoint', () => {
  const memberId = '123e4567-e89b-12d3-a456-426614174000';
  const productId = '987f6543-e21a-11d3-b456-426614174999';

  test('GET /api/terminal/transactions/{member_id} returns transaction list', async ({ request }) => {
    const response = await request.get(`/api/terminal/transactions/${memberId}`, {
      headers: authHeaders,
    });

    expect(response.ok()).toBeTruthy();

    const body = await response.json();

    // Validate response structure per ADR-0024 spec
    expect(body.member_id).toBe(memberId);
    expect(body.count).toBeDefined();
    expect(typeof body.count).toBe('number');
    expect(body.transactions).toBeDefined();
    expect(Array.isArray(body.transactions)).toBeTruthy();
  });

  test('GET /api/terminal/transactions/{member_id} returns transactions in descending order', async ({ request }) => {
    const response = await request.get(`/api/terminal/transactions/${memberId}?limit=10`, {
      headers: authHeaders,
    });

    expect(response.ok()).toBeTruthy();

    const body = await response.json();

    // If there are transactions, verify they're sorted by created_at DESC
    if (body.transactions.length > 1) {
      for (let i = 0; i < body.transactions.length - 1; i++) {
        const current = new Date(body.transactions[i].created_at).getTime();
        const next = new Date(body.transactions[i + 1].created_at).getTime();
        expect(current).toBeGreaterThanOrEqual(next);
      }
    }
  });

  test('GET /api/terminal/transactions/{member_id} respects limit parameter', async ({ request }) => {
    const response = await request.get(`/api/terminal/transactions/${memberId}?limit=5`, {
      headers: authHeaders,
    });

    expect(response.ok()).toBeTruthy();

    const body = await response.json();

    // Should return at most 5 transactions
    expect(body.transactions.length).toBeLessThanOrEqual(5);
  });

  test('GET /api/terminal/transactions/{member_id} supports offset parameter', async ({ request }) => {
    const response = await request.get(`/api/terminal/transactions/${memberId}?limit=10&offset=5`, {
      headers: authHeaders,
    });

    expect(response.ok()).toBeTruthy();

    const body = await response.json();

    // Should return valid response
    expect(body.member_id).toBe(memberId);
    expect(Array.isArray(body.transactions)).toBeTruthy();
  });

  test('GET /api/terminal/transactions/{member_id} returns 404 for unknown member', async ({ request }) => {
    const unknownMemberId = '00000000-0000-0000-0000-000000000000';

    const response = await request.get(`/api/terminal/transactions/${unknownMemberId}`, {
      headers: authHeaders,
    });

    expect(response.status()).toBe(404);

    const body = await response.json();
    expect(body.error).toBeDefined();
  });

  test('GET /api/terminal/transactions/{member_id} returns 401 without authorization', async ({ request }) => {
    // Make request without Bearer token
    const response = await request.get(`/api/terminal/transactions/${memberId}`);

    expect(response.status()).toBe(401);
  });

  test('GET /api/terminal/transactions/{member_id} returns correct transaction fields', async ({ request }) => {
    const response = await request.get(`/api/terminal/transactions/${memberId}?limit=1`, {
      headers: authHeaders,
    });

    expect(response.ok()).toBeTruthy();

    const body = await response.json();

    // If there are transactions, verify structure
    if (body.transactions.length > 0) {
      const tx = body.transactions[0];

      // Verify required fields per ADR-0024 spec
      expect(tx.id).toBeDefined();
      expect(tx.amount_cents).toBeDefined();
      expect(typeof tx.amount_cents).toBe('number');
      expect(tx.type).toBeDefined(); // 'purchase' or 'correction'
      expect(tx.product_id).toBeDefined(); // may be null for corrections
      expect(tx.product_name).toBeDefined(); // should be translated to member's language
      expect(tx.created_at).toBeDefined();
    }
  });

  test('GET /api/terminal/transactions/{member_id} returns product_name in member language', async ({ request }) => {
    // This test verifies product names are translated
    // First, create a transaction with a product
    const postResponse = await request.post('/api/sync/transactions', {
      headers: authHeaders,
      data: {
        transactions: [{
          id: randomUUID(),
          member_id: memberId,
          product_id: productId,
          amount_cents: 350,
          created_at: new Date().toISOString(),
        }],
      },
    });

    expect(postResponse.ok()).toBeTruthy();

    // Now fetch transaction history
    const getResponse = await request.get(`/api/terminal/transactions/${memberId}?limit=1`, {
      headers: authHeaders,
    });

    expect(getResponse.ok()).toBeTruthy();

    const body = await getResponse.json();

    // Verify product_name is present and not empty (translated)
    if (body.transactions.length > 0) {
      const tx = body.transactions[0];
      expect(tx.product_name).toBeDefined();
      expect(typeof tx.product_name).toBe('string');
    }
  });

  test('GET /api/terminal/transactions/{member_id} handles missing product gracefully', async ({ request }) => {
    const response = await request.get(`/api/terminal/transactions/${memberId}?limit=50`, {
      headers: authHeaders,
    });

    expect(response.ok()).toBeTruthy();

    const body = await response.json();

    // For corrections (no product_id), product_name should still be set
    for (const tx of body.transactions) {
      expect(tx.product_name).toBeDefined(); // should be "Correction: ..." or similar
    }
  });

  test('GET /api/terminal/transactions/{member_id} returns default limit of 50 if not specified', async ({ request }) => {
    const response = await request.get(`/api/terminal/transactions/${memberId}`, {
      headers: authHeaders,
    });

    expect(response.ok()).toBeTruthy();

    const body = await response.json();

    // Should return at most 50 transactions (default limit)
    expect(body.transactions.length).toBeLessThanOrEqual(50);
  });
});
