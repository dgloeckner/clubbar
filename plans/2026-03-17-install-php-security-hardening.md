# install.php Security Hardening

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close three security holes in `install.php` and `Env::get()` that allow the installer to run in production without a key, expose credentials in server logs, and silently misread env vars.

**Architecture:** Pure PHP fixes — no new dependencies, no framework changes. Each task is self-contained and independently testable.

**Tech Stack:** PHP 8.3, PHPUnit (unit tests), curl (manual verification)

---

## Security Issues Being Fixed

| # | Issue | File | Risk |
|---|-------|------|------|
| 1 | `Env::get()` operator precedence bug silently returns wrong value for falsy env vars (e.g. `'0'`) | `Env.php` | Medium — silent misconfiguration |
| 2 | `install.php` accepts `?key=` query param — key appears in server access logs and browser history | `install.php` | High — credential exposure |
| 3 | `install.php` has no minimum key length — weak keys like `"x"` pass the check | `install.php` | Medium — weak auth bypass |

> **Note on the production bypass:** `install.php` on production shows the installer without requiring a key. This is most likely caused by the production server running an older version of `install.php` that lacks the `$installKey === ''` guard entirely. After this plan is applied and deployed, all three code-level issues are resolved. The deployment step itself (Task 4) is what actually fixes the live server.

---

## Files Changed

| File | Action | What changes |
|------|--------|--------------|
| `backend/src/Shared/Config/Env.php` | Modify | Fix operator precedence in `get()` |
| `backend/public/install.php` | Modify | Header-only key, minimum length check |
| `backend/tests/Unit/Shared/Config/EnvTest.php` | Create | Unit tests for `Env::get()` edge cases |

---

## Task 1: Fix `Env::get()` Operator Precedence Bug

**Problem:** In PHP, `??` has higher precedence than `?:`. The expression:
```php
self::$vars[$key] ?? $_ENV[$key] ?? getenv($key) ?: $default ?? throw ...
```
actually parses as:
```php
(self::$vars[$key] ?? $_ENV[$key] ?? getenv($key)) ?: ($default ?? throw ...)
```
If a var is set in the system environment to a falsy string like `'0'`, `getenv()` returns `'0'`, the `??` chain yields `'0'`, then `'0' ?: $default` returns `$default` instead of `'0'`. Silent misconfiguration.

**Files:**
- Create: `backend/tests/Unit/Shared/Config/EnvTest.php`
- Modify: `backend/src/Shared/Config/Env.php`

- [ ] **Step 1.1: Write the failing tests**

Create `backend/tests/Unit/Shared/Config/EnvTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Config;

use App\Shared\Config\Env;
use PHPUnit\Framework\TestCase;

class EnvTest extends TestCase
{
    protected function setUp(): void
    {
        Env::reset();
    }

    protected function tearDown(): void
    {
        Env::reset();
    }

    public function test_get_returns_value_from_loaded_vars(): void
    {
        // Simulate loading a .env file
        $_SERVER['TEST_KEY_LOADED'] = 'hello';
        // Use the static vars path via a temp file approach isn't easy — use $_ENV directly
        $_ENV['TEST_KEY_ENV'] = 'from-env';

        $this->assertSame('from-env', Env::get('TEST_KEY_ENV', 'default'));

        unset($_ENV['TEST_KEY_ENV']);
    }

    public function test_get_returns_default_when_key_not_set(): void
    {
        $result = Env::get('DEFINITELY_NOT_SET_XYZ_123', 'my-default');

        $this->assertSame('my-default', $result);
    }

    public function test_get_throws_when_key_not_set_and_no_default(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing env var: DEFINITELY_NOT_SET_XYZ_123');

        Env::get('DEFINITELY_NOT_SET_XYZ_123');
    }

    public function test_get_returns_falsy_string_zero_not_default(): void
    {
        // This is the bug: '0' is falsy, must NOT return the default
        $_ENV['TEST_FALSY_ZERO'] = '0';

        $result = Env::get('TEST_FALSY_ZERO', 'default');

        $this->assertSame('0', $result, 'Falsy string "0" must be returned, not the default');

        unset($_ENV['TEST_FALSY_ZERO']);
    }

    public function test_get_returns_empty_string_not_default_when_explicitly_set(): void
    {
        // Empty string set in $_ENV should return empty string, not default
        $_ENV['TEST_EMPTY_STRING'] = '';

        $result = Env::get('TEST_EMPTY_STRING', 'default');

        $this->assertSame('', $result, 'Empty string must be returned as-is, not replaced by default');

        unset($_ENV['TEST_EMPTY_STRING']);
    }

    public function test_get_empty_string_install_key_returns_empty(): void
    {
        // The install.php check depends on this: empty key must return '' not something else
        $_ENV['INSTALL_KEY'] = '';

        $result = Env::get('INSTALL_KEY', '');

        $this->assertSame('', $result);

        unset($_ENV['INSTALL_KEY']);
    }
}
```

