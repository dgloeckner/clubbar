# Backend Modules & Endpoints

**Context**: Playwright API and E2E tests for the Club Bar backend (Slim 4, PDO, PHP 8.3).

Use this when planning which endpoints to test, understanding the backend module structure, or verifying audit trail entries.

Source: `backend/patterns/` (Patterns 004, 005, 008, 009, 010, 011, 016).

---

## Module Inventory

Each module lives in `src/Modules/{Name}/` with Controllers, Services, Repositories, DTOs, and Enums subdirectories. All dependencies wired via `src/ServiceFactory.php` (Pattern 008).

### Members (`/api/admin/members`, `/api/sync/members`)

**Admin endpoints:**

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/admin/members` | List (paginated, filterable, sortable) |
| `POST` | `/api/admin/members` | Create member |
| `GET` | `/api/admin/members/{memberId}` | Show member detail |
| `PATCH` | `/api/admin/members/{memberId}` | Update member |
| `DELETE` | `/api/admin/members/{memberId}` | Soft-delete member |
| `POST` | `/api/admin/members/{memberId}/export` | GDPR data export |
| `POST` | `/api/admin/members/{memberId}/anonymize` | GDPR anonymization |

**Terminal endpoints:**

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/sync/members?since=<ms>` | Delta sync (modified since timestamp) |
| `PATCH` | `/api/sync/members/{memberId}/language` | Update preferred language |

**Required fields for create:** `first_name`, `last_name`, `email`, `preferred_language` (de/en/fr)

---

### Products & Categories (`/api/admin/products`, `/api/admin/categories`, `/api/sync/products`)

**Admin endpoints:**

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/admin/categories` | List categories |
| `POST` | `/api/admin/categories` | Create category |
| `PATCH` | `/api/admin/categories/{categoryId}` | Update category |
| `PATCH` | `/api/admin/categories/{categoryId}/status` | Toggle active/inactive |
| `DELETE` | `/api/admin/categories/{categoryId}` | Delete category |
| `GET` | `/api/admin/products` | List products (paginated) |
| `POST` | `/api/admin/products` | Create product |
| `PATCH` | `/api/admin/products/{productId}` | Update product |
| `PATCH` | `/api/admin/products/{productId}/status` | Toggle active/inactive |
| `DELETE` | `/api/admin/products/{productId}` | Delete product |

**Terminal endpoints:**

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/sync/categories?since=<ms>` | Delta sync categories |
| `GET` | `/api/sync/products?since=<ms>` | Delta sync products |

**Product names are multilingual** (JSON object): `{ "de": "Bier", "en": "Beer" }`

---

### Transactions (`/api/admin/transactions`, `/api/sync/transactions`)

**Admin endpoints:**

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/admin/transactions` | List (paginated, filterable) |
| `GET` | `/api/admin/transactions/export` | Export CSV |
| `GET` | `/api/admin/members/{memberId}/transactions` | Member transaction history |
| `POST` | `/api/admin/transactions/{transactionId}/storno` | Reverse one transaction; `{reason}` only — the amount is derived as the exact negation and is never accepted from the caller (#169) |

**Terminal endpoints:**

| Method | Path | Purpose |
|--------|------|---------|
| `POST` | `/api/sync/transactions` | Submit transaction batch |
| `GET` | `/api/terminal/transactions/{memberId}` | Member history for terminal |

**Transactions are immutable** (ADR-0004). No UPDATE or DELETE. A booking is corrected by a **storno** — a reverse transaction naming the one it reverses, with the amount derived as the exact negation. There is no free-amount adjustment and no admin-booked purchase (#169).

---

### Settlements (`/api/admin/settlements`)

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/admin/settlements` | List settlements |
| `GET` | `/api/admin/settlements/{id}` | Settlement detail |
| `POST` | `/api/admin/settlements/preview` | Preview before creating |
| `POST` | `/api/admin/settlements` | Create settlement |
| `DELETE` | `/api/admin/settlements/{id}` | Cancel settlement |
| `GET` | `/api/admin/settlements/filter-preview` | Preview filter results |
| `POST` | `/api/admin/settlements/settle-filter` | Settle by filter |
| `GET` | `/api/admin/settlements/{id}/export-sepa` | Export SEPA XML |
| `GET` | `/api/admin/settlements/{id}/export-csv` | Export CSV |
| `GET` | `/api/admin/settlements/{id}/export-transactions` | Export transactions CSV |

**Requires `settlement_type`:** `sepa` or `manual`.

---

### Terminals (`/api/admin/terminals`)

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/admin/terminals` | List terminals |
| `POST` | `/api/admin/terminals` | Create (returns plaintext API token) |
| `GET` | `/api/admin/terminals/{id}` | Show terminal |
| `PATCH` | `/api/admin/terminals/{id}` | Update terminal |
| `DELETE` | `/api/admin/terminals/{id}` | Delete terminal |
| `POST` | `/api/admin/terminals/{id}/rotate-token` | Rotate API token |
| `POST` | `/api/admin/terminals/{id}/revoke` | Revoke terminal access |

---

### Admin Users (`/api/admin/admin-users`)

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/admin/admin-users` | List admin users |
| `POST` | `/api/admin/admin-users` | Create admin user |
| `GET` | `/api/admin/admin-users/{id}` | Show admin user |
| `PATCH` | `/api/admin/admin-users/{id}` | Update admin user |
| `DELETE` | `/api/admin/admin-users/{id}` | Deactivate admin user |
| `POST` | `/api/admin/admin-users/{id}/reactivate` | Reactivate |
| `POST` | `/api/admin/admin-users/{id}/reset-password` | Reset password |

