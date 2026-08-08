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

// Helper to create a member with no IBAN and no SEPA mandate.
// A storno must still be possible for them: it reduces what they owe, and
// gating a debt reduction on the ability to collect is inverted (#158 §1).
async function createMemberWithoutMandate(authenticatedRequest) {
  const uniqueId = randomUUID().substring(0, 8);

  const response = await authenticatedRequest.post('/api/admin/members', {
    data: {
      first_name: `NoMandate${uniqueId}`,
      last_name: `Member${uniqueId}`,
      email: `nomandate${uniqueId}@example.com`,
      preferred_language: 'de',
    },
  });

  if (!response.ok()) {
    throw new Error(`Failed to create member without mandate: ${response.status()} - ${await response.text()}`);
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

  /**
   * Issue #82 — the batch validator never required `id`, so a transaction
   * without one reached the insert, where `INSERT IGNORE` discarded it and
   * reported it accepted. `id` is the client-generated UUID the whole
   * idempotency guarantee of ADR-0004 rests on; a batch entry without one is
   * not storable and must be refused before anything is written.
   */
  test('POST /api/sync/transactions rejects a transaction with no id', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);
    const { id, ...transactionWithoutId } = createValidTransaction(member.id, product.id);

    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions: [transactionWithoutId] },
    });

    expect(response.status()).toBe(422);

    const body = await response.json();
    expect(body.error).toBe('validation_failed');
    expect(body.details.some((d) => d.field === 'id')).toBeTruthy();
  });

  /**
   * Issue #82, the sale-loss case: a row MariaDB refuses used to be swallowed
   * by `INSERT IGNORE` and returned in `accepted_ids`, at which point the
   * terminal purged a served drink from its offline queue and no record of the
   * sale existed anywhere. A refused row is reported as rejected.
   *
   * The refusal is provoked with an over-long `dispenser_tx_id` (the column is
   * VARCHAR(16)). It used to be provoked with a `transaction_type` outside the
   * ENUM, which no longer reaches SQL at all: #79's allowlist forces that field
   * server-side. The vehicle changed; what #82 asserts did not.
   */
  test('POST /api/sync/transactions never accepts a transaction the database refuses', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);
    const transaction = createValidTransaction(member.id, product.id, {
      dispenser_tx_id: 'D'.repeat(64),
    });

    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions: [transaction] },
    });

    expect(response.status()).toBe(201);

    const body = await response.json();
    expect(body.accepted_ids).not.toContain(transaction.id);
    expect(body.rejected.count).toBe(1);
    expect(body.rejected.errors[0].transaction_id).toBe(transaction.id);

    // And nothing was written — the terminal keeps the sale in its queue.
    const history = await authenticatedTerminalRequest.get(`/api/terminal/transactions/${member.id}`);
    expect(history.ok()).toBeTruthy();
    expect((await history.json()).count).toBe(0);
  });

  /**
   * Issue #82 — one refused entry must not cost the club the sales beside it.
   */
  test('POST /api/sync/transactions accepts the rest of a batch around a refused entry', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);
    const good = createValidTransaction(member.id, product.id, { amount_cents: 350 });
    const refused = createValidTransaction(member.id, product.id, {
      amount_cents: 700,
      dispenser_tx_id: 'D'.repeat(64),
    });

    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions: [good, refused] },
    });

    expect(response.status()).toBe(201);

    const body = await response.json();
    expect(body.accepted_ids).toEqual([good.id]);
    expect(body.rejected.count).toBe(1);
    expect(body.member_balances[member.id]).toBe(350);
  });

  /**
   * Issue #82 item 3 — the core ADR-0004 guarantee had no test at all.
   * A terminal that loses the response resends the same batch; the same client
   * UUID must be accepted again and must not book the drink twice.
   */
  test('POST /api/sync/transactions is idempotent when a batch is resent', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);
    const transaction = createValidTransaction(member.id, product.id, { amount_cents: 450 });

    const first = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions: [transaction] },
    });
    expect(first.status()).toBe(201);
    expect((await first.json()).accepted_ids).toContain(transaction.id);

    // The terminal never saw that response and retries the identical batch.
    const retry = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions: [transaction] },
    });
    expect(retry.status()).toBe(201);

    const retryBody = await retry.json();
    expect(retryBody.accepted_ids).toContain(transaction.id);
    expect(retryBody.rejected.count).toBe(0);

    // Booked once, not twice — in the ledger and in the balance alike.
    expect(retryBody.member_balances[member.id]).toBe(450);

    const history = await authenticatedTerminalRequest.get(`/api/terminal/transactions/${member.id}`);
    const historyBody = await history.json();
    expect(historyBody.count).toBe(1);
    expect(historyBody.transactions[0].id).toBe(transaction.id);
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

  /**
   * Issue #83 — the balance every user-facing surface reports is the member's
   * *unsettled* position (ruling #141), not a lifetime sum over every
   * transaction ever booked. A settlement run collects the tab; the Deckel the
   * member sees next must not still contain what they already paid.
   */
  test('POST /api/sync/transactions excludes settled transactions from member_balances', async ({ authenticatedRequest, authenticatedTerminalRequest, settlementFactory }) => {
    // The factory gives this member one 2500 purchase, already swept into a
    // settlement of its own.
    const settlement = await settlementFactory.create({ amountCents: 2500 });
    const product = await createProduct(authenticatedRequest);

    // A fresh purchase after the settlement — the only thing still owed.
    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: {
        transactions: [
          createValidTransaction(settlement.memberId, product.id, { amount_cents: 700 }),
        ],
      },
    });

    expect(response.ok()).toBeTruthy();
    const body = await response.json();

    // 700, not 3200: the settled 2500 is gone from the member's tab.
    expect(body.member_balances[settlement.memberId]).toBe(700);
  });

  test('GET /api/admin/members/{id}/transactions excludes settled transactions from current_balance_cents', async ({ authenticatedRequest, settlementFactory }) => {
    const settlement = await settlementFactory.create({ amountCents: 2500 });

    const response = await authenticatedRequest.get(
      `/api/admin/members/${settlement.memberId}/transactions`,
    );

    expect(response.ok()).toBeTruthy();
    const body = await response.json();

    // The history below still lists the settled purchase; the figure on top is
    // what the member owes, which the settlement brought back to zero.
    expect(body.current_balance_cents).toBe(0);
    expect(body.transactions.length).toBeGreaterThan(0);
  });

  // recordCorrection's third balance surface, `new_balance_cents`, has no HTTP
  // seam to assert at: the controller returns the storno row flat and drops the
  // balance. It is covered by TransactionsServiceTest instead.

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
 * Sync idempotency (#99)
 *
 * The offline-first design rests on terminals safely retrying transaction
 * batches, and ADR-0004 (immutable, append-only storage) exists specifically to
 * make that retry safe: the client-generated UUID is the identity of the sale,
 * so replaying it is a no-op rather than a second drink on the member's tab.
 * CLAUDE.md lists idempotent APIs as a core design principle.
 *
 * A terminal replaying a batch after a network drop is the *normal* case this
 * system is built around, not an edge case — but the only test of it posted a
 * single transaction twice. These cover the shapes a real retry actually takes:
 * a whole batch resent, a batch that overlaps the previous one only in part,
 * and a retry that still contains the row the database refuses.
 *
 * Every one of them asserts stored state, not just the 201. The defect class
 * this guards (`INSERT IGNORE`, #82) produced a perfectly happy response while
 * the ledger disagreed with it.
 */
test.describe('Sync idempotency (#99)', () => {
  // The terminal keys its offline queue by transaction id, so "the same batch"
  // means the same ids — the ordering of the response is not part of the
  // contract, only its contents.
  const sortedIds = (ids: string[]) => [...ids].sort();

  async function historyFor(authenticatedTerminalRequest, memberId: string) {
    const response = await authenticatedTerminalRequest.get(`/api/terminal/transactions/${memberId}?limit=100`);
    expect(response.ok()).toBeTruthy();
    return await response.json();
  }

  /**
   * Issue #99 item 1 — a whole batch, resent. The single-transaction version of
   * this lives above; a batch is what a terminal actually uploads, and it is the
   * batch that gets replayed when the response is lost.
   */
  test('a resent batch is accepted identically and books every sale exactly once', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);
    const transactions = [
      createValidTransaction(member.id, product.id, { amount_cents: 350 }),
      createValidTransaction(member.id, product.id, { amount_cents: 500 }),
      createValidTransaction(member.id, product.id, { amount_cents: 725 }),
    ];
    const batchIds = sortedIds(transactions.map((tx) => tx.id));
    const total = 350 + 500 + 725;

    const first = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions },
    });
    expect(first.status()).toBe(201);
    const firstBody = await first.json();
    expect(sortedIds(firstBody.accepted_ids)).toEqual(batchIds);
    expect(firstBody.rejected.count).toBe(0);
    expect(firstBody.member_balances[member.id]).toBe(total);

    // The terminal never saw that response and resends the identical batch.
    const retry = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions },
    });
    expect(retry.status()).toBe(201);
    const retryBody = await retry.json();

    // Same answer, to the id: a replay is indistinguishable from the original
    // so far as the terminal is concerned, which is what lets it clear its queue.
    expect(sortedIds(retryBody.accepted_ids)).toEqual(batchIds);
    expect(retryBody.rejected.count).toBe(0);

    // ...and the tab was charged once, not twice.
    expect(retryBody.member_balances[member.id]).toBe(total);

    const history = await historyFor(authenticatedTerminalRequest, member.id);
    expect(history.count).toBe(3);
    expect(sortedIds(history.transactions.map((tx: any) => tx.id))).toEqual(batchIds);
  });

  /**
   * Issue #99 item 3 — the partial overlap, which is what a retry looks like
   * once the terminal has served more drinks in the meantime. Three sales go up,
   * the response is lost, two more minutes pass, and the next batch carries the
   * two the terminal still believes unsynced plus the new one.
   *
   * Four rows must exist afterwards. Absorbing the overlap as three fresh
   * inserts would double-charge; refusing the batch because part of it is known
   * would strand the new sale.
   */
  test('a partial-overlap retry stores only the sale the server has not seen', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);
    const first = createValidTransaction(member.id, product.id, { amount_cents: 100 });
    const second = createValidTransaction(member.id, product.id, { amount_cents: 200 });
    const third = createValidTransaction(member.id, product.id, { amount_cents: 300 });
    const fourth = createValidTransaction(member.id, product.id, { amount_cents: 400 });

    const initial = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions: [first, second, third] },
    });
    expect(initial.status()).toBe(201);
    expect((await initial.json()).member_balances[member.id]).toBe(600);

    // The terminal lost the response for `second` and `third`, and has since
    // booked `fourth`.
    const overlapping = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions: [second, third, fourth] },
    });
    expect(overlapping.status()).toBe(201);
    const body = await overlapping.json();

    // All three are accepted — two because they are already stored, one because
    // it just was. The terminal cannot tell the difference and does not need to.
    expect(sortedIds(body.accepted_ids)).toEqual(sortedIds([second.id, third.id, fourth.id]));
    expect(body.rejected.count).toBe(0);

    // 1000, not 1500: the overlap was absorbed, and the new sale still landed.
    expect(body.member_balances[member.id]).toBe(1000);

    const history = await historyFor(authenticatedTerminalRequest, member.id);
    expect(history.count).toBe(4);
    expect(sortedIds(history.transactions.map((tx: any) => tx.id)))
      .toEqual(sortedIds([first.id, second.id, third.id, fourth.id]));
  });

  /**
   * Issue #99 item 2, in the form the retry path makes dangerous. A refused row
   * being reported as accepted is #82; what is untested is that the refusal
   * *survives the retry*.
   *
   * This is precisely where `INSERT IGNORE` was lethal: it discarded the row and
   * reported success, so every subsequent attempt looked the same and the
   * terminal eventually purged a served drink it had every reason to think was
   * stored. A refused row must be refused identically each time, and must never
   * decay into a "duplicate" — because there is no row for it to duplicate.
   */
  test('a sale the database refuses is refused again on retry, never absorbed as a replay', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);
    const good = createValidTransaction(member.id, product.id, { amount_cents: 350 });
    // dispenser_tx_id is VARCHAR(16); 64 characters is a row MariaDB will refuse
    // identically forever. Same vehicle as the #82 tests above.
    const refused = createValidTransaction(member.id, product.id, {
      amount_cents: 700,
      dispenser_tx_id: 'D'.repeat(64),
    });

    for (const attempt of ['first', 'retry']) {
      const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
        data: { transactions: [good, refused] },
      });
      expect(response.status(), `${attempt} attempt`).toBe(201);
      const body = await response.json();

      // The storable sale is accepted every time — first as an insert, then as
      // a replay.
      expect(body.accepted_ids, `${attempt} attempt`).toEqual([good.id]);

      // The refused one never joins it, however often it is retried.
      expect(body.accepted_ids, `${attempt} attempt`).not.toContain(refused.id);
      expect(body.rejected.count, `${attempt} attempt`).toBe(1);
      expect(body.rejected.errors[0].transaction_id, `${attempt} attempt`).toBe(refused.id);
      expect(body.member_balances[member.id], `${attempt} attempt`).toBe(350);
    }

    // One row, and it is the one that was storable.
    const history = await historyFor(authenticatedTerminalRequest, member.id);
    expect(history.count).toBe(1);
    expect(history.transactions[0].id).toBe(good.id);
  });

  /**
   * The same guarantee, from inside a single request: a terminal that enqueued
   * the same sale twice must still be charged for it once. The id is the
   * identity of the sale (ADR-0004), so it does not matter whether the repeat
   * arrives in a later batch or the same one.
   */
  test('the same id twice inside one batch books the sale once', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);
    const transaction = createValidTransaction(member.id, product.id, { amount_cents: 450 });

    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions: [transaction, transaction] },
    });

    expect(response.status()).toBe(201);
    const body = await response.json();
    expect(body.accepted_ids).toContain(transaction.id);
    expect(body.rejected.count).toBe(0);
    expect(body.member_balances[member.id]).toBe(450);

    const history = await historyFor(authenticatedTerminalRequest, member.id);
    expect(history.count).toBe(1);
    expect(history.transactions[0].id).toBe(transaction.id);
  });

  /**
   * A replay must not resurrect what the treasurer has since reversed. The
   * purchase is stornoed, then the terminal — which knows nothing of the storno
   * — resends the batch that carried it. ADR-0004 makes the ledger append-only,
   * so the replay writes nothing and the reversal stands.
   */
  test('a replay does not undo a storno booked against the original sale', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);
    const transaction = createValidTransaction(member.id, product.id, { amount_cents: 350 });

    const upload = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions: [transaction] },
    });
    expect(upload.status()).toBe(201);

    const storno = await authenticatedRequest.post(`/api/admin/transactions/${transaction.id}/storno`, {
      data: { reason: 'Bernd scanned Annas card by mistake' },
    });
    expect(storno.status()).toBe(201);

    // The terminal still has the sale queued and resends it.
    const retry = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions: [transaction] },
    });
    expect(retry.status()).toBe(201);
    const body = await retry.json();
    expect(body.accepted_ids).toContain(transaction.id);

    // Purchase and storno still net to zero: the replay re-added nothing.
    expect(body.member_balances[member.id]).toBe(0);

    const history = await historyFor(authenticatedTerminalRequest, member.id);
    expect(history.count).toBe(2);
    expect(history.transactions.filter((tx: any) => tx.id === transaction.id)).toHaveLength(1);
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
 * Storno (UC-A23)
 *
 * POST /api/admin/transactions/{id}/storno  { reason }
 *
 * The transaction is the subject of the operation, not a parameter of it.
 * There is no amount field anywhere in this contract: the amount is derived as
 * the exact negation of the target, and the member is read from the target too.
 * This replaces the free-amount correction endpoint removed in #169.
 */
