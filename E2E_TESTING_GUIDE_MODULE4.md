# Module 4 E2E Testing Guide - Phase 6

This guide provides a template and patterns for creating the 30 E2E tests for the Settlements module.

## Test Structure Template

```typescript
import { test, expect } from '@playwright/test';

test.describe('Settlements API', () => {
  // Helper: Create authenticated request
  const authenticatedRequest = async (request: any, method: string, path: string, data?: any) => {
    // 1. Login as admin
    const loginResponse = await request.post('/api/auth/login', {
      data: { email: 'admin@test.example.com', password: 'InitialPassword123!' }
    });
    expect(loginResponse.ok()).toBeTruthy();

    // 2. Make authenticated request with session
    const response = await request[method.toLowerCase()](path, { data });
    return response;
  };

  // Test groups follow...
});
```

## A. SEPA Config Tests (5 tests)

### Test 1: GET /sepa-config returns config with masked fields
```typescript
test('GET /api/admin/sepa-config returns config with masked fields', async ({ request }) => {
  // Setup: Create or use existing SEPA config

  // Action: GET /api/admin/sepa-config
  const response = await authenticatedRequest(request, 'GET', '/api/admin/sepa-config');

  // Assert:
  expect(response.status()).toBe(200);
  const body = await response.json();
  expect(body).toHaveProperty('creditor_id'); // Masked: "DE01****9999"
  expect(body).toHaveProperty('is_configured'); // boolean
  expect(body.creditor_id).toMatch(/^[A-Z]{2}\d{2}\*{4}\d{4}$/); // Masked format
});
```

### Test 2: PUT /sepa-config updates successfully
```typescript
test('PUT /api/admin/sepa-config updates successfully', async ({ request }) => {
  // Action: PUT with new creditor details
  const response = await authenticatedRequest(request, 'PUT', '/api/admin/sepa-config', {
    creditor_id: 'DE01ZZZ09999999999',
    creditor_name: 'Test Organization',
    creditor_iban: 'DE89370400440532013000',
    creditor_address_street: '123 Test Street',
    creditor_address_city: 'Berlin',
    creditor_address_country: 'DE'
  });

  // Assert: Returns 200 with masked fields
  expect(response.status()).toBe(200);
  const body = await response.json();
  expect(body.creditor_name).toBe('Test Organization');
  expect(body.is_configured).toBe(true);
});
```

### Test 3: PUT /sepa-config rejects invalid IBAN checksum
```typescript
test('PUT /sepa-config rejects invalid IBAN checksum', async ({ request }) => {
  // Action: PUT with invalid IBAN
  const response = await authenticatedRequest(request, 'PUT', '/api/admin/sepa-config', {
    creditor_iban: 'DE00370400440532013000' // Invalid checksum
  });

  // Assert: Returns 422 with validation error
  expect(response.status()).toBe(422);
  const body = await response.json();
  expect(body.messages.creditor_iban).toBeTruthy();
});
```

### Test 4: PUT /sepa-config rejects creditor_id change (immutability)
```typescript
test('PUT /sepa-config rejects creditor_id change', async ({ request }) => {
  // Setup: Config already has creditor_id set

  // Action: Try to change creditor_id
  const response = await authenticatedRequest(request, 'PUT', '/api/admin/sepa-config', {
    creditor_id: 'DE02DIFFERENT9999999999' // Different ID
  });

  // Assert: Returns 422 with immutability error
  expect(response.status()).toBe(422);
  const body = await response.json();
  expect(body.error).toBe('SEPA creditor ID cannot be changed once set');
});
```

### Test 5: PUT /sepa-config requires authentication
```typescript
test('PUT /sepa-config requires authentication', async ({ request }) => {
  // Action: PUT without authentication
  const response = await request.put('/api/admin/sepa-config', {
    data: { creditor_name: 'Test' }
  });

  // Assert: Returns 401 or redirects to login
  expect([401, 302]).toContain(response.status());
});
```

## B. Settlement Preview Tests (4 tests)