---

### Auth (`/api/auth`)

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| `POST` | `/api/auth/login` | Public | Login (returns session cookie) |
| `POST` | `/api/auth/logout` | Session | Destroy session |
| `GET` | `/api/auth/profile` | Session | Get current admin profile |
| `PATCH` | `/api/auth/profile` | Session | Update profile |
| `PATCH` | `/api/auth/change-password` | Session | Change password |

---

### Dashboard & Audit Log

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/admin/dashboard` | Dashboard statistics |
| `GET` | `/api/admin/statistics/monthly` | Monthly statistics |
| `GET` | `/api/admin/audit-log` | Audit log entries (filterable) |

### SEPA Config

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/admin/sepa-config` | Get SEPA creditor configuration |
| `PUT` | `/api/admin/sepa-config` | Update SEPA creditor configuration |

---

## Request Flow (Patterns 004-006)

Every request follows this chain:

```
HTTP Request
  → Slim Router
  → ErrorHandler middleware (catches all exceptions → JSON error)
  → CORS + JSON Body Parser middleware
  → Auth middleware (TerminalTokenAuth or AdminSessionAuth)
  → Controller (thin — extract input, validate, delegate)
  → Validator (Pattern 001 — returns 422 on failure)
  → Service (business logic, throws domain exceptions)
  → Repository (PDO prepared statements, returns associative arrays)
  → DTO (fromRow() factory → toArray() serialization)
  → JSON Response
```

**For tests this means:**
- Invalid input → `422` before service is called
- Entity not found → `404` from service layer
- Business rule violated → `409` from service layer
- Server bug → `500` from ErrorHandler
- Auth failure → `401` from middleware (before controller)

---

## Audit Log Verification (Pattern 016)

Every master data change is logged in the `audit_log` table:

```json
{
  "admin_user_id": "uuid-of-admin",
  "action": "create",
  "entity_type": "member",
  "entity_id": "uuid-of-entity",
  "old_values": null,
  "new_values": { "first_name": "Max", "last_name": "Muster" },
  "ip_address": "127.0.0.1",
  "created_at": "2024-01-15 10:30:00"
}
```

**Audit actions**: `create`, `update`, `delete`, `anonymize`, `export`, `login`, `login_failed`

**Sensitive field masking:**
- IBAN: `DE89****...****3000` (first 4 + last 4 visible)
- Passwords: `[MASKED]`
- API tokens: `[MASKED]`

**In tests** — verify audit entries via the admin API:

```typescript
const auditRes = await adminRequest.get('/api/admin/audit-log');
const { items } = await auditRes.json();
const entry = items.find(e => e.entity_id === createdMemberId && e.action === 'create');
expect(entry).toBeDefined();
expect(entry.entity_type).toBe('member');
```

---

## Delta Sync Protocol (Terminal)

Terminal sync endpoints use timestamp-based delta sync:

```
GET /api/sync/members?since=0         → All members (initial sync)
GET /api/sync/members?since=1709136000000  → Members modified after timestamp
```

- `since` is Unix timestamp in **milliseconds**
- Response includes both active and soft-deleted records (so terminal can remove them)
- MySQL DATETIME has **second precision** — in tests, delays between operations must be >= 1 second

---

## Standard CRUD Test Pattern

Most modules follow the same CRUD pattern. Template for API tests:

```typescript
test.describe('Members API', () => {
  test('POST creates member', async ({ authenticatedRequest }) => {
    const res = await authenticatedRequest.post('/api/admin/members', {
      data: {
        first_name: `Test${Date.now()}`,
        last_name: 'Member',
        email: `test-${Date.now()}@example.com`,
        preferred_language: 'de',
      }
    });
    expect(res.status()).toBe(201);
    const member = await res.json();
    expect(member.id).toBeTruthy();
    expect(member.first_name).toContain('Test');
  });

  test('GET lists members', async ({ authenticatedRequest }) => {
    const res = await authenticatedRequest.get('/api/admin/members');
    expect(res.ok()).toBeTruthy();
    const body = await res.json();
    expect(body.items).toBeInstanceOf(Array);
    expect(body.total).toBeGreaterThanOrEqual(0);
  });

  test('GET shows member by ID', async ({ authenticatedRequest }) => {
    // Create first, then fetch
    const createRes = await authenticatedRequest.post('/api/admin/members', {
      data: { /* ... */ }
    });
    const { id } = await createRes.json();

    const showRes = await authenticatedRequest.get(`/api/admin/members/${id}`);
    expect(showRes.ok()).toBeTruthy();
  });

  test('DELETE soft-deletes member', async ({ authenticatedRequest }) => {
    // Create, then delete
    const createRes = await authenticatedRequest.post('/api/admin/members', {
      data: { /* ... */ }
    });
    const { id } = await createRes.json();

    const deleteRes = await authenticatedRequest.delete(`/api/admin/members/${id}`);
    expect(deleteRes.ok()).toBeTruthy();

    // Verify: show returns 404
    const showRes = await authenticatedRequest.get(`/api/admin/members/${id}`);
    expect(showRes.status()).toBe(404);
  });
});
```
