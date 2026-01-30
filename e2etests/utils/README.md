# E2E Test Utilities

## Overview

Test utilities provide reusable functions and fixtures for creating test data (members, transactions, settlements) in E2E tests.

Implements E2E Testing Patterns:
- **Pattern 001**: Test Data Isolation (via timestamps)
- **Pattern 003**: Database-Agnostic Assertions (via test data builders)
- **Pattern 007**: Page Object Fixtures (extended with `testTransactions`)

## Files

### `transactions.ts`

Pure TypeScript utilities for building test data structures. No API calls.

```typescript
// Build test data (no API calls)
const memberData = createTestMember('John', 'Doe', 'john')
const txnData = createSyncTransaction(memberId, 2500, 'Test transaction')
const correction = createCorrection(1000, 'Adjustment', 'adjustment')
const settlement = createSettlement([txn1Id, txn2Id], 7)
```

**Functions:**
- `generateUUID()` - Generate UUID v4 for transaction IDs
- `createTestMember(firstName, lastName, baseEmail)` - Build member data with isolation
- `createSyncTransaction(memberId, amountCents, notes)` - Build sync transaction data
- `createCorrection(amountCents, notes, reason)` - Build correction data
- `createSettlement(transactionIds, daysFromNow)` - Build settlement data

### `fixtures/auth.fixture.ts` (extended)

Playwright fixture that provides `testTransactions` - convenient API methods for creating test data via authenticated API calls.

**Extends** the existing authentication fixture with:

```typescript
// In test function parameters, use testTransactions fixture
test('my test', async ({ testTransactions }) => {
  // All methods handle authentication automatically
  const member = await testTransactions.createMember('John', 'Doe')
  const txnId = await testTransactions.createSyncTransaction(member.id, 2500)
  const corrId = await testTransactions.createCorrection(member.id, 1000)
  const settlementId = await testTransactions.createSettlement([txnId, corrId])
})
```

**Methods:**
- `createMember(firstName?, lastName?, baseEmail?)` → member object with ID
- `createSyncTransaction(memberId, amountCents?, notes?)` → transaction ID
- `createCorrection(memberId, amountCents?, notes?, reason?)` → transaction ID
- `createSettlement(transactionIds, daysFromNow?)` → settlement ID

All methods:
- Handle authentication automatically (session + bearer token)
- Throw descriptive errors if API calls fail
- Return created object IDs (or full objects for members)

## Usage Examples

### Example 1: Simple Transaction Creation

```typescript
test('member can view their balance', async ({ page, testTransactions }) => {
  // Create member and transaction
  const member = await testTransactions.createMember('Alice', 'Smith')
  await testTransactions.createSyncTransaction(member.id, 5000, 'Beer')

  // Navigate and verify
  await page.goto('/members')
  expect(page.content()).toContain('50.00') // €50.00
})
```

### Example 2: Multi-Step Settlement Workflow

```typescript
test('settlement exports correct CSV', async ({ testTransactions, authenticatedRequest }) => {
  // Create test data
  const member = await testTransactions.createMember('Bob', 'Jones')
  const txn1 = await testTransactions.createSyncTransaction(member.id, 2500)
  const txn2 = await testTransactions.createCorrection(member.id, 1500)

  // Create settlement
  const settlementId = await testTransactions.createSettlement([txn1, txn2], 7)

  // Verify via API
  const csvResponse = await authenticatedRequest.get(
    `/api/admin/settlements/${settlementId}/export-csv`
  )
  expect(csvResponse.status()).toBe(200)
  const csv = await csvResponse.text()
  expect(csv).toContain('Bob Jones')
  expect(csv).toContain('40.00') // €40.00 total
})
```

### Example 3: Using Builders Without API Calls

```typescript
test('settlement structure is valid', async ({ testTransactions }) => {
  // Just build data structures (no API calls)
  const memberData = testTransactions.builders.member('Test', 'User')
  const settlementData = testTransactions.builders.settlement(['txn-1', 'txn-2'], 7)

  // Verify structure
  expect(memberData.first_name).toMatch(/Test_\d+/) // Timestamp appended
  expect(settlementData.settlement_date).toBeTruthy()
})
```

## Test Isolation

All test data builders automatically include timestamps in unique fields:

```typescript
createTestMember('John', 'Doe', 'john')
// Result:
// {
//   first_name: 'John_1234567890123',
//   email: 'john-1234567890123@test.example',
//   ...
// }
```

This ensures tests can run in parallel without conflicts.

## Error Handling

Fixture methods throw descriptive errors on API failures:

```typescript
test('handles member creation error', async ({ testTransactions }) => {
  try {
    // Missing required fields
    await testTransactions.createMember('', '')
  } catch (err) {
    expect(err.message).toContain('Failed to create member')
  }
})
```

## Advanced: Using Both Builders and API Methods

```typescript
test('complex workflow', async ({ testTransactions, authenticatedRequest }) => {
  // Create test data via API (with fixtures)
  const member = await testTransactions.createMember('Advanced', 'Test')

  // Build test data locally (no API calls)
  const txnData = testTransactions.builders.syncTransaction(member.id, 3000)

  // Do custom processing...
  txnData.notes = 'Modified notes'

  // Make your own API call if needed
  const response = await authenticatedRequest.post(
    '/api/sync/transactions',
    { data: { transactions: [txnData] } }
  )
})
```

## Migration Guide: Before and After

### Before (verbose, repetitive)

```typescript
test('settlement workflow', async ({ page, authenticatedRequest, authenticatedTerminalRequest }) => {
  // Manual member creation
  const timestamp = Date.now()
  const memberResponse = await authenticatedRequest.post('/api/admin/members', {
    data: {
      first_name: `TestMember_${timestamp}`,
      last_name: 'Test',
      email: `member-${timestamp}@test.example`,
      preferred_language: 'de',
    },
  })
  const member = await memberResponse.json()
  const memberId = member.id

  // Manual UUID generation
  const generateUUID = () =>
    'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
      const r = Math.random() * 16 | 0
      return (c === 'x' ? r : r & 0x3 | 0x8).toString(16)
    })

  // Manual sync transaction
  const syncResponse = await authenticatedTerminalRequest.post(
    '/api/sync/transactions',
    {
      data: {
        transactions: [{
          id: generateUUID(),
          member_id: memberId,
          type: 'product',
          product_id: generateUUID(),
          quantity: 1,
          unit_price_cents: 2500,
          amount_cents: 2500,
          notes: 'Test',
          created_at: new Date().toISOString(),
        }],
      },
    }
  )
  // ... many lines of boilerplate
})
```

### After (clean, DRY)

```typescript
test('settlement workflow', async ({ page, testTransactions }) => {
  // Create all test data with one line each
  const member = await testTransactions.createMember('Test', 'Member')
  const txnId = await testTransactions.createSyncTransaction(member.id, 2500, 'Test')
  const settlementId = await testTransactions.createSettlement([txnId], 7)

  // Focus on testing the actual workflow
  // ... your test logic
})
```

**Reduction:** 60 lines → 4 lines ✅
