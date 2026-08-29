# UC-A11: Create Member

**Implementation Status**: Implemented

## Actor
Admin

## Preconditions
- Admin is logged in

## Trigger
Admin clicks "New Member" button

## Overview

Creates a new member account.

> ### ⚠️ Amended 2026-08-07 — creating a member does **not** create a mandate
>
> A member is created **without** a mandate and cannot use the bar until one exists ([ADR-0020](../../adr/0020-sepa-mandate-requirement-terminal-access.md), [#164](https://github.com/dgloeckner/clubbar/issues/164)). The mandate is a separate record created when the signed paper is recorded — see [#175](https://github.com/dgloeckner/clubbar/issues/175) for the onboarding form.
>
> Step 7 below ("System generates mandate reference") moves to mandate creation. The claim `is_sepa_valid = TRUE (IBAN is required)` is **wrong**: an IBAN alone never made a mandate, it only made the old predicate return true.
>
> This UC was already closer to right than the code — it required a **mandate date** at creation, which the implementation then checked nowhere.

## Main Flow
1. Admin clicks "New Member"
2. System displays member form
3. Admin enters member data:
   - First name (required)
   - Last name (required)
   - Email (required — #362)
   - Card UID (optional; usually entered later, see UC-A12)
   - IBAN (optional — #131)
   - Mandate date (required with an IBAN)
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
| Date of birth | Yes | Date, not in the future | Jugendschutz ([ADR-0045](../../adr/0045-age-restricted-products.md)); erasable — anonymization nulls it |
| Email | **Yes** | Valid email format | Required since [#362](https://github.com/dgloeckner/clubbar/issues/362); the column stays nullable only so erasure can clear it, and it cannot be cleared while the member is active |
| Card UID | No | 8–20 uppercase hex, unique | Usually entered afterwards ([ADR-0021](../../adr/0021-rfid-card-assignment-workflow.md)). **Assigning it is what welcomes the member** ([UC-A67](./UC-A67-member-lifecycle-mail.md)) |
| IBAN | No | Valid IBAN format + checksum | Optional since [#131](https://github.com/dgloeckner/clubbar/issues/131); without it the member cannot be collected from |
| Mandate date | With IBAN | Date, not in future | |
| Language | Yes | ISO 639-1 code from enabled list | Selects the language of every mail the member receives (de/en; fr falls back to German) |

**Email**: required because the Vorabankündigung and the settlement statement are a contractual promise (Nutzungsordnung § 7 Abs. 3) — a member cannot be onboarded without an address to announce to.

**No mail is sent by creating the record.** A member with no card cannot start a Session or run up a Deckel; the welcome waits for the card ([UC-A67](./UC-A67-member-lifecycle-mail.md)). Creating a member *with* a card in the same request welcomes them immediately.

## Postconditions
- Member created with UUID
- Mandate reference generated when an IBAN was supplied
- Tab balance = 0
- Member is active
- Audit log entry created
- A `member_welcome` is queued **only** if a card UID was supplied ([UC-A67](./UC-A67-member-lifecycle-mail.md))

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
