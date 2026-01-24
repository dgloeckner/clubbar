# Security Audit Checklist (Milestone 2.5)

**Phase**: Phase 1, Milestone 2.5
**Goal**: Verify all security implementations against ADR-0015 patterns before restructuring to modules (M3)
**Duration**: ~3-5 hours
**Reviewer**: Security-focused developer

---

## Overview

This checklist verifies that existing code and new security patterns provide comprehensive coverage of:
- **Authentication** (Patterns 012-013, ADR-0015)
- **Identification** (Pattern 014, ADR-0015)
- **Authorization** (Pattern 015, ADR-0015)
- **Transport Security** (ADR-0016)
- **Input Validation** (ADR-0017)
- **Error Handling** (Pattern 007)
- **Audit Logging** (ADR-0013)

---

## Checklist Categories

### 1. Authentication: Terminal API Token (Pattern 012)

**Objective**: Verify Terminal device authentication is secure

#### 1.1 Token Generation
- [ ] `TokenService::generateTerminalToken()` exists
- [ ] Generates 64-character hexadecimal string
- [ ] Uses `random_bytes(32)` (256-bit entropy)
- [ ] No hardcoded tokens or constants
- [ ] Test: Token is unique each time (run 3x, all different)

```bash
# Manual test
php artisan tinker
>>> App\Shared\Services\TokenService::generateTerminalToken()
# Should return different string each time
```

#### 1.2 Token Hashing (Storage)
- [ ] `TokenService::hashToken()` uses bcrypt
- [ ] Cost factor is 12 or higher
- [ ] Hash is different each time (bcrypt adds salt)
- [ ] Never stores plaintext token in database
- [ ] Test: Hash is ~60 chars, starts with `$2y$`

```bash
# Verify hash format
php artisan tinker
>>> $token = App\Shared\Services\TokenService::generateTerminalToken()
>>> $hash = App\Shared\Services\TokenService::hashToken($token)
>>> strlen($hash) == 60 && str_starts_with($hash, '$2y$')
# Should return true
```

#### 1.3 Token Validation (Verification)
- [ ] `TokenService::verifyToken()` uses `password_verify()`
- [ ] Constant-time comparison (no timing leaks)
- [ ] Returns true only for correct token+hash pair
- [ ] Rejects invalid tokens
- [ ] Test: Valid token matches hash, invalid doesn't

```bash
# Verify constant-time comparison
php artisan tinker
>>> $token = App\Shared\Services\TokenService::generateTerminalToken()
>>> $hash = App\Shared\Services\TokenService::hashToken($token)
>>> App\Shared\Services\TokenService::verifyToken($token, $hash)  # true
>>> App\Shared\Services\TokenService::verifyToken('invalid', $hash)  # false
```

#### 1.4 Middleware: AuthenticateTerminalToken
- [ ] Middleware class exists: `app/Http/Middleware/AuthenticateTerminalToken.php`
- [ ] Extracts Bearer token from `Authorization` header
- [ ] Validates token format (Bearer prefix)
- [ ] Compares against terminal hashes in database
- [ ] Returns 401 for invalid/missing tokens
- [ ] Attaches terminal to request for controller use
- [ ] Test: Manual curl with valid/invalid tokens

```bash
# Test with valid token
curl -H "Authorization: Bearer $VALID_TOKEN" http://localhost:8080/api/sync/members
# Should return 200

# Test with invalid token
curl -H "Authorization: Bearer invalid" http://localhost:8080/api/sync/members
# Should return 401

# Test without token
curl http://localhost:8080/api/sync/members
# Should return 401
```

#### 1.5 Terminal Pairing (Admin Setup)
- [ ] Admin endpoint exists to generate tokens: `POST /api/admin/terminals`
- [ ] Returns plaintext token (shown once only)
- [ ] Admin must save token (no recovery)
- [ ] Token not stored again after generation
- [ ] Test: Admin can create terminal and see token

