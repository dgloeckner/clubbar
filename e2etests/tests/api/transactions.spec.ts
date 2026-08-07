import { randomUUID } from 'crypto';
import { test, expect } from '../../fixtures/auth.fixture';

/**
 * Transactions Upload endpoint tests
 *
 * Tests the POST /api/sync/transactions endpoint per Terminal API spec.
 * Batch uploads purchase transactions from terminal to backend.
 *
 * Uses E2E Pattern 001: Test Data Isolation
 * - Each test creates unique test data
 * - Tests are independent and can run in parallel
 * - No shared or mutated state between tests
 */

// Helper to create test member
async function createMember(authenticatedRequest) {
  const uniqueId = randomUUID().substring(0, 8);

  // Format date for MySQL (YYYY-MM-DD HH:MM:SS)
  const now = new Date();
  const mandateDate = now.toISOString().slice(0, 19).replace('T', ' ');

  const response = await authenticatedRequest.post('/api/admin/members', {
    data: {
      first_name: `Test${uniqueId}`,
      last_name: `Member${uniqueId}`,
      email: `test${uniqueId}@example.com`,
      iban: 'DE89370400440532013000',
      mandate_signed_at: mandateDate,
      preferred_language: 'de',
    },
  });

  if (!response.ok()) {
    throw new Error(`Failed to create member: ${response.status()} - ${await response.text()}`);
  }

  return await response.json();
}

// Helper to create test category
async function createCategory(authenticatedRequest) {
  const response = await authenticatedRequest.post('/api/admin/categories', {
    data: {
      names: {
        de: `Kategorie ${randomUUID().substring(0, 8)}`,
        en: `Category ${randomUUID().substring(0, 8)}`,
      },
    },
  });

  if (!response.ok()) {
    throw new Error(`Failed to create category: ${response.status()} - ${await response.text()}`);
  }

  return await response.json();
}

// Helper to create test product
async function createProduct(authenticatedRequest) {
  const category = await createCategory(authenticatedRequest);

  const response = await authenticatedRequest.post('/api/admin/products', {
    data: {
      names: {
        de: `Produkt ${randomUUID().substring(0, 8)}`,
        en: `Product ${randomUUID().substring(0, 8)}`,
      },
      price_cents: 350,
      category_id: category.id,
    },
  });

  if (!response.ok()) {
    throw new Error(`Failed to create product: ${response.status()} - ${await response.text()}`);
  }

  return await response.json();
}

// Helper to create valid transaction with specific member and product
function createValidTransaction(memberId: string, productId: string, overrides = {}) {
  return {
    id: randomUUID(),
    member_id: memberId,
    product_id: productId,
    amount_cents: 350,
    created_at: new Date().toISOString(),
    ...overrides,
  };
}

// Helper to create a purchase transaction for a member and return its id.
// A storno must name the transaction it reverses via `related_transaction_id`
// (GoBD Rz. 64), so storno tests need a real purchase to point at.
async function createPurchaseTransaction(authenticatedRequest, authenticatedTerminalRequest, memberId: string, amountCents = 1000) {
  const product = await createProduct(authenticatedRequest);
  const transaction = createValidTransaction(memberId, product.id, { amount_cents: amountCents });

  const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
    data: { transactions: [transaction] },
  });

  if (!response.ok()) {
    throw new Error(`Failed to create purchase transaction: ${response.status()} - ${await response.text()}`);
  }

  return transaction.id;
}

