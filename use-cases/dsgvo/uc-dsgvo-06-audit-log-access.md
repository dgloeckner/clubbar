# UC-DSGVO-06: Audit Log Access (Art. 30)

**GDPR Article**: Art. 30 - Records of processing activities
**Retention**: 10 years (§ 147 AO)

## Summary

Administrators access the audit log to review data processing activities for compliance verification, incident investigation, or regulatory requests.

## Actors

- **Admin**: Full access to audit log (role: `admin`)
- **Auditor**: Read-only access for compliance (role: `auditor`)
- **Viewer**: No access (role: `viewer`)

## Preconditions

1. User has `admin` or `auditor` role
2. Audit log contains entries

## Use Cases for Audit Log Access

| Scenario | Purpose |
|----------|---------|
| Member complaint | Verify what data was accessed/modified |
| Regulatory inquiry | Demonstrate compliance |
| Security incident | Investigate unauthorized access |
| Internal review | Periodic compliance check |
| Dispute resolution | Verify transaction history integrity |

## Main Flow

1. Admin/Auditor navigates to Audit Log section
2. System displays list of audit entries (newest first)
3. User applies filters as needed
4. User selects entry to view details
5. System shows full entry with old/new values

## Audit Log Entry Structure

| Field | Description |
|-------|-------------|
| id | Auto-increment identifier |
| created_at | Timestamp of action (immutable) |
| admin_user_id | Who performed the action |
| action | What was done (see below) |
| entity_type | What type of record was affected |
| entity_id | UUID of affected record |
| old_values | JSON: field values before change |
| new_values | JSON: field values after change |
| ip_address | Client IP address |
| user_agent | Browser/client identifier |

## Logged Actions

| Action | Trigger |
|--------|---------|
| `create` | New record created |
| `update` | Record modified |
| `delete` | Record hard-deleted |
| `anonymize` | GDPR anonymization |
| `export` | GDPR data export |
| `login` | Successful admin login |
| `logout` | Admin logout |
| `login_failed` | Failed login attempt |
| `settlement_create` | Settlement created |
| `settlement_finalize` | Settlement finalized |

## Logged Entities

| Entity Type | Key Events |
|-------------|------------|
| `member` | create, update, anonymize, export |
| `product` | create, update, delete |
| `admin_user` | create, update, delete, login, logout |
| `terminal` | create, update, delete |
| `settlement` | create, finalize |
| `sepa_config` | create, update |

## Filter Options

| Filter | Values |
|--------|--------|
| Date range | From date, To date |
| Action | create, update, delete, anonymize, export, login, etc. |
| Entity type | member, product, admin_user, terminal, settlement |
| Admin user | Dropdown of admin users |
| Entity ID | Specific UUID |

## Sensitive Data Masking

Data masked in audit log entries:

| Field | Masking |
|-------|---------|
| IBAN | `DE89****...****4567` (first 4 + last 4) |
| Password | `[CHANGED]` (never logged) |
| API tokens | Never logged |

## Alternative Flows

### AF1: No entries match filter
- Step 3: System shows "No entries found"

### AF2: Viewer attempts access
- Step 1: Audit Log section not visible or access denied

## Postconditions

- Audit log entries displayed (read-only)
- No modifications possible to audit log
- Access itself is NOT logged (avoid infinite recursion)

## Immutability

- Audit log entries cannot be modified or deleted via application
- Database-level protection recommended (no DELETE/UPDATE permissions)
- Archival to separate storage for long-term retention

## Test Scenarios

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| T01 | Admin views audit log | Full list displayed |
| T02 | Auditor views audit log | Full list displayed (read-only) |
| T03 | Viewer views audit log | Access denied |
| T04 | Filter by action=anonymize | Only anonymize entries shown |
| T05 | Filter by entity_type=member | Only member entries shown |
| T06 | Filter by date range | Entries within range shown |
| T07 | View entry with IBAN change | IBAN masked in display |
| T08 | View entry with password change | Shows `[CHANGED]`, no hash |
| T09 | Entry detail shows IP address | IP visible in detail view |
| T10 | Entry detail shows user agent | User agent visible |
| T11 | Attempt to delete audit entry | Not possible via UI |
| T12 | Search by entity_id (UUID) | Correct entry found |
