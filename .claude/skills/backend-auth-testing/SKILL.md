# Backend Auth for Testing

**Context**: Playwright API and E2E tests for the Ruderbar backend (Slim 4, PDO, PHP 8.3).

Use this when setting up authentication in Playwright tests — admin sessions, terminal Bearer tokens, RFID member identification, and access control boundaries.

Source: `backend/patterns/` (Patterns 012, 013, 014, 015).

---

## Three Auth Mechanisms

The backend has three distinct auth mechanisms. Each protects different routes:

| Mechanism | Routes | How | Who |
|-----------|--------|-----|-----|
| Admin session | `/api/admin/*` | Session cookie (HttpOnly) | Admin users (humans) |
| Terminal token | `/api/sync/*` | `Authorization: Bearer <token>` | POS terminals (devices) |
| RFID identification | Transaction payloads | `card_uid` field in request body | Members (not auth — just lookup) |

**They never mix.** Terminal tokens cannot access `/api/admin/*`. Admin sessions cannot access `/api/sync/*`. Attempting crossover returns `401`.

---

## Admin Session Auth (Pattern 013)

Used for all admin panel API tests.

### Login Flow

```typescript
// POST /api/auth/login — public endpoint, no auth required
const loginRes = await request.post('/api/auth/login', {
  data: { email: 'admin@example.com', password: 'secret' }
});
expect(loginRes.status()).toBe(200);
// Response sets HttpOnly session cookie automatically
// Subsequent requests on same context include cookie
```

### What the Backend Does

1. Validates email + password via `password_verify()` (bcrypt)
2. Creates PHP session (`session_start()`)
3. Stores `admin_user_id` and `admin_user` in `$_SESSION`
4. Regenerates session ID (fixation prevention)
5. Returns `{ id, name, email }`

### Session Properties

- **Cookie**: HttpOnly, SameSite=Lax, Secure in production
- **Idle timeout**: 2 hours of inactivity
- **Absolute timeout**: 24 hours max
- **Logout**: `POST /api/auth/logout` destroys session

### In Playwright Tests

Use `storageState` for persistent login across tests:

```typescript
// auth.setup.ts — run once, save session
const loginRes = await request.post('/api/auth/login', {
  data: { email: 'admin@example.com', password: 'password' }
});
await page.context().storageState({ path: 'admin.json' });

// In test files — reuse saved session
test.use({ storageState: 'admin.json' });
```

### Admin Request Attributes

After auth middleware, the request carries:
- `admin_user_id` — UUID of logged-in admin
- `admin_user` — Full admin user array

These are used for audit logging (Pattern 016).

---

## Terminal Token Auth (Pattern 012)

Used for terminal sync API tests.

### Token Format

- 64-character hex string (256-bit entropy)
- Stored as SHA-256 hash in `terminals.api_token_hash`
- Plaintext shown **once** at creation (never stored)

### How to Use in Tests

```typescript
// Create terminal via admin API (requires admin session)
const createRes = await adminRequest.post('/api/admin/terminals', {
  data: { name: 'Test Terminal', location: 'Bar' }
});
const { api_token } = await createRes.json();
// api_token is the plaintext 64-char hex — save it

// Use token for sync requests
const syncRes = await request.get('/api/sync/members?since=0', {
  headers: { 'Authorization': `Bearer ${api_token}` }
});
expect(syncRes.ok()).toBeTruthy();
```

### Error Responses

```typescript
// Missing token
const res = await request.get('/api/sync/members');
expect(res.status()).toBe(401);

// Invalid token
const res = await request.get('/api/sync/members', {
  headers: { 'Authorization': 'Bearer invalid-token' }
});
expect(res.status()).toBe(401);
```

### Token Lifecycle

| Action | Endpoint | Effect |
|--------|----------|--------|
| Create | `POST /api/admin/terminals` | Returns plaintext token |
| Rotate | `POST /api/admin/terminals/{id}/rotate-token` | Old token invalidated, new one returned |
| Revoke | `POST /api/admin/terminals/{id}/revoke` | Token invalidated, terminal deactivated |

---

## RFID Member Identification (Pattern 014)

RFID is **identification, not authentication**. Card UIDs are public identifiers used to link transactions to members.

### Card UID Format

- 8-12 uppercase hex characters: `A1B2C3D4` or `A1B2C3D4E5F6`
- Stored plaintext in `members.card_uid`
- Validated with regex: `/^[A-F0-9]{8,12}$/`

### In Transaction Tests

```typescript
// Terminal submits transaction batch with card UID
const batchRes = await terminalRequest.post('/api/sync/transactions', {
  data: {
    transactions: [{
      id: crypto.randomUUID(),
      card_uid: 'A1B2C3D4',
      product_id: 'some-product-uuid',
      quantity: 1,
      total_cents: 350,
      transacted_at: new Date().toISOString(),
    }]
  }
});
```

### Error Cases

- Unknown card UID → Transaction processed but `member_id` is `null` (not rejected)
- Missing card UID → Validation error
- Invalid format → Validation error

---

## Access Control Boundaries (Pattern 015)

### Route Group Isolation

Slim 4 route groups enforce separation structurally:

```
/api/sync/*   → TerminalTokenAuth middleware → Terminal only
/api/admin/*  → AdminSessionAuth middleware  → Admin only
/api/auth/*   → Mixed (login is public, rest require session)
/api/health   → Public (no auth)
```

### Test Access Control

```typescript
// Terminal token cannot access admin routes
const res = await request.get('/api/admin/members', {
  headers: { 'Authorization': `Bearer ${terminalToken}` }
});
expect(res.status()).toBe(401);

// Admin session cannot access sync routes (no Bearer token)
const res = await adminRequest.get('/api/sync/members?since=0');
expect(res.status()).toBe(401);

// Health endpoint is always public
const res = await request.get('/api/health');
expect(res.ok()).toBeTruthy();
```

---

## Quick Reference

```
Admin tests:  POST /api/auth/login → cookie → /api/admin/*
Terminal tests: Authorization: Bearer <64-hex-token> → /api/sync/*
Member lookup: card_uid in transaction payload (not HTTP auth)
Health check:  GET /api/health (no auth)
```
