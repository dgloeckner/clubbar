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
});
