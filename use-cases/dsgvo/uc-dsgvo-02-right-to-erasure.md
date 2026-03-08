# UC-DSGVO-02: Right to Erasure (Art. 17)

**Implementation Status**: Partially implemented — action needed (backend needs fixes, frontend UI button missing)

**GDPR Article**: [Art. 17 — Right to erasure ("right to be forgotten")](https://gdpr-info.eu/art-17-gdpr/)
**Response Deadline**: 1 month
**Legal Exception**: [Art. 17(3)(b)](https://gdpr-info.eu/art-17-gdpr/) — retention for legal obligation ([§ 147 AO](https://www.gesetze-im-internet.de/ao_1977/__147.html))

## Summary

A member requests deletion of their personal data. Due to tax law requirements, the system anonymizes personal data while retaining transaction history. Anonymization is **irreversible** — all PII is destroyed across all storage including audit log entries.

## Legal Basis

| Requirement | GDPR Article | Summary |
|-------------|-------------|---------|
| Erase personal data on request | [Art. 17(1)](https://gdpr-info.eu/art-17-gdpr/) | Data subject may request deletion when purpose lapses |
| Retain financial records | [Art. 17(3)(b)](https://gdpr-info.eu/art-17-gdpr/) | Exception: legal obligation (§ 147 AO) permits retention |
| Storage limitation | [Art. 5(1)(e)](https://gdpr-info.eu/art-5-gdpr/) | No identifiable data beyond purpose |
| Accountability | [Art. 5(2)](https://gdpr-info.eu/art-5-gdpr/) | Controller must prove compliance |
| True anonymization | [Art. 4(5)](https://gdpr-info.eu/art-4-gdpr/) / [Recital 26](https://gdpr-info.eu/recitals/no-26/) | Pseudonymized data is still personal data; only truly anonymous data falls outside GDPR |
| Processing records | [Art. 30](https://gdpr-info.eu/art-30-gdpr/) | Document anonymization as processing activity |

## Actors

- **Member**: Requests deletion
- **Admin**: Processes the request

## Preconditions

1. Member exists in system (not already anonymized)
2. Admin has verified member identity
3. Admin is logged in

## Trigger

Member submits deletion request (verbal, written, or email - outside system).

## Pre-Deletion Checks

| Check | Condition | Blocking |
|-------|-----------|----------|
| Outstanding balance | Must be €0.00 | Yes |
| Active settlement | No pending settlement including this member | Yes |
| Already anonymized | `deleted_at` must be NULL | Yes |

## Main Flow

1. Admin navigates to member detail page
2. Admin selects "Anonymize Member" action
3. System performs pre-deletion checks
4. System displays confirmation dialog with:
   - Fields to be anonymized
   - Warning about irreversibility
   - Note about transaction retention
5. Admin confirms anonymization
6. System anonymizes member data (see [Anonymization Transformations](#anonymization-transformations))
7. System scrubs historical audit log entries (see [Audit Log Scrubbing](#audit-log-scrubbing))
8. System creates anonymization audit entry (no PII)
9. System displays confirmation

## Anonymization Transformations

All personal data fields set to NULL — true anonymization, not pseudonymization.

| Field | Before | After |
|-------|--------|-------|
| first_name | "Max" | NULL |
| last_name | "Mustermann" | NULL |
| email | "max@example.com" | NULL |
| phone | "+49 170 1234567" | NULL |
| iban | "DE89370400440532013000" | NULL |
| account_holder_name | "Max Mustermann" | NULL |
| mandate_reference | "ABC123..." | NULL |
| card_uid | "A1B2C3D4" | "ANON-{uuid}" |
| is_active | true/false | false |
| deleted_at | NULL | Current timestamp |

**card_uid** is set to `"ANON-{uuid}"` (not NULL) to maintain UNIQUE constraint validity and prevent RFID re-use.

**Unchanged**: `id` (UUID), `created_at`, `updated_at`, `preferred_language`, `mandate_signed_at`

## Audit Log Scrubbing

**Critical**: Anonymization must scrub PII from **all** storage, including historical audit log entries. Leaving PII in audit entries would allow identity reconstruction, violating [Art. 17 GDPR](https://gdpr-info.eu/art-17-gdpr/).

**Step 1 — Scrub historical entries:**
```sql
UPDATE audit_log
SET old_values = NULL, new_values = NULL
WHERE entity_type = 'member' AND entity_id = ?
```

**Step 2 — Create anonymization entry (no PII):**

| Field | Value |
|-------|-------|
| action | `anonymize` |
| entity_type | `member` |
| entity_id | Member UUID |
| old_values | NULL |
| new_values | `{ "deleted_at": "2025-01-23T14:30:00Z" }` |

**What is preserved**: Entry metadata (who, what action, when, IP) — proves accountability per [Art. 5(2)](https://gdpr-info.eu/art-5-gdpr/).
**What is removed**: All `old_values`/`new_values` JSON payloads containing PII.

## Alternative Flows

### AF1: Outstanding balance > €0
- Step 3: System shows "Cannot anonymize: Outstanding balance of €X.XX"
- Admin must first:
  - Process settlement, OR
  - Record cash payment (correction transaction), OR
  - Write off balance (board resolution + correction transaction)

### AF2: Active settlement includes member
- Step 3: System shows "Cannot anonymize: Member included in pending settlement"
- Admin must finalize or cancel settlement first

### AF3: Member already anonymized
- Step 2: "Anonymize" action not available
- Member detail shows "Anonymized on {date}"

## Postconditions

- All personal data fields set to NULL in `members` table
- card_uid changed to "ANON-{uuid}"
- is_active = false, deleted_at = timestamp
- All historical audit log entries for this member have old_values/new_values = NULL
- New anonymization audit entry created (no PII)
- Transactions remain intact (linked to anonymized member via UUID)

## Terminal Sync Impact

1. Next sync: Terminal receives member with `deleted_at` set
2. Terminal removes member from local cache
3. RFID scan with old card: "Unknown card" displayed

## Test Scenarios

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| T01 | Anonymize member with zero balance | All personal fields NULL, deleted_at set |
| T02 | Anonymize member with positive balance | Blocked with balance error |
| T03 | Anonymize member with negative balance | Blocked with balance error |
| T04 | Anonymize member in active settlement | Blocked with settlement error |
| T05 | Transactions after anonymization | Transactions exist, linked to anonymized member |
| T06 | Terminal sync after anonymization | Member removed from terminal cache |
| T07 | RFID scan after anonymization | "Unknown card" displayed |
| T08 | Anonymize without authentication | Access denied (401) |
| T09 | Historical audit log entries scrubbed | All old_values/new_values NULL for this member's audit entries |
| T10 | Anonymization audit entry has no PII | Entry contains only action metadata and deleted_at |
| T11 | Double anonymization attempt | Action not available / rejected |
| T12 | Member data not reconstructable | No PII exists in any table for this member after anonymization |

## Related Documentation

- [ADR-0004: Immutable Transaction Storage](../../adr/0004-immutable-transaction-storage.md) — Transactions retained per Art. 17(3)(b)
- [ADR-0013: Audit Logging](../../adr/0013-audit-logging.md) — Audit log scrubbing during anonymization
- [UC-DSGVO-01: Right to Access](./uc-dsgvo-01-right-to-access.md) — Data export must happen before anonymization
