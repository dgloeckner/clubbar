# UC-A12: Edit Member

**Implementation Status**: Implemented

## Actor
Admin

## Preconditions
- Admin is logged in
- Member exists

## Trigger
Admin selects member from list

## Main Flow
1. Admin clicks member in list
2. System displays member detail view with SEPA status indicator
3. Admin clicks "Edit"
4. System displays edit form with current values
5. Admin modifies fields
6. Admin saves changes
7. System validates input
8. System updates member record
9. System displays success message with updated SEPA status

## Editable Fields

| Field | Notes |
|-------|-------|
| First name | |
| Last name | |
| Email | |
| IBAN | Validation on change; clearing removes SEPA validity |
| Mandate date | Required if IBAN set |
| Mandate reference | SEPA identifier; default = UUID without hyphens |
| Preferred language | |

## Read-Only Fields

| Field | Reason |
|-------|--------|
| UUID | Immutable identifier |
| Created date | Historical |
| Tab balance | Changed via transactions only |

## SEPA Status Display

Form shows current SEPA status (derived, not editable directly):

| Status | Condition | Display |
|--------|-----------|---------|
| Valid | IBAN present AND mandate_reference present | Green: "SEPA valid - can use terminal" |
| Invalid | IBAN missing OR mandate_reference missing | Yellow: "SEPA invalid - cannot use terminal" |

To "revoke" SEPA access: clear the IBAN field. See [ADR-0006](../../adr/0006-sepa-mandate-reference-strategy.md).

## Postconditions
- Member record updated
- SEPA status recalculated based on new field values
- Audit log entry with old and new values

## Error Cases

### E1: Validation Failed
- Same as UC-A11

### E2: Concurrent Edit
- Another admin modified record
- Display "Record was modified, please reload"

## Test Derivation
- Edit name: save → name updated
- Edit IBAN: valid IBAN saved → SEPA status becomes valid
- Remove IBAN: clear IBAN field → SEPA status becomes invalid, member blocked from terminal
- Edit mandate reference: custom reference saved
- Clear mandate reference: SEPA status becomes invalid
- Validation errors: same as create
- Audit log: changes logged with before/after
- Concurrent edit: modify same record → conflict detected
- Cancel edit: discard changes → original values
- SEPA indicator updates: add IBAN → indicator changes to green

## Related

- [UC-A82: Members Needing SEPA Data](./UC-A82-sepa-invalid-report.md) - Report for members without SEPA
- [ADR-0006: SEPA Mandate Reference Strategy](../../adr/0006-sepa-mandate-reference-strategy.md)
- [ADR-0020: SEPA Mandate Requirement](../../adr/0020-sepa-mandate-requirement-terminal-access.md)
