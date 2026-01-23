# UC-A60: Edit Organization Data

## Actor
Admin

## Preconditions
- Admin is logged in

## Trigger
Admin opens Settings → Organization

## Main Flow
1. Admin clicks "Organization" in Settings
2. System displays organization form
3. Admin edits fields
4. Admin saves changes
5. System validates input
6. System updates configuration
7. System displays success message

## Form Fields

| Field | Required | Validation |
|-------|----------|------------|
| Organization name | Yes | Non-empty, max 70 chars |
| Gläubiger-ID | Yes | Valid German creditor ID format |
| IBAN | Yes | Valid IBAN format + checksum |
| Street address | No | Max 70 chars |
| City | No | Max 35 chars |
| Country | No | ISO 3166-1 alpha-2 |

## Field Usage
- Organization name: SEPA XML header
- Gläubiger-ID: SEPA creditor identifier
- IBAN: Receiving account for collections
- Address: SEPA XML (optional)

## Postconditions
- Organization data updated
- Next SEPA export uses new data
- Audit log entry

## Error Cases

### E1: Invalid Gläubiger-ID
- Format not matching DE pattern
- Display "Invalid creditor ID format"

### E2: Invalid IBAN
- Checksum or format error
- Display "Invalid IBAN"

## Test Derivation
- Update all fields: saved correctly
- Invalid Gläubiger-ID: validation error
- Invalid IBAN: validation error
- Empty required field: validation error
- SEPA export: uses updated data
- Audit log: changes logged
