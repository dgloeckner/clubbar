# Terminal Auth Rate Limiting — Design Spec

**Date:** 2026-03-17
**Status:** Approved

---

## Problem

Terminal sync endpoints (`/api/sync/*`, `/api/terminal/transactions/{memberId}`) are protected by bearer token authentication but have no rate limiting on failed attempts. An attacker who knows the server IP can probe for valid terminal tokens by cycling through guesses — one 401 per attempt, no throttle.

**Threat model:** IP-based brute-force of terminal bearer tokens. A compromised token is out of scope (revoke it via admin panel).

---

## Solution

Rate-limit by **IP address** on **failed** terminal auth attempts — the same pattern already used for admin login (`RateLimitMiddleware` + `login_attempts` table). Terminal attempts are tracked in a dedicated table to keep the two flows independent and separately tunable.

**Limits:** 10 failed attempts per 15-minute window per IP. On the 11th attempt: `429 Too Many Requests`.

---

## Components

### 1. Migration — `terminal_auth_attempts` table

New file: `backend/db/migrations/003_terminal_auth_attempts.sql`

```sql
CREATE TABLE IF NOT EXISTS terminal_auth_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    ip_address   VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

No `email` column — terminals are identified by token, not email.

The migration runner (`MigrationRunner`) picks up files in numeric order from `db/migrations/`. Numbering `003` follows the existing `001_initial_schema.sql` and `002_login_attempts.sql`. Run via `install.php?action=migrate` after deploy.

**CI note:** The project's `.htaccess` blocks `install.php` by default. The CI pipeline already handles this (see commits `78a17ed`, `cebdeb5` — the workflow strips the Files block before running migrations). No additional CI changes are needed for this migration; it will be picked up automatically alongside existing migrations.

---

### 2. `RateLimitMiddleware` — make configurable

**File:** `backend/src/Shared/Middleware/RateLimitMiddleware.php`

Add constructor parameters with defaults that exactly preserve existing login behaviour:

| Parameter | Type | Default | Purpose |
|-----------|------|---------|---------|
| `$table` | `string` | `'login_attempts'` | Table to count rows from |
| `$maxAttempts` | `int` | `5` | Threshold before 429 |
| `$windowMinutes` | `int` | `15` | Rolling window |

**Critical:** The SQL `process()` method must reference `$this->table` (not the hardcoded string `'login_attempts'`). After this change, the login instance (default `$table = 'login_attempts'`) and the terminal instance (`$table = 'terminal_auth_attempts'`) both work correctly.

The 429 error message changes from `'Too many login attempts. Please try again later.'` to `'Too many failed authentication attempts. Please try again later.'` — a generic form that applies to both login and terminal contexts. This is an intentional, backward-compatible change (the error `code` `too_many_attempts` is unchanged).

The middleware only **reads** the table (pre-check count). Writing failure rows is the auth layer's responsibility.

**429 response body:**
```json
{
  "error": "too_many_attempts",
  "message": "Too many failed authentication attempts. Please try again later.",
  "retry_after_seconds": 900
}
```

`Retry-After` header set to `$windowMinutes * 60`.

---

### 3. `TerminalTokenAuth` — record failures

**File:** `backend/src/Modules/Auth/Middleware/TerminalTokenAuth.php`

**Pattern 005 deviation (conscious):** This middleware will inject `PDO` directly to record failure rows, following the same precedent as `AuthController` for login attempts. A dedicated `TerminalAuthAttemptsRepository` with a single `recordAttempt()` method would be more consistent with Pattern 005, but adds two files for one SQL statement. The deviation is acceptable here; if the failure-recording logic needs to grow, introduce the repository at that point.

Inject `PDO` alongside the existing `TerminalsRepository`. When the middleware returns 401 for any reason (missing header, wrong format, invalid token, inactive terminal), insert one row before returning the response:

```sql
INSERT INTO terminal_auth_attempts (ip_address) VALUES (:ip)
```

IP is read from `$request->getServerParams()['REMOTE_ADDR']`.

**On valid token: no insert.** There is no counter-reset on successful auth (unlike login, which deletes all attempt rows for the IP on success). Failed rows expire naturally as they age out of the 15-minute window.

---

### 4. `ServiceFactory` — wire terminal rate limiter

**File:** `backend/src/ServiceFactory.php`

Add `getTerminalRateLimitMiddleware()`:

```php
public function getTerminalRateLimitMiddleware(): RateLimitMiddleware
{
    return new RateLimitMiddleware($this->pdo, 'terminal_auth_attempts', 10, 15);
}
```

Update `getTerminalTokenAuth()` to inject `$this->pdo`:

```php
public function getTerminalTokenAuth(): TerminalTokenAuth
{
    return $this->resolve(TerminalTokenAuth::class,
        fn() => new TerminalTokenAuth($this->getTerminalsRepository(), $this->pdo)
    );
}
```

**No `FQCN_MAP` entry needed** for the terminal rate limit middleware. Slim 4 accepts a `MiddlewareInterface` instance directly in `->add()` — no container resolution required. The terminal rate limiter is passed as an instance (`$factory->getTerminalRateLimitMiddleware()`), not as a class name string.

---

### 5. `routes.php` — apply to terminal routes

**File:** `backend/src/routes.php`

Pass the instance from the factory (not the class name — see §4):

```php
$terminalRateLimit = $factory->getTerminalRateLimitMiddleware();

