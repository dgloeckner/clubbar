# Pattern 002: Authentication Isolation

**Status**: Established and Verified
**Derived From**: Multi-auth system requiring session and bearer token isolation
**Test Coverage**: All 123 E2E tests use appropriate auth patterns

---

## Problem

The system has multiple authentication methods that must be kept isolated:
- **Admin API**: Session-based authentication (cookies)
- **Terminal API**: Bearer token authentication (Authorization header)
- **Public Endpoints**: No authentication required

Tests must use the correct authentication method without mixing concerns or leaking credentials.

---

## Solution: Isolated Auth Fixtures and Helpers

Use context-specific fixtures for different authentication methods.

### Core Pattern

```typescript
// Admin API Tests (Session-Based)
import { test, expect } from '../../fixtures/auth.fixture';

test('admin test', async ({ authenticatedRequest }) => {
  // authenticatedRequest automatically adds session cookie
  const response = await authenticatedRequest.get('/api/admin/members');
  expect(response.ok()).toBeTruthy();
});

// Terminal API Tests (Bearer Token)
import { test, expect } from '@playwright/test';

const validToken = process.env.TEST_TERMINAL_TOKEN;
const authHeaders = validToken ? { 'Authorization': `Bearer ${validToken}` } : {};

test('terminal test', async ({ request }) => {
  const response = await request.get('/api/sync/members', {
    headers: authHeaders,
  });
  expect(response.ok()).toBeTruthy();
});

// Public API Tests (No Auth)
test('public test', async ({ request }) => {
  const response = await request.get('/api/health');
  expect(response.ok()).toBeTruthy();
});
```

---

## Authentication Methods

### 1. Admin API: Session-Based Authentication

**Use Case**: Admin panel endpoints (`/api/admin/`)

**Mechanism**: Session cookie set by login endpoint

**Implementation**:

```typescript
import { test, expect } from '../../fixtures/auth.fixture';

test('get admin members list', async ({ authenticatedRequest }) => {
  // Session cookie automatically added by fixture
  const response = await authenticatedRequest.get('/api/admin/members');
  expect(response.ok()).toBeTruthy();
  expect(response.json().items).toBeDefined();
});

test('create admin member', async ({ authenticatedRequest }) => {
  // Cookie works for POST, PATCH, DELETE as well
  const response = await authenticatedRequest.post('/api/admin/members', {
    data: {
      first_name: 'Test',
      last_name: 'Member',
      email: 'test@example.com',
      preferred_language: 'en',
    },
  });
  expect(response.ok()).toBeTruthy();
});
```

**Fixture Details** (fixtures/auth.fixture.ts):
```typescript
interface AuthFixtures {
  authenticatedRequest: APIRequestContext & {
    cookieString: string;
  };
}

class AuthenticatedRequestContext {
  constructor(
    private request: APIRequestContext,
    private cookieString: string
  ) {}

  // All HTTP methods automatically add cookie header
  get = (url: string, options?: any) =>
    this.request.get(url, {
      ...options,
      headers: {
        ...options?.headers,
        cookie: this.cookieString,  // Automatic session
      },
    });

  post = (url: string, options?: any) =>
    this.request.post(url, {
      ...options,
      headers: {
        ...options?.headers,
        cookie: this.cookieString,  // Automatic session
      },
    });
  // ... patch, delete, put, head
}

export const test = base.extend<AuthFixtures>({
  authenticatedRequest: async ({ request }, use) => {
    // Step 1: Login to get session
    const loginResponse = await request.post(`${API_BASE}/auth/login`, {
      data: {
        email: 'admin@example.com',
        password: 'password123',
      },
    });

    // Step 2: Extract session cookie
    const setCookieHeader = loginResponse.headers()['set-cookie'];
    const fullCookieString = Array.isArray(setCookieHeader)
      ? setCookieHeader[0]
      : setCookieHeader || '';

    // Step 3: Extract name=value part
    const cookieString = fullCookieString.split(';')[0];

    // Step 4: Create request wrapper that adds cookie to every request
    const authenticatedRequest = new AuthenticatedRequestContext(
      request,
      cookieString
    ) as any;

    // Step 5: Provide to test
    await use(authenticatedRequest);
  },
});
```

**Advantages**:
- ✅ Session cookie automatically added to every request
- ✅ No manual header management
- ✅ Type-safe request methods
- ✅ Same interface as `request` (drop-in replacement)

