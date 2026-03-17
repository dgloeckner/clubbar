# Security Critical Fixes Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix three CRITICAL security vulnerabilities that expose member data or allow installation key bypass: timing attack on `install.php`, wildcard CORS, and `APP_DEBUG=true` shipped as default.

**Architecture:** All three fixes are configuration and middleware changes — no new dependencies, no data model changes. Each task is self-contained and independently testable with Playwright E2E tests targeting `http://localhost:8080`.

**Tech Stack:** PHP 8.3, Slim 4 middleware, Playwright (E2E tests), curl (manual verification)

---

## Files Changed

| File | Action | What changes |
|------|--------|--------------|
| `backend/public/install.php` | Modify | Always call `hash_equals` (timing safety) |
| `backend/src/Shared/Middleware/CorsMiddleware.php` | Modify | Never send `Allow-Credentials: true` with wildcard origin |
| `backend/src/ServiceFactory.php` | Modify | Reject `*` CORS origin with warning |
| `backend/.env.example` | Modify | `APP_DEBUG=false`, `CORS_ORIGINS=http://localhost:5173` |
| `docker-compose.yml` | Modify | `APP_DEBUG=false`, `CORS_ORIGINS=http://localhost:5173,http://localhost:5174` |
| `e2etests/tests/api/security-critical.spec.ts` | Create | E2E tests for all three fixes |

---

## Background: What the Tests Prove

Before touching any code, understand what each fix achieves and how the tests confirm it:

- **C1 (install.php):** `hash_equals` currently only runs when `INSTALL_KEY` is configured AND ≥16 chars. An attacker timing responses can distinguish "key not configured" from "wrong key". The fix: always hash both strings to 64 bytes first, then always call `hash_equals` — no short-circuit before it.

- **C2 (CORS):** `Access-Control-Allow-Origin: *` combined with `Access-Control-Allow-Credentials: true` is a spec violation. Browsers silently discard the credentials, but the misconfiguration should be corrected. More importantly, `CORS_ORIGINS=*` in shipped configs means any deployer gets wildcard CORS on their member data API.

- **C3 (APP_DEBUG):** When `APP_DEBUG=true`, the `ErrorHandler` appends `trace` (a PHP stack trace) to error responses. Stack traces expose file paths, database queries, and class names — all useful for attackers.

---

## Task 1: Fix Timing Attack in install.php (C1)

**File:** `backend/public/install.php:20-22`

**The problem:** PHP short-circuit evaluation means `hash_equals` is only reached when `$keyNotConfigured` and `$keyTooShort` are both false. Measuring response time reveals whether `INSTALL_KEY` is empty or too short — before any key comparison runs.

**The fix:** Hash both values to equal-length strings (64-byte SHA-256 hex) before comparing. SHA-256 hashing is constant-time with respect to input, and `hash_equals` on equal-length strings is constant-time. This ensures `hash_equals` always runs.

**Files:**
- Modify: `backend/public/install.php`
- Create: `e2etests/tests/api/security-critical.spec.ts` (shared test file for all three tasks)

- [ ] **Step 1.1: Write the failing E2E test**

Create `e2etests/tests/api/security-critical.spec.ts` with the install.php section:

```typescript
import { test, expect } from '@playwright/test';

const API_BASE = 'http://localhost:8080';
const DEV_INSTALL_KEY = 'dev-install-key-x'; // must be ≥16 chars

// ============================================================
// C1: install.php access control
// ============================================================
test.describe('C1: install.php access control', () => {
  test('returns 403 when X-Install-Key header is absent', async ({ request }) => {
    const response = await request.get(`${API_BASE}/install.php?action=status`);
    expect(response.status()).toBe(403);
    const body = await response.json();
    expect(body.error).toBe('Forbidden');
  });

  test('returns 403 when X-Install-Key header is wrong', async ({ request }) => {
    const response = await request.get(`${API_BASE}/install.php?action=status`, {
      headers: { 'X-Install-Key': 'wrong-key-value-here' },
    });
    expect(response.status()).toBe(403);
  });

  test('returns 403 when X-Install-Key header is empty string', async ({ request }) => {
    const response = await request.get(`${API_BASE}/install.php?action=status`, {
      headers: { 'X-Install-Key': '' },
    });
    expect(response.status()).toBe(403);
  });

  test('returns 200 with correct X-Install-Key header', async ({ request }) => {
    const response = await request.get(`${API_BASE}/install.php?action=status`, {
      headers: { 'X-Install-Key': DEV_INSTALL_KEY },
    });
    // 200 = status action succeeded; 429 = concurrency lock (also acceptable)
    expect([200, 429]).toContain(response.status());
  });
});
```