- [ ] **Step 1.2: Run tests and confirm failures**

```bash
cd backend && php artisan test tests/Unit/Shared/Config/EnvTest.php
```

Expected: `test_get_returns_falsy_string_zero_not_default` and `test_get_returns_empty_string_not_default_when_explicitly_set` FAIL. Other tests may pass or fail depending on current behavior.

- [ ] **Step 1.3: Fix `Env::get()` — rewrite with explicit, unambiguous logic**

In `backend/src/Shared/Config/Env.php`, replace the `get()` method:

```php
public static function get(string $key, ?string $default = null): string
{
    if (array_key_exists($key, self::$vars)) {
        return self::$vars[$key];
    }

    if (array_key_exists($key, $_ENV)) {
        return (string) $_ENV[$key];
    }

    $fromSystem = getenv($key);
    if ($fromSystem !== false) {
        return $fromSystem;
    }

    if ($default !== null) {
        return $default;
    }

    throw new \RuntimeException("Missing env var: {$key}");
}
```

**Why this fixes it:** Uses `array_key_exists()` (not `??`) so empty strings and `'0'` are returned correctly. `getenv()` returns `false` for missing vars (not `null`), so `!== false` is the right check, not `??`.

- [ ] **Step 1.4: Run all unit tests and confirm they pass**

```bash
cd backend && php artisan test tests/Unit/Shared/Config/EnvTest.php
```

Expected: `6 passed`.

- [ ] **Step 1.5: Run full backend test suite to check for regressions**

```bash
cd backend && php artisan test
```

Expected: All tests pass.

- [ ] **Step 1.6: Commit**

```bash
git add backend/src/Shared/Config/Env.php backend/tests/Unit/Shared/Config/EnvTest.php
git commit -m "fix: resolve Env::get() operator precedence bug for falsy env var values"
```

---

## Task 2: Harden `install.php` Access Control

**Two problems to fix:**

**Problem A — Key in query param:** `?key=...` appears in Apache/nginx access logs:
```
GET /install.php?action=migrate&key=my-secret-key HTTP/1.1
```
Anyone with log access (sysadmin, monitoring tools, log aggregators) sees the key in plaintext.

**Problem B — No minimum key length:** `INSTALL_KEY=x` passes the `hash_equals` check. Operators can accidentally set a trivially-guessable key.

**Files:**
- Modify: `backend/public/install.php`

- [ ] **Step 2.1: Write a failing integration test via curl**

This verifies current behavior before changes. Run these and note the responses:

```bash
# Should return 403 (no key at all)
curl -s -o /dev/null -w "%{http_code}" "http://localhost:8080/install.php?action=status"

# Should return 200 with dev key via query param (current behavior — will break after fix)
curl -s -o /dev/null -w "%{http_code}" "http://localhost:8080/install.php?action=status&key=dev-install-key"

# Should return 200 with dev key via header (should still work after fix)
curl -s -o /dev/null -w "%{http_code}" -H "X-Install-Key: dev-install-key" "http://localhost:8080/install.php?action=status"
```

Expected now: first=403, second=200, third=200. After fix: first=403, second=403, third=200.

- [ ] **Step 2.2: Update `install.php` — remove query param, add minimum key length**

Replace the access control section in `backend/public/install.php` (lines 15–23):

```php
// --- Access control ---
$installKey  = Env::get('INSTALL_KEY', '');
$providedKey = $_SERVER['HTTP_X_INSTALL_KEY'] ?? '';

$keyMissing    = $installKey === '';
$keyTooShort   = strlen($installKey) < 16;
$keyMismatch   = !hash_equals($installKey, $providedKey);

if ($keyMissing || $keyTooShort || $keyMismatch) {
    http_response_code(403);
    header('Content-Type: application/json');
    exit(json_encode(['error' => 'Forbidden']));
}
```

**Why header-only:** The `HTTP_X_INSTALL_KEY` server variable is set by Apache from the `X-Install-Key` request header. It never appears in access logs (Apache only logs the request line and status, not request headers unless explicitly configured to do so).

**Why 16 chars minimum:** Prevents accidental weak keys like `test`, `1234`, `dev`. 16 random hex chars = 64 bits of entropy — sufficient for a low-frequency install endpoint.

- [ ] **Step 2.3: Update DEV_SETUP.md — replace ?key= with -H header**

In `DEV_SETUP.md`, find all curl examples that use `?key=dev-install-key` and replace them. Example change:

Before:
```bash
curl -sf "http://localhost:8080/install.php?action=migrate&key=dev-install-key"
```

After:
```bash
curl -sf -H "X-Install-Key: dev-install-key" "http://localhost:8080/install.php?action=migrate"
```

Apply this to ALL curl examples in DEV_SETUP.md that reference the install key.

- [ ] **Step 2.4: Update docker-compose.yml — ensure dev key is ≥ 16 chars**

