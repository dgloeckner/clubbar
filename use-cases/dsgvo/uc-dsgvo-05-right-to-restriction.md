# UC-DSGVO-05: Right to Restriction of Processing (Art. 18)

**GDPR Article**: Art. 18 - Right to restriction of processing
**Response Deadline**: 1 month

## Summary

A member requests that their data processing be restricted (but not deleted). The system implements this by deactivating the member account.

## When Restriction Applies

| Situation | Example |
|-----------|---------|
| Accuracy contested | Member disputes stored data; restriction while verifying |
| Unlawful processing | Member prefers restriction over erasure |
| Data no longer needed | Controller doesn't need data, but member needs it for legal claims |
| Objection pending | Member objected (Art. 21); restriction while evaluating |

## Actors

- **Member**: Requests restriction
- **Admin**: Deactivates member

## Preconditions

1. Member exists in system (not anonymized)
2. Admin has `admin` role

## Trigger

Member requests processing restriction (verbal, written, or email - outside system).

## Main Flow

1. Admin navigates to member detail page
2. Admin selects "Edit" action
3. Admin sets `is_active` to false
4. Admin adds note (if notes field available) documenting restriction reason
5. System updates member record
6. System creates audit log entry
7. Admin confirms restriction to member

## Effect of Restriction

| Processing Activity | Allowed | Notes |
|---------------------|---------|-------|
| Storage | Yes | Data retained |
| New transactions | No | Terminal rejects inactive members |
| Include in settlements | No | Only active members settled |
| Data access (Art. 15) | Yes | Member can still request their data |
| Rectification (Art. 16) | Yes | Admin can correct data |
| Erasure (Art. 17) | Yes | Member can request deletion |

## Audit Log Entry

| Field | Value |
|-------|-------|
| action | `update` |
| entity_type | `member` |
| entity_id | Member UUID |
| old_values | `{ "is_active": true }` |
| new_values | `{ "is_active": false }` |

## Lifting Restriction

When restriction is lifted:

1. Admin sets `is_active` to true
2. System creates audit log entry
3. Admin informs member before resuming processing

## Terminal Behavior

When member is deactivated:

1. Terminal syncs `is_active: false`
2. RFID scan shows "Member inactive" or similar
3. No transactions can be created for this member

## Alternative Flows

### AF1: Member already inactive
- Step 3: Field already false
- Admin confirms restriction is in place

### AF2: Member is anonymized
- Cannot restrict; data already minimized
- Inform member that deletion was already processed

## Postconditions

- Member marked as inactive
- Processing limited to storage only
- Terminal rejects new transactions
- Audit log records status change

## Test Scenarios

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| T01 | Deactivate active member | is_active = false, audit log created |
| T02 | Terminal sync after deactivation | Member shows as inactive |
| T03 | RFID scan for inactive member | Transaction rejected with message |
| T04 | Settlement excludes inactive member | Inactive members not included |
| T05 | Data export for inactive member | Export still available |
| T06 | Anonymize inactive member | Anonymization proceeds normally |
| T07 | Reactivate member | is_active = true, audit log created |
| T08 | RFID scan after reactivation | Transactions allowed again |
| T09 | Deactivate by viewer role | Access denied |
| T10 | Deactivate already inactive member | No error, no duplicate audit entry |