---

### 2. Terminal API: Bearer Token Authentication

**Use Case**: Terminal sync endpoints (`/api/sync/`)

**Mechanism**: Authorization header with Bearer token

**Implementation**:

```typescript
import { test, expect } from '@playwright/test';

const validToken = process.env.TEST_TERMINAL_TOKEN;

// Pattern 1: Inline token (for single endpoint)
test('GET members with token', async ({ request }) => {
  const response = await request.get('/api/sync/members', {
    headers: {
      'Authorization': `Bearer ${validToken}`,
    },
  });
  expect(response.ok()).toBeTruthy();
});

// Pattern 2: Helper function (reusable)
function terminalHeaders() {
  if (!validToken) {
    throw new Error('TEST_TERMINAL_TOKEN not set');
  }
  return {
    'Authorization': `Bearer ${validToken}`,
  };
}

test('sync multiple endpoints', async ({ request }) => {
  const headers = terminalHeaders();

  const members = await request.get('/api/sync/members', { headers });
  const products = await request.get('/api/sync/products', { headers });
  const categories = await request.get('/api/sync/categories', { headers });

  expect(members.ok()).toBeTruthy();
  expect(products.ok()).toBeTruthy();
  expect(categories.ok()).toBeTruthy();
});

// Pattern 3: Fixture (for token test files)
const test = base.extend({
  terminalHeaders: async ({}, use) => {
    if (!validToken) {
      throw new Error('TEST_TERMINAL_TOKEN environment variable not set');
    }
    await use({
      'Authorization': `Bearer ${validToken}`,
    });
  },
});

test('transaction upload', async ({ request, terminalHeaders }) => {
  const response = await request.post('/api/sync/transactions', {
    headers: terminalHeaders,
    data: {
      transactions: [
        {
          id: randomUUID(),
          member_id: '...',
          product_id: '...',
          amount_cents: 350,
          created_at: new Date().toISOString(),
        },
      ],
    },
  });
  expect(response.ok()).toBeTruthy();
});
```

**Token Generation**:
```bash
# Run seeder to generate token
curl -sf -H "X-Install-Key: $INSTALL_KEY" "http://localhost:8080/install.php?action=seed"

# Output includes:
# API Token: 666f1dced4ea1b797c39dfcdabbd1e62ebbdd8d3014acbb593411948b69dd646

# Set environment variable for tests
export TEST_TERMINAL_TOKEN="666f1dced4ea1b797c39dfcdabbd1e62ebbdd8d3014acbb593411948b69dd646"
npx playwright test
```

**Advantages**:
- ✅ Explicit authentication (visible in code)
- ✅ No session management needed
- ✅ Works with stateless API
- ✅ Easy to test auth failures (use invalid token)

---

### 3. Public Endpoints: No Authentication

**Use Case**: Public endpoints like `/api/health`

**Implementation**:

```typescript
import { test, expect } from '@playwright/test';

test('health endpoint is public', async ({ request }) => {
  const response = await request.get('/api/health');
  expect(response.ok()).toBeTruthy();
  expect(response.json()).toHaveProperty('status', 'ok');
});
```

**No special handling needed** - use standard `request` fixture.

---

## Authentication Testing Patterns

### Pattern 1: Testing Missing Authentication

```typescript
test('rejects missing authentication', async ({ request }) => {
  // Terminal API without token
  const response = await request.get('/api/sync/members');
  expect(response.status()).toBe(401);
  expect(response.json().error).toContain('Unauthorized');
});

test('rejects invalid token format', async ({ request }) => {
  // Missing "Bearer " prefix
  const response = await request.get('/api/sync/members', {
    headers: {
      'Authorization': 'InvalidToken123',
    },
  });
  expect(response.status()).toBe(401);
});
```

### Pattern 2: Testing Token Expiration

```typescript
test('rejects expired token', async ({ request }) => {
  const expiredToken = 'eyJhbGc...'; // Expired JWT

  const response = await request.get('/api/sync/members', {
    headers: {
      'Authorization': `Bearer ${expiredToken}`,
    },
  });

  expect(response.status()).toBe(401);
});
```

### Pattern 3: Testing Session Validity