$app->group('/api/sync', function (RouteCollectorProxy $group) {
    // ... existing routes unchanged ...
})->add(TerminalTokenAuth::class)->add($terminalRateLimit);

$app->get('/api/terminal/transactions/{memberId}', ...)
    ->add(TerminalTokenAuth::class)
    ->add($terminalRateLimit);
```

Slim executes middleware in reverse-add order. Adding `$terminalRateLimit` last means it runs first (pre-check before auth). Adding `TerminalTokenAuth::class` first means it runs second (validates token, records failures).

---

## Data Flow

```
Incoming request to /api/sync/* or /api/terminal/transactions/{id}
         │
         ▼
TerminalRateLimitMiddleware (pre-check)
  SELECT COUNT(*) FROM terminal_auth_attempts
  WHERE ip_address = :ip
  AND attempted_at > NOW() - INTERVAL 15 MINUTE
         │
   count ≥ 10? ──YES──▶ 429 Too Many Requests (Retry-After: 900)
         │
        NO
         │
         ▼
TerminalTokenAuth (validate token)
         │
   invalid? ──YES──▶ INSERT INTO terminal_auth_attempts (ip_address = :ip)
         │                    │
         │                    ▼
         │             401 Unauthorized
         │
        NO (valid token, no insert)
         │
         ▼
   Route handler → 200
```

---

## Testing

Rate limiting cannot be reliably tested under parallel execution — all local test requests originate from `127.0.0.1`, and there is no API endpoint to reset the `terminal_auth_attempts` table between tests. The rate limit tests must run **serially and in isolation** from the rest of the suite.

**New file:** `e2etests/tests/api/terminal-rate-limit.spec.ts`

Use `test.describe.configure({ mode: 'serial' })` at the top level. Run this file explicitly when verifying rate limiting; it is excluded from the default parallel run.

Three tests:

1. **Baseline** — one bad token request returns 401 (rate limit not yet triggered)
2. **Rate limit triggered** — send 10 bad-token requests to `/api/sync/members`, assert 11th returns 429 with `error: 'too_many_attempts'` and `Retry-After` header present
3. **Valid token unaffected** — after the 10 bad requests, a valid token still returns 200 (rate limit only blocks further *failed* pre-checks, not valid traffic)

**Test prerequisite:** `terminal_auth_attempts` table must be empty. In CI, the database is always fresh. Locally, clear manually before running: `docker compose exec database mysql -uclubbar -pclubbar clubbar -e "TRUNCATE terminal_auth_attempts;"`.

**Running the file:**
```bash
cd e2etests
npm test -- tests/api/terminal-rate-limit.spec.ts --workers=1
```

---

## Files Changed

| File | Action |
|------|--------|
| `backend/db/migrations/003_terminal_auth_attempts.sql` | Create |
| `backend/src/Shared/Middleware/RateLimitMiddleware.php` | Modify — configurable constructor; SQL uses `$this->table`; generic error message |
| `backend/src/Modules/Auth/Middleware/TerminalTokenAuth.php` | Modify — inject PDO, record failures on 401 |
| `backend/src/ServiceFactory.php` | Modify — `getTerminalRateLimitMiddleware()`, update `getTerminalTokenAuth()` to pass PDO |
| `backend/src/routes.php` | Modify — apply terminal rate limit middleware instance to `/api/sync` group and `/api/terminal/transactions/{memberId}` |
| `e2etests/tests/api/terminal-rate-limit.spec.ts` | Create — serial rate limit tests |
