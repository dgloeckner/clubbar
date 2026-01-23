# UC-SEPA-01: SEPA Configuration Setup

**Category**: Configuration
**Prerequisite**: Gläubiger-ID obtained from Bundesbank

## Summary

Admin performs initial SEPA configuration to enable direct debit settlements.

## Actors

- **Admin**: Configures organization SEPA settings

## Preconditions

1. Admin has `admin` role
2. SEPA configuration does not exist (first-time setup)
3. Organization has obtained Gläubiger-ID from Bundesbank

## Trigger

Admin navigates to Settings → SEPA Configuration for the first time.

## Required Fields

| Field | Format | Validation |
|-------|--------|------------|
| Gläubiger-ID | DE + 2 digits + ZZZ + 11 chars | 18 characters, German format |
| Organization Name | Text | Max 70 characters (SEPA limit) |
| Organization IBAN | ISO 13616 | Mod-97 checksum valid |
| Street Address | Text | Max 70 characters |
| City/Postal Code | Text | Max 70 characters |
| Country | ISO 3166-1 | 2-letter code (e.g., DE) |

## Main Flow

1. Admin navigates to Settings → SEPA Configuration
2. System shows setup wizard (no existing config)
3. Admin enters Gläubiger-ID
4. Admin enters organization name
5. Admin enters organization IBAN
6. System validates IBAN in real-time
7. Admin enters address (street, city, country)
8. Admin clicks Save
9. System validates all fields
10. System creates SEPA config record
11. System creates audit log entry
12. System displays confirmation

## Audit Log Entry

| Field | Value |
|-------|-------|
| action | `create` |
| entity_type | `sepa_config` |
| entity_id | 1 (singleton) |
| new_values | All fields (IBAN masked) |

## Alternative Flows

### AF1: Invalid IBAN checksum
- Step 6: System shows "Invalid IBAN" error
- Admin corrects IBAN

### AF2: Invalid Gläubiger-ID format
- Step 9: System shows "Invalid Gläubiger-ID format"
- Admin corrects ID

### AF3: Config already exists
- Step 2: System shows edit mode instead of wizard
- See UC-SEPA-02

## Postconditions

- SEPA config record created
- Settlements can now be exported as SEPA XML
- Audit log records creation

## Test Scenarios

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| T01 | First-time setup with valid data | Config created |
| T02 | Setup with invalid IBAN | Validation error |
| T03 | Setup with invalid Gläubiger-ID format | Validation error |
| T04 | Setup with name > 70 chars | Validation error or truncation |
| T05 | Setup by viewer role | Access denied |
| T06 | Audit log created | Contains all fields, IBAN masked |
| T07 | IBAN real-time validation | Feedback shown before submit |
| T08 | Country code validation | Only 2-letter codes accepted |