```typescript
test('rejects request without session cookie', async ({ request }) => {
  // Logout first
  const logoutResponse = await request.post('/api/auth/logout');
  expect(logoutResponse.ok()).toBeTruthy();

  // Now request without cookie
  const memberResponse = await request.get('/api/admin/members');
  expect(memberResponse.status()).toBe(401);
});
```

### Pattern 4: Testing Auth Across Multiple Requests

```typescript
test('session persists across multiple requests', async ({ authenticatedRequest }) => {
  // First request
  const firstResponse = await authenticatedRequest.get('/api/admin/members');
  expect(firstResponse.ok()).toBeTruthy();

  // Second request (same session)
  const secondResponse = await authenticatedRequest.post('/api/admin/members', {
    data: { first_name: 'Test', ... }
  });
  expect(secondResponse.ok()).toBeTruthy();

  // Third request (same session)
  const thirdResponse = await authenticatedRequest.get('/api/admin/members');
  expect(thirdResponse.ok()).toBeTruthy();

  // All work because session persists in authenticatedRequest
});
```

---

## Credential Management

### Development Environment

**Never commit credentials to version control:**

```bash
# ✅ Good: Use environment variables
export TEST_TERMINAL_TOKEN="..."
export ADMIN_EMAIL="admin@example.com"
export ADMIN_PASSWORD="password123"

# ❌ Bad: Hardcoded in test file
const token = "abc123def456";
```

### CI/CD Pipeline

```yaml
# GitHub Actions example
env:
  API_URL: http://localhost:8080
  TEST_TERMINAL_TOKEN: ${{ secrets.TEST_TERMINAL_TOKEN }}

steps:
  - name: Run tests
    run: |
      curl -sf -H "X-Install-Key: $INSTALL_KEY" "http://localhost:8080/install.php?action=seed"
      npx playwright test
```

### Local Development

```bash
# Create .env.test (don't commit)
TEST_TERMINAL_TOKEN=666f1dced4ea1b797c39dfcdabbd1e62ebbdd8d3014acbb593411948b69dd646
ADMIN_EMAIL=admin@example.com
ADMIN_PASSWORD=password123

# Load and run tests
source .env.test
npx playwright test
```

---

## Troubleshooting

### Issue 1: "TEST_TERMINAL_TOKEN not set"

**Solution**:
```bash
# Generate token
curl -sf -H "X-Install-Key: $INSTALL_KEY" "http://localhost:8080/install.php?action=seed"

# Copy token from output and set it
export TEST_TERMINAL_TOKEN="<token from output>"

# Run tests
npx playwright test
```

### Issue 2: "401 Unauthorized" on admin endpoints

**Solution**: Check session cookie is being sent:
```typescript
test('debug: verify cookie is sent', async ({ authenticatedRequest }) => {
  // authenticatedRequest.cookieString is available
  console.log('Cookie:', authenticatedRequest.cookieString);

  const response = await authenticatedRequest.get('/api/admin/members');
  console.log('Status:', response.status());
});
```

### Issue 3: Auth works in some tests but not others

**Solution**: Ensure using correct fixture:
```typescript
// ✅ Correct: Using auth.fixture for admin tests
import { test, expect } from '../../fixtures/auth.fixture';

test('admin test', async ({ authenticatedRequest }) => { ... });

// ❌ Wrong: Using @playwright/test for admin tests
import { test, expect } from '@playwright/test';

test('admin test', async ({ request }) => { ... }); // No auth!
```

---

## Best Practices

1. **Use the right fixture for the right API**
   - Admin endpoints → `auth.fixture.ts` with `authenticatedRequest`
   - Terminal endpoints → `@playwright/test` with token header
   - Public endpoints → standard `request` fixture

2. **Keep auth setup minimal**
   - Don't repeat auth logic in tests
   - Use fixtures to handle authentication
   - Tests focus on business logic, not auth mechanics

3. **Test auth failures separately**
   - Test missing auth
   - Test invalid auth
   - Test auth expiration
   - Keep these separate from happy-path tests

4. **Never hardcode credentials**
   - Use environment variables
   - Store secrets in CI/CD system
   - Document required credentials

5. **Isolate auth concerns**
   - Auth tests in separate files
   - Business logic tests assume auth works
   - Clear separation of concerns

---

## Related Patterns

- [Pattern 001: Test Data Isolation](pattern-001-test-data-isolation.md)
- [Pattern 004: Parallel Execution Safety](pattern-004-parallel-execution-safety.md)