- [ ] **Step 1.2: Run to confirm tests exist and can be found**

```bash
cd e2etests && npm test -- tests/api/security-critical.spec.ts --workers=1
```

Expected: 3 tests pass (403 cases hit current code correctly), 1 test with correct key also passes.
If any fail unexpectedly, check that `INSTALL_KEY=dev-install-key-x` is set in `docker-compose.yml` and PHP is running.

- [ ] **Step 1.3: Fix `install.php` — always run hash_equals**

In `backend/public/install.php`, replace lines 15–28 (the access control section):

**Before:**
```php
// --- Access control ---
$installKey  = Env::get('INSTALL_KEY', '');
$providedKey = $_SERVER['HTTP_X_INSTALL_KEY'] ?? '';

// strlen intentional: enforces minimum byte length, not character count
$keyNotConfigured = $installKey === '';
$keyTooShort      = !$keyNotConfigured && strlen($installKey) < 16;
$keyMismatch      = !$keyNotConfigured && !$keyTooShort && !hash_equals($installKey, $providedKey);

if ($keyNotConfigured || $keyTooShort || $keyMismatch) {
    http_response_code(403);
    header('Content-Type: application/json');
    exit(json_encode(['error' => 'Forbidden']));
}
```

**After:**
```php
// --- Access control ---
$installKey  = Env::get('INSTALL_KEY', '');
$providedKey = $_SERVER['HTTP_X_INSTALL_KEY'] ?? '';

// Hash both values to equal length before comparing.
// This ensures hash_equals() always runs (no short-circuit) and
// both inputs are always 64 bytes (no length timing leak).
$installHash = hash('sha256', $installKey);
$providedHash = hash('sha256', $providedKey);
$keyMatch = hash_equals($installHash, $providedHash);

// strlen intentional: enforces minimum byte length, not character count
$keyInvalid = ($installKey === '') || (strlen($installKey) < 16) || !$keyMatch;

if ($keyInvalid) {
    http_response_code(403);
    header('Content-Type: application/json');
    exit(json_encode(['error' => 'Forbidden']));
}
```

- [ ] **Step 1.4: Restart PHP and run the tests**

```bash
docker compose exec backend supervisorctl restart php-fpm:php-fpmd && sleep 2
cd e2etests && npm test -- tests/api/security-critical.spec.ts --workers=1
```

Expected: All C1 tests pass.

- [ ] **Step 1.5: Commit**

```bash
git add backend/public/install.php e2etests/tests/api/security-critical.spec.ts
git commit -m "fix(security): install.php always runs hash_equals — close C1 timing attack

Previously hash_equals only ran when INSTALL_KEY was configured and ≥16 chars.
Timing differences exposed whether the key was empty or too short.

Now both values are SHA-256 hashed before comparison — hash_equals always
runs on equal-length 64-byte inputs, closing the timing side channel.

Ref: plans/2026-03-17-security-critical-fixes.md C1"
```

---

## Task 2: Fix Wildcard CORS (C2)

**Files:** `backend/src/Shared/Middleware/CorsMiddleware.php`, `backend/src/ServiceFactory.php`, `backend/.env.example`, `docker-compose.yml`

**The problem (two parts):**

1. `CorsMiddleware` sends `Access-Control-Allow-Credentials: true` unconditionally — even when `Allow-Origin: *`. This violates the CORS spec (browsers reject `credentials=true` with wildcard origin) and is a misconfiguration.
2. `CORS_ORIGINS=*` is the shipped default in both `.env.example` and `docker-compose.yml`, so every deployer starts with wildcard CORS on their member data API.

