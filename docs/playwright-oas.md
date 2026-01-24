# Playwright API Tests with Generated OAS Client

## Overview

This guide describes the approach for testing the Terminal API endpoints using a generated TypeScript client from the OpenAPI specification. This provides type safety, better IDE support, and automatic contract validation.

---

## Setup

### 1. Install Dependencies

```bash
npm install -D @hey-api/openapi-ts @hey-api/client-fetch
```

### 2. Configure Client Generation

Add to `package.json`:

```json
{
  "scripts": {
    "generate:api": "openapi-ts -i api/terminal.yaml -o src/generated/terminal-api -c @hey-api/client-fetch",
    "test:api": "playwright test tests/api"
  }
}
```

### 3. Generate the Client

```bash
npm run generate:api
```

This generates typed functions and interfaces from `api/terminal.yaml`:

```
src/generated/terminal-api/
├── index.ts
├── types.gen.ts      # MemberDeltaResponse, TransactionBatchRequest, etc.
├── services.gen.ts   # getSyncMembers, postSyncTransactions, etc.
└── client.ts
```

---

## Playwright Fixtures

### API Client Fixture

```typescript
// tests/fixtures/api-client.ts
import { test as base } from '@playwright/test';
import { client } from '../../src/generated/terminal-api';

export const test = base.extend<{ api: typeof client }>({
  api: async ({ baseURL }, use) => {
    client.setConfig({
      baseUrl: baseURL,
    });
    await use(client);
  },
});

export { expect } from '@playwright/test';
```

### Common Assertions

```typescript
// tests/fixtures/api-assertions.ts
import { expect } from '@playwright/test';

export function expectIsoTimestamp(value: string) {
  expect(value).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/);
}

export function expectUuid(value: string) {
  expect(value).toMatch(
    /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i
  );
}

export function expectApiError(
  error: { error: string; message: string; timestamp: string } | undefined,
  expectedError: string
) {
  expect(error).toBeDefined();
  expect(error!.error).toBe(expectedError);
  expectIsoTimestamp(error!.timestamp);
}

export function expectPaginatedResponse<T>(
  data: { cursor: string; count: number; has_more: boolean } | undefined
) {
  expect(data).toBeDefined();
  expectIsoTimestamp(data!.cursor);
  expect(typeof data!.count).toBe('number');
  expect(typeof data!.has_more).toBe('boolean');
}
```

---

## Test Specifications

### Sync Members Endpoint

```typescript
// tests/api/sync-members.spec.ts
import { test, expect } from '../fixtures/api-client';
import { getSyncMembers } from '../../src/generated/terminal-api';
import { expectUuid, expectIsoTimestamp, expectPaginatedResponse } from '../fixtures/api-assertions';

test.describe('GET /api/sync/members', () => {
  test.describe('successful responses', () => {
    test('returns member delta response with valid structure', async ({ api }) => {
      const { data, error, response } = await getSyncMembers({
        query: { since: '1970-01-01T00:00:00Z' },
      });

      expect(error).toBeUndefined();
      expect(response.status).toBe(200);
      expect(data).toBeDefined();
      expect(Array.isArray(data!.members)).toBe(true);
      expectPaginatedResponse(data);
    });

    test('filters members by since timestamp', async ({ api }) => {
      const since = '2025-01-20T00:00:00Z';
      const { data } = await getSyncMembers({
        query: { since },
      });

      for (const member of data!.members) {
        expect(new Date(member.updated_at).getTime())
          .toBeGreaterThanOrEqual(new Date(since).getTime());
      }
    });

    test('returns empty array when no updates since timestamp', async ({ api }) => {
      const { data } = await getSyncMembers({
        query: { since: new Date().toISOString() },
      });

      expect(data!.members).toHaveLength(0);
      expect(data!.count).toBe(0);
      expect(data!.has_more).toBe(false);
    });
  });

  test.describe('member schema validation', () => {
    test('each member has required fields with correct types', async ({ api }) => {
      const { data } = await getSyncMembers({
        query: { since: '1970-01-01T00:00:00Z' },
      });

      for (const member of data!.members) {
        expectUuid(member.id);
        expect(typeof member.first_name).toBe('string');
        expect(typeof member.last_name).toBe('string');
        expect(typeof member.is_active).toBe('boolean');
        expect(typeof member.is_sepa_valid).toBe('boolean');
        expect(['de', 'en', 'fr']).toContain(member.preferred_language);
        expectIsoTimestamp(member.created_at);
        expectIsoTimestamp(member.updated_at);
      }
    });

    test('card_uid follows expected format when present', async ({ api }) => {
      const { data } = await getSyncMembers({
        query: { since: '1970-01-01T00:00:00Z' },
      });

      for (const member of data!.members) {
        if (member.card_uid) {
          expect(member.card_uid).toMatch(/^([0-9a-fA-F]{2}:){6}[0-9a-fA-F]{2}$/);
        }
      }
    });

    test('deleted_at is null or valid ISO timestamp', async ({ api }) => {
      const { data } = await getSyncMembers({
        query: { since: '1970-01-01T00:00:00Z' },
      });

      for (const member of data!.members) {
        if (member.deleted_at !== null) {
          expectIsoTimestamp(member.deleted_at);
        }
      }
    });
  });

  test.describe('pagination', () => {
    test('cursor can be used for subsequent requests', async ({ api }) => {
      const first = await getSyncMembers({
        query: { since: '1970-01-01T00:00:00Z' },
      });

      if (first.data!.has_more) {
        const next = await getSyncMembers({
          query: { since: first.data!.cursor },
        });

        expect(next.error).toBeUndefined();
        expect(next.data).toBeDefined();
      }
    });
  });
});
```