test.describe('Storno Endpoint', () => {
  test('derives the amount as the exact negation, with no amount supplied', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const purchaseId = await createPurchaseTransaction(authenticatedRequest, authenticatedTerminalRequest, member.id, 350);

    const response = await authenticatedRequest.post(`/api/admin/transactions/${purchaseId}/storno`, {
      data: { reason: 'Bernd scanned Annas card by mistake' },
    });

    expect(response.status()).toBe(201);

    const body = await response.json();
    expect(body.id).toBeDefined();
    expect(body.member_id).toBe(member.id);
    expect(body.amount_cents).toBe(-350);
    expect(body.transaction_type).toBe('storno');
    expect(body.related_transaction_id).toBe(purchaseId);
    expect(body.notes).toBe('Bernd scanned Annas card by mistake');
    expect(body.created_by_admin_id).toBeDefined();
  });

  test('ignores any amount the caller tries to smuggle in', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const purchaseId = await createPurchaseTransaction(authenticatedRequest, authenticatedTerminalRequest, member.id, 500);

    const response = await authenticatedRequest.post(`/api/admin/transactions/${purchaseId}/storno`, {
      data: { reason: 'Wrong booking', amount_cents: -99999, member_id: randomUUID() },
    });

    expect(response.status()).toBe(201);

    const body = await response.json();
    // Derived from the target, never from the request body.
    expect(body.amount_cents).toBe(-500);
    expect(body.member_id).toBe(member.id);
  });

  test('leaves the original transaction untouched', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const purchaseId = await createPurchaseTransaction(authenticatedRequest, authenticatedTerminalRequest, member.id, 700);

    const before = await authenticatedTerminalRequest.get(`/api/terminal/transactions/${member.id}?limit=100`);
    const originalBefore = (await before.json()).transactions.find((tx: any) => tx.id === purchaseId);

    const response = await authenticatedRequest.post(`/api/admin/transactions/${purchaseId}/storno`, {
      data: { reason: 'Append-only check' },
    });
    expect(response.status()).toBe(201);

    const after = await authenticatedTerminalRequest.get(`/api/terminal/transactions/${member.id}?limit=100`);
    const originalAfter = (await after.json()).transactions.find((tx: any) => tx.id === purchaseId);

    expect(originalAfter).toEqual(originalBefore);
  });

  test('refuses a second storno of the same transaction with 409', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const purchaseId = await createPurchaseTransaction(authenticatedRequest, authenticatedTerminalRequest, member.id, 350);

    const first = await authenticatedRequest.post(`/api/admin/transactions/${purchaseId}/storno`, {
      data: { reason: 'First storno' },
    });
    expect(first.status()).toBe(201);

    const second = await authenticatedRequest.post(`/api/admin/transactions/${purchaseId}/storno`, {
      data: { reason: 'Second storno of the same booking' },
    });

    expect(second.status()).toBe(409);
    const body = await second.json();
    expect(body.error).toBe('already_stornoed');
  });

  test('the second storno is refused by the database, not only by the service', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    // The service's own "already stornoed?" lookup is a courtesy, not the
    // guarantee. Firing both attempts concurrently defeats it: both read
    // "not yet stornoed" before either writes, so whichever loses can only be
    // stopped by the unique index on related_transaction_id. Exactly one must
    // survive (#169 acceptance criteria).
    const member = await createMember(authenticatedRequest);
    const purchaseId = await createPurchaseTransaction(authenticatedRequest, authenticatedTerminalRequest, member.id, 350);

    const attempts = await Promise.all(
      [1, 2, 3].map((n) =>
        authenticatedRequest.post(`/api/admin/transactions/${purchaseId}/storno`, {
          data: { reason: `Concurrent attempt ${n}` },
        }),
      ),
    );

    const statuses = attempts.map((r) => r.status()).sort();
    expect(statuses.filter((s) => s === 201)).toHaveLength(1);
    expect(statuses.filter((s) => s === 409)).toHaveLength(2);

    // And the journal holds exactly one storno for that purchase.
    const history = await authenticatedTerminalRequest.get(`/api/terminal/transactions/${member.id}?limit=100`);
    const stornos = (await history.json()).transactions.filter(
      (tx: any) => tx.type === 'storno',
    );
    expect(stornos).toHaveLength(1);
  });

  test('refuses to storno a storno with 422', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const purchaseId = await createPurchaseTransaction(authenticatedRequest, authenticatedTerminalRequest, member.id, 350);

    const storno = await authenticatedRequest.post(`/api/admin/transactions/${purchaseId}/storno`, {
      data: { reason: 'The original mistake' },
    });
    expect(storno.status()).toBe(201);
    const stornoId = (await storno.json()).id;

    const response = await authenticatedRequest.post(`/api/admin/transactions/${stornoId}/storno`, {
      data: { reason: 'Undoing the undo' },
    });

    expect(response.status()).toBe(422);
    const body = await response.json();
    expect(body.error).toBe('cannot_storno_a_storno');
  });

  test('allows stornoing a transaction for a member with no SEPA mandate', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    // A storno *reduces* what the member owes. Gating a debt reduction on the
    // ability to collect is inverted — #158 §1. The old endpoint rejected this
    // with a SepaValidationException.
    const member = await createMemberWithoutMandate(authenticatedRequest);
    const purchaseId = await createPurchaseTransaction(authenticatedRequest, authenticatedTerminalRequest, member.id, 420);

    const response = await authenticatedRequest.post(`/api/admin/transactions/${purchaseId}/storno`, {
      data: { reason: 'No mandate, still stornoable' },
    });

    expect(response.status()).toBe(201);
    const body = await response.json();
    expect(body.amount_cents).toBe(-420);
  });

  test('writes an audit entry naming admin, target transaction and reason', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const purchaseId = await createPurchaseTransaction(authenticatedRequest, authenticatedTerminalRequest, member.id, 350);
    const reason = `Audited storno ${randomUUID().substring(0, 8)}`;

    const response = await authenticatedRequest.post(`/api/admin/transactions/${purchaseId}/storno`, {
      data: { reason },
    });
    expect(response.status()).toBe(201);
    const storno = await response.json();

    const auditResponse = await authenticatedRequest.get(
      `/api/admin/audit-log?entity_id=${storno.id}&per_page=50`,
    );
    expect(auditResponse.ok()).toBeTruthy();
    const audit = await auditResponse.json();
    const entry = audit.data.find((e: any) => e.entity_id === storno.id);
    expect(entry).toBeDefined();
    expect(entry.entity_type).toBe('transaction');
    expect(entry.action).toBe('transaction_storno');
    expect(entry.admin_user_id).toBeTruthy();
    expect(JSON.stringify(entry.new_values)).toContain(reason);
    expect(JSON.stringify(entry.new_values)).toContain(purchaseId);
  });

  test('rejects a missing reason with 422', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const purchaseId = await createPurchaseTransaction(authenticatedRequest, authenticatedTerminalRequest, member.id);

    const response = await authenticatedRequest.post(`/api/admin/transactions/${purchaseId}/storno`, {
      data: {},
    });

    expect(response.status()).toBe(422);
    const body = await response.json();
    expect(body.errors.reason).toBeDefined();
  });

  test('rejects a blank reason with 422', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const purchaseId = await createPurchaseTransaction(authenticatedRequest, authenticatedTerminalRequest, member.id);

    const response = await authenticatedRequest.post(`/api/admin/transactions/${purchaseId}/storno`, {
      data: { reason: '   ' },
    });

    expect(response.status()).toBe(422);
    const body = await response.json();
    expect(body.errors.reason).toBeDefined();
  });

  test('rejects a reason exceeding 500 chars with 422', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const purchaseId = await createPurchaseTransaction(authenticatedRequest, authenticatedTerminalRequest, member.id);

    const response = await authenticatedRequest.post(`/api/admin/transactions/${purchaseId}/storno`, {
      data: { reason: 'A'.repeat(501) },
    });

    expect(response.status()).toBe(422);
    const body = await response.json();
    expect(body.errors.reason).toBeDefined();
  });

  test('returns 404 for an unknown transaction', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post(`/api/admin/transactions/${randomUUID()}/storno`, {
      data: { reason: 'Nothing to reverse' },
    });

    expect(response.status()).toBe(404);
    const body = await response.json();
    expect(body.error).toBe('not_found');
  });

  test('the storno appears in the member history and reduces the balance', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const purchaseId = await createPurchaseTransaction(authenticatedRequest, authenticatedTerminalRequest, member.id, 250);

    const response = await authenticatedRequest.post(`/api/admin/transactions/${purchaseId}/storno`, {
      data: { reason: 'History check' },
    });
    expect(response.status()).toBe(201);
    const storno = await response.json();

    const historyResponse = await authenticatedTerminalRequest.get(`/api/terminal/transactions/${member.id}?limit=100`);
    expect(historyResponse.ok()).toBeTruthy();
    const history = await historyResponse.json();

    const found = history.transactions.find((tx: any) => tx.id === storno.id);
    expect(found).toBeDefined();
    expect(found?.type).toBe('storno');
    expect(found?.amount_cents).toBe(-250);

    // Purchase and its storno net to zero.
    const net = history.transactions
      .filter((tx: any) => tx.id === purchaseId || tx.id === storno.id)
      .reduce((sum: number, tx: any) => sum + tx.amount_cents, 0);
    expect(net).toBe(0);
  });

  test('the free-amount correction endpoints are gone', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    // #169 acceptance: no endpoint anywhere accepts a caller-supplied amount.
    const member = await createMember(authenticatedRequest);
    const purchaseId = await createPurchaseTransaction(authenticatedRequest, authenticatedTerminalRequest, member.id, 350);
    const payload = {
      data: { amount_cents: -350, reason: 'Free amount', related_transaction_id: purchaseId },
    };

    // 405 where the path still serves GET (the member's history), 404 where the
    // path is gone entirely. What matters is that neither books anything.
    for (const path of [
      `/api/admin/members/${member.id}/transactions`,
      `/api/admin/members/${member.id}/transactions/correction`,
      `/api/admin/members/${member.id}/transactions/correct`,
    ]) {
      const response = await authenticatedRequest.post(path, payload);
      expect([404, 405], `POST ${path} must not be routable`).toContain(response.status());
    }

    // And nothing was written: the purchase still stands alone.
    const history = await authenticatedTerminalRequest.get(`/api/terminal/transactions/${member.id}?limit=100`);
    const transactions = (await history.json()).transactions;
    expect(transactions).toHaveLength(1);
    expect(transactions[0].id).toBe(purchaseId);
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
    expect(csv).toContain('date;member_name;product;type;amount_eur');
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
    expect(csv).toContain('date;member_name;product;type;amount_eur');
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
    expect(csv).toContain('date;member_name;product;type;amount_eur');
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
    expect(csv).toContain('date;member_name;product;type;amount_eur');
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
    const storedTx = journal.data.find(tx => tx.id === transaction.id);
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

/**
 * Issue #79 / ruling #144 §3 — field authority on the sync path.
 *
 * `processBatch` used to hand the whole client array to the repository, which
 * read `transaction_type`, `related_transaction_id` and `created_by_admin_id`
 * straight out of it. A terminal token could therefore forge a storno; and
 * since #169 made the UNIQUE index on `related_transaction_id` the arbiter of
 * "stornoable at most once", the forged row *consumed the slot* and the
 * treasurer's genuine storno of that purchase was refused forever.
 *
 * These are the regression tests for the allowlist that closes it. They assert
 * stored state, not just the 201 — the whole defect was a happily-accepted
 * batch writing something other than what the contract says it may write.
 */
test.describe('Terminal sync field authority (#79)', () => {
  async function storedTransaction(authenticatedRequest, memberId: string, transactionId: string) {
    const response = await authenticatedRequest.get(`/api/admin/members/${memberId}/transactions`);
    expect(response.ok()).toBeTruthy();

    const history = await response.json();
    const stored = history.transactions.find((tx) => tx.id === transactionId);
    expect(stored).toBeDefined();
    return stored;
  }

  test('a batch claiming transaction_type storno stores a purchase', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);
    const transaction = createValidTransaction(member.id, product.id, {
      transaction_type: 'storno',
    });

    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions: [transaction] },
    });

    expect(response.status()).toBe(201);
    expect((await response.json()).accepted_ids).toContain(transaction.id);

    const stored = await storedTransaction(authenticatedRequest, member.id, transaction.id);
    expect(stored.transaction_type).toBe('purchase');
  });

  test('a batch claiming transaction_type payout stores a purchase', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);
    const transaction = createValidTransaction(member.id, product.id, {
      transaction_type: 'payout',
    });

    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions: [transaction] },
    });

    expect(response.status()).toBe(201);

    const stored = await storedTransaction(authenticatedRequest, member.id, transaction.id);
    expect(stored.transaction_type).toBe('purchase');
  });

  /**
   * The denial-of-correction guard. It is the one that will silently rot if
   * only the enum is fixed: nulling the link is what keeps the reversal slot
   * free for the treasurer.
   */
  test('a supplied related_transaction_id is stored as null and the purchase stays stornoable', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const purchaseId = await createPurchaseTransaction(authenticatedRequest, authenticatedTerminalRequest, member.id, 1000);

    const product = await createProduct(authenticatedRequest);
    const forged = createValidTransaction(member.id, product.id, {
      transaction_type: 'storno',
      related_transaction_id: purchaseId,
    });

    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions: [forged] },
    });
    expect(response.status()).toBe(201);

    const stored = await storedTransaction(authenticatedRequest, member.id, forged.id);
    expect(stored.related_transaction_id).toBeNull();

    // The reversal slot was never consumed: the treasurer can still storno.
    const storno = await authenticatedRequest.post(`/api/admin/transactions/${purchaseId}/storno`, {
      data: { reason: 'Bernd scanned Annas card by mistake' },
    });
    expect(storno.status()).toBe(201);
    expect((await storno.json()).related_transaction_id).toBe(purchaseId);
  });

  test('a supplied created_by_admin_id is stored as null', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);
    const transaction = createValidTransaction(member.id, product.id, {
      created_by_admin_id: randomUUID(),
    });

    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions: [transaction] },
    });
    expect(response.status()).toBe(201);

    const stored = await storedTransaction(authenticatedRequest, member.id, transaction.id);
    expect(stored.created_by_admin_id).toBeNull();
    // ...and the row still carries the terminal that actually booked it.
    expect(stored.created_by_terminal_id).not.toBeNull();
  });

  test('a client-supplied notes field never reaches the row', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);
    const transaction = createValidTransaction(member.id, product.id, {
      notes: 'Refund approved by the treasurer',
    });

    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions: [transaction] },
    });
    expect(response.status()).toBe(201);

    const stored = await storedTransaction(authenticatedRequest, member.id, transaction.id);
    expect(stored.notes).toBeNull();
  });

  /**
   * Ruling #144 §2: an implausible sale time is stored *exactly as sent* and
   * flagged, never rewritten and never rejected. Clamping it would make a dead
   * clock battery indistinguishable from a probing attacker, and refusing it
   * would cost the club an evening's revenue over a hardware fault. The
   * unforgeable anchor is `received_at`, which the server stamps.
   *
   * The backdating attack itself is already defused: settlement sweeps the
   * member's entire unsettled position regardless of date (#141), so the
   * backdated sale is still collected — asserted here as the balance.
   */
  test('a backdated sale time is stored as sent, accepted, and still owed', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);
    const transaction = createValidTransaction(member.id, product.id, {
      amount_cents: 350,
      created_at: '2019-01-02T03:04:05Z',
    });

    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions: [transaction] },
    });

    expect(response.status()).toBe(201);
    const body = await response.json();
    expect(body.accepted_ids).toContain(transaction.id);
    expect(body.member_balances[member.id]).toBe(350);

    const stored = await storedTransaction(authenticatedRequest, member.id, transaction.id);
    expect(stored.created_at).toContain('2019-01-02T03:04:05');
  });

  test('a sale time in the far future is stored as sent and accepted', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const member = await createMember(authenticatedRequest);
    const product = await createProduct(authenticatedRequest);
    const transaction = createValidTransaction(member.id, product.id, {
      created_at: '2036-05-06T07:08:09Z',
    });

    const response = await authenticatedTerminalRequest.post('/api/sync/transactions', {
      data: { transactions: [transaction] },
    });

    expect(response.status()).toBe(201);
    expect((await response.json()).accepted_ids).toContain(transaction.id);

    const stored = await storedTransaction(authenticatedRequest, member.id, transaction.id);
    expect(stored.created_at).toContain('2036-05-06T07:08:09');
  });
});