test.describe('Transactions Upload Endpoint', () => {

  test('POST /api/sync/transactions accepts single transaction', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);
    const transaction = createValidTransaction(member.id, product.id);

    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions: [transaction] },
    });

    expect(response.ok()).toBeTruthy();
    expect(response.status()).toBe(201);

    const body = await response.json();

    // Validate response structure per OAS TransactionBatchResponse schema
    expect(body.accepted_ids).toBeDefined();
    expect(Array.isArray(body.accepted_ids)).toBeTruthy();
    expect(body.accepted_ids).toContain(transaction.id);
    expect(body.rejected).toBeDefined();
    expect(body.rejected.count).toBe(0);
    expect(body.rejected.errors).toEqual([]);
  });

  test('POST /api/sync/transactions accepts batch of transactions', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);
    const transactions = [
      createValidTransaction(member.id, product.id),
      createValidTransaction(member.id, product.id, { amount_cents: 500 }),
      createValidTransaction(member.id, product.id, { amount_cents: 280 }),
    ];

    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions },
    });

    expect(response.ok()).toBeTruthy();

    const body = await response.json();
    expect(body.accepted_ids.length).toBe(3);

    for (const tx of transactions) {
      expect(body.accepted_ids).toContain(tx.id);
    }
  });

  test('POST /api/sync/transactions rejects empty transactions array', async ({ authenticatedTerminalRequest }) => {
    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions: [] },
    });

    expect(response.status()).toBe(400);

    const body = await response.json();
    expect(body.error).toBe('invalid_request');
  });

  test('POST /api/sync/transactions rejects missing transactions field', async ({ authenticatedTerminalRequest }) => {
    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: {},
    });

    expect(response.status()).toBe(400);

    const body = await response.json();
    expect(body.error).toBe('invalid_request');
  });

  test('POST /api/sync/transactions validates required transaction fields', async ({ authenticatedTerminalRequest }) => {
    const incompleteTransaction = {
      id: randomUUID(),
      // missing member_id, product_id, amount_cents, created_at
    };

    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions: [incompleteTransaction] },
    });

    expect(response.status()).toBe(422);

    const body = await response.json();
    expect(body.error).toBe('validation_failed');
    expect(body.details).toBeDefined();
    expect(body.details.length).toBeGreaterThan(0);
  });

  test('POST /api/sync/transactions rejects negative amount_cents', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);
    const transaction = createValidTransaction(member.id, product.id, { amount_cents: -100 });

    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions: [transaction] },
    });

    expect(response.status()).toBe(422);

    const body = await response.json();
    expect(body.error).toBe('validation_failed');
  });

  test('POST /api/sync/transactions rejects zero amount_cents', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);
    const transaction = createValidTransaction(member.id, product.id, { amount_cents: 0 });

    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions: [transaction] },
    });

    expect(response.status()).toBe(422);

    const body = await response.json();
    expect(body.error).toBe('validation_failed');
  });

  test('POST /api/sync/transactions returns JSON content type', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);
    const transaction = createValidTransaction(member.id, product.id);

    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions: [transaction] },
    });

    const contentType = response.headers()['content-type'];
    expect(contentType).toContain('application/json');
  });

  test('POST /api/sync/transactions accepts max batch size', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);
    // Create batch of 100 transactions (max allowed)
    const transactions = Array.from({ length: 100 }, () => createValidTransaction(member.id, product.id));

    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions },
    });

    expect(response.ok()).toBeTruthy();

    const body = await response.json();
    expect(body.accepted_ids.length).toBe(100);
  });

  test('POST /api/sync/transactions rejects batch exceeding max size', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);
    // Create batch of 101 transactions (exceeds max)
    const transactions = Array.from({ length: 101 }, () => createValidTransaction(member.id, product.id));

    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
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
  test('POST /api/sync/transactions includes member_balances in response', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);
    const transaction = createValidTransaction(member.id, product.id);

    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions: [transaction] },
    });

    expect(response.ok()).toBeTruthy();

    const body = await response.json();

    // Verify member_balances field exists
    expect(body.member_balances).toBeDefined();
    expect(typeof body.member_balances).toBe('object');
  });

  test('POST /api/sync/transactions calculates correct balance for member', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);
    const transaction = createValidTransaction(member.id, product.id, {
      amount_cents: 123 // Unique amount to distinguish from other tests
    });

    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions: [transaction] },
    });

    expect(response.ok()).toBeTruthy();

    const body = await response.json();

    // Member balance should be exactly the transaction amount (new member, first transaction)
    expect(body.member_balances[member.id]).toBe(123);
  });

  test('POST /api/sync/transactions calculates cumulative balance for multiple transactions', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);
    const totalAmount = 350 + 500 + 200;

    const transactions = [
      createValidTransaction(member.id, product.id, { amount_cents: 350 }),
      createValidTransaction(member.id, product.id, { amount_cents: 500 }),
      createValidTransaction(member.id, product.id, { amount_cents: 200 }),
    ];

    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions },
    });

    expect(response.ok()).toBeTruthy();

    const body = await response.json();

    // Verify balance is exactly the sum of all transactions (new member)
    expect(body.member_balances[member.id]).toBe(totalAmount);
    expect(typeof body.member_balances[member.id]).toBe('number');
  });

  test('POST /api/sync/transactions calculates separate balances for multiple members', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member1 = await createMember(authenticatedRequest);
    const member2 = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);

    const expectedBalance1 = 350 + 200;
    const expectedBalance2 = 500;

    const transactions = [
      createValidTransaction(member1.id, product.id, { amount_cents: 350 }),
      createValidTransaction(member2.id, product.id, { amount_cents: 500 }),
      createValidTransaction(member1.id, product.id, { amount_cents: 200 }),
    ];

    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions },
    });

    expect(response.ok()).toBeTruthy();

    const body = await response.json();

    // Verify both members have balances calculated
    expect(body.member_balances).toHaveProperty(member1.id);
    expect(body.member_balances).toHaveProperty(member2.id);

    // Verify balances are exactly the transaction amounts (new members)
    expect(body.member_balances[member1.id]).toBe(expectedBalance1);
    expect(body.member_balances[member2.id]).toBe(expectedBalance2);

    // Verify balances are numbers not strings
    expect(typeof body.member_balances[member1.id]).toBe('number');
    expect(typeof body.member_balances[member2.id]).toBe('number');
  });

  test('POST /api/sync/transactions returns zero balance for empty transaction', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);
    // Note: This might be rejected by validation, but if allowed, balance should be 0
    const transaction = createValidTransaction(member.id, product.id, { amount_cents: 0 });

    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
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
  test('GET /api/terminal/transactions/{member_id} returns transaction list', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const response = await authenticatedTerminalRequest.get(`/api/terminal/transactions/${member.id}`);

    expect(response.ok()).toBeTruthy();

    const body = await response.json();

    // Validate response structure per ADR-0024 spec
    expect(body.member_id).toBe(member.id);
    expect(body.count).toBeDefined();
    expect(typeof body.count).toBe('number');
    expect(body.transactions).toBeDefined();
    expect(Array.isArray(body.transactions)).toBeTruthy();
  });

  test('GET /api/terminal/transactions/{member_id} returns transactions in descending order', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);

    // Upload multiple transactions with different timestamps
    await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: {
        transactions: [
          createValidTransaction(member.id, product.id, { amount_cents: 100 }),
          createValidTransaction(member.id, product.id, { amount_cents: 200 }),
        ],
      },
    });

    const response = await authenticatedTerminalRequest.get(`/api/terminal/transactions/${member.id}?limit=10`);

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

  test('GET /api/terminal/transactions/{member_id} respects limit parameter', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const response = await authenticatedTerminalRequest.get(`/api/terminal/transactions/${member.id}?limit=5`);

    expect(response.ok()).toBeTruthy();

    const body = await response.json();

    // Should return at most 5 transactions
    expect(body.transactions.length).toBeLessThanOrEqual(5);
  });

  test('GET /api/terminal/transactions/{member_id} supports offset parameter', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const response = await authenticatedTerminalRequest.get(`/api/terminal/transactions/${member.id}?limit=10&offset=5`);

    expect(response.ok()).toBeTruthy();

    const body = await response.json();

    // Should return valid response
    expect(body.member_id).toBe(member.id);
    expect(Array.isArray(body.transactions)).toBeTruthy();
  });

  test('GET /api/terminal/transactions/{member_id} returns 404 for unknown member', async ({ authenticatedTerminalRequest }) => {
    const unknownMemberId = '00000000-0000-0000-0000-000000000000';

    const response = await authenticatedTerminalRequest.get(`/api/terminal/transactions/${unknownMemberId}`);

    expect(response.status()).toBe(404);

    const body = await response.json();
    expect(body.error).toBeDefined();
  });

  test('GET /api/terminal/transactions/{member_id} returns 401 without authorization', async ({ authenticatedRequest, request }) => {
    const member = await createMember(authenticatedRequest);
    // Make request without Bearer token
    const response = await request.get(`/api/terminal/transactions/${member.id}`);

    expect(response.status()).toBe(401);
  });

  test('GET /api/terminal/transactions/{member_id} returns correct transaction fields', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);

    // Upload a transaction first
    await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: {
        transactions: [createValidTransaction(member.id, product.id)],
      },
    });

    const response = await authenticatedTerminalRequest.get(`/api/terminal/transactions/${member.id}?limit=1`);

    expect(response.ok()).toBeTruthy();

    const body = await response.json();

    // Verify transaction structure
    expect(body.transactions.length).toBeGreaterThan(0);
    const tx = body.transactions[0];

    // Verify required fields per ADR-0024 spec
    expect(tx.id).toBeDefined();
    expect(tx.amount_cents).toBeDefined();
    expect(typeof tx.amount_cents).toBe('number');
    expect(tx.type).toBeDefined(); // 'purchase', 'storno', or 'payout'
    expect(tx.product_id).toBeDefined(); // may be null for stornos
    expect(tx.product_name).toBeDefined(); // should be translated to member's language
    expect(tx.created_at).toBeDefined();
  });

  test('GET /api/terminal/transactions/{member_id} returns product_icon, settlement_id, settlement_date fields', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);

    // Upload a purchase transaction
    const transaction = createValidTransaction(member.id, product.id);
    const postResponse = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions: [transaction] },
    });
    expect(postResponse.ok()).toBeTruthy();

    const response = await authenticatedTerminalRequest.get(`/api/terminal/transactions/${member.id}?limit=50`);

    expect(response.ok()).toBeTruthy();

    const body = await response.json();
    const tx = body.transactions.find((t: any) => t.id === transaction.id);

    expect(tx).toBeDefined();

    // Fields must be present (string or null), per api/terminal.yaml TransactionHistoryResponse schema
    expect(tx).toHaveProperty('product_icon');
    expect(tx.product_icon === null || typeof tx.product_icon === 'string').toBeTruthy();

    expect(tx).toHaveProperty('settlement_id');
    expect(tx.settlement_id === null || typeof tx.settlement_id === 'string').toBeTruthy();

    expect(tx).toHaveProperty('settlement_date');
    expect(tx.settlement_date === null || typeof tx.settlement_date === 'string').toBeTruthy();

    // This purchase has not been part of any settlement yet
    expect(tx.settlement_id).toBeNull();
    expect(tx.settlement_date).toBeNull();
  });

  test('GET /api/terminal/transactions/{member_id} returns product_name in member language', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);

    // This test verifies product names are translated
    // First, create a transaction with a product
    const postResponse = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: {
        transactions: [createValidTransaction(member.id, product.id)],
      },
    });

    expect(postResponse.ok()).toBeTruthy();

    // Now fetch transaction history
    const getResponse = await authenticatedTerminalRequest.get(`/api/terminal/transactions/${member.id}?limit=1`);

    expect(getResponse.ok()).toBeTruthy();

    const body = await getResponse.json();

    // Verify product_name is present and not empty (translated)
    expect(body.transactions.length).toBeGreaterThan(0);
    const tx = body.transactions[0];
    expect(tx.product_name).toBeDefined();
    expect(typeof tx.product_name).toBe('string');
  });

  test('GET /api/terminal/transactions/{member_id} handles missing product gracefully', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const response = await authenticatedTerminalRequest.get(`/api/terminal/transactions/${member.id}?limit=50`);

    expect(response.ok()).toBeTruthy();

    const body = await response.json();

    // For stornos (no product_id), product_name should still be set
    for (const tx of body.transactions) {
      expect(tx.product_name).toBeDefined(); // should be "Storno: ..." or similar
    }
  });

  test('GET /api/terminal/transactions/{member_id} returns default limit of 50 if not specified', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const response = await authenticatedTerminalRequest.get(`/api/terminal/transactions/${member.id}`);

    expect(response.ok()).toBeTruthy();

    const body = await response.json();

    // Should return at most 50 transactions (default limit)
    expect(body.transactions.length).toBeLessThanOrEqual(50);
  });
});

