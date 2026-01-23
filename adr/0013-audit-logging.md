# ADR-0013: Audit Logging for Master Data Changes

**Status**: Accepted

**Date**: 2025-01-23

---

## Context

The Member Bar system manages sensitive member data (personal information, banking details) and financial records. Regulatory and operational requirements demand a complete audit trail:

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

---

## Decision

**All changes to master data tables are recorded in a centralized `audit_log` table. Each entry captures who made the change, what changed (before/after values), when, and from where. Sensitive fields are masked in log entries.**

### Core Principles

1. **Append-only audit log**: Audit entries are never updated or deleted
2. **Complete change capture**: Record old and new values for all modified fields
3. **Sensitive data masking**: IBAN, passwords, and tokens are masked or omitted
4. **Actor identification**: Every entry links to the admin user who performed the action
5. **Request context**: Capture IP address and user agent for security analysis
6. **Retention alignment**: Audit log retained for same period as financial records (10 years per § 147 AO)

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
| `delete` | Record hard-deleted | All fields | NULL |
| `anonymize` | GDPR anonymization | Anonymized fields (masked) | New anonymized values |
| `login` | Admin login successful | NULL | NULL |
| `logout` | Admin logout | NULL | NULL |
| `login_failed` | Failed login attempt | NULL | `{ "attempted_email": "..." }` |
| `export` | Data export generated | NULL | `{ "export_type": "...", "format": "..." }` |
| `settlement_create` | Settlement created | NULL | `{ "settlement_id": "...", "total_amount": ... }` |
| `settlement_cancel` | Settlement cancelled | `{ "settlement_id": "..." }` | NULL |
| `settlement_export` | Settlement exported | NULL | `{ "settlement_id": "...", "format": "csv/xml" }` |

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
  "old_values": {
    "first_name": "Max",
    "last_name": "Mustermann",
    "iban": "[MASKED]"
  },
  "new_values": {
    "first_name": null,
    "last_name": null,
    "display_name": "Deleted User",
    "card_uid": "ANONYMOUS-d4e5f6...",
    "deleted_at": "2025-01-23T14:30:00Z"
  }
}
```

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

- **GDPR Article 5**: Principles of data processing (accountability)
- **GDPR Article 30**: Records of processing activities
- **§ 147 AO (German Tax Code)**: 10-year retention for business records
- **OWASP Logging Guide**: [Logging Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html)

---

## Post-Implementation Monitoring

- Monitor audit_log table size growth monthly
- Alert on unusual login_failed patterns (brute force detection)
- Review anonymization actions quarterly (GDPR compliance check)
- Verify audit entries exist for all admin panel write operations
- Test audit log restoration from backups annually
