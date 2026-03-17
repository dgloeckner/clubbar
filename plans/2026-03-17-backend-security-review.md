# Backend Security Review

**Date:** 2026-03-17
**Scope:** Backend PHP codebase (`/backend/`)
**Focus:** Member data protection, OWASP Top 10, GDPR compliance

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 3 |
| HIGH | 6 |
| MEDIUM | 4 |
| LOW | 4 |

---

## CRITICAL

### C1 — Timing Attack on INSTALL_KEY Validation

**File:** `backend/public/install.php:16-28`

Early-return branches fire **before** `hash_equals()` is reached. An attacker measuring response times can distinguish between "key not configured", "key too short", and "wrong key" — leaking information about the installation key.

**Current logic:**
```php
if ($keyNotConfigured) { return 403; }
if ($keyTooShort)      { return 403; }
if (!hash_equals(...)) { return 403; }
```

**Remediation:** Collapse all three conditions into one expression so all branches take the same code path:
```php
$keyInvalid = $keyNotConfigured || $keyTooShort || !hash_equals($installKey, $providedKey);
if ($keyInvalid) {
    http_response_code(403);
    // ...
}
```

---

### C2 — Wildcard CORS Origin (`*`)

**File:** `backend/src/Shared/Middleware/CorsMiddleware.php:17`

The default constructor sets `allowedOrigins = ['*']`, meaning any website can make cross-origin requests to the API.

**Remediation:**
- Remove `*` default; require explicit `CORS_ORIGINS` env var (comma-separated)
- Reject `*` when credentials are enabled

---

### C3 — `APP_DEBUG=true` as Default

**Files:** `backend/.env.example`, `docker-compose.yml:55`

Debug mode enabled by default causes stack traces, file paths, and database schema details to appear in error responses. `ErrorHandler.php:66-67` exposes these when debug is on.

**Remediation:** Default to `APP_DEBUG=false` in all shipped configs. Only enable locally via `.env.local` (gitignored).

---

## HIGH

### H1 — No Rate Limiting on Admin Endpoints

**File:** `backend/src/routes.php:54-131`

`RateLimitMiddleware` is applied only to the `/login` route. All `/api/admin/*` endpoints (members, settlements, terminals, exports) have no rate limiting, enabling brute-force and resource exhaustion attacks.

**Remediation:** Apply `RateLimitMiddleware` to the `/api/admin` route group, with stricter limits on write operations and exports.

---

### H2 — Full IBAN Exposed in Settlement CSV Export

**File:** `backend/src/Modules/Settlements/Controllers/AdminController.php:275`

SEPA CSV export contains unmasked IBANs with no access audit trail, no download logging, and no encryption.

**Remediation:**
- Log every SEPA export (who, when, IP) to the audit log
- Consider encrypting the downloaded file at rest before serving
- Require confirmation step before triggering export

---

### H3 — Missing HTTP Security Headers

No middleware adds security headers to responses.

**Missing headers:**
- `Strict-Transport-Security` (HSTS) — no HTTPS enforcement
- `X-Frame-Options: DENY` — clickjacking possible
- `X-Content-Type-Options: nosniff` — MIME sniffing possible
- `Content-Security-Policy` — no XSS mitigation
- `Cache-Control: no-store` — browsers may cache member/IBAN data

**Remediation:** Add a `SecurityHeadersMiddleware` to the global middleware stack.

---

### H4 — No Session Regeneration on Login

**File:** `backend/src/Modules/Auth/Controllers/AuthController.php:59-65`

After successful login, the existing session ID is kept. An attacker who plants a session ID before login (session fixation) gains access.

**Remediation:** Call `session_regenerate_id(true)` immediately after verifying credentials and before writing to `$_SESSION`.

---

### H5 — Weak Default Database Credentials in Version Control

**File:** `docker-compose.yml:14-17, 59-63`

Username `clubbar` / password `clubbar` are committed to the repository. If these configs are used as-is in a non-local environment, the database is immediately compromised.

**Remediation:** Remove hardcoded defaults. Require credentials via environment variables with no fallback (`${DB_PASS:?DB_PASS must be set}`).

---

### H6 — CSV Injection in Settlement Export

**File:** `backend/src/Modules/Settlements/Controllers/AdminController.php:267-280`

Member names and emails are written to CSV without sanitization. Values starting with `=`, `+`, `-`, or `@` are interpreted as formulas by spreadsheet applications (Excel, LibreOffice), potentially executing arbitrary code or exfiltrating data.

**Remediation:** Prefix any field value starting with a formula character with a single quote (`'`) before writing to CSV.

---

## MEDIUM

### M1 — Terminal Auth Tokens Returned in Plaintext

**File:** `backend/src/Modules/Terminals/Controllers/AdminController.php`

Plaintext tokens are returned in API responses. If logs, browser history, or network traffic are captured, tokens are compromised.