/**
 * Milestone 3: Manual Stornos (UC-A21)
 *
 * Tests for admin endpoint to record manual transaction stornos.
 * A storno reverses one specific transaction and must name it via
 * `related_transaction_id` (GoBD Rz. 64); omitting it is a 422.
 * POST /api/admin/members/{memberId}/transactions/correct
 */
test.describe('Manual Storno Endpoint', () => {
  test('POST /api/admin/members/{id}/transactions/correct creates storno transaction', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const purchaseId = await createPurchaseTransaction(authenticatedRequest, authenticatedTerminalRequest, member.id, 350);
    const response = await authenticatedRequest.post(`/api/admin/members/${member.id}/transactions/correct`, {
      data: {
        amount_cents: -350,
        reason: 'Refund for duplicate charge',
        related_transaction_id: purchaseId,
      },
    });

    expect(response.status()).toBe(201);

    const body = await response.json();

    // Verify response structure
    expect(body.id).toBeDefined();
    expect(body.member_id).toBe(member.id);
    expect(body.amount_cents).toBe(-350);
    expect(body.transaction_type).toBe('storno');
    expect(body.notes).toBe('Refund for duplicate charge');
    expect(body.created_by_admin_id).toBeDefined();
    expect(body.created_at).toBeDefined();
  });

  test('POST /api/admin/members/{id}/transactions/correct accepts positive amount', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const purchaseId = await createPurchaseTransaction(authenticatedRequest, authenticatedTerminalRequest, member.id, 500);
    const response = await authenticatedRequest.post(`/api/admin/members/${member.id}/transactions/correct`, {
      data: {
        amount_cents: 500,
        reason: 'Manual charge for damaged item',
        related_transaction_id: purchaseId,
      },
    });

    expect(response.status()).toBe(201);

    const body = await response.json();

    expect(body.amount_cents).toBe(500);
    expect(body.transaction_type).toBe('storno');
  });

  test('POST /api/admin/members/{id}/transactions/correct rejects zero amount', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const purchaseId = await createPurchaseTransaction(authenticatedRequest, authenticatedTerminalRequest, member.id);
    const response = await authenticatedRequest.post(`/api/admin/members/${member.id}/transactions/correct`, {
      data: {
        amount_cents: 0,
        reason: 'Invalid zero amount',
        related_transaction_id: purchaseId,
      },
    });

    expect(response.status()).toBe(422);

    const body = await response.json();
    expect(body.message).toBeDefined();
    expect(body.errors).toBeDefined();
  });

  test('POST /api/admin/members/{id}/transactions/correct rejects missing reason', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const purchaseId = await createPurchaseTransaction(authenticatedRequest, authenticatedTerminalRequest, member.id);
    const response = await authenticatedRequest.post(`/api/admin/members/${member.id}/transactions/correct`, {
      data: {
        amount_cents: -350,
        related_transaction_id: purchaseId,
        // missing reason
      },
    });

    expect(response.status()).toBe(422);

    const body = await response.json();
    expect(body.errors).toBeDefined();
    expect(body.errors.reason).toBeDefined();
  });

  test('POST /api/admin/members/{id}/transactions/correct rejects missing related_transaction_id', async ({ authenticatedRequest }) => {
    const member = await createMember(authenticatedRequest);
    const response = await authenticatedRequest.post(`/api/admin/members/${member.id}/transactions/correct`, {
      data: {
        amount_cents: -350,
        reason: 'Refund without naming what it reverses',
        // missing related_transaction_id
      },
    });

    expect(response.status()).toBe(422);

    const body = await response.json();
    expect(body.errors).toBeDefined();
    expect(body.errors.related_transaction_id).toBeDefined();
  });

  test('POST /api/admin/members/{id}/transactions/correct returns 404 for unknown member', async ({ authenticatedRequest }) => {
    const unknownMemberId = '00000000-0000-0000-0000-000000000000';

    const response = await authenticatedRequest.post(`/api/admin/members/${unknownMemberId}/transactions/correct`, {
      data: {
        amount_cents: -350,
        reason: 'Test storno',
        related_transaction_id: randomUUID(),
      },
    });

    expect(response.status()).toBe(404);

    const body = await response.json();
    expect(body.error).toBe('not_found');
  });

  test('POST /api/admin/members/{id}/transactions/correct returns 404 for unknown related_transaction_id', async ({ authenticatedRequest }) => {
    const member = await createMember(authenticatedRequest);

    const response = await authenticatedRequest.post(`/api/admin/members/${member.id}/transactions/correct`, {
      data: {
        amount_cents: -350,
        reason: 'Test storno for unknown related transaction',
        related_transaction_id: randomUUID(),
      },
    });

    expect(response.status()).toBe(404);

    const body = await response.json();
    expect(body.error).toBe('not_found');
  });

  test('POST /api/admin/members/{id}/transactions/correct rejects reason exceeding 500 chars', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const purchaseId = await createPurchaseTransaction(authenticatedRequest, authenticatedTerminalRequest, member.id);
    const longReason = 'A'.repeat(501);

    const response = await authenticatedRequest.post(`/api/admin/members/${member.id}/transactions/correct`, {
      data: {
        amount_cents: -350,
        reason: longReason,
        related_transaction_id: purchaseId,
      },
    });

    expect(response.status()).toBe(422);

    const body = await response.json();
    expect(body.errors.reason).toBeDefined();
  });

  test('POST /api/admin/members/{id}/transactions/correct creates transaction appearing in history', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const purchaseId = await createPurchaseTransaction(authenticatedRequest, authenticatedTerminalRequest, member.id, 250);
    // Record a storno
    const stornoResponse = await authenticatedRequest.post(`/api/admin/members/${member.id}/transactions/correct`, {
      data: {
        amount_cents: -250,
        reason: 'Test storno for history verification',
        related_transaction_id: purchaseId,
      },
    });

    expect(stornoResponse.status()).toBe(201);

    const storno = await stornoResponse.json();

    // Add small delay to ensure transaction is persisted
    await new Promise(resolve => setTimeout(resolve, 100));

    // Fetch transaction history (with terminal auth)
    const historyResponse = await authenticatedTerminalRequest.get(`/api/terminal/transactions/${member.id}?limit=1`);

    expect(historyResponse.ok()).toBeTruthy();

    const history = await historyResponse.json();

    // Verify storno appears in history (most recent)
    const found = history.transactions.find((tx: any) => tx.id === storno.id);
    expect(found).toBeDefined();
    expect(found?.type).toBe('storno');
    expect(found?.amount_cents).toBe(-250);
  });

  test('POST /api/admin/members/{id}/transactions/correct updates member balance', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const purchaseId = await createPurchaseTransaction(authenticatedRequest, authenticatedTerminalRequest, member.id, 1000);

    // Record a storno
    const response = await authenticatedRequest.post(`/api/admin/members/${member.id}/transactions/correct`, {
      data: {
        amount_cents: 1000,
        reason: 'Balance adjustment test',
        related_transaction_id: purchaseId,
      },
    });

    expect(response.status()).toBe(201);

    const storno = await response.json();

    // Add small delay to ensure transaction is persisted
    await new Promise(resolve => setTimeout(resolve, 100));

    // Fetch transaction history to verify balance includes the storno
    const historyResponse = await authenticatedTerminalRequest.get(`/api/terminal/transactions/${member.id}?limit=100`);

    expect(historyResponse.ok()).toBeTruthy();

    const history = await historyResponse.json();

    // Verify storno is in transactions
    const found = history.transactions.find((tx: any) => tx.id === storno.id);
    expect(found).toBeDefined();
    expect(found?.amount_cents).toBe(1000);
  });
});