#### 1.6 Token Rotation
- [ ] Endpoint exists: `POST /api/admin/terminals/{id}/rotate-token`
- [ ] Invalidates old token immediately
- [ ] Returns new plaintext token
- [ ] Old token becomes useless
- [ ] Test: Rotate token and verify old one fails

#### 1.7 Token Revocation
- [ ] Endpoint exists: `POST /api/admin/terminals/{id}/revoke`
- [ ] Sets token_hash to null or impossible value
- [ ] Terminal gets 401 on next sync attempt
- [ ] Test: Revoke terminal and verify sync fails

---

### 2. Authentication: Admin Session (Pattern 013)

**Objective**: Verify Admin user session authentication is secure

#### 2.1 Admin User Model
- [ ] `AdminUser` model exists
- [ ] Password field is hashed before storage (mutator)
- [ ] Password hidden in JSON output
- [ ] `isActive()` method exists
- [ ] Test: Password never returned in API responses

```bash
# Verify password not in response
curl http://localhost:8080/api/auth/profile -H "Cookie: session_id=..."
# Response should NOT contain "password" key
```

#### 2.2 Password Hashing
- [ ] Uses bcrypt with cost factor 12+
- [ ] Never stores plaintext passwords
- [ ] Different hash for same password (salt)
- [ ] Test: Hash is ~60 chars, starts with `$2y$`

```bash
# Verify in code
grep -r "Hash::make" backend/app/Models/AdminUser.php
# Should show cost => 12
```

#### 2.3 Login Endpoint
- [ ] `POST /api/auth/login` exists
- [ ] Accepts email + password (FormRequest validation)
- [ ] Validates email format
- [ ] Validates password length (8+ chars)
- [ ] Authenticates against stored hash
- [ ] Returns 401 for invalid credentials
- [ ] Generic error message ("Invalid email or password")
- [ ] Does NOT reveal if email exists
- [ ] Test: Valid login, invalid password, invalid email

```bash
# Valid login
curl -X POST http://localhost:8080/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@test.com","password":"password123"}'
# Should return 200 + Set-Cookie

# Invalid password
curl -X POST http://localhost:8080/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@test.com","password":"wrong"}'
# Should return 401

# Non-existent email
curl -X POST http://localhost:8080/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"nobody@test.com","password":"password123"}'
# Should return 401 (same message as wrong password)
```

#### 2.4 Session Creation
- [ ] `session_regenerate_id(true)` called after login
- [ ] Old session deleted (prevent fixation)
- [ ] New session ID generated
- [ ] user_id stored in session
- [ ] Test: Session ID changes after login

