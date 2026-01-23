# UC-A11: Create Member

## Actor
Admin

## Preconditions
- Admin is logged in

## Trigger
Admin clicks "New Member" button

## Overview

Creates a new member account. For the member to use the terminal, they must have valid SEPA data (IBAN and mandate reference). Members without SEPA data will be blocked at terminal login.

## Main Flow
1. Admin clicks "New Member"
2. System displays member form
3. Admin enters member data:
   - First name (required)
   - Last name (required)
   - Email (optional)
   - IBAN (required)
   - Mandate date (required)
   - Preferred language (default: organization default)
4. Admin submits form
5. System validates input
6. System generates UUID
7. System generates mandate reference (UUID without hyphens)
8. System creates member record
9. System displays success message
10. System shows member detail view

## Form Fields

| Field | Required | Validation | Note |
|-------|----------|------------|------|
| First name | Yes | Non-empty, max 100 chars | |
| Last name | Yes | Non-empty, max 100 chars | |
| Email | No | Valid email format | |
| IBAN | Yes | Valid IBAN format + checksum | Required for terminal access |
| Mandate date | Yes | Date, not in future | Required with IBAN |
| Language | Yes | ISO 639-1 code from enabled list | |

**SEPA Requirement**: IBAN and mandate date are required. Members cannot use the terminal without valid SEPA data. This is part of the standard onboarding process.

## Postconditions
- Member created with UUID
- Mandate reference generated
- Tab balance = 0
- Member is active
- is_sepa_valid = TRUE (IBAN is required)
- Audit log entry created

## Error Cases

### E1: Validation Failed
- Display field-specific error messages
- Form not submitted

### E2: Invalid IBAN
- IBAN format or checksum invalid
- Display "Invalid IBAN"

### E3: Mandate Date in Future
- Display "Mandate date cannot be in the future"

## Test Derivation
- Happy path: create with all required fields → member created, SEPA valid
- Required field empty: validation error shown (name, IBAN, mandate date)
- Invalid email format: validation error
- Invalid IBAN: checksum error
- Missing IBAN: validation error (required field)
- Missing mandate date: validation error (required field)
- Mandate date in future: validation error
- UUID generated: verify format
- Mandate reference: verify = UUID without hyphens
- Initial balance: verify = 0
- Audit log: creation logged

## Related

- [UC-A82: Members Needing SEPA Data](./UC-A82-sepa-invalid-report.md) - Report for members without SEPA
- [ADR-0020: SEPA Mandate Requirement](../../adr/0020-sepa-mandate-requirement-terminal-access.md)