### Test 6: POST /settlements/preview returns eligible members
```typescript
test('POST /settlements/preview returns eligible members', async ({ request }) => {
  // Setup: Create members with IBAN + mandate_reference
  // Create unsettled transactions for those members

  // Action: POST /settlements/preview
  const response = await authenticatedRequest(request, 'POST', '/api/admin/settlements/preview', {
    from_date: '2026-01-01',
    to_date: '2026-01-31'
  });

  // Assert:
  expect(response.status()).toBe(200);
  const body = await response.json();
  expect(body.eligible_members.length).toBeGreaterThan(0);
  expect(body.eligible_total_cents).toBeGreaterThan(0);
  expect(body.member_count).toBeGreaterThan(0);
});
```

### Test 7: POST /settlements/preview excludes members without IBAN
```typescript
test('POST /settlements/preview excludes members without IBAN', async ({ request }) => {
  // Setup: Create member without IBAN, with transactions

  // Action: POST /settlements/preview
  const response = await authenticatedRequest(request, 'POST', '/api/admin/settlements/preview');

  // Assert: Member in ineligible_members list with warning
  expect(response.status()).toBe(200);
  const body = await response.json();
  expect(body.ineligible_members.some(m => m.id === memberId)).toBe(true);
  expect(body.warnings.some(w => w.includes('IBAN'))).toBe(true);
});
```

### Test 8: POST /settlements/preview excludes members without mandate_reference
```typescript
test('POST /settlements/preview excludes members without mandate', async ({ request }) => {
  // Similar to Test 7 but for mandate_reference
});
```

### Test 9: POST /settlements/preview calculates balances correctly
```typescript
test('POST /settlements/preview calculates balances correctly', async ({ request }) => {
  // Setup: Create member with known transactions
  // Example: 2 transactions of 1000 cents and 2000 cents = 3000 total

  // Action: POST /settlements/preview
  const response = await authenticatedRequest(request, 'POST', '/api/admin/settlements/preview');

  // Assert: Balance matches sum of transactions
  expect(response.status()).toBe(200);
  const body = await response.json();
  const member = body.eligible_members.find(m => m.id === memberId);
  expect(member.balance_cents).toBe(3000);
});
```

## C. Settlement Creation Tests (8 tests)

### Test 10: POST /settlements creates SEPA settlement
```typescript
test('POST /settlements creates SEPA settlement', async ({ request }) => {
  // Setup: Get transaction IDs to include
  const transactionIds = ['txn-uuid-1', 'txn-uuid-2'];

  // Action: POST /settlements with SEPA type
  const response = await authenticatedRequest(request, 'POST', '/api/admin/settlements', {
    settlement_type: 'sepa',
    transaction_ids: transactionIds,
    settlement_date: '2026-01-26',
    execution_date: '2026-02-02', // 7 days later
    period_start: '2026-01-01',
    period_end: '2026-01-25'
  });

  // Assert:
  expect(response.status()).toBe(201);
  const body = await response.json();
  expect(body.settlement_type).toBe('sepa');
  expect(body.sepa_message_id).toMatch(/^SET-\d{4}-\d{3}$/);
});
```

### Test 11: POST /settlements creates manual settlement with reason
```typescript
test('POST /settlements creates manual settlement', async ({ request }) => {
  // Action: POST with manual_type and manual_reason
  const response = await authenticatedRequest(request, 'POST', '/api/admin/settlements', {
    settlement_type: 'manual',
    transaction_ids: transactionIds,
    settlement_date: '2026-01-26',
    execution_date: '2026-02-02',
    manual_reason: 'cash'
  });

  // Assert:
  expect(response.status()).toBe(201);
  const body = await response.json();
  expect(body.settlement_type).toBe('manual');
  expect(body.manual_reason).toBe('cash');
});
```

### Test 12: POST /settlements rejects execution_date < settlement_date + 7
```typescript
test('POST /settlements rejects early execution_date', async ({ request }) => {
  // Action: POST with execution_date too early
  const response = await authenticatedRequest(request, 'POST', '/api/admin/settlements', {
    settlement_date: '2026-01-26',
    execution_date: '2026-01-28' // Only 2 days later, needs 7
  });

  // Assert: Returns 422
  expect(response.status()).toBe(422);
  const body = await response.json();
  expect(body.messages.execution_date).toBeTruthy();
});
```

