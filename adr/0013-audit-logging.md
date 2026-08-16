# ADR-0013: Audit Logging for Master Data Changes

**Status**: Accepted (amended 2026-08-09 — see [Audit Log Scrubbing](#audit-log-scrubbing-during-gdpr-anonymization); amended 2026-08-16 — see [Out of scope](#scope) for the two transaction exceptions)

**Date**: 2025-01-23

---

## Context

The Club Bar system manages sensitive member data (personal information, banking details) and financial records. Regulatory and operational requirements demand a complete audit trail:

1. **GDPR compliance**: Demonstrate lawful data processing; track who accessed/modified personal data
2. **Financial accountability**: Record who created settlements, exported data, or modified member accounts
3. **Dispute resolution**: Reconstruct historical state when members question charges or data changes
4. **Security monitoring**: Detect unauthorized access attempts or suspicious admin behavior
5. **Operational transparency**: Enable administrators to review system activity

### Scope

**In scope for audit logging:**
- Members (create, update, anonymize)
- Products (create, update, delete)
- Admin users (create, update, delete, login, logout)
- Terminals (create, update, delete)
- Settlements (create, revoke)
- SEPA configuration changes
- Data exports (GDPR, settlements)

**Out of scope:**
- Transactions — already immutable and append-only (see [ADR-0004](./0004-immutable-transaction-storage.md)); the transaction table itself serves as its own audit trail

**Two exceptions to that last line**, both recording something the transaction table cannot say about itself:

1. `transaction_storno` — a booking reversed in full (#169). The ledger shows the two rows; only the audit entry records *who* decided to reverse and *why*.
2. `transaction_price_divergence` — a synced sale whose `amount_cents` disagreed with the product's current `price_cents` (#204, ruling #144 §3). The row is stored exactly as sent and is perfectly self-consistent; what is not visible in it is the *disagreement*, because the comparison is against a catalogue that keeps moving. A price change days later would make yesterday's honest booking look divergent and today's stale one look fine, so the observation has to be recorded at the moment it is made or not at all.

The distinction the exceptions do **not** cross: an ordinary purchase is still never audited. What gets an entry is a human decision about a booking, or an anomaly observed while storing one — never the booking itself.

---

## Decision

**All changes to master data tables are recorded in a centralized `audit_log` table. Each entry captures who made the change, what changed (before/after values), when, and from where. Sensitive fields are masked in log entries.**

### Core Principles

1. **Append-only audit log**: Audit entries are never updated or deleted — with one exception: GDPR anonymization (see below)
2. **Complete change capture**: Record old and new values for all modified fields
3. **Sensitive data masking**: IBAN, passwords, and tokens are masked or omitted
4. **Actor identification**: Every entry links to the admin user who performed the action
5. **Request context**: Capture IP address and user agent for security analysis
6. **Retention alignment**: Audit log retained for same period as financial records (10 years per § 147 AO)
7. **GDPR audit log scrubbing**: When a member is anonymized, every historical audit entry carrying that member's `entity_id` must have `old_values` and `new_values` set to NULL to prevent PII reconstruction (see [Audit Log Scrubbing](#audit-log-scrubbing-during-gdpr-anonymization))
8. **No member PII in a foreign-keyed payload**: An audit entry filed under some *other* entity's id references members by id only, never by name or contact data — the scrub in principle 7 cannot reach inside it (see [What the scrub cannot reach](#what-the-scrub-cannot-reach))

### Data Structure

| Column | Type | Description |
|--------|------|-------------|
| id | BIGINT, PK, AUTO_INCREMENT | Unique log entry identifier |
| admin_user_id | UUID, FK → admin_users.id | Admin who performed the action |
| action | ENUM | Type of action performed |
| entity_type | VARCHAR(50) | Table/entity affected (e.g., 'member', 'product') |
| entity_id | UUID | Primary key of affected record |
| old_values | JSON | Field values before change (NULL for create) |
| new_values | JSON | Field values after change (NULL for delete) |
| ip_address | VARCHAR(45) | Client IP (supports IPv6) |
| user_agent | VARCHAR(500) | Browser/client identifier |
| created_at | DATETIME | Timestamp of action |

### Audit Actions

| Action | Description | old_values | new_values |
|--------|-------------|------------|------------|
| `create` | New record created | NULL | All fields |
| `update` | Record modified | Changed fields (before) | Changed fields (after) |
| `delete` | Record hard/soft-deleted | All fields | NULL |
| `activate` | Record reactivated (product, category, admin user, terminal) | NULL | `{ "is_active": true }` |
| `deactivate` | Record deactivated | NULL | `{ "is_active": false }` |
| `anonymize` | GDPR anonymization | NULL | `{ "deleted_at": "..." }` — no PII, per [What the scrub cannot reach](#what-the-scrub-cannot-reach) |
| `login` | Admin login successful | NULL | NULL |
| `logout` | Admin logout | NULL | NULL |
| `login_failed` | Failed login, or a failed step-up re-authentication before a sensitive cross-account action (#337) | NULL | `{ "attempted_email": "...", "context": "step_up_reauth" }` — `context` present only for the step-up case |
| `export` | Data export generated. Reserved: declared in the `AuditAction` enum but not currently emitted by any endpoint | NULL | `{ "export_type": "...", "format": "..." }` |
| `transaction_storno` | A booking reversed in full — the only admin-initiated transaction (#169); see [Out of scope](#scope) for why ordinary purchases are not logged here | NULL | `{ "related_transaction_id": "...", "member_id": "...", "amount_cents": ..., "reason": "..." }` |
| `transaction_price_divergence` | A synced sale claimed an `amount_cents` other than the product's current `price_cents` (#204). Written by the sync path, so `admin_user_id` is NULL; `ip_address` is the terminal's. The amount stands — this records the disagreement, never corrects it, and never rejects the row. A product that no longer exists is not a divergence and produces no entry | NULL | `{ "member_id": "...", "product_id": "...", "terminal_id": "...", "amount_cents": ..., "current_price_cents": ... }` — ids only, per [What the scrub cannot reach](#what-the-scrub-cannot-reach) |
| `settlement_create` | Settlement created | NULL | `{ "total_amount_cents": ..., "member_count": ..., "transaction_count": ... }` |
| `settlement_cancel` | Settlement cancelled | `{ "is_cancelled": false }` | `{ "is_cancelled": true, "reason": "..." }` |
| `settlement_export` | Settlement exported | NULL | `{ "exported_at": "...", ... }` |
| `settlement_submit` | The exported file reached the bank — the point cancellation ends (#81) | NULL | `{ "submitted_at": "...", "submitted_by_admin_id": "..." }` |
| `settlement_reverse` | Money that already moved has come back, per member (#196, ruling #148) | NULL | `{ "reason": "...", "member_ids": [...], "member_count": ..., "amount_cents": ... }` |
| `collection_hold_placed` | A bank return stopped the next run re-debiting this member (#196 §3) | NULL | `{ "collection_hold": true, "reason": "..." }` |
| `collection_hold_cleared` | An admin released that member back into the next run (#196 §5) | `{ "collection_hold": true, "reason": "..." }` | `{ "collection_hold": false }` |
| `totp_enrolled` | Admin enabled 2FA on their own account | NULL | NULL |
| `totp_reset` | An admin, after step-up re-authentication, removed 2FA from another admin's account (#337) | NULL | `{ "target_email": "..." }` |
| `mandate_document_upload` | SEPA mandate document uploaded for a member | NULL | `{ "original_filename": "...", "file_size_bytes": ... }` |
| `mandate_document_delete` | SEPA mandate document deleted | `{ "original_filename": "..." }` | NULL |

`reorder` is declared in the `AuditAction` enum but not currently emitted anywhere — reserved for a future product/category ordering feature.

### Audited Entities

| Entity | create | update | delete | Notes |
|--------|--------|--------|--------|-------|
| members | ✓ | ✓ | ✓ (anonymize) | IBAN masked in logs |
| products | ✓ | ✓ | ✓ | — |
| admin_users | ✓ | ✓ | ✓ | Password changes logged as `[CHANGED]` |
| terminals | ✓ | ✓ | ✓ | API tokens never logged |
| settlements | ✓ | — | ✓ (cancel) | Can be cancelled; export logged separately |
| sepa_config | ✓ | ✓ | — | IBAN masked |

### Sensitive Data Handling

| Field | Treatment in Audit Log |
|-------|----------------------|
| IBAN | Masked: `DE89****...****4567` (first 4 + last 4 visible) |
| Password | Only `[CHANGED]` recorded; never log hash or plaintext |
| API Token | Never logged |
| BIC | Logged fully (not sensitive) |
| Email | Logged fully (needed for audit trail) |

### Example Log Entries

**Member update (IBAN change):**
```json
{
  "admin_user_id": "a1b2c3...",
  "action": "update",
  "entity_type": "member",
  "entity_id": "d4e5f6...",
  "old_values": { "iban": "DE89****...****1234" },
  "new_values": { "iban": "DE89****...****5678" },
  "ip_address": "192.168.1.100",
  "created_at": "2025-01-23T14:30:00Z"
}
```

**GDPR anonymization:**
```json
{
  "admin_user_id": "a1b2c3...",
  "action": "anonymize",
  "entity_type": "member",
  "entity_id": "d4e5f6...",
  "old_values": null,
  "new_values": { "deleted_at": "2025-01-23T14:30:00Z" },
  "ip_address": "192.168.1.100",
  "created_at": "2025-01-23T14:30:00Z"
}
```

**Important**: The anonymization audit entry contains **no PII** — only the fact that anonymization occurred, when, and by whom. Storing old PII values (even masked) in the audit log would defeat the purpose of anonymization ([Art. 17 GDPR](https://gdpr-info.eu/art-17-gdpr/)).

**Failed login attempt:**
```json
{
  "admin_user_id": null,
  "action": "login_failed",
  "entity_type": "admin_user",
  "entity_id": null,
  "old_values": null,
  "new_values": { "attempted_email": "unknown@example.com" },
  "ip_address": "203.0.113.50",
  "created_at": "2025-01-23T14:30:00Z"
}
```

### Admin UI: Audit Log Viewer

The admin panel provides a dedicated audit log view with search and filtering capabilities.

**List View Features:**

| Feature | Description |
|---------|-------------|
| Pagination | Server-side pagination (default 50 entries per page) |
| Sort | By timestamp (default: newest first) |
| Search | Free-text search across entity_id, admin email, IP address |

**Filter Options:**

| Filter | Type | Description |
|--------|------|-------------|
| Date range | Date picker | Start and end date (inclusive) |
| Action | Multi-select | Filter by action type (create, update, delete, etc.) |
| Entity type | Multi-select | Filter by entity (member, product, admin_user, etc.) |
| Admin user | Dropdown | Filter by specific admin who performed action |

**Detail View:**

Clicking an audit entry opens a detail panel showing:
- Full timestamp with timezone
- Admin user name and email
- Action performed
- Entity type and ID (with link to entity if it still exists)
- Diff view: old_values vs new_values side-by-side
- Request metadata (IP address, user agent)

**Access Control:**

- All admin users have full access to audit log viewer

### Audit Log Scrubbing During GDPR Anonymization

> **Amended 2026-08-09.** The original scrub matched `entity_type = 'member' AND entity_id = ?`, which made the completeness of an erasure depend on every writer having filed its entry under the right type, and said nothing about member data embedded in *another* entity's payload — where no member-keyed scrub can follow it. See [#115](https://github.com/dgloeckner/clubbar/issues/115). The sweep is now keyed on `entity_id` alone, and the gap it cannot close is stated as an obligation on writers (principle 8). Amended text is marked inline.

When a member exercises their right to erasure ([Art. 17 GDPR](https://gdpr-info.eu/art-17-gdpr/)), personal data must be erased from **all storage**, including audit log entries. Leaving PII in historical audit entries (e.g., name changes, IBAN updates) would allow reconstruction of the member's identity, which violates the erasure obligation.

**Legal basis:**
- [Art. 17(1) GDPR](https://gdpr-info.eu/art-17-gdpr/) — Right to erasure: personal data must be deleted without undue delay
- [Art. 5(1)(e) GDPR](https://gdpr-info.eu/art-5-gdpr/) — Storage limitation: identifiable data kept no longer than necessary
- [Art. 4(5) GDPR](https://gdpr-info.eu/art-4-gdpr/) / [Recital 26](https://gdpr-info.eu/recitals/no-26/) — Pseudonymized data (reversible) is still personal data; only truly anonymous data falls outside GDPR scope

**Scrubbing procedure** (executed atomically during member anonymization):

1. **Scrub all historical audit entries** for the member:
   ```sql
   UPDATE audit_log
   SET old_values = NULL, new_values = NULL
   WHERE entity_id = ?
   ```

   *Amended:* keyed on the id alone. Entity ids are UUIDs, so an entry
   carrying this member's id is about this member whatever type it claims to
   be, and a mistyped entry is precisely the one an erasure must not skip.
   `idx_audit_entity_id` serves the sweep, so dropping the type costs nothing.
2. **Create new anonymization entry** with no PII:
   ```sql
   INSERT INTO audit_log (action, entity_type, entity_id, old_values, new_values, ...)
   VALUES ('anonymize', 'member', ?, NULL, '{"deleted_at": "..."}', ...)
   ```

**What is preserved** after scrubbing:
- Audit entry metadata: `id`, `admin_user_id`, `action`, `entity_type`, `entity_id`, `ip_address`, `user_agent`, `created_at`
- This proves accountability ([Art. 5(2) GDPR](https://gdpr-info.eu/art-5-gdpr/)): *who* performed *what action* on *which entity* and *when*

**What is removed**:
- `old_values` and `new_values` JSON payloads containing PII (names, IBAN, email, phone, etc.)

**Why this is the only exception to append-only**: The audit log's append-only design exists to ensure accountability. GDPR Art. 17 creates a legal obligation that supersedes the append-only principle for PII content — but not for the audit structure itself. The entry rows remain; only the PII payloads are nullified.

#### What the scrub cannot reach

An erasure sweeps the entries keyed to the member's id. It cannot sweep an
entry keyed to a **different** entity that mentions the member inside its
payload — a settlement's export entry naming the members the bank file left
out, for example. Nulling that payload is not an option either: it would
destroy the record for every other member named in the same entry, and that
record exists because somebody has to be able to read it later.

So the obligation runs the other way, as principle 8 above:

| Entry keyed to | May carry |
|----------------|-----------|
| The member (`entity_id` = member id) | Their personal data — the scrub reaches it and nulls it |
| Any other entity | Member **ids** only; no name, email, phone, address or banking data |

A member id is not anonymous while the member exists (Art. 4(5): pseudonymous
data is still personal data), but it is the one reference that *changes meaning*
when the erasure runs: afterwards it resolves to an anonymized row and names
nobody. A name written into a foreign-keyed payload resolves to the person
forever.

This is a rule about what writers may put in a payload, so it is held by tests
at the writers rather than by a constraint on the table — see
`SepaExportServiceTest::test_the_audit_summary_carries_no_member_pii` and the
scrub tests in `AuditLogRepositoryTest`. It is worth re-checking whenever a new
audit action is added that spans more than one entity.

The same reasoning extends to the **application log**, which no erasure reaches
at all: a member named in a log line outlives the erasure until the file is
rotated away. Log lines about members carry ids for the same reason.

---

## Consequences

### Positive

- **Compliance ready**: Meets GDPR accountability requirements (Art. 5, Art. 30)
- **Complete history**: Any past state can be reconstructed from audit trail
- **Security visibility**: Failed logins and suspicious patterns detectable
- **Dispute resolution**: Clear evidence of who changed what and when
- **Non-repudiation**: Admin actions are attributable and timestamped

### Negative

- **Storage growth**: Audit log grows with every change (mitigated by 10-year retention policy)
- **Write overhead**: Every change requires additional INSERT (minimal impact)
- **Query complexity**: Historical reconstruction requires JSON parsing

### Mitigations

1. **Storage**: Implement log rotation after retention period; archive to cold storage if needed
2. **Performance**: Audit INSERT is async-safe; no transaction blocking required
3. **Querying**: Add indexes on `entity_type`, `entity_id`, `admin_user_id`, `created_at` for common queries

---

## Alternatives Considered

### Alternative 1: Database Triggers

Use MariaDB triggers to automatically log changes.

**Pros**: Automatic; cannot be bypassed by application code
**Cons**:
- No access to admin user context (who made the change)
- No IP address or user agent capture
- Harder to maintain and debug
- Trigger logic duplicated across tables

**Rejected**: Application-level logging provides richer context (actor, request metadata).

### Alternative 2: Event Sourcing

Store all changes as events; reconstruct current state from event stream.

**Pros**: Complete history; enables temporal queries
**Cons**:
- Significant architectural complexity
- Overkill for simple CRUD operations
- Requires event replay for current state
- Learning curve for team

**Rejected**: Traditional audit log sufficient for compliance and debugging needs.

### Alternative 3: Audit per Table (Separate History Tables)

Create `members_history`, `products_history`, etc. with full row copies.

**Pros**: Simple queries; full row snapshots
**Cons**:
- Schema duplication (history table mirrors main table)
- Schema changes require updating both tables
- No unified view across entity types
- Larger storage footprint

**Rejected**: Centralized audit log with JSON change capture is more flexible and maintainable.

---

## Related Decisions

- [ADR-0004: Immutable Transaction Storage](./0004-immutable-transaction-storage.md) - Transactions are self-auditing; excluded from audit_log

---

## References

- **GDPR**:
  - [Art. 4(5)](https://gdpr-info.eu/art-4-gdpr/) — Definition of pseudonymization (reversible ≠ anonymous)
  - [Art. 5(1)(e)](https://gdpr-info.eu/art-5-gdpr/) — Storage limitation principle
  - [Art. 5(2)](https://gdpr-info.eu/art-5-gdpr/) — Accountability principle
  - [Art. 17](https://gdpr-info.eu/art-17-gdpr/) — Right to erasure ("right to be forgotten")
  - [Art. 30](https://gdpr-info.eu/art-30-gdpr/) — Records of processing activities
  - [Recital 26](https://gdpr-info.eu/recitals/no-26/) — Anonymous data falls outside GDPR scope
- **German Law**:
  - [§ 147 AO](https://www.gesetze-im-internet.de/ao_1977/__147.html) — Retention of business records (8 years for booking vouchers, 10 years for annual statements)
  - [§ 257 HGB](https://www.gesetze-im-internet.de/hgb/__257.html) — Commercial retention obligations
- **OWASP Logging Guide**: [Logging Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html)

---

## Post-Implementation Monitoring

- Monitor audit_log table size growth monthly
- Alert on unusual login_failed patterns (brute force detection)
- Review anonymization actions quarterly (GDPR compliance check)
- Verify audit entries exist for all admin panel write operations
- Test audit log restoration from backups annually
