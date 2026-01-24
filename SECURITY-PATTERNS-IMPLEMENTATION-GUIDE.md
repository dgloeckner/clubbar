# Security Patterns Implementation Guide (ADR-0015)

**Status**: Implementation Ready

**Date**: 2026-01-24

**Reference**: ADR-0015 Authentication and Authorization Strategy

---

## Overview

ADR-0015 establishes four core security principles. This guide shows how four backend patterns implement them:

| Principle | Implementation | Pattern |
|-----------|---|---|
| **1. Separation of Identification & Authentication** | RFID identifies (not authenticates) | Pattern 014 |
| **2. Device-level Terminal Authentication** | Bearer tokens per terminal device | Pattern 012 |
| **3. Session-based Admin Authentication** | HTTP-only session cookies | Pattern 013 |
| **4. No Member Authentication** | Members identified by RFID only | Pattern 014 |

---

## Quick Start: Implementing Security

### For Terminal API Endpoints (`/api/sync/*`)

Use **Pattern 012: Terminal API Token Authentication**

```php
// routes/api.php
Route::prefix('sync')
    ->middleware([
        App\Http\Middleware\AuthenticateTerminalToken::class,  // Pattern 012
        App\Http\Middleware\AuthorizeTerminalSync::class,      // Pattern 015
    ])
    ->group(function () {
        Route::get('/members', [SyncController::class, 'members']);
    });
```

**What happens:**
1. Client sends: `GET /api/sync/members Authorization: Bearer <token>`
2. Middleware validates token (Pattern 012)
3. Middleware checks terminal can access sync (Pattern 015)
4. Controller executes

**When to use**: All Terminal API endpoints

---

### For Admin API Endpoints (`/api/admin/*`)

Use **Pattern 013: Admin Session Authentication**

```php
// routes/api.php
Route::prefix('admin')
    ->middleware([
        App\Http\Middleware\AuthenticateSession::class,        // Pattern 013
        App\Http\Middleware\AuthorizeAdminSession::class,      // Pattern 015
    ])
    ->group(function () {
        Route::apiResource('members', MembersAdminController::class);
    });
```

**What happens:**
1. Client logs in: `POST /api/auth/login email=... password=...`
2. Backend validates credentials (Pattern 013)
3. Backend creates session: `session_regenerate_id()`, stores user_id
4. Browser receives: `Set-Cookie: session_id=...`
5. Client sends cookie: `GET /api/admin/members Cookie: session_id=...`
6. Middleware validates session (Pattern 013)
7. Middleware checks admin can access endpoint (Pattern 015)
8. Controller executes

**When to use**: All Admin API endpoints (`/api/admin/*`)

---

### For Member Identification (Transaction Processing)

Use **Pattern 014: RFID Member Identification**

```php
// app/Http/Modules/Transactions/Services/TransactionService.php
public function processBatch(array $transactions): TransactionBatchResultDto
{
    foreach ($transactions as $transaction) {
        // Identify member by card UID (not authentication)
        $member = $this->membersService->identifyMemberByCard($transaction['card_uid']);

        // Link transaction to member
        $this->transactionRepository->create([
            'member_id' => $member->id,
            'card_uid' => $transaction['card_uid'],  // Visible, not secret
            'amount' => $transaction['amount'],
        ]);
    }
}
```

**Key Point**: Card UID is **visible on the card**; it's **identification**, not authentication.

**When to use**: Transaction processing, member lookups for billing

---

## The Four Security Patterns Explained

### Pattern 012: Terminal API Token Authentication

**What it does:**
- Authenticates **terminal devices** as clients
- Uses cryptographically secure Bearer tokens (64-char hex, 256 bits)
- Stores tokens as bcrypt hashes (irreversible)
- Allows token rotation and revocation

**Key characteristics:**
- ✅ Device-level (one token per terminal device)
- ✅ Long-lived (no auto-refresh; manual rotation)
- ✅ Revocable (admin can deauthorize instantly)
- ✅ Constant-time comparison (secure against timing attacks)
- ❌ No user login (device auth, not user auth)

**ADR-0015 Principle**: "Device-level Terminal Authentication: Terminals authenticate as devices, not users"

**Read**: `backend/patterns/pattern-012-terminal-api-token-authentication.md`