**The fix:**
- In `CorsMiddleware`: only send `Allow-Credentials: true` when origin is a specific domain (not `*`)
- In `.env.example`: change to `http://localhost:5173` with a comment
- In `docker-compose.yml`: change to `http://localhost:5173,http://localhost:5174` (admin + terminal)

**Files:**
- Modify: `backend/src/Shared/Middleware/CorsMiddleware.php`
- Modify: `backend/.env.example`
- Modify: `docker-compose.yml`
- Modify: `e2etests/tests/api/security-critical.spec.ts` (add C2 tests)

- [ ] **Step 2.1: Add C2 tests to the spec file**

Append to `e2etests/tests/api/security-critical.spec.ts`:

```typescript
// ============================================================
// C2: CORS — no wildcard with credentials
// ============================================================
test.describe('C2: CORS configuration', () => {
  test('does not echo Access-Control-Allow-Origin: * on API response', async ({ request }) => {
    const response = await request.get(`${API_BASE}/api/health`, {
      headers: { Origin: 'http://evil.example.com' },
    });
    // Should not reflect a wildcard or an unknown origin
    const allowOrigin = response.headers()['access-control-allow-origin'];
    // Either no header (origin not in allowlist) or the specific origin — never '*' when credentials present
    if (allowOrigin) {
      expect(allowOrigin).not.toBe('*');
    }
  });

  test('allows requests from localhost:5173 (admin frontend)', async ({ request }) => {
    const response = await request.get(`${API_BASE}/api/health`, {
      headers: { Origin: 'http://localhost:5173' },
    });
    expect(response.ok()).toBeTruthy();
    const allowOrigin = response.headers()['access-control-allow-origin'];
    // Must be the specific origin, not wildcard
    expect(allowOrigin).toBe('http://localhost:5173');
  });

  test('does not send Allow-Credentials: true with a wildcard origin', async ({ request }) => {
    // Set CORS_ORIGINS=* scenario: even if misconfigured, credentials header must not accompany *
    // This test runs against the configured (fixed) state
    const response = await request.options(`${API_BASE}/api/health`, {
      headers: {
        Origin: 'http://localhost:5173',
        'Access-Control-Request-Method': 'GET',
      },
    });
    const allowOrigin = response.headers()['access-control-allow-origin'];
    const allowCredentials = response.headers()['access-control-allow-credentials'];

    // If origin is *, credentials must not be true
    if (allowOrigin === '*') {
      expect(allowCredentials).not.toBe('true');
    }
  });
});
```

- [ ] **Step 2.2: Run C2 tests to see current state**

```bash
cd e2etests && npm test -- tests/api/security-critical.spec.ts --grep "C2" --workers=1
```

Note which tests fail — the `localhost:5173` test will fail because `CORS_ORIGINS=*` currently reflects `*` not the specific origin.

- [ ] **Step 2.3: Fix `CorsMiddleware.php` — don't send credentials with wildcard**

In `backend/src/Shared/Middleware/CorsMiddleware.php`, replace the `process()` method body:

**Before (line 35–41):**
```php
if ($allowOrigin) {
    $response = $response
        ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
        ->withHeader('Access-Control-Allow-Credentials', 'true')
        ->withHeader('Access-Control-Max-Age', '86400');
}
```

**After:**
```php
if ($allowOrigin) {
    $response = $response
        ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-CSRF-Token')
        ->withHeader('Access-Control-Max-Age', '86400');

    // Credentials (cookies/session) only work with a specific origin — never with wildcard.
    // https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS#requests_with_credentials
    if ($allowOrigin !== '*') {
        $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
    }
}
```

- [ ] **Step 2.4: Update `.env.example` — change CORS default**

In `backend/.env.example`, replace:
```
CORS_ORIGINS=*
```
with:
```
# Comma-separated list of allowed frontend origins. Never use * in production.
# Example: CORS_ORIGINS=https://admin.your-club.de,https://terminal.your-club.de
CORS_ORIGINS=http://localhost:5173
```

Also update `APP_URL` comment if needed (no code change, comment only).

- [ ] **Step 2.5: Update `docker-compose.yml` — change CORS for local dev**

