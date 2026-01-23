# UC-DSGVO-03: Right to Rectification (Art. 16)

**GDPR Article**: Art. 16 - Right to rectification
**Response Deadline**: 1 month

## Summary

A member requests correction of inaccurate personal data.

## Actors

- **Member**: Requests correction
- **Admin**: Updates the data

## Preconditions

1. Member exists in system (not anonymized)
2. Admin is logged in

## Trigger

Member reports incorrect data (verbal, written, or email - outside system).

## Editable Fields

| Field | Validation |
|-------|------------|
| first_name | Non-empty, max 100 characters |
| last_name | Non-empty, max 100 characters |
| card_uid | Hex string, 8-20 characters, unique |
| iban | ISO 13616 format, mod-97 checksum valid |
| mandate_reference | Max 35 characters |
| preferred_language | ISO 639-1 code (de, en, etc.) |
| is_active | Boolean |

**Not editable**: id, created_at

## Main Flow

1. Admin navigates to member detail page
2. Admin selects "Edit" action
3. System displays edit form with current values
4. Admin modifies incorrect field(s)
5. System validates input
6. Admin saves changes
7. System updates member record
8. System creates audit log entry
9. System displays confirmation

## Audit Log Entry

| Field | Value |
|-------|-------|
| action | `update` |
| entity_type | `member` |
| entity_id | Member UUID |
| old_values | `{ "first_name": "Mxa", "iban": "DE89****0000" }` |
| new_values | `{ "first_name": "Max", "iban": "DE12****5678" }` |

Note: IBAN masked in audit log.

## Alternative Flows

### AF1: IBAN validation fails
- Step 5: System shows "Invalid IBAN: Checksum incorrect"
- Admin corrects IBAN and retries

### AF2: Card UID already in use
- Step 5: System shows "Card UID already assigned to another member"
- Admin investigates duplicate

### AF3: Member is anonymized
- Step 2: "Edit" action not available
- Cannot rectify anonymized data

## Postconditions

- Member record updated with corrected data
- Audit log records old and new values
- Terminal receives update on next sync

## Terminal Sync Impact

Only synced fields are updated on terminal:
- card_uid (for RFID lookup)
- first_name, last_name (for display)
- preferred_language (for UI language)
- is_active (for blocking)

IBAN changes do NOT affect terminal (not synced).

## Historical Data

- Transactions retain original values (product name, price at time of purchase)
- No retroactive changes to transaction history
- Member rectification does not alter past records

## Test Scenarios

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| T01 | Update first_name | Field updated, audit log created |
| T02 | Update IBAN with valid value | IBAN updated, masked in audit log |
| T03 | Update IBAN with invalid checksum | Validation error, no update |
| T04 | Update card_uid to unique value | card_uid updated |
| T05 | Update card_uid to duplicate | Validation error, no update |
| T06 | Update anonymized member | Edit not available |
| T07 | Update without authentication | Access denied (401) |
| T08 | Terminal sync after name change | Terminal shows new name |
| T09 | Terminal sync after IBAN change | No change on terminal (IBAN not synced) |
| T10 | Transactions after name change | Transactions unchanged |