---

### Pattern 013: Admin Session Authentication

**What it does:**
- Authenticates **admin users** with email + password
- Uses traditional server-side sessions
- Stores sessions in database (or files)
- Sends session ID via secure HTTP-only cookies

**Key characteristics:**
- ✅ Session regeneration on login (prevents fixation attacks)
- ✅ Idle timeout (2 hours inactive = logout)
- ✅ Absolute timeout (24 hours max = re-login)
- ✅ Secure cookies (HttpOnly, Secure, SameSite=Lax)
- ✅ Password hashing (bcrypt cost 12+)
- ❌ No JWT tokens (simpler than refresh token flow)
- ❌ No multi-device tokens (sessions per device)

**ADR-0015 Principle**: "Session-based Admin Authentication: Admin panel uses traditional session cookies"

**Read**: `backend/patterns/pattern-013-admin-session-authentication.md`

---

### Pattern 014: RFID Member Identification

**What it does:**
- Identifies which member is making a purchase via RFID card UID
- **NOT authentication** (no login, no secrets, no access granted)
- Links transactions to member accounts for billing

**Key characteristics:**
- ✅ Simple identification (card UID → member lookup)
- ✅ Trusted environment (members are organization members)
- ✅ Audit trail (transaction history linked to member)
- ✅ GDPR-compatible (card UID retained for accounting)
- ❌ No security (stolen card = anyone can spend on that member's account)
- ❌ No authentication (member never logs in)
- ❌ Card UID is public (visible on physical card)

**ADR-0015 Principle**:
- "Separation of Identification and Authentication: RFID identifies members; it does not authenticate them"
- "No Member Authentication: Members never log in; they are identified by RFID for billing purposes only"

**Read**: `backend/patterns/pattern-014-rfid-member-identification.md`

---

### Pattern 015: Authorization & Access Control

**What it does:**
- Enforces endpoint-level access control
- Ensures Terminal devices only access `/api/sync/*`
- Ensures Admin users only access `/api/admin/*`
- Prevents authentication method confusion

**Key characteristics:**
- ✅ Middleware-based (applied to route groups)
- ✅ Clear separation (sync vs admin vs public)
- ✅ Prevents auth mixup (terminal can't use session; admin can't use bearer token)
- ✅ Rate limiting by auth type
- ✅ Resource ownership checks (optional)
- ✅ Audit logging of access attempts

**ADR-0015 Implementation**: Enforces all four principles via middleware

**Read**: `backend/patterns/pattern-015-authorization-access-control.md`

---

## Implementation Checklist

### Phase 1: Terminal API (Terminal + Device Auth)

- [ ] Pattern 012 implemented
  - [ ] `TokenService::generateTerminalToken()` (256-bit secure tokens)
  - [ ] `TokenService::hashToken()` (bcrypt hashing)
  - [ ] `TokenService::verifyToken()` (constant-time comparison)
  - [ ] `AuthenticateTerminalToken` middleware
  - [ ] Terminal pairing flow (admin generates + displays token)
  - [ ] Token rotation endpoint

- [ ] Pattern 015 implemented (Terminal authorization)
  - [ ] `AuthorizeTerminalSync` middleware
  - [ ] `PreventAuthMixup` middleware
  - [ ] Rate limiting for terminal endpoints

- [ ] Tests written (Playwright)
  - [ ] Terminal with valid token → 200
  - [ ] Terminal with invalid token → 401
  - [ ] Terminal without token → 401
  - [ ] Terminal accessing admin endpoint → 403

### Phase 2: Admin API (User Auth)

- [ ] Pattern 013 implemented
  - [ ] `AdminUser` model with password hashing
  - [ ] `LoginRequest` form request (Pattern 001)
  - [ ] `AuthService` for password validation
  - [ ] `AuthController` (login, logout, profile, password change)
  - [ ] `AuthenticateSession` middleware
  - [ ] Session configuration (timeouts, secure cookies)

- [ ] Pattern 015 implemented (Admin authorization)
  - [ ] `AuthorizeAdminSession` middleware
  - [ ] Resource ownership checks (if needed)
  - [ ] Admin-only endpoint protection

- [ ] Tests written (Playwright)
  - [ ] Admin login with valid credentials → 200
  - [ ] Admin login with invalid credentials → 401
  - [ ] Admin accessing admin endpoint → 200
  - [ ] Unauthenticated accessing admin endpoint → 401
  - [ ] Session timeout → 401

### Phase 3: Member Identification (RFID)

- [ ] Pattern 014 implemented
  - [ ] `findByCardUid()` repository method
  - [ ] `identifyMemberByCard()` service method
  - [ ] `validateCardUid()` form request validation
  - [ ] Transaction processing with card UID

- [ ] Tests written
  - [ ] Valid card → member identified
  - [ ] Invalid card → not found
  - [ ] Inactive member → not found
  - [ ] Transaction linked to member via card_uid

---

## Key Differences Between Authentication Types

| Aspect | Terminal (Pattern 012) | Admin (Pattern 013) | Member (Pattern 014) |
|--------|---|---|---|
| **Type** | Device auth | User auth | Identification |
| **Credential** | Bearer token | Email + password | RFID card UID |
| **Secret?** | Yes (256-bit) | Yes (password) | No (visible on card) |
| **Endpoint** | `/api/sync/*` | `/api/admin/*` | Transaction processing |
| **Authorization** | Device access | User account status | Member active status |
| **Revocation** | Token rotation | Deactivate account | Deactivate member |
| **API Access** | Yes | Yes | No (not API auth) |

---

## Common Mistakes to Avoid

### ❌ DON'T: Use RFID as Authentication

```php
// WRONG: This is NOT authentication
if ($rfid_card_uid == $member->card_uid) {
    // Grant access to member's account
}
```

RFID is **identification only**. Card UID is visible and public.

### ✅ DO: Use RFID for Transaction Linking

```php
// CORRECT: Identification for billing
$member = $this->membersService->identifyMemberByCard($card_uid);
$transaction->member_id = $member->id;
```

---

### ❌ DON'T: Store Plaintext Tokens

```php
// WRONG
$terminal->update(['api_token' => $plainToken]);  // Exposed!
```

### ✅ DO: Hash Tokens Before Storage

```php
// CORRECT
$terminal->update([
    'api_token_hash' => TokenService::hashToken($plainToken),
]);
```

---

### ❌ DON'T: Use Bearer Tokens for Admin

```php
// WRONG: Admin endpoints need sessions, not tokens
Route::post('/api/admin/members', [AdminController::class, 'create'])
    ->middleware(AuthenticateTerminalToken::class);  // ❌ Wrong!
```

### ✅ DO: Use Sessions for Admin

```php
// CORRECT: Admin endpoints use sessions
Route::post('/api/admin/members', [AdminController::class, 'create'])
    ->middleware(AuthenticateSession::class);  // ✅ Correct
```

---

### ❌ DON'T: Log Passwords

```php
// WRONG
\Log::info('User login attempt', ['password' => $request->password()]);
```

### ✅ DO: Log Authentication Events (Not Secrets)

```php
// CORRECT
\Log::info('LOGIN_SUCCESS', ['email' => $request->email()]);
\Log::warning('LOGIN_FAILURE', ['email' => $request->email(), 'reason' => 'invalid_password']);
```

---

## Security Testing Checklist

### Terminal API Security Tests

```bash
# Valid token
curl -H "Authorization: Bearer $VALID_TOKEN" http://localhost:8080/api/sync/members
# Expected: 200 OK

# Invalid token
curl -H "Authorization: Bearer invalid_token" http://localhost:8080/api/sync/members
# Expected: 401 Unauthorized

# Missing token
curl http://localhost:8080/api/sync/members
# Expected: 401 Unauthorized

# Wrong auth method (session instead of bearer)
curl -H "Cookie: session_id=..." http://localhost:8080/api/sync/members
# Expected: 401 Unauthorized

# Terminal accessing admin endpoint
curl -H "Authorization: Bearer $VALID_TOKEN" http://localhost:8080/api/admin/members
# Expected: 403 Forbidden
```

### Admin API Security Tests

```bash
# Valid session
curl -H "Cookie: session_id=$VALID_SESSION" http://localhost:8080/api/admin/members
# Expected: 200 OK

# Invalid session
curl -H "Cookie: session_id=invalid" http://localhost:8080/api/admin/members
# Expected: 401 Unauthorized

# Missing session
curl http://localhost:8080/api/admin/members
# Expected: 401 Unauthorized

# Wrong auth method (bearer instead of session)
curl -H "Authorization: Bearer $TOKEN" http://localhost:8080/api/admin/members
# Expected: 401 Unauthorized

# Admin accessing sync endpoint (wrong method)
curl -H "Authorization: Bearer $TOKEN" http://localhost:8080/api/sync/members
# Expected: Expected bearer token, not session

# Public endpoint (no auth required)
curl http://localhost:8080/api/health
# Expected: 200 OK
```

---

## Configuration Reference

### Terminal Token Configuration

```php
// app/Services/TokenService.php
// Token length: 64 characters (32 bytes = 256 bits)
// Hashing: bcrypt with cost factor 12

TokenService::generateTerminalToken();     // Returns 64-char hex string
TokenService::hashToken($token);           // Returns bcrypt hash
TokenService::verifyToken($token, $hash);  // Returns bool
```

### Admin Session Configuration

```php
// config/session.php
[
    'driver' => 'database',         // Store in DB, not files
    'lifetime' => 120,              // 2 hours idle timeout
    'http_only' => true,            // JS cannot access cookie
    'secure' => true,               // HTTPS only
    'same_site' => 'Lax',          // CSRF protection
]
```

### Admin Password Configuration

```php
// app/Models/AdminUser.php
Hash::make($password, ['cost' => 12]);  // Bcrypt cost 12
```

---

## Audit Logging (Pattern 014: ADR-0013)

Log all authentication and authorization events:

```php
// Successful authentication
\Log::info('AUTH_SUCCESS', [
    'type' => 'terminal|admin',
    'actor' => $terminal_id || $admin_id,
    'timestamp' => now(),
]);

// Failed authentication
\Log::warning('AUTH_FAILURE', [
    'type' => 'terminal|admin',
    'reason' => 'invalid_token|wrong_password|user_inactive',
    'timestamp' => now(),
]);

// Successful authorization
\Log::info('AUTHZ_ALLOWED', [
    'actor' => $terminal_id || $admin_id,
    'resource' => '/api/members',
    'method' => 'GET',
]);

// Failed authorization
\Log::warning('AUTHZ_DENIED', [
    'actor' => $terminal_id || $admin_id,
    'resource' => '/api/members',
    'reason' => 'wrong_auth_method|insufficient_permissions',
]);
```

---

## Deployment Checklist

### Before Production

- [ ] All tokens generated server-side using `random_bytes()`
- [ ] All tokens hashed with bcrypt (cost 12+)
- [ ] Session storage in database (not files)
- [ ] HTTPS required (secure cookies, no HTTP)
- [ ] Session timeouts configured (2 hour idle)
- [ ] Rate limiting enabled on login endpoint
- [ ] Audit logging configured and tested
- [ ] Password reset flow implemented
- [ ] Token rotation UI in admin panel
- [ ] Security headers set (HSTS, CSP, X-Frame-Options, etc.)
- [ ] SQL injection prevention (prepared statements)
- [ ] XSS prevention (output encoding)
- [ ] CSRF protection (SameSite + tokens if needed)

---

## References

- **ADR-0015**: Authentication and Authorization Strategy
- **Pattern 012**: Terminal API Token Authentication
- **Pattern 013**: Admin Session Authentication
- **Pattern 014**: RFID Member Identification
- **Pattern 015**: Authorization & Access Control
- **ADR-0016**: Transport Security (HTTPS/TLS)
- **ADR-0017**: Input Validation and Injection Prevention
- **ADR-0013**: Audit Logging

---

## Questions?

1. **How do I implement Pattern 012?** → Read `pattern-012-terminal-api-token-authentication.md`
2. **How do I implement Pattern 013?** → Read `pattern-013-admin-session-authentication.md`
3. **Is RFID authentication?** → No! Read `pattern-014-rfid-member-identification.md`
4. **How do I protect endpoints?** → Use Pattern 015: `pattern-015-authorization-access-control.md`
5. **What does ADR-0015 say?** → Read `/adr/0015-authentication-and-authorization-strategy.md`