### Sync Categories Endpoint

```typescript
// tests/api/sync-categories.spec.ts
import { test, expect } from '../fixtures/api-client';
import { getSyncCategories } from '../../src/generated/terminal-api';
import { expectUuid, expectIsoTimestamp, expectPaginatedResponse } from '../fixtures/api-assertions';

test.describe('GET /api/sync/categories', () => {
  test('returns category delta response', async ({ api }) => {
    const { data, error, response } = await getSyncCategories({
      query: { since: '1970-01-01T00:00:00Z' },
    });

    expect(error).toBeUndefined();
    expect(response.status).toBe(200);
    expect(Array.isArray(data!.categories)).toBe(true);
    expectPaginatedResponse(data);
  });

  test('each category has localized names', async ({ api }) => {
    const { data } = await getSyncCategories({
      query: { since: '1970-01-01T00:00:00Z' },
    });

    for (const category of data!.categories) {
      expectUuid(category.id);
      expect(category.names).toBeDefined();
      expect(typeof category.names.de).toBe('string');
      expect(typeof category.names.en).toBe('string');
      expect(typeof category.display_order).toBe('number');
      expect(typeof category.is_active).toBe('boolean');
      expectIsoTimestamp(category.created_at);
      expectIsoTimestamp(category.updated_at);
    }
  });
});
```

### Sync Products Endpoint

```typescript
// tests/api/sync-products.spec.ts
import { test, expect } from '../fixtures/api-client';
import { getSyncProducts } from '../../src/generated/terminal-api';
import { expectUuid, expectIsoTimestamp, expectPaginatedResponse } from '../fixtures/api-assertions';

test.describe('GET /api/sync/products', () => {
  test('returns product delta response', async ({ api }) => {
    const { data, error, response } = await getSyncProducts({
      query: { since: '1970-01-01T00:00:00Z' },
    });

    expect(error).toBeUndefined();
    expect(response.status).toBe(200);
    expect(Array.isArray(data!.products)).toBe(true);
    expectPaginatedResponse(data);
  });

  test('each product has required fields', async ({ api }) => {
    const { data } = await getSyncProducts({
      query: { since: '1970-01-01T00:00:00Z' },
    });

    for (const product of data!.products) {
      expectUuid(product.id);
      expectUuid(product.category_id);
      expect(product.names).toBeDefined();
      expect(typeof product.names.de).toBe('string');
      expect(typeof product.price_cents).toBe('number');
      expect(product.price_cents).toBeGreaterThan(0);
      expect(typeof product.is_active).toBe('boolean');
      expectIsoTimestamp(product.created_at);
      expectIsoTimestamp(product.updated_at);
    }
  });

  test('product descriptions are optional but structured when present', async ({ api }) => {
    const { data } = await getSyncProducts({
      query: { since: '1970-01-01T00:00:00Z' },
    });

    for (const product of data!.products) {
      if (product.descriptions) {
        expect(typeof product.descriptions).toBe('object');
      }
    }
  });
});
```

### Update Member Language Endpoint