### Test 13: POST /settlements requires transaction_ids
```typescript
test('POST /settlements requires transaction_ids', async ({ request }) => {
  const response = await authenticatedRequest(request, 'POST', '/api/admin/settlements', {
    settlement_type: 'sepa',
    transaction_ids: [], // Empty
    settlement_date: '2026-01-26',
    execution_date: '2026-02-02'
  });

  expect(response.status()).toBe(422);
});
```

### Test 14: POST /settlements marks transactions as settled
```typescript
test('POST /settlements marks transactions as settled', async ({ request }) => {
  // Setup: Get transaction IDs
  const transactionIds = ['txn-uuid-1', 'txn-uuid-2'];

  // Action: Create settlement
  const settleResponse = await authenticatedRequest(request, 'POST', '/api/admin/settlements', {
    settlement_type: 'sepa',
    transaction_ids: transactionIds,
    settlement_date: '2026-01-26',
    execution_date: '2026-02-02'
  });

  // Assert: Check database that transactions have settlement_id set
  // This would require a test database query or API to list transaction state
  expect(settleResponse.status()).toBe(201);
  const settlement = await settleResponse.json();
  expect(settlement.items.length).toBe(2);
});
```

### Test 15: POST /settlements calculates total_amount_cents correctly
```typescript
test('POST /settlements calculates total_amount_cents', async ({ request }) => {
  // Setup: Know exact amounts of transactions being settled

  // Action: Create settlement
  const response = await authenticatedRequest(request, 'POST', '/api/admin/settlements', {...});

  // Assert: total_amount_cents = sum of transaction amounts
  expect(response.status()).toBe(201);
  const body = await response.json();
  expect(body.total_amount_cents).toBe(5000); // Or whatever the correct sum is
});
```

### Test 16: POST /settlements calculates member_count correctly
```typescript
test('POST /settlements calculates member_count', async ({ request }) => {
  // Setup: Create 3 transactions for 2 different members

  // Action: Create settlement
  const response = await authenticatedRequest(request, 'POST', '/api/admin/settlements', {...});

  // Assert: member_count = 2
  expect(response.status()).toBe(201);
  const body = await response.json();
  expect(body.member_count).toBe(2);
});
```

## D. Settlement List/Details Tests (3 tests)

### Test 17: GET /settlements returns list
```typescript
test('GET /settlements returns paginated list', async ({ request }) => {
  // Setup: Create multiple settlements

  // Action: GET /settlements
  const response = await authenticatedRequest(request, 'GET', '/api/admin/settlements');

  // Assert:
  expect(response.status()).toBe(200);
  const body = await response.json();
  expect(body.data).toBeInstanceOf(Array);
  expect(body.pagination).toHaveProperty('total');
  expect(body.pagination).toHaveProperty('per_page');
});
```

### Test 18: GET /settlements filters by settlement_type
```typescript
test('GET /settlements filters by type', async ({ request }) => {
  // Action: GET /settlements?type=sepa
  const response = await authenticatedRequest(request, 'GET', '/api/admin/settlements?type=sepa');

  // Assert: All returned settlements are SEPA type
  expect(response.status()).toBe(200);
  const body = await response.json();
  body.data.forEach(s => {
    expect(s.settlement_type).toBe('sepa');
  });
});
```

### Test 19: GET /settlements/{id} returns settlement with items
```typescript
test('GET /settlements/{id} returns with items', async ({ request }) => {
  // Setup: Get settlement ID

  // Action: GET /settlements/[id]
  const response = await authenticatedRequest(request, 'GET', `/api/admin/settlements/${settlementId}`);

  // Assert:
  expect(response.status()).toBe(200);
  const body = await response.json();
  expect(body.id).toBe(settlementId);
  expect(body.items).toBeInstanceOf(Array);
  expect(body.items.length).toBeGreaterThan(0);
  expect(body.items[0]).toHaveProperty('member_id');
  expect(body.items[0]).toHaveProperty('amount_cents');
});
```

## E. Settlement Cancellation Tests (3 tests)

### Test 20: DELETE /settlements/{id} cancels settlement
```typescript
test('DELETE /settlements/{id} cancels settlement', async ({ request }) => {
  // Setup: Create settlement
  const settlementId = 'settlement-uuid';

  // Action: DELETE /settlements/{id}
  const response = await authenticatedRequest(request, 'DELETE', `/api/admin/settlements/${settlementId}`, {
    reason: 'Cancelled by mistake'
  });

  // Assert: Returns 204 No Content
  expect(response.status()).toBe(204);
});
```

