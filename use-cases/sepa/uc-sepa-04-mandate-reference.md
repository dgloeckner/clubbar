# UC-SEPA-04: Member Mandate Reference Management

**Implementation Status**: Implemented

**Category**: Member Data

## Summary

Admin manages a mandate's SEPA reference. Auto-generated, and still editable where a paper mandate already carries a different reference.

> ### ⚠️ Amended 2026-08-07
>
> The reference is generated **when the mandate is created**, not when the member is created ([#164](https://github.com/dgloeckner/clubbar/issues/164)). A member exists without a mandate — and until one exists they cannot use the bar at all.
>
> Crucially, **the reference is no longer what makes a member SEPA-valid.** Previously it was auto-generated the moment an IBAN was entered, so both fields went non-NULL together and "has a valid mandate" collapsed to *"somebody typed an IBAN"*. Validity now requires an **active mandate with a signature date** — a real-world event, not data entry.
>
> Auto-generation survives precisely because it is no longer the gate.

## Actors

- **Admin**: Manages mandate references

## Preconditions

1. Admin is logged in
2. Member exists and is not anonymized

## Mandate Reference Rules

| Rule | Value |
|------|-------|
| Max length | 35 characters |
| Allowed characters | 0-9 a-z A-Z + ? / - : ( ) . , ' space |
| Default value | Member UUID without hyphens (32 chars) |
| Uniqueness | Should be unique per organization |

## Default Generation

When member is created:
- UUID: `550e8400-e29b-41d4-a716-446655440000`
- Mandate Reference: `550e8400e29b41d4a716446655440000`

## Main Flow

1. Admin navigates to member detail page
2. Admin clicks Edit
3. Admin locates mandate reference field
4. Admin modifies mandate reference (or accepts default)
5. System validates format
6. Admin clicks Save
7. System updates member record
8. System creates audit log entry
9. System displays confirmation

## Audit Log Entry

| Field | Value |
|-------|-------|
| action | `update` |
| entity_type | `member` |
| entity_id | Member UUID |
| old_values | `{ "mandate_reference": "550e8400e29b41d4a716446655440000" }` |
| new_values | `{ "mandate_reference": "MANDATE-2024-0042" }` |

## Alternative Flows

### AF1: Invalid characters in reference
- Step 5: System shows "Invalid characters in mandate reference"
- Only SEPA-allowed characters accepted

### AF2: Reference too long
- Step 5: System shows "Mandate reference exceeds 35 characters"
- Admin shortens reference

### AF3: Existing mandate has different reference
- Admin enters the reference from the signed mandate document
- System accepts the externally-defined reference

### AF4: Reset to default
- Admin clears field or clicks "Reset to default"
- System regenerates from UUID

## Postconditions

- Mandate reference updated
- Next SEPA export uses new reference
- Audit log records change

## Out of Scope

The following are NOT managed by the system:
- Original mandate document storage
- Mandate signature date
- Mandate revocation status
- Mandate expiry tracking
- First-use vs recurring determination

These are the organization's responsibility to track externally.

## Test Scenarios

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| T01 | Default reference on new member | UUID without hyphens |
| T02 | Update with valid custom reference | Reference updated |
| T03 | Update with invalid characters | Validation error |
| T04 | Update with > 35 characters | Validation error |
| T05 | Update with special SEPA chars (+?/-:) | Reference saved |
| T06 | Clear reference | Validation error (required field) |
| T07 | Reset to default | UUID-based reference restored |
| T08 | Update without authentication | Access denied (401) |
| T09 | Audit log entry | Old and new reference recorded |
| T10 | Reference with spaces | Spaces allowed (SEPA compliant) |