#### 2.5 Session Configuration (config/session.php)
- [ ] `driver: 'database'` (store in DB, not files)
- [ ] `lifetime: 120` (2-hour idle timeout)
- [ ] `http_only: true` (JavaScript can't access)
- [ ] `secure: true` (HTTPS only in production)
- [ ] `same_site: 'Lax'` (CSRF protection)
- [ ] Test: Check config values

```bash
grep -E "lifetime|http_only|secure|same_site" backend/config/session.php
```

#### 2.6 Session Validation Middleware
- [ ] `AuthenticateSession` middleware exists
- [ ] Checks session has user_id
- [ ] Verifies user still exists in database
- [ ] Verifies user is still active
- [ ] Returns 401 for missing session
- [ ] Flushes session if user deleted
- [ ] Test: Access with valid session, without session, with revoked user

#### 2.7 Logout Endpoint
- [ ] `POST /api/auth/logout` exists
- [ ] Flushes session data
- [ ] Returns 200
- [ ] Session becomes invalid immediately
- [ ] Test: Logout then try to access protected endpoint (should 401)

#### 2.8 Session Timeout
- [ ] Idle timeout configured (2 hours)
- [ ] Absolute timeout configured (24 hours)
- [ ] Sessions expire in database
- [ ] Expired sessions invalid
- [ ] Test: Expired session returns 401

#### 2.9 Cookie Security
- [ ] Session cookie has HttpOnly flag
- [ ] Session cookie has Secure flag (HTTPS)
- [ ] Session cookie has SameSite=Lax
- [ ] Test: Inspect Set-Cookie header

```bash
curl -I http://localhost:8080/api/auth/login
# Look for Set-Cookie header with: HttpOnly, Secure, SameSite=Lax
```

#### 2.10 Inactive Users
- [ ] Cannot login if `is_active` = false
- [ ] Session flushed if user deactivated
- [ ] Returns 401 on next request
- [ ] Test: Deactivate admin, try to access (should 401)

---

### 3. Identification: RFID Member (Pattern 014)

**Objective**: Verify RFID card identification (NOT authentication)

#### 3.1 Card UID Storage
- [ ] Card UID stored in members table
- [ ] Unique constraint on card_uid
- [ ] Format validated (8-12 hex chars)
- [ ] Public identifier (not secret)
- [ ] Test: Card UID visible in member responses

#### 3.2 Member Lookup by Card
- [ ] Repository method: `findByCardUid(string $cardUid): ?Member`
- [ ] Returns member for valid card
- [ ] Returns null for unknown card
- [ ] Only returns active members
- [ ] Test: Lookup with valid/invalid card

```bash
php artisan tinker
>>> $member = Member::factory()->create(['card_uid' => '12345678', 'is_active' => true])
>>> $repo->findByCardUid('12345678')  # Should return member
>>> $repo->findByCardUid('unknown')  # Should return null
```

#### 3.3 Member Identification Service
- [ ] Service method: `identifyMemberByCard(string $cardUid): Member`
- [ ] Calls repository to find member
- [ ] Throws MemberNotFoundException for unknown card
- [ ] Throws for inactive member
- [ ] Test: Identify with valid/invalid card

#### 3.4 Card UID Validation
- [ ] FormRequest validates card_uid format
- [ ] Rejects invalid formats (not 8-12 hex)
- [ ] Checks uniqueness (no duplicates)
- [ ] Test: Create member with valid/invalid card

```bash
# Valid card
curl -X POST http://localhost:8080/api/admin/members \
  -H "Content-Type: application/json" \
  -H "Cookie: session_id=..." \
  -d '{"card_uid":"12345678","first_name":"John","last_name":"Smith",...}'
# Should return 201

# Invalid card (not hex)
curl -X POST http://localhost:8080/api/admin/members \
  -H "Content-Type: application/json" \
  -H "Cookie: session_id=..." \
  -d '{"card_uid":"INVALID!!!","first_name":"John","last_name":"Smith",...}'
# Should return 422 (validation error)

# Duplicate card
curl -X POST http://localhost:8080/api/admin/members \
  -H "Content-Type: application/json" \
  -H "Cookie: session_id=..." \
  -d '{"card_uid":"12345678","first_name":"Jane","last_name":"Smith",...}'
# Should return 422 (card already assigned)
```

#### 3.5 Transaction Member Linking
- [ ] Transactions include card_uid field
- [ ] Member identified by card_uid
- [ ] Transaction linked to member_id
- [ ] Audit trail connects card_uid → member
- [ ] Test: Process transaction and verify member_id set

---

### 4. Authorization & Access Control (Pattern 015)

**Objective**: Verify endpoint access control

#### 4.1 Terminal Access Control
- [ ] Middleware: `AuthorizeTerminalSync`
- [ ] Terminal can access `/api/sync/*`
- [ ] Terminal cannot access `/api/admin/*`
- [ ] Returns 403 (Forbidden) for admin endpoints
- [ ] Test: Terminal accessing admin endpoint

```bash
curl -H "Authorization: Bearer $VALID_TERMINAL_TOKEN" \
  http://localhost:8080/api/admin/members
# Should return 403 Forbidden
```

#### 4.2 Admin Access Control
- [ ] Middleware: `AuthorizeAdminSession`
- [ ] Admin can access `/api/admin/*`
- [ ] Admin cannot access `/api/sync/*` with session
- [ ] Returns 401 (wrong auth method) for sync endpoints
- [ ] Test: Admin accessing sync endpoint

```bash
curl -H "Cookie: session_id=..." \
  http://localhost:8080/api/sync/members
# Should return 401 (wrong auth method)
```

#### 4.3 Auth Method Enforcement
- [ ] Middleware: `PreventAuthMixup`
- [ ] Sync endpoints reject session auth
- [ ] Admin endpoints reject bearer token auth
- [ ] Clear error message about auth method
- [ ] Test: Mixed auth on both endpoint types

#### 4.4 Rate Limiting
- [ ] Terminal endpoints: 60 req/min (throttle:60,1)
- [ ] Admin endpoints: 120 req/min (throttle:120,1)
- [ ] Login endpoint: 5 attempts/min (brute force protection)
- [ ] Test: Exceed rate limit, verify 429 response

```bash
# Rapid requests to sync endpoint
for i in {1..65}; do
  curl http://localhost:8080/api/sync/members -H "Authorization: Bearer $TOKEN"
done
# After 60 requests, should get 429 Too Many Requests
```

#### 4.5 Public Endpoints
- [ ] Health endpoint accessible without auth
- [ ] Login endpoint accessible without auth
- [ ] Other endpoints require auth
- [ ] Test: Public endpoints return 200 without auth

```bash
curl http://localhost:8080/api/health
# Should return 200 (no auth required)

curl http://localhost:8080/api/auth/login
# Should return 422 (validation error, not 401)
```

---

### 5. Transport Security (ADR-0016)

**Objective**: Verify HTTPS and secure communication

#### 5.1 HTTPS Configuration
- [ ] `APP_ENV` set to 'production' or 'staging'
- [ ] `SESSION_SECURE_COOKIES` enabled
- [ ] Tokens sent only over HTTPS
- [ ] Test: Verify headers indicate HTTPS

```bash
curl -I http://localhost:8080/api/health | grep -i strict-transport
# Should show HSTS header if HTTPS required
```

#### 5.2 Security Headers
- [ ] `Strict-Transport-Security` (HSTS) set
- [ ] `X-Content-Type-Options: nosniff`
- [ ] `X-Frame-Options: DENY`
- [ ] `X-XSS-Protection` set (if needed)
- [ ] Test: Check all headers present

```bash
curl -I http://localhost:8080/api/health
# Should show all security headers
```

#### 5.3 Cookie Security
- [ ] HttpOnly flag set on session cookie
- [ ] Secure flag set on session cookie
- [ ] SameSite attribute set
- [ ] Test: Inspect Set-Cookie header

```bash
curl -v http://localhost:8080/api/auth/login 2>&1 | grep Set-Cookie
# Should show: HttpOnly; Secure; SameSite=Lax
```

#### 5.4 CSRF Protection
- [ ] `VerifyCsrfToken` middleware active
- [ ] API routes either use tokens or stateless auth
- [ ] POST/PATCH/DELETE requests protected
- [ ] Test: POST without CSRF protection (should fail)

```bash
# POST without CSRF token (stateless auth should work)
curl -X POST http://localhost:8080/api/sync/transactions \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"transactions":[...]}'
# Should work (Bearer auth, no CSRF needed)

# POST without auth (should fail CSRF)
curl -X POST http://localhost:8080/api/admin/members \
  -H "Content-Type: application/json" \
  -d '{"first_name":"John",...}'
# Should return 401 (no session auth)
```

---

### 6. Input Validation (ADR-0017)

**Objective**: Verify input validation and injection prevention

#### 6.1 FormRequest Validation
- [ ] All endpoints have FormRequest classes
- [ ] Validation rules cover all inputs
- [ ] Typed accessor methods for validated data
- [ ] Test: Invalid input returns 422

```bash
# Missing required field
curl -X POST http://localhost:8080/api/admin/members \
  -H "Content-Type: application/json" \
  -H "Cookie: session_id=..." \
  -d '{"first_name":"John"}'
# Should return 422 (missing last_name, email, card_uid)

# Invalid email format
curl -X POST http://localhost:8080/api/admin/members \
  -H "Content-Type: application/json" \
  -H "Cookie: session_id=..." \
  -d '{"email":"not-an-email","first_name":"John","last_name":"Smith","card_uid":"12345678"}'
# Should return 422 (invalid email)
```

#### 6.2 SQL Injection Prevention
- [ ] All queries use parameterized statements
- [ ] No string concatenation in SQL
- [ ] Eloquent ORM used correctly
- [ ] Test: Attempt SQL injection in input

```bash
# Attempt SQL injection
curl http://localhost:8080/api/sync/members?since=0%27%20OR%20%271%27=%271
# Should treat as invalid timestamp, return error or safe response
```

#### 6.3 XSS Prevention
- [ ] Output encoded in JSON responses
- [ ] No HTML in API responses
- [ ] User input never echoed unsafely
- [ ] Test: Inject HTML/JS, verify it's escaped

```bash
# Create member with HTML in name
curl -X POST http://localhost:8080/api/admin/members \
  -H "Content-Type: application/json" \
  -H "Cookie: session_id=..." \
  -d '{"first_name":"<script>alert(1)</script>","last_name":"Smith",...}'

# Retrieve member, verify script is escaped (not executed)
curl http://localhost:8080/api/admin/members -H "Cookie: session_id=..."
# Should show escaped HTML: \u003cscript\u003e... (not raw <script>)
```

#### 6.4 Type Validation
- [ ] Email addresses validated as email
- [ ] Numbers validated as numeric
- [ ] Booleans validated as boolean
- [ ] Enums validated against allowed values
- [ ] Test: Wrong type returns 422

```bash
# String where number expected
curl -X POST http://localhost:8080/api/admin/members \
  -H "Content-Type: application/json" \
  -H "Cookie: session_id=..." \
  -d '{"phone":"abc"}'
# Should return 422 if phone must be numeric
```

---

### 7. Error Handling (Pattern 007, ADR-0013)

**Objective**: Verify safe error responses

#### 7.1 Error Response Format
- [ ] All errors return JSON
- [ ] Consistent error structure: `{"error": "code", "message": "human readable"}`
- [ ] HTTP status codes correct (400, 401, 403, 404, 422, 500)
- [ ] Test: Trigger various errors, check format

```bash
# Not found error
curl http://localhost:8080/api/admin/members/nonexistent \
  -H "Cookie: session_id=..."
# Should return 404 with {"error": "not_found", "message": "..."}

# Validation error
curl -X POST http://localhost:8080/api/admin/members \
  -H "Content-Type: application/json" \
  -H "Cookie: session_id=..." \
  -d '{}'
# Should return 422 with validation errors
```

#### 7.2 No Information Leakage
- [ ] Errors don't expose SQL details
- [ ] Errors don't expose file paths
- [ ] Errors don't expose system architecture
- [ ] Generic error messages for security-sensitive operations
- [ ] Test: Trigger errors, verify safe messages

```bash
# Invalid login (should NOT say "email not found" vs "password wrong")
curl -X POST http://localhost:8080/api/auth/login \
  -d '{"email":"nonexistent@test.com","password":"test"}'
# Should return: "Invalid email or password" (generic)

# 500 error (should NOT expose stack trace)
# Trigger a system error and verify response doesn't include file paths or stack
```

#### 7.3 Error Logging
- [ ] Errors logged with context (user, action, timestamp)
- [ ] Sensitive data not logged (passwords, tokens)
- [ ] Logs accessible to admins for debugging
- [ ] Test: Check Laravel logs

```bash
docker exec backend tail /app/storage/logs/laravel.log
# Should show error details but NO passwords or sensitive data
```

#### 7.4 Exception Handling
- [ ] Domain exceptions caught and formatted
- [ ] Database exceptions caught and formatted
- [ ] Validation exceptions return 422
- [ ] Not found exceptions return 404
- [ ] Unauthorized exceptions return 401
- [ ] Forbidden exceptions return 403
- [ ] Test: Trigger each exception type

---

### 8. Audit Logging (ADR-0013)

**Objective**: Verify security events are logged

#### 8.1 Authentication Logging
- [ ] Successful login logged
- [ ] Failed login logged (with reason)
- [ ] Logout logged
- [ ] Session timeout logged
- [ ] Test: Check logs for login events

```bash
# Login and check logs
curl -X POST http://localhost:8080/api/auth/login \
  -d '{"email":"admin@test.com","password":"password123"}'

# Check logs
docker exec backend tail /app/storage/logs/laravel.log | grep -i login
# Should show: LOGIN_SUCCESS or AUTHENTICATION_SUCCESS
```

#### 8.2 Authorization Logging
- [ ] Successful access logged (optional, but recommended)
- [ ] Failed access attempts logged
- [ ] Reason logged (wrong auth method, forbidden, etc.)
- [ ] Test: Check logs for auth failures

```bash
# Terminal accessing admin endpoint (should fail)
curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8080/api/admin/members

# Check logs
docker exec backend tail /app/storage/logs/laravel.log | grep -i forbidden
# Should show: AUTHORIZATION_FAILURE or similar
```

#### 8.3 Sensitive Operation Logging
- [ ] Password changes logged
- [ ] Token rotation logged
- [ ] Member anonymization logged
- [ ] Account deactivation logged
- [ ] Test: Perform sensitive operations, check logs

#### 8.4 No Sensitive Data in Logs
- [ ] Plaintext passwords NOT logged
- [ ] Tokens NOT logged (hash or ID only)
- [ ] PII carefully logged (email OK, SSN not)
- [ ] Test: Inspect logs for sensitive data

```bash
docker exec backend grep -r "password" /app/storage/logs/
# Should NOT find plaintext passwords

docker exec backend grep -r "token" /app/storage/logs/
# If tokens logged, should be hashed or truncated (not plaintext)
```

---

## Finding Classification

### P0 (Critical) — Fix Before M3 Start
- Plaintext password storage
- Plaintext token storage
- No CSRF protection on state-changing requests
- SQL injection vulnerability
- Auth bypass (wrong token accepted, or no token required)
- XSS in API responses

### P1 (High) — Fix Before M4 (Admin API)
- No rate limiting on login
- Session not regenerating
- Missing security headers
- Weak password hashing (cost < 10)
- No audit logging of auth events
- Authorization not enforced

### P2 (Medium) — Fix Before M5
- Missing HttpOnly flag on cookies
- Missing SameSite attribute
- Incomplete validation rules
- Information leakage in error messages
- Unused auth middleware

### P3 (Low) — Document for Future
- Minor validation improvements
- Optional logging enhancements
- Performance optimizations
- Documentation improvements

---

## Audit Report Template

```markdown
# Security Audit Report (Milestone 2.5)

**Date**: [Date]
**Auditor**: [Name]
**Duration**: [Hours]

## Summary
- Total Checks: 0
- Passed: 0
- Failed: 0
- Critical (P0): 0
- High (P1): 0
- Medium (P2): 0
- Low (P3): 0

## Findings by Severity

### Critical (P0)
[List critical findings]

### High (P1)
[List high findings]

### Medium (P2)
[List medium findings]

### Low (P3)
[List low findings]

## Recommendations
1. [Action items]

## Sign-Off
- [ ] All P0 findings resolved
- [ ] All P1 findings documented
- [ ] Ready to proceed with Milestone 3
```

---

## Success Criteria

**Milestone 2.5 is complete when:**
- [ ] All 18 checks reviewed
- [ ] Code review complete
- [ ] Manual tests executed
- [ ] All P0 findings resolved
- [ ] All P1 findings documented with timeline
- [ ] Audit report signed off
- [ ] Ready to start Milestone 3 (ADR-0018 restructuring)

---

## References

- **ADR-0015**: Authentication and Authorization Strategy
- **ADR-0016**: Transport Security (HTTPS/TLS)
- **ADR-0017**: Input Validation and Injection Prevention
- **ADR-0013**: Audit Logging
- **Pattern 012**: Terminal API Token Authentication
- **Pattern 013**: Admin Session Authentication
- **Pattern 014**: RFID Member Identification
- **Pattern 015**: Authorization & Access Control
- **SECURITY-PATTERNS-IMPLEMENTATION-GUIDE.md**: Implementation details