### Test 21: DELETE /settlements/{id} unmarks transactions
```typescript
test('DELETE /settlements/{id} unmarks transactions', async ({ request }) => {
  // Setup: Create settlement with known transaction IDs

  // Action: Delete settlement
  const response = await authenticatedRequest(request, 'DELETE', `/api/admin/settlements/${settlementId}`);

  // Assert: Transactions now have settlement_id = NULL
  expect(response.status()).toBe(204);
  // Database verification would check settlement_id is NULL for those transactions
});
```

### Test 22: DELETE /settlements/{id} rejects if already exported
```typescript
test('DELETE /settlements/{id} rejects if exported', async ({ request }) => {
  // Setup: Create settlement, export it

  // Action: Try to delete exported settlement
  const response = await authenticatedRequest(request, 'DELETE', `/api/admin/settlements/${exportedId}`);

  // Assert: Returns 422
  expect(response.status()).toBe(422);
  const body = await response.json();
  expect(body.error).toContain('exported');
});
```

## F. SEPA XML Export Tests (4 tests)

### Test 23: GET /settlements/{id}/export-sepa generates valid XML
```typescript
test('GET /settlements/{id}/export-sepa generates valid XML', async ({ request }) => {
  // Setup: Create SEPA settlement

  // Action: GET /settlements/{id}/export-sepa
  const response = await authenticatedRequest(request, 'GET', `/api/admin/settlements/${sepaId}/export-sepa`);

  // Assert:
  expect(response.status()).toBe(200);
  expect(response.headers()['content-type']).toContain('application/xml');
  const xml = await response.text();
  expect(xml).toContain('<?xml');
  expect(xml).toContain('pain.008');
});
```

### Test 24: GET /settlements/{id}/export-sepa validates pain.008.001.02 format
```typescript
test('GET /settlements/{id}/export-sepa validates format', async ({ request }) => {
  // Action: Export SEPA settlement
  const response = await authenticatedRequest(request, 'GET', `/api/admin/settlements/${sepaId}/export-sepa`);

  // Assert: XML contains required SEPA elements
  expect(response.status()).toBe(200);
  const xml = await response.text();
  expect(xml).toContain('<MsgId>');           // Message ID
  expect(xml).toContain('<OrgnlInf>');        // Original info (creditor)
  expect(xml).toContain('<Drctns>');          // Directives
});
```

### Test 25: GET /settlements/{id}/export-sepa includes all items
```typescript
test('GET /settlements/{id}/export-sepa includes all items', async ({ request }) => {
  // Setup: Settlement with 3 items (debits for 3 members)

  // Action: Export
  const response = await authenticatedRequest(request, 'GET', `/api/admin/settlements/${sepaId}/export-sepa`);

  // Assert: XML contains payment instruction for each member
  expect(response.status()).toBe(200);
  const xml = await response.text();
  // Count <Drbt> elements (debtor records)
  const matches = xml.match(/<Drbt>/g);
  expect(matches?.length).toBe(3);
});
```

### Test 26: GET /settlements/{id}/export-sepa uses correct SEPA IDs
```typescript
test('GET /settlements/{id}/export-sepa uses correct IDs', async ({ request }) => {
  // Setup: Get settlement with known sepa_message_id

  // Action: Export
  const response = await authenticatedRequest(request, 'GET', `/api/admin/settlements/${sepaId}/export-sepa`);

  // Assert: XML MsgId matches settlement.sepa_message_id
  expect(response.status()).toBe(200);
  const xml = await response.text();
  expect(xml).toContain(`<MsgId>${expectedMessageId}</MsgId>`);
});
```

## G. CSV Export Tests (3 tests)

### Test 27: GET /settlements/{id}/export-csv generates CSV with semicolon delimiter
```typescript
test('GET /settlements/{id}/export-csv generates CSV', async ({ request }) => {
  // Setup: Create settlement with items

  // Action: GET /settlements/{id}/export-csv
  const response = await authenticatedRequest(request, 'GET', `/api/admin/settlements/${settlementId}/export-csv`);

  // Assert:
  expect(response.status()).toBe(200);
  expect(response.headers()['content-type']).toContain('text/csv');
  const csv = await response.text();
  expect(csv).toContain('Member Name;Email;IBAN;Amount EUR');
  expect(csv.split('\n').length).toBeGreaterThan(1);
});
```