**Remediation:** Return the token only once (at creation). Never return it again in any subsequent response. Add an audit log entry for token generation events.

---

### M2 — `install.php` Accessible After Setup (No Lock File)

**File:** `backend/public/install.php`

There is no persistent marker (lock file or DB flag) that prevents `install.php` from being re-run after a successful installation. An attacker with the install key could reinitialize the database.

**Remediation:** Write a lock file (e.g., `storage/.installed`) on first successful run. Reject all subsequent requests with 403 if lock file exists.

---

### M3 — Malformed JSON Body Silently Accepted

**File:** `backend/src/Shared/Middleware/JsonBodyParser.php:20-23`

If the request body is invalid JSON, `json_decode()` returns `null` and the middleware continues silently. The request proceeds with an empty parsed body, potentially bypassing validation logic that expects non-null fields.

**Remediation:** Return `400 Bad Request` with a descriptive error when `json_last_error() !== JSON_ERROR_NONE`.

---

### M4 — Audit Log Client IP Inaccurate Behind Proxies

**File:** `backend/src/Shared/Services/AuditService.php:34`

IP is read from `$_SERVER['REMOTE_ADDR']` without considering `X-Forwarded-For`. Behind a load balancer or reverse proxy, all audit entries record the proxy IP rather than the actual client IP.

**Remediation:** Trust `X-Forwarded-For` only from a whitelist of known proxy IPs. Extract the leftmost IP from the header when request comes from a trusted proxy.

---

## LOW

### L1 — Email Address Logged as Plaintext on Failed Login (GDPR)

**File:** `backend/src/Modules/Auth/Services/AuthService.php:22,27`

Failed login attempts log the submitted email address in plaintext. Email is PII under GDPR — storing it in logs without purpose limitation and retention controls is a compliance risk.

**Remediation:** Log a SHA-256 hash of the email instead of the plaintext value.

---

### L2 — Login Errors Enable Email Enumeration (via Logs)

**File:** `backend/src/Modules/Auth/Services/AuthService.php:21-23`

Log messages distinguish "unknown email" from "invalid password". While the HTTP response to the client is generic, the log distinction could enable enumeration if logs are accessible to multiple parties.

**Remediation:** Use a single generic log message: `'Login failed'` with a boolean `admin_exists` field (acceptable for internal security monitoring).

---

### L3 — No `Cache-Control` on Sensitive API Responses

No middleware sets `Cache-Control: no-store` on API responses. Browsers and intermediary caches may store member data, IBAN values, and transaction history.

**Remediation:** Add `Cache-Control: no-store, no-cache, must-revalidate` and `Pragma: no-cache` to all API responses in the security headers middleware.

---

### L4 — Content-Disposition Filename Unencoded

**File:** `backend/src/Modules/Settlements/Controllers/AdminController.php:206,226,246`

Export filenames use the settlement UUID directly in `Content-Disposition`. UUIDs are safe in practice, but the value is not sanitized or encoded.

**Remediation:** Strip non-alphanumeric/hyphen characters with `preg_replace('/[^a-zA-Z0-9-]/', '', $id)` before using in the header value.

---

## Immediate Action Items

### This week (critical + quick wins)

- [x] **C1** Fix timing attack in `install.php` — always hash both keys before `hash_equals()` (commit e661666)
- [x] **C2** Remove CORS wildcard default — `CORS_ORIGINS` now defaults to specific origins; `Allow-Credentials` never sent with `*` (commit 8d96e9d)
- [x] **C3** Set `APP_DEBUG=false` in `.env.example` and `docker-compose.yml` (commit 4cd0027)
- [x] **H4** Add `session_regenerate_id(true)` on login (commit cdc4f5f, ADR-0025)
- [ ] **H3** Add `SecurityHeadersMiddleware` (HSTS, X-Frame-Options, CSP, Cache-Control)
- [ ] **H6** Fix CSV injection in settlement export
- [ ] **M3** Return 400 on malformed JSON request body

### Next sprint

- [ ] **H1** Apply rate limiting to `/api/admin` route group
- [ ] **H2** Add audit log entry for every SEPA/IBAN export download
- [ ] **H5** Remove hardcoded DB credentials from `docker-compose.yml`
- [ ] **M1** Remove token from responses after initial creation
- [ ] **M2** Add lock file check to `install.php`
- [ ] **M4** Fix client IP detection behind proxies in `AuditService`

### GDPR compliance

- [ ] **L1** Hash email addresses in authentication logs
- [ ] **L3** Add `Cache-Control: no-store` to all API responses

---

## References

- OWASP Top 10: https://owasp.org/www-project-top-ten/
- GDPR Art. 5 (data minimization, storage limitation)
- GDPR Art. 17 (right to erasure — see also GDPR Anonymization Fix plan)
- ADR-0022: Test Strategy (verify fixes with tests before merging)
