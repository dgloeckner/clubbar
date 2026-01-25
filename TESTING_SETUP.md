# Testing Setup & Execution Guide

## Quick Start

### One-Time Setup
```bash
# 1. Reset environment
docker compose down -v
docker compose up -d

# 2. Wait for backend health
sleep 5
curl http://localhost:8080/api/health

# 3. Run migrations
docker compose exec backend bash -c "cd /app && php artisan migrate --force"

# 4. Run seeders (creates test data and admin user)
docker compose exec backend bash -c "cd /app && php artisan db:seed"

# 5. Generate terminal token
docker compose exec backend bash -c "cd /app && php artisan db:seed --class=TerminalSeeder"
```

This will output something like:
```
Test Terminal Created
Terminal ID: 8c880ea5-08af-41b5-ae3e-f3cb15d8bf56
Device ID: test-device-001
API Token: 666f1dced4ea1b797c39dfcdabbd1e62ebbdd8d3014acbb593411948b69dd646
```

### Running Tests

**Using the token from above**:
```bash
cd e2etests
TEST_TERMINAL_TOKEN="666f1dced4ea1b797c39dfcdabbd1e62ebbdd8d3014acbb593411948b69dd646" npx playwright test
```

**Or set it as an environment variable**:
```bash
export TEST_TERMINAL_TOKEN="666f1dced4ea1b797c39dfcdabbd1e62ebbdd8d3014acbb593411948b69dd646"
npx playwright test
```

---

## Test Suite Overview

### 123 Total Tests Organized By Module

| Module | Test File | Tests | Purpose |
|--------|-----------|-------|---------|
| Admin Auth | admin-auth.spec.ts | 18 | Login, logout, session management |
| Admin Members CRUD | admin-members-crud.spec.ts | 8 | Create, read, update, delete operations |
| Admin Members List | admin-members-list.spec.ts | 9 | List, filter, pagination |
| Admin Members Persistence | admin-members-persistence.spec.ts | 22 | Database persistence verification |
| Admin Members GDPR | admin-members-gdpr.spec.ts | 11 | Data export and anonymization |
| Audit Logging | admin-members-audit.spec.ts | 11 | Audit trail verification |
| Sync Members | sync-members.spec.ts | 4 | Terminal API member sync |
| Sync Categories | sync-categories.spec.ts | 5 | Terminal API category sync |
| Sync Products | sync-products.spec.ts | 6 | Terminal API product sync |
| Member Language | member-language.spec.ts | 7 | Language preference updates |
| Terminal Auth | terminal-authentication.spec.ts | 5 | Bearer token validation |
| Transactions | transactions.spec.ts | 10 | Transaction batch upload |
| Health | health.spec.ts | 3 | Health endpoint |
| **Total** | | **123** | |

---

## Authentication Methods

### Admin API (Session-Based)
Used by admin panel tests (`admin-*.spec.ts`)

**Fixture**: `fixtures/auth.fixture.ts`
```typescript
import { test, expect } from '../../fixtures/auth.fixture';

test('my test', async ({ authenticatedRequest }) => {
  // Login automatically happens in fixture
  const response = await authenticatedRequest.get('/api/admin/members');
  expect(response.ok()).toBeTruthy();
});
```

**Credentials** (from AdminUsersSeeder):
- Email: `admin@example.com`
- Password: `password123`

### Terminal API (Bearer Token)
Used by terminal sync tests (`sync-*.spec.ts`, `transactions.spec.ts`, etc.)

**Environment Variable**: `TEST_TERMINAL_TOKEN`
```typescript
const validToken = process.env.TEST_TERMINAL_TOKEN;
const authHeaders = validToken ? { 'Authorization': `Bearer ${validToken}` } : {};

const response = await request.post('/api/sync/transactions', {
  headers: authHeaders,
  data: { transactions },
});
```

**Token Generation**: From `TerminalSeeder`
```bash
docker compose exec backend bash -c "cd /app && php artisan db:seed --class=TerminalSeeder"
```

---

## Common Issues & Solutions

### Issue 1: Tests Fail with 401 Unauthorized
**Cause**: `TEST_TERMINAL_TOKEN` environment variable not set

**Solution**:
```bash
# Generate token
TOKEN=$(docker compose exec backend bash -c "cd /app && php artisan db:seed --class=TerminalSeeder" | grep -oP 'API Token.*\K\w+')

# Run tests with token
TEST_TERMINAL_TOKEN="$TOKEN" npx playwright test
```

### Issue 2: Tests Fail with "Member not found"
**Cause**: Database not seeded with test data

**Solution**:
```bash
docker compose exec backend bash -c "cd /app && php artisan migrate && php artisan db:seed"
```

### Issue 3: Tests Fail Intermittently
**Cause**: Parallel test isolation issues (tests interfering with each other)

**Solution**: Tests have been fixed to create their own data. If you add new tests:
- ✅ DO: Create test-specific data for each test
- ✅ DO: Use unique identifiers for test data
- ❌ DON'T: Rely on seeded data that other tests might modify
- ❌ DON'T: Depend on execution order

### Issue 4: Health check fails
**Cause**: Backend container not ready

**Solution**:
```bash
docker compose up -d
sleep 10  # Wait longer
curl http://localhost:8080/api/health
```

---

## Running Specific Tests

### Run single test file
```bash
TEST_TERMINAL_TOKEN="..." npx playwright test tests/api/admin-members-crud.spec.ts
```

