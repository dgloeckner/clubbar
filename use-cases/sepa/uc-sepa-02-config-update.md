# UC-SEPA-02: SEPA Configuration Update

**Category**: Configuration

## Summary

Admin updates existing SEPA configuration. Gläubiger-ID cannot be changed after initial setup.

## Actors

- **Admin**: Updates organization SEPA settings

## Preconditions

1. Admin has `admin` role
2. SEPA configuration exists

## Trigger

Admin navigates to Settings → SEPA Configuration to modify settings.

## Editable Fields

| Field | Editable | Notes |
|-------|----------|-------|
| Gläubiger-ID | No | Immutable after initial setup |
| Organization Name | Yes | Max 70 characters |
| Organization IBAN | Yes | Validated with mod-97 |
| Street Address | Yes | Max 70 characters |
| City/Postal Code | Yes | Max 70 characters |
| Country | Yes | ISO 3166-1 code |

## Main Flow

1. Admin navigates to Settings → SEPA Configuration
2. System displays current config in read-only mode
3. Admin clicks Edit
4. System shows editable form (Gläubiger-ID disabled)
5. Admin modifies fields
6. System validates changes in real-time
7. Admin clicks Save
8. System validates all fields
9. System updates SEPA config record
10. System creates audit log entry
11. System displays confirmation

## Audit Log Entry

| Field | Value |
|-------|-------|
| action | `update` |
| entity_type | `sepa_config` |
| entity_id | 1 (singleton) |
| old_values | Previous values (IBAN masked) |
| new_values | Updated values (IBAN masked) |

## Alternative Flows

### AF1: No changes made
- Step 7: Admin clicks Save without changes
- System shows "No changes to save" or saves without audit entry

### AF2: Invalid IBAN
- Step 6: Real-time validation shows error
- Save button disabled until corrected

### AF3: Attempt to change Gläubiger-ID
- Step 4: Field is disabled/read-only
- Cannot be modified via UI

## Postconditions

- SEPA config updated
- Future exports use new values
- Audit log records changes

## Impact on Existing Settlements

- Already finalized settlements: Not affected
- Pending settlements: Will use updated config on export

## Test Scenarios

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| T01 | Update organization name | Name updated, audit log created |
| T02 | Update IBAN with valid value | IBAN updated |
| T03 | Update IBAN with invalid value | Validation error |
| T04 | Attempt to change Gläubiger-ID | Field disabled, cannot change |
| T05 | Update by viewer role | Access denied |
| T06 | Audit log old/new values | Both values present, IBAN masked |
| T07 | Update address fields | All address fields updated |
| T08 | Cancel edit without saving | No changes persisted |