```typescript
// tests/api/sync-member-language.spec.ts
import { test, expect } from '../fixtures/api-client';
import { patchSyncMembersLanguage } from '../../src/generated/terminal-api';
import { expectIsoTimestamp, expectApiError } from '../fixtures/api-assertions';

const VALID_MEMBER_ID = '123e4567-e89b-12d3-a456-426614174000';

test.describe('PATCH /api/sync/members/{memberId}/language', () => {
  test.describe('successful updates', () => {
    test('updates member language preference', async ({ api }) => {
      const { data, error, response } = await patchSyncMembersLanguage({
        path: { memberId: VALID_MEMBER_ID },
        body: { preferred_language: 'en' },
      });

      expect(error).toBeUndefined();
      expect(response.status).toBe(200);
      expect(data!.id).toBe(VALID_MEMBER_ID);
      expect(data!.preferred_language).toBe('en');
      expectIsoTimestamp(data!.updated_at);
    });

    test.each(['de', 'en', 'fr'])('accepts supported language: %s', async ({ api }, language) => {
      const { data, error } = await patchSyncMembersLanguage({
        path: { memberId: VALID_MEMBER_ID },
        body: { preferred_language: language },
      });

      expect(error).toBeUndefined();
      expect(data!.preferred_language).toBe(language);
    });
  });

  test.describe('validation errors', () => {
    test('rejects unsupported language code', async ({ api }) => {
      const { error, response } = await patchSyncMembersLanguage({
        path: { memberId: VALID_MEMBER_ID },
        body: { preferred_language: 'es' },
      });

      expect(response.status).toBe(400);
      expectApiError(error, 'invalid_request');
    });

    test('rejects invalid language format', async ({ api }) => {
      const { error, response } = await patchSyncMembersLanguage({
        path: { memberId: VALID_MEMBER_ID },
        body: { preferred_language: 'invalid' as any },
      });

      expect(response.status).toBe(400);
      expectApiError(error, 'invalid_request');
    });
  });

  test.describe('not found errors', () => {
    test('returns 404 for non-existent member', async ({ api }) => {
      const { error, response } = await patchSyncMembersLanguage({
        path: { memberId: '00000000-0000-0000-0000-000000000000' },
        body: { preferred_language: 'de' },
      });

      expect(response.status).toBe(404);
      expectApiError(error, 'not_found');
    });

    test('returns 404 for invalid UUID format', async ({ api }) => {
      const { error, response } = await patchSyncMembersLanguage({
        path: { memberId: 'not-a-uuid' },
        body: { preferred_language: 'de' },
      });

      expect(response.status).toBe(404);
      expectApiError(error, 'not_found');
    });
  });
});
```

### Sync Transactions Endpoint