In `docker-compose.yml`, the current `INSTALL_KEY: dev-install-key` is exactly 15 chars — one short of the new minimum. Update it:

```yaml
INSTALL_KEY: dev-install-key-x
```

(16 chars — satisfies the minimum for local dev.)

- [ ] **Step 2.5: Verify the fix with curl**

```bash
# Restart PHP to pick up any env changes
docker compose exec backend supervisorctl restart php-fpm:php-fpmd && sleep 2

# Must return 403 — query param no longer accepted
curl -s -o /dev/null -w "%{http_code}" "http://localhost:8080/install.php?action=status&key=dev-install-key-x"

# Must return 200 — header still works
curl -s -o /dev/null -w "%{http_code}" -H "X-Install-Key: dev-install-key-x" "http://localhost:8080/install.php?action=status"

# Must return 403 — short key rejected even if it matches
# (Test by temporarily changing INSTALL_KEY in docker-compose.yml to "short" and restarting)
```

Expected: first=403, second=200.

- [ ] **Step 2.6: Commit**

```bash
git add backend/public/install.php DEV_SETUP.md docker-compose.yml
git commit -m "fix: restrict install.php key to X-Install-Key header only, enforce 16-char minimum"
```

---

## Task 3: Block install.php at the Web Server Level

Even with a key check, `install.php` on a live production server is an attack surface that shouldn't exist after initial setup. Add a `.htaccess` rule that **denies all access to `install.php`** by default, requiring an explicit override to allow it through (used only during initial setup or migrations).

**Files:**
- Modify: `backend/public/.htaccess`

- [ ] **Step 3.1: Add deny rule to `.htaccess`**

Add to `backend/public/.htaccess`, after the `RedirectMatch 403 /\.(.*)$` line:

```apache
# Block installer in production. Remove or comment this line only during initial setup/migrations.
# Re-add immediately after. The installer has its own key-based auth, but defense-in-depth matters.
<Files "install.php">
    Require all denied
</Files>
```

> **Important:** This rule must be **commented out** (or removed) during initial deployment/migrations, then **restored immediately** after. Add this note to the deployment documentation.

- [ ] **Step 3.2: Verify the block works locally**

```bash
# Must return 403 (blocked by .htaccess)
curl -s -o /dev/null -w "%{http_code}" -H "X-Install-Key: dev-install-key-x" "http://localhost:8080/install.php?action=status"
```

Expected: 403.

> **Local dev note:** You'll need to comment out the `<Files "install.php">` block during local development if you need to run migrations via curl. The `php artisan migrate` CLI approach avoids this entirely — use that for local dev.

- [ ] **Step 3.3: Update DEV_SETUP.md — document the .htaccess toggle**

Add a note in DEV_SETUP.md explaining:
- `install.php` is blocked by `.htaccess` in all environments
- To run migrations via HTTP, temporarily comment out the `<Files "install.php">` block
- For local dev, prefer: `docker compose exec backend php artisan migrate` instead

- [ ] **Step 3.4: Commit**

```bash
git add backend/public/.htaccess DEV_SETUP.md
git commit -m "fix: block install.php via .htaccess by default — uncomment only during migrations"
```

---

## Task 4: Deploy to Production

- [ ] **Step 4.1: Deploy updated code to production server**

Push to main and deploy via your normal deployment process. The production server at `ruderbar.rudern-in-frankfurt.de` needs the new code.

- [ ] **Step 4.2: Verify install.php is now blocked on production**

```bash
curl -s -o /dev/null -w "%{http_code}" "https://ruderbar.rudern-in-frankfurt.de/install.php"
```

Expected: 403.

- [ ] **Step 4.3: Verify the rest of the API still works**

```bash
curl -s "https://ruderbar.rudern-in-frankfurt.de/api/health" | jq .
```

Expected: `{"status": "ok"}` (or equivalent health response).

---

## Verification Summary

After all tasks complete:

| Check | Command | Expected |
|-------|---------|----------|
| `install.php` blocked on prod | `curl -s -o /dev/null -w "%{http_code}" https://ruderbar.rudern-in-frankfurt.de/install.php` | `403` |
| Key in query param rejected locally | `curl -s -o /dev/null -w "%{http_code}" "http://localhost:8080/install.php?key=dev-install-key-x"` | `403` |
| Header key still works locally (after removing htaccess block) | `curl -s -o /dev/null -w "%{http_code}" -H "X-Install-Key: dev-install-key-x" "http://localhost:8080/install.php?action=status"` | `200` |
| Short key rejected | Set `INSTALL_KEY=short` in docker-compose, restart PHP, hit endpoint | `403` |
| `Env::get('KEY', 'default')` returns `'0'` not `'default'` when `KEY=0` | PHPUnit `EnvTest` | `6 passed` |
| No regressions in backend suite | `cd backend && php artisan test` | All pass |