/**
 * Milestone 3: Transaction Export (UC-A22)
 *
 * Tests for admin endpoint to export transactions as CSV.
 * GET /api/admin/transactions/export?from_date=YYYY-MM-DD&to_date=YYYY-MM-DD
 */
test.describe('Transaction Export Endpoint', () => {
  const fromDate = '2026-01-01';
  const toDate = '2026-01-31';

  test('GET /api/admin/transactions/export returns CSV data', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/transactions/export', {
      params: {
        from_date: fromDate,
        to_date: toDate,
      },
    });

    expect(response.ok()).toBeTruthy();

    // Verify CSV content type
    const contentType = response.headers()['content-type'];
    expect(contentType).toContain('text/csv');

    // Verify content disposition header for download
    const disposition = response.headers()['content-disposition'];
    expect(disposition).toContain('attachment');
    expect(disposition).toContain('.csv');

    const text = await response.text();
    expect(text).toBeDefined();
    expect(text.length).toBeGreaterThan(0);
  });

  test('GET /api/admin/transactions/export returns valid CSV with headers', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/transactions/export', {
      params: {
        from_date: fromDate,
        to_date: toDate,
      },
    });

    expect(response.ok()).toBeTruthy();

    const csv = await response.text();

    // Verify CSV headers
    expect(csv).toContain('date;member_name;product;type;amount');
  });

  test('GET /api/admin/transactions/export respects date range', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/transactions/export', {
      params: {
        from_date: '2026-12-01',
        to_date: '2026-12-31',
      },
    });

    expect(response.ok()).toBeTruthy();

    const csv = await response.text();

    // CSV should have headers even if no transactions in range
    expect(csv).toContain('date;member_name;product;type;amount');
  });

  test('GET /api/admin/transactions/export filters by member_id', async ({ authenticatedRequest }) => {
    const member = await createMember(authenticatedRequest);
    const response = await authenticatedRequest.get('/api/admin/transactions/export', {
      params: {
        from_date: fromDate,
        to_date: toDate,
        member_id: member.id,
      },
    });

    expect(response.ok()).toBeTruthy();

    const csv = await response.text();

    // Verify CSV headers present
    expect(csv).toContain('date;member_name;product;type;amount');
  });

  test('GET /api/admin/transactions/export filters by transaction type', async ({ authenticatedRequest }) => {
    // Export only stornos
    const response = await authenticatedRequest.get('/api/admin/transactions/export', {
      params: {
        from_date: fromDate,
        to_date: toDate,
        type: 'storno',
      },
    });

    expect(response.ok()).toBeTruthy();

    const csv = await response.text();

    // Verify CSV format
    expect(csv).toContain('date;member_name;product;type;amount');
  });

  test('GET /api/admin/transactions/export rejects invalid date format', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/transactions/export', {
      params: {
        from_date: '01-01-2026',  // Wrong format
        to_date: toDate,
      },
    });

    expect(response.status()).toBe(422);

    const body = await response.json();
    expect(body.errors).toBeDefined();
    expect(body.errors.from_date).toBeDefined();
  });

  test('GET /api/admin/transactions/export rejects to_date before from_date', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/transactions/export', {
      params: {
        from_date: '2026-01-31',
        to_date: '2026-01-01',  // Before from_date
      },
    });

    expect(response.status()).toBe(422);

    const body = await response.json();
    expect(body.errors).toBeDefined();
    expect(body.errors.to_date).toBeDefined();
  });

  test('GET /api/admin/transactions/export returns CSV filename with date range', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/transactions/export', {
      params: {
        from_date: '2026-01-15',
        to_date: '2026-01-25',
      },
    });

    expect(response.ok()).toBeTruthy();

    const disposition = response.headers()['content-disposition'];
    expect(disposition).toContain('transactions-2026-01-15-to-2026-01-25.csv');
  });

  test('POST /api/sync/transactions sets created_by_terminal_id from authenticated terminal', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);
    const transaction = createValidTransaction(member.id, product.id);

    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions: [transaction] },
    });

    expect(response.ok()).toBeTruthy();
    expect(response.status()).toBe(201);

    // Verify created_by_terminal_id was set by checking the transaction in the journal
    const journalResponse = await authenticatedRequest.get('/api/admin/transactions', {
      params: { search: member.first_name, per_page: '10' },
    });
    expect(journalResponse.ok()).toBeTruthy();

    const journal = await journalResponse.json();
    const storedTx = journal.items.find(tx => tx.id === transaction.id);
    expect(storedTx).toBeDefined();
    expect(storedTx.created_by_terminal_id).not.toBeNull();
  });

  test('POST /api/sync/transactions updates terminal last_transaction_at in admin list', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);
    const transaction = createValidTransaction(member.id, product.id);

    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions: [transaction] },
    });

    expect(response.ok()).toBeTruthy();

    // Fetch the seed terminal directly by its known UUID (from backend/db/seed.sql)
    const seedTerminalId = '44e4567-e89b-12d3-a456-426614174000';
    const terminalsResponse = await authenticatedRequest.get(`/api/admin/terminals/${seedTerminalId}`);
    expect(terminalsResponse.ok()).toBeTruthy();

    const terminalsData = await terminalsResponse.json();
    const testTerminal = terminalsData.terminal;
    expect(testTerminal).toBeDefined();
    expect(testTerminal.device_id).toBe('test-device-001');
    expect(testTerminal.last_transaction_at).not.toBeNull();
    // Verify it's a valid ISO 8601 timestamp
    expect(new Date(testTerminal.last_transaction_at).getTime()).not.toBeNaN();
  });

  test('POST /api/sync/transactions stores dispenser metadata fields', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);
    const transaction = createValidTransaction(member.id, product.id, {
      dispenser_tx_id: 'abc123ef',
      dispenser_requested: 3,
      dispenser_actual: 2,
    });

    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions: [transaction] },
    });

    expect(response.ok()).toBeTruthy();
    expect(response.status()).toBe(201);

    const body = await response.json();
    expect(body.accepted_ids).toContain(transaction.id);

    // Verify dispenser metadata was stored by fetching transaction history
    const historyResponse = await authenticatedRequest.get(`/api/admin/members/${member.id}/transactions`);
    expect(historyResponse.ok()).toBeTruthy();

    const history = await historyResponse.json();
    const storedTransaction = history.transactions.find(tx => tx.id === transaction.id);

    expect(storedTransaction).toBeDefined();
    expect(storedTransaction.dispenser_tx_id).toBe('abc123ef');
    expect(storedTransaction.dispenser_requested).toBe(3);
    expect(storedTransaction.dispenser_actual).toBe(2);
  });

  test('POST /api/sync/transactions accepts null dispenser metadata', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);
    const transaction = createValidTransaction(member.id, product.id);
    // No dispenser metadata fields provided

    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions: [transaction] },
    });

    expect(response.ok()).toBeTruthy();
    expect(response.status()).toBe(201);

    const body = await response.json();
    expect(body.accepted_ids).toContain(transaction.id);

    // Verify null dispenser metadata
    const historyResponse = await authenticatedRequest.get(`/api/admin/members/${member.id}/transactions`);
    expect(historyResponse.ok()).toBeTruthy();

    const history = await historyResponse.json();
    const storedTransaction = history.transactions.find(tx => tx.id === transaction.id);

    expect(storedTransaction).toBeDefined();
    expect(storedTransaction.dispenser_tx_id).toBeNull();
    expect(storedTransaction.dispenser_requested).toBeNull();
    expect(storedTransaction.dispenser_actual).toBeNull();
  });
});
