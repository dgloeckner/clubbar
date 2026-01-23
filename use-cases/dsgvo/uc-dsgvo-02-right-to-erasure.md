# UC-DSGVO-02: Right to Erasure (Art. 17)

**GDPR Article**: Art. 17 - Right to erasure ("right to be forgotten")
**Response Deadline**: 1 month
**Legal Exception**: § 147 AO requires 10-year transaction retention

## Summary

A member requests deletion of their personal data. Due to tax law requirements, the system anonymizes personal data while retaining transaction history.

## Actors

- **Member**: Requests deletion
- **Admin**: Processes the request

## Preconditions

1. Member exists in system (not already anonymized)
2. Admin has verified member identity
3. Admin has `admin` role

## Trigger

Member submits deletion request (verbal, written, or email - outside system).

## Pre-Deletion Checks

| Check | Condition | Blocking |
|-------|-----------|----------|
| Outstanding balance | Must be €0.00 | Yes |
| Active settlement | No pending settlement including this member | Yes |

## Main Flow

1. Admin navigates to member detail page
2. Admin selects "Anonymize Member" action
3. System performs pre-deletion checks
4. System displays confirmation dialog with:
   - Fields to be anonymized
   - Warning about irreversibility
   - Note about transaction retention
5. Admin confirms anonymization
6. System anonymizes member data
7. System creates audit log entry
8. System displays confirmation

## Anonymization Transformations

| Field | Before | After |
|-------|--------|-------|
| first_name | "Max" | NULL |
| last_name | "Mustermann" | NULL |
| iban | "DE89370400440532013000" | NULL |
| bic | "COBADEFFXXX" | NULL |
| card_uid | "A1B2C3D4" | "DELETED-{uuid}" |
| is_active | true/false | false |
| deleted_at | NULL | Current timestamp |

**Unchanged**: id (UUID), created_at

## Audit Log Entry

| Field | Value |
|-------|-------|
| action | `anonymize` |
| entity_type | `member` |
| entity_id | Member UUID |
| old_values | `{ "first_name": "Max", "last_name": "Mustermann", "iban": "DE89****0000", "card_uid": "A1B2C3D4" }` |
| new_values | `{ "first_name": null, "last_name": null, "iban": null, "card_uid": "DELETED-{uuid}", "deleted_at": "..." }` |

Note: IBAN masked in audit log (first 4 + last 4 characters only).

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

- Personal data fields set to NULL
- card_uid changed to "DELETED-{uuid}"
- is_active = false, deleted_at = timestamp
- Transactions remain intact (linked to anonymized member)
- Audit log records anonymization

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
| T08 | Anonymize by viewer role | Access denied |
| T09 | Audit log IBAN masking | IBAN shows as "DE89****0000" in log |
| T10 | Double anonymization attempt | Action not available |