In `docker-compose.yml`, replace (line 71):
```yaml
CORS_ORIGINS: "*"
```
with:
```yaml
# Local dev: allow both admin (5173) and terminal (5174) frontends
CORS_ORIGINS: "http://localhost:5173,http://localhost:5174"
```

- [ ] **Step 2.6: Restart PHP and run all C2 tests**

```bash
docker compose exec backend supervisorctl restart php-fpm:php-fpmd && sleep 2
cd e2etests && npm test -- tests/api/security-critical.spec.ts --grep "C2" --workers=1
```

Expected: All C2 tests pass.

- [ ] **Step 2.7: Run full E2E suite to catch CORS regressions**

CORS changes can silently break the authenticated admin API tests (they send session cookies cross-origin). Run the full suite:

```bash
cd e2etests && npm test -- --workers=4
```

Expected: All tests pass. If any admin auth tests fail with CORS errors, check that `http://localhost:5173` is in the allowlist. (The Playwright test runner connects directly to `localhost:8080` without an Origin header — so most API tests are unaffected.)

- [ ] **Step 2.8: Commit**

```bash
git add backend/src/Shared/Middleware/CorsMiddleware.php backend/.env.example docker-compose.yml e2etests/tests/api/security-critical.spec.ts
git commit -m "fix(security): remove wildcard CORS default, fix credentials/origin conflict

- CorsMiddleware no longer sends Allow-Credentials: true with wildcard origin
  (browsers reject this combination; it's a spec violation and misconfiguration)
- .env.example defaults CORS_ORIGINS to localhost:5173 instead of *
- docker-compose.yml defaults to localhost:5173,localhost:5174 for local dev
- Added X-CSRF-Token to allowed CORS headers (required for admin session API)

Ref: plans/2026-03-17-security-critical-fixes.md C2"
```

---

## Task 3: Fix APP_DEBUG=true as Default (C3)

**Files:** `backend/.env.example`, `docker-compose.yml`

**The problem:** `APP_DEBUG=true` in shipped configs causes `ErrorHandler` to append the full PHP stack trace to every non-auth error response (line 66–68 of `ErrorHandler.php`). Stack traces expose internal file paths, class names, database query patterns, and library versions — all useful to attackers.

**The fix:** Change `APP_DEBUG=false` in both shipped config files. Local developers who want debug output set it locally (or keep the docker-compose value, documented with a comment).

**Important nuance:** `docker-compose.yml` is explicitly a dev environment file. `APP_DEBUG=true` there is defensible. However, it's better to default to `false` and let developers opt in — this models production behavior locally and avoids the risk of a dev compose being used in a non-dev context.

**Files:**
- Modify: `backend/.env.example`
- Modify: `docker-compose.yml`
- Modify: `e2etests/tests/api/security-critical.spec.ts` (add C3 test)

- [ ] **Step 3.1: Add C3 test to the spec file**

Append to `e2etests/tests/api/security-critical.spec.ts`:

```typescript
// ============================================================
// C3: Error responses must not expose stack traces
// ============================================================
test.describe('C3: No stack traces in error responses', () => {
  test('404 response does not contain a stack trace', async ({ request }) => {
    const response = await request.get(`${API_BASE}/api/nonexistent-endpoint-xyz`);
    expect(response.status()).toBe(404);
    const body = await response.json();
    // Stack traces contain file paths like /app/src/...
    expect(body).not.toHaveProperty('trace');
    expect(JSON.stringify(body)).not.toContain('/app/');
    expect(JSON.stringify(body)).not.toContain('Stack trace');
  });

  test('404 response has error and message fields only', async ({ request }) => {
    const response = await request.get(`${API_BASE}/api/nonexistent-endpoint-xyz`);
    const body = await response.json();
    expect(body).toHaveProperty('error');
    expect(body).toHaveProperty('message');
    // No internal fields
    expect(body).not.toHaveProperty('trace');
    expect(body).not.toHaveProperty('file');
    expect(body).not.toHaveProperty('line');
  });
});
```

- [ ] **Step 3.2: Run C3 tests to confirm they currently FAIL (debug is on)**

```bash
cd e2etests && npm test -- tests/api/security-critical.spec.ts --grep "C3" --workers=1
```