```typescript
// tests/api/sync-transactions.spec.ts
import { test, expect } from '../fixtures/api-client';
import { postSyncTransactions } from '../../src/generated/terminal-api';
import { expectApiError } from '../fixtures/api-assertions';
import { randomUUID } from 'crypto';

function createTransaction(overrides = {}) {
  return {
    id: randomUUID(),
    member_id: '123e4567-e89b-12d3-a456-426614174000',
    product_id: '987f6543-e21a-11d3-b456-426614174999',
    amount_cents: 350,
    created_at: new Date().toISOString(),
    ...overrides,
  };
}

test.describe('POST /api/sync/transactions', () => {
  test.describe('successful batch uploads', () => {
    test('accepts single valid transaction', async ({ api }) => {
      const transaction = createTransaction();

      const { data, error, response } = await postSyncTransactions({
        body: { transactions: [transaction] },
      });

      expect(error).toBeUndefined();
      expect(response.status).toBe(200);
      expect(data!.accepted_ids).toContain(transaction.id);
      expect(data!.rejected.count).toBe(0);
      expect(data!.rejected.errors).toHaveLength(0);
    });

    test('accepts batch of 100 transactions', async ({ api }) => {
      const transactions = Array.from({ length: 100 }, () => createTransaction());

      const { data, error } = await postSyncTransactions({
        body: { transactions },
      });

      expect(error).toBeUndefined();
      expect(data!.accepted_ids).toHaveLength(100);
      expect(data!.rejected.count).toBe(0);
    });

    test('accepts transaction with quantity > 1', async ({ api }) => {
      const transaction = createTransaction({
        quantity: 3,
        amount_cents: 1050, // 3 x 350
      });

      const { data, error } = await postSyncTransactions({
        body: { transactions: [transaction] },
      });

      expect(error).toBeUndefined();
      expect(data!.accepted_ids).toContain(transaction.id);
    });
  });

  test.describe('batch size validation', () => {
    test('rejects empty transactions array', async ({ api }) => {
      const { error, response } = await postSyncTransactions({
        body: { transactions: [] },
      });

      expect(response.status).toBe(400);
      expectApiError(error, 'invalid_request');
    });

    test('rejects batch exceeding 100 transactions', async ({ api }) => {
      const transactions = Array.from({ length: 101 }, () => createTransaction());

      const { error, response } = await postSyncTransactions({
        body: { transactions },
      });

      expect(response.status).toBe(400);
      expectApiError(error, 'invalid_request');
      expect(error!.message).toContain('100');
    });
  });

  test.describe('field validation', () => {
    test('rejects transaction missing required field: id', async ({ api }) => {
      const { id, ...transactionWithoutId } = createTransaction();

      const { error, response } = await postSyncTransactions({
        body: { transactions: [transactionWithoutId as any] },
      });

      expect(response.status).toBe(422);
      expectApiError(error, 'validation_failed');
      expect(error!.details).toContainEqual(
        expect.objectContaining({ field: expect.stringContaining('id') })
      );
    });

    test('rejects transaction missing required field: member_id', async ({ api }) => {
      const { member_id, ...transaction } = createTransaction();

      const { error, response } = await postSyncTransactions({
        body: { transactions: [transaction as any] },
      });

      expect(response.status).toBe(422);
      expectApiError(error, 'validation_failed');
    });

    test('rejects transaction with zero amount_cents', async ({ api }) => {
      const transaction = createTransaction({ amount_cents: 0 });

      const { error, response } = await postSyncTransactions({
        body: { transactions: [transaction] },
      });

      expect(response.status).toBe(422);
      expectApiError(error, 'validation_failed');
      expect(error!.details).toContainEqual(
        expect.objectContaining({
          field: expect.stringContaining('amount_cents'),
        })
      );
    });

    test('rejects transaction with negative amount_cents', async ({ api }) => {
      const transaction = createTransaction({ amount_cents: -100 });

      const { error, response } = await postSyncTransactions({
        body: { transactions: [transaction] },
      });

      expect(response.status).toBe(422);
      expectApiError(error, 'validation_failed');
    });
  });

  test.describe('idempotency', () => {
    test('accepts duplicate transaction ID on retry', async ({ api }) => {
      const transaction = createTransaction();

      // First submission
      const first = await postSyncTransactions({
        body: { transactions: [transaction] },
      });
      expect(first.data!.accepted_ids).toContain(transaction.id);

      // Retry with same ID
      const retry = await postSyncTransactions({
        body: { transactions: [transaction] },
      });
      expect(retry.data!.accepted_ids).toContain(transaction.id);
    });
  });
});
```

---

## Project Structure

```
tests/
├── fixtures/
│   ├── api-client.ts         # Playwright fixture with configured client
│   └── api-assertions.ts     # Reusable assertion helpers
├── api/
│   ├── sync-members.spec.ts
│   ├── sync-categories.spec.ts
│   ├── sync-products.spec.ts
│   ├── sync-member-language.spec.ts
│   └── sync-transactions.spec.ts
└── e2e/
    └── ...                   # UI tests

src/
└── generated/
    └── terminal-api/         # Generated from OAS
        ├── index.ts
        ├── types.gen.ts
        └── services.gen.ts
```

---

## CI Integration

```yaml
# .github/workflows/api-tests.yml
name: API Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'

      - run: npm ci

      - name: Generate API client
        run: npm run generate:api

      - name: Check for uncommitted changes
        run: |
          if [ -n "$(git status --porcelain src/generated)" ]; then
            echo "Generated client is out of sync with OAS spec"
            exit 1
          fi

      - name: Run API tests
        run: npm run test:api
```

---

## Benefits Summary

| Aspect | Before (Raw Requests) | After (Generated Client) |
|--------|----------------------|--------------------------|
| **Type Safety** | None - `body.members` is `any` | Full - IDE knows all fields |
| **Contract Drift** | Silent failures | Compile-time errors |
| **Autocomplete** | None | Full IDE support |
| **Refactoring** | Manual find/replace | Automatic with TypeScript |
| **Documentation** | Separate from code | Types are self-documenting |
| **Maintenance** | Update tests manually | Regenerate client |

---

## Regenerating After OAS Changes

When `api/terminal.yaml` changes:

```bash
# Regenerate client
npm run generate:api

# Run tests to catch breaking changes
npm run test:api

# Commit updated generated code
git add src/generated/
git commit -m "chore: regenerate API client from updated OAS"
```d