# Backend API Contracts

**Context**: Playwright API and E2E tests for the Club Bar backend (Slim 4, PDO, PHP 8.3).

Use this when writing test assertions against backend API responses — expected shapes, status codes, error formats, field naming.

Source: `backend/patterns/` (Patterns 001, 002, 003, 006, 007).

---

## Response Format

All responses are JSON with `Content-Type: application/json`.

### Success Responses

| Operation | Status | Body |
|-----------|--------|------|
| List | `200` | `{ items: [...], total: N, limit: N, offset: N }` |
| Show | `200` | Single DTO object |
| Create | `201` | Created DTO object |
| Update | `200` | Updated DTO object |
| Delete | `200` | `{ message: "... deleted" }` |

### Error Responses

Every error follows the same shape:

```json
{ "error": "error_code", "message": "Human-readable description" }
```

| Status | `error` value | When |
|--------|---------------|------|
| `401` | `unauthorized` | Missing or invalid auth (session/token) |
| `404` | `not_found` | Entity does not exist |
| `409` | `business_rule_violation` | Business logic constraint (e.g., settle already-settled txn) |
| `409` | `duplicate_resource` | Unique constraint (e.g., duplicate email) |
| `422` | `validation_failed` | Input validation failed (see below) |
| `500` | `internal_error` | Unhandled server error |

### Validation Errors (422)

Validation errors include a per-field `messages` object:

```json
{
  "error": "validation_failed",
  "messages": {
    "email": ["email must be a valid email"],
    "first_name": ["first_name is required"]
  }
}
```

**Assertion pattern:**
```typescript
const res = await request.post('/api/admin/members', { data: { email: 'bad' } });
expect(res.status()).toBe(422);
const body = await res.json();
expect(body.error).toBe('validation_failed');
expect(body.messages.email).toBeDefined();
```

---

## Validation Rules Reference

Common rules the backend enforces (Pattern 001). Tests sending invalid data should expect `422`:

| Rule | Meaning | Example |
|------|---------|---------|
| `required` | Field must be present and non-empty | `first_name` |
| `string` | Must be a string | `last_name` |
| `email` | Must be valid email format | `email` |
| `integer` | Must be an integer | `price_cents` |
| `uuid` | Must be valid UUID | `member_id` |
| `max:N` | String max length N chars | `max:100` |
| `min:N` | Numeric minimum | `min:0` |
| `in:a,b,c` | Must be one of listed values | `in:de,en,fr` |
| `unique:table,column` | Must not exist in DB | `unique:members,email` |
| `regex:/pattern/` | Must match regex | RFID card UID |

---

## DTO Field Conventions (Pattern 003)

**All response fields use `snake_case`:**
```json
{
  "id": "uuid-string",
  "first_name": "Max",
  "last_name": "Muster",
  "card_uid": "A1B2C3D4",
  "preferred_language": "de",
  "is_active": true,
  "is_sepa_valid": true,
  "created_at": "2024-01-15 10:30:00",
  "updated_at": "2024-01-15 10:30:00"
}
```

**Key conventions:**
- IDs are UUID strings (client-generated for transactions, server-generated for members)
- Booleans: `is_active`, `is_sepa_valid` (true/false, not 0/1)
- Timestamps: MySQL DATETIME format `YYYY-MM-DD HH:MM:SS`
- Nullable fields: present as `null` (not omitted)
- Computed fields: `is_sepa_valid` = has IBAN + mandate date (not stored in DB)

## Enum Values (Pattern 002)

Enums serialize as their **backing string value**, not the PHP name:

| Enum | Valid values | Used in |
|------|-------------|---------|
| `preferred_language` | `de`, `en`, `fr` | Members |
| `transaction_type` | `purchase`, `deposit`, `correction` | Transactions |
| `settlement_type` | `sepa`, `manual` | Settlements |
| `audit_action` | `create`, `update`, `delete`, `anonymize`, `export`, `login`, `login_failed` | Audit log |

**In tests**: Always use the backing string (`"de"` not `"German"`).

---

## Pagination Response

All list endpoints return paginated results:

```json
{
  "items": [ { ... }, { ... } ],
  "total": 42,
  "limit": 50,
  "offset": 0
}
```

**Query parameters** (sent by frontend/tests):

| Param | Default | Meaning |
|-------|---------|---------|
| `per_page` | `50` | Items per page |
| `page` | `1` | Page number (backend converts to offset) |
| `sort` | `created_at` | Sort field |
| `order` | `desc` | Sort direction (`asc`/`desc`) |
| `search` | — | Full-text search across name/email fields |

**Assertion pattern:**
```typescript
const res = await request.get('/api/admin/members?per_page=10&sort=last_name&order=asc');
expect(res.ok()).toBeTruthy();
const body = await res.json();
expect(body.items).toBeInstanceOf(Array);
expect(body.total).toBeGreaterThanOrEqual(0);
expect(body.limit).toBe(10);
```

---

## Quick Reference for Test Assertions

```typescript
// Successful create
expect(res.status()).toBe(201);
const member = await res.json();
expect(member.id).toBeTruthy();
expect(member.first_name).toBe('Test');

// Not found
expect(res.status()).toBe(404);
const err = await res.json();
expect(err.error).toBe('not_found');

// Validation failure
expect(res.status()).toBe(422);
const err = await res.json();
expect(err.error).toBe('validation_failed');
expect(err.messages).toBeDefined();

// Business rule violation
expect(res.status()).toBe(409);
const err = await res.json();
expect(err.error).toBe('business_rule_violation');
```