### Run tests matching pattern
```bash
TEST_TERMINAL_TOKEN="..." npx playwright test --grep "CRUD"
```

### Run in specific mode
```bash
# Headed mode (see browser, only for UI tests)
npx playwright test --headed

# Debug mode (pauses execution)
npx playwright test --debug

# Single worker (sequential execution)
npx playwright test --workers=1

# Verbose output
npx playwright test --verbose
```

### Generate HTML report
```bash
# Report is automatically generated in playwright-report/
npx playwright show-report
```

---

## Continuous Integration Setup

For CI/CD pipelines, use this sequence:

```bash
#!/bin/bash
set -e

# 1. Start environment
docker compose down -v
docker compose up -d

# 2. Wait for services
sleep 5
curl -f http://localhost:8080/api/health || exit 1

# 3. Run migrations and seeders
docker compose exec backend bash -c "cd /app && php artisan migrate --force"
docker compose exec backend bash -c "cd /app && php artisan db:seed"

# 4. Generate token and run tests
TOKEN=$(docker compose exec backend bash -c "cd /app && php artisan db:seed --class=TerminalSeeder" | grep -oP 'API Token.*\K\w+')

cd e2etests
TEST_TERMINAL_TOKEN="$TOKEN" npm run test  # or npx playwright test
```

---

## Test Execution Patterns

### Parallel Execution (Default)
```bash
TEST_TERMINAL_TOKEN="..." npx playwright test
# Runs with 6 workers by default (configurable in playwright.config.ts)
```

**Benefits**:
- Faster overall execution time
- Better resource utilization
- Catches race condition bugs

**Requirements**:
- Database connections must support concurrent access (✅ MariaDB handles this)
- Tests must have proper isolation (✅ All tests create own data)

### Sequential Execution
```bash
TEST_TERMINAL_TOKEN="..." npx playwright test --workers=1
```

**Use when**:
- Debugging intermittent failures
- Running in resource-constrained environments
- Tests show issues with parallelization

---

## Test Data Cleanup

### Automatic Cleanup (Recommended)
The test suite automatically gets a clean database before each run via Docker reset:
```bash
docker compose down -v    # Removes volumes
docker compose up -d      # Fresh database
docker compose exec backend bash -c "cd /app && php artisan migrate && php artisan db:seed"
```

### Manual Test Data Inspection
```bash
# Connect to database
docker compose exec database mysql -u ruderbar -pruderbar ruderbar

# View test members
SELECT id, first_name, email, is_active FROM members LIMIT 5;

# View audit log entries
SELECT id, action, entity_type, entity_id, created_at FROM audit_log ORDER BY created_at DESC LIMIT 10;
```

---

## Performance Monitoring

### View test execution times
```bash
TEST_TERMINAL_TOKEN="..." npx playwright test --reporter=list
```

### Benchmark tests
```bash
# Run tests 3 times and compare
for i in {1..3}; do
  echo "=== Run $i ==="
  TEST_TERMINAL_TOKEN="..." npx playwright test --reporter=line
done
```

---

## Troubleshooting

### Get full test output
```bash
TEST_TERMINAL_TOKEN="..." npx playwright test --reporter=verbose 2>&1 | tee test-output.txt
```

### View network requests
Tests automatically capture network requests in the HTML report:
```bash
npx playwright show-report
# Click on failed test → "Network" tab
```

### Debug specific test
```bash
TEST_TERMINAL_TOKEN="..." npx playwright test admin-members-crud.spec.ts --debug
# Use Playwright Inspector to step through test
```

---

## Best Practices for New Tests

### 1. Create Test Data Locally
```typescript
// ✅ Good: Test creates its own data
test('my test', async ({ authenticatedRequest }) => {
  const createResponse = await authenticatedRequest.post('/api/admin/members', {
    data: { first_name: 'TestMember', ... }
  });
  const member = await createResponse.json();
  // Use member.id in test
});

// ❌ Bad: Test depends on seeded data
test('my test', async ({ authenticatedRequest }) => {
  const response = await authenticatedRequest.get('/api/admin/members/123e4567-e89b-...');
  // Fails if seeded member doesn't exist or was modified
});
```

### 2. Use Database-Agnostic Assertions
```typescript
// ✅ Good: Find specific record
const auditEntry = auditData.items.find(e => e.entity_id === memberId);

// ❌ Bad: Assume position
expect(auditData.items[0].entity_id).toBe(memberId);
```

### 3. Use Unique Identifiers
```typescript
// ✅ Good: Each test has unique data
const createResponse = await authenticatedRequest.post('/api/admin/members', {
  data: { email: `test-${Date.now()}@example.com` }
});

// ❌ Bad: Same email in multiple tests
const createResponse = await authenticatedRequest.post('/api/admin/members', {
  data: { email: 'test@example.com' }  // Causes conflicts
});
```

### 4. No Test Dependencies
```typescript
// ✅ Good: Tests are independent
test('Test A', async ({ authenticatedRequest }) => { ... });
test('Test B', async ({ authenticatedRequest }) => { ... });

// ❌ Bad: Test B depends on Test A running first
test('Test A', async ({ authenticatedRequest }) => {
  globalTestData = await createSomething();
});
test('Test B', async ({ authenticatedRequest }) => {
  const member = globalTestData.member;  // Fails if Test A doesn't run
});
```