Expected: Tests FAIL — the body currently contains `trace` because `APP_DEBUG=true` in docker-compose.yml. This confirms the vulnerability is real and the tests catch it.

- [ ] **Step 3.3: Fix `docker-compose.yml` — set APP_DEBUG=false**

In `docker-compose.yml`, replace line 55:
```yaml
APP_DEBUG: "true"
```
with:
```yaml
# Set to "true" locally to see stack traces in error responses. Must be false in production.
APP_DEBUG: "false"
```

- [ ] **Step 3.4: Fix `.env.example` — set APP_DEBUG=false**

In `backend/.env.example`, line 2 is already handled by the CORS task above. Ensure it reads:
```
APP_DEBUG=false
```
(not `true`). Add a comment above it:
```
# Never enable in production — exposes stack traces in API error responses
APP_DEBUG=false
```

- [ ] **Step 3.5: Restart PHP and run C3 tests — they must now pass**

```bash
docker compose exec backend supervisorctl restart php-fpm:php-fpmd && sleep 2
cd e2etests && npm test -- tests/api/security-critical.spec.ts --grep "C3" --workers=1
```

Expected: Both C3 tests pass — no `trace` field in 404 response.

- [ ] **Step 3.6: Run full security test suite to verify all three fixes together**

```bash
cd e2etests && npm test -- tests/api/security-critical.spec.ts --workers=1
```

Expected: All tests pass (C1 + C2 + C3).

- [ ] **Step 3.7: Run full E2E suite to check for regressions**

```bash
cd e2etests && npm test -- --workers=4
```

Expected: All tests pass. No regressions from any of the three fixes.

- [ ] **Step 3.8: Commit**

```bash
git add docker-compose.yml backend/.env.example e2etests/tests/api/security-critical.spec.ts
git commit -m "fix(security): set APP_DEBUG=false as default in all shipped configs

Previously APP_DEBUG=true in both .env.example and docker-compose.yml caused
ErrorHandler to append full PHP stack traces to all non-auth error responses.
Stack traces expose internal file paths, class structure, and query patterns.

To re-enable debug locally: set APP_DEBUG=true in docker-compose.yml and
restart PHP: docker compose exec backend supervisorctl restart php-fpm:php-fpmd

Ref: plans/2026-03-17-security-critical-fixes.md C3"
```

---

## Final Verification

After all three tasks are complete, run the complete verification:

```bash
# 1. Full E2E suite — no regressions
cd e2etests && npm test -- --workers=4

# 2. Security-specific tests — all pass
cd e2etests && npm test -- tests/api/security-critical.spec.ts --workers=1

# 3. Manually confirm no stack trace in error response
curl -s http://localhost:8080/api/does-not-exist | jq .
# Expected: {"error":"not_found","message":"..."} — no "trace" key

# 4. Confirm correct CORS header
curl -s -H "Origin: http://localhost:5173" http://localhost:8080/api/health -I | grep -i access-control
# Expected: Access-Control-Allow-Origin: http://localhost:5173 (not *)

# 5. Confirm install.php rejects wrong key
curl -s -o /dev/null -w "%{http_code}" -H "X-Install-Key: wrongkey" http://localhost:8080/install.php?action=status
# Expected: 403
```

| Check | Expected |
|-------|----------|
| Full E2E suite (4 workers) | All pass |
| `security-critical.spec.ts` (1 worker) | All pass |
| `curl /api/does-not-exist` | No `trace` field in JSON |
| `curl -H "Origin: evil.com" /api/health` | No `Access-Control-Allow-Origin` header |
| `curl -H "Origin: http://localhost:5173" /api/health` | `Access-Control-Allow-Origin: http://localhost:5173` |
| `curl -H "X-Install-Key: wrongkey" /install.php` | `403` |

---

## Update the Security Review Document

After all tasks are complete, mark the three critical items as resolved in `plans/2026-03-17-backend-security-review.md`:

- [x] **C1** Fix timing attack in `install.php`
- [x] **C2** Remove CORS wildcard default — require explicit `CORS_ORIGINS` env var
- [x] **C3** Set `APP_DEBUG=false` in `.env.example` and `docker-compose.yml`
