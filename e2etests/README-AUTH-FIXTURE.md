# Authentication Fixture for Admin Tests

## Overview

The `authenticatedRequest` fixture automatically handles admin authentication for all tests that need to access protected endpoints.

**Location:** `fixtures/auth.fixture.ts`

## How It Works

1. **Automatic Login** - Before each test runs, the fixture logs in as the test admin account
2. **Cookie Management** - Extracts and stores the session cookie from the login response
3. **Request Wrapping** - Provides a request context that automatically adds the session cookie to every HTTP request
4. **Clean Session** - New login for each test ensures isolation and fresh sessions

## Using the Fixture

### Basic Usage

Replace the standard Playwright test import with the auth fixture:

```typescript
// ❌ OLD - No authentication
import { test, expect } from '@playwright/test';

test('my test', async ({ request }) => {
  const response = await request.get('/api/admin/members');
  // This will return 401 because no session
});
```

```typescript
// ✅ NEW - With authentication
import { test, expect } from '../../fixtures/auth.fixture';

test('my test', async ({ authenticatedRequest }) => {
  const response = await authenticatedRequest.get('/api/admin/members');
  // This works! Session cookie is automatically added
  expect(response.status()).toBe(200);
});
```

### All HTTP Methods Supported

The `authenticatedRequest` fixture supports all standard HTTP methods:

```typescript
test('all methods', async ({ authenticatedRequest }) => {
  // GET requests
  await authenticatedRequest.get('/api/admin/members');

  // POST requests
  await authenticatedRequest.post('/api/admin/members', {
    data: { email: 'test@example.com', first_name: 'Test' },
  });

  // PATCH requests
  await authenticatedRequest.patch('/api/admin/members/{id}', {
    data: { first_name: 'Updated' },
  });

  // DELETE requests
  await authenticatedRequest.delete('/api/admin/members/{id}');

  // Additional methods: PUT, HEAD
  await authenticatedRequest.put('/api/admin/members/{id}', { data: {} });
  await authenticatedRequest.head('/api/admin/members');
});
```

### Accessing Session Cookie

If you need the raw session cookie string for some reason:

```typescript
test('access cookie', async ({ authenticatedRequest }) => {
  const cookieString = authenticatedRequest.cookieString;
  console.log('Session cookie:', cookieString);
});
```

## Test Admin Account

All tests authenticate as:

- **Email:** admin@example.com
- **Password:** password123
- **ID:** 33e4567-e89b-12d3-a456-426614174000

This account is created by the `AdminUsersSeeder` during database setup.

## Implementation Details

### Request Context Wrapper

The fixture creates a custom `AuthenticatedRequestContext` class that wraps Playwright's `APIRequestContext`. Each HTTP method automatically adds the session cookie to request headers:

```typescript
get = (url: string, options?: any) =>
  this.request.get(url, {
    ...options,
    headers: {
      ...options?.headers,
      cookie: this.cookieString,
    },
  });
```

### Session Cookie Flow

1. Test starts → Fixture runs
2. Fixture sends POST /api/auth/login with admin credentials
3. Backend returns Set-Cookie header with session cookie
4. Fixture extracts cookie from response headers
5. Fixture wraps request context with cookie injection
6. Test makes requests → Cookies automatically added
7. Test ends → Session discarded (fresh session next time)

## Migrating Existing Tests

To update an existing test file:

1. Change the import:
   ```typescript
   // From:
   import { test, expect } from '@playwright/test';

   // To:
   import { test, expect } from '../../fixtures/auth.fixture';
   ```

2. Update test function parameters:
   ```typescript
   // From:
   test('name', async ({ request }) => {

   // To:
   test('name', async ({ authenticatedRequest }) => {
   ```

3. Replace all request calls:
   ```typescript
   // From:
   const response = await request.get('/api/endpoint');

   // To:
   const response = await authenticatedRequest.get('/api/endpoint');
   ```

## Example: Complete Test File

```typescript
import { test, expect } from '../../fixtures/auth.fixture';

test.describe('Admin Members', () => {
  test('list all members', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get('/api/admin/members');

    expect(response.status()).toBe(200);

    const data = await response.json();
    expect(data.items).toBeDefined();
    expect(Array.isArray(data.items)).toBe(true);
  });

  test('create member', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post('/api/admin/members', {
      data: {
        email: 'new@example.com',
        first_name: 'John',
        last_name: 'Doe',
      },
    });

    expect(response.status()).toBe(201);

    const member = await response.json();
    expect(member.id).toBeDefined();
    expect(member.email).toBe('new@example.com');
  });

  test('get member by id', async ({ authenticatedRequest }) => {
    // First create a member
    const createResponse = await authenticatedRequest.post('/api/admin/members', {
      data: {
        email: 'test@example.com',
        first_name: 'Test',
        last_name: 'User',
      },
    });

    const member = await createResponse.json();

    // Then fetch it
    const getResponse = await authenticatedRequest.get(
      `/api/admin/members/${member.id}`
    );

    expect(getResponse.status()).toBe(200);

    const fetched = await getResponse.json();
    expect(fetched.email).toBe('test@example.com');
  });
});
```

## Files Using This Fixture

- `tests/api/admin-auth.spec.ts` - Authentication tests
- `tests/api/admin-members-list.spec.ts` - List endpoint tests
- `tests/api/admin-members-crud.spec.ts` - CRUD endpoint tests
- `tests/api/admin-members-persistence.spec.ts` - Database persistence tests

## Troubleshooting

### Tests get 401 Unauthorized

**Problem:** Tests still returning 401 despite using authenticatedRequest

**Solutions:**
1. Verify import: `import { test, expect } from '../../fixtures/auth.fixture';`
2. Verify test signature: `async ({ authenticatedRequest })`
3. Verify requests use authenticatedRequest: `await authenticatedRequest.get(...)`

### Different Admin Account Needed

To modify the test admin account used:

1. Edit `fixtures/auth.fixture.ts`
2. Update the constants:
   ```typescript
   const ADMIN_EMAIL = "new@example.com";
   const ADMIN_PASSWORD = "newpassword";
   ```
3. Ensure that admin account exists in the database

### Session Expires During Test

Session timeout is set to 120 minutes, so this shouldn't happen. If it does:

1. Check server logs for session issues
2. Verify session table exists: `docker compose exec database mysql clubbar -e "SHOW TABLES LIKE 'sessions'"`
3. Check Laravel session config: `config/session.php`

## Performance Notes

- Each test gets a fresh login (~50-100ms overhead per test)
- Session cookies are small and don't impact performance
- For parallel test execution, each worker gets its own session
- To speed up tests, consider grouping into fewer test files

## Related Documentation

- [Admin API Spec](../../api/admin.yaml)
- [Pattern 013: Admin Session Authentication](../../adr/0013-admin-session-authentication.md)
- [Playwright Testing Guide](https://playwright.dev/docs/intro)