### Test 28: GET /settlements/{id}/export-csv includes member names and amounts
```typescript
test('GET /settlements/{id}/export-csv includes names and amounts', async ({ request }) => {
  // Setup: Settlement with known member and amount

  // Action: Export CSV
  const response = await authenticatedRequest(request, 'GET', `/api/admin/settlements/${settlementId}/export-csv`);

  // Assert:
  expect(response.status()).toBe(200);
  const csv = await response.text();
  expect(csv).toContain('John Doe'); // Member name
  expect(csv).toContain('john@example.com'); // Email
  expect(csv).toContain('50.00'); // Amount in EUR
});
```

### Test 29: GET /settlements/{id}/export-csv formats amounts as EUR decimals
```typescript
test('GET /settlements/{id}/export-csv formats amounts correctly', async ({ request }) => {
  // Setup: Settlement with transaction of 1234 cents (12.34 EUR)

  // Action: Export CSV
  const response = await authenticatedRequest(request, 'GET', `/api/admin/settlements/${settlementId}/export-csv`);

  // Assert: Amount shown as 12.34 (not 1234, not 12,34)
  expect(response.status()).toBe(200);
  const csv = await response.text();
  expect(csv).toContain('12.34');
  expect(csv).not.toContain('1234');
});
```

## General Test (30)

### Test 30: E2E workflow - Create, Preview, Export
```typescript
test('E2E: Full settlement workflow', async ({ request }) => {
  // Setup: Create members, transactions

  // Step 1: Preview
  const preview = await authenticatedRequest(request, 'POST', '/api/admin/settlements/preview', {});
  expect(preview.status()).toBe(200);

  // Step 2: Create settlement
  const create = await authenticatedRequest(request, 'POST', '/api/admin/settlements', {
    settlement_type: 'sepa',
    transaction_ids: [...],
    settlement_date: '2026-01-26',
    execution_date: '2026-02-02'
  });
  expect(create.status()).toBe(201);
  const settlement = await create.json();

  // Step 3: Export XML
  const export_resp = await authenticatedRequest(request, 'GET', `/api/admin/settlements/${settlement.id}/export-sepa`);
  expect(export_resp.status()).toBe(200);
});
```

---

## Running Tests

```bash
cd e2etests

# Install deps
npm install

# Run all settlement tests
npm test -- tests/api/settlements.spec.ts

# Run specific test
npm test -- --grep "creates SEPA settlement"

# Run with 1 worker (serial) for debugging
npm test -- tests/api/settlements.spec.ts --workers=1

# Run with 4 workers (parallel) for CI
npm test -- tests/api/settlements.spec.ts --workers=4
```

---

## Test Data Setup Patterns

```typescript
// Helper: Create authenticated admin
async function loginAsAdmin(request) {
  const response = await request.post('/api/auth/login', {
    data: {
      email: 'admin@test.example.com',
      password: 'InitialPassword123!'
    }
  });
  return response;
}

// Helper: Create test member with IBAN
async function createMemberWithIban(request) {
  return await authenticatedRequest(request, 'POST', '/api/admin/members', {
    first_name: 'Test',
    last_name: `Member-${Date.now()}`,
    email: `member-${Date.now()}@test.example.com`,
    iban: 'DE89370400440532013000',
    mandate_reference: 'MANDATE' + Date.now(),
    mandate_signed_at: '2025-01-01'
  });
}

// Helper: Create test transaction
async function createTransaction(request, memberId) {
  return await authenticatedRequest(request, 'POST', '/api/admin/transactions', {
    member_id: memberId,
    amount_cents: 1000,
    created_at: new Date().toISOString()
  });
}
```

---

## Success Criteria

- ✅ All 30 tests pass
- ✅ Tests run in parallel (4 workers) without flakiness
- ✅ All endpoint combinations covered (POST, GET, DELETE with various params)
- ✅ Success and failure cases tested
- ✅ Data validation tested (IBAN checksum, dates, amounts)
- ✅ Business rules validated (7-day minimum, SEPA eligibility, immutability)
