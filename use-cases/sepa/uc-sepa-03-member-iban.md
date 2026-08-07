# UC-SEPA-03: Member IBAN

> ### ⚠️ Superseded in structure 2026-08-07
>
> IBAN is **no longer a member field**. It belongs to the **mandate record** ([ADR-0006](../../adr/0006-sepa-mandate-reference-strategy.md) amended, [#164](https://github.com/dgloeckner/ruderbar/issues/164)) — one record carrying reference, IBAN and signature date, at most one active per member.
>
> So *"edit a member's IBAN"* is not an operation. **Changing bank means ending the current mandate and creating a new one**, which is also what SEPA expects. The old mandate row is retained — that is what keeps a returned collection matchable via `MREF+` after the change ([#165](https://github.com/dgloeckner/ruderbar/issues/165)).
>
> The IBAN **validation** rules below (ISO 13616 format, mod-97 checksum, real-time feedback) are unchanged and apply when a mandate is created. Management

**Implementation Status**: Implemented

**Category**: Member Data

## Summary

Admin adds or updates a member's IBAN for SEPA direct debit collections.

## Actors

- **Admin**: Manages member bank details

## Preconditions

1. Admin is logged in
2. Member exists and is not anonymized

## Trigger

- New member registration with bank details
- Member provides updated bank details
- Correction of incorrectly entered IBAN

## IBAN Validation Rules

| Check | Rule |
|-------|------|
| Length | 15-34 characters |
| Format | 2 letters (country) + 2 digits (checksum) + BBAN |
| Checksum | ISO 13616 mod-97 algorithm |
| Characters | Alphanumeric only (after removing spaces) |

## Mod-97 Validation Algorithm

1. Remove spaces, convert to uppercase
2. Move first 4 characters to end
3. Convert letters to numbers (A=10, B=11, ..., Z=35)
4. Calculate mod 97 of resulting number
5. Valid if remainder = 1

## Main Flow

1. Admin navigates to member detail page
2. Admin clicks Edit
3. Admin enters or modifies IBAN
4. System validates IBAN in real-time
5. System shows validation status (valid/invalid)
6. Admin clicks Save
7. System performs server-side validation
8. System updates member record
9. System creates audit log entry
10. System displays confirmation

## Audit Log Entry

| Field | Value |
|-------|-------|
| action | `update` |
| entity_type | `member` |
| entity_id | Member UUID |
| old_values | `{ "iban": "DE89****0000" }` |
| new_values | `{ "iban": "DE12****5678" }` |

Note: IBAN masked (first 4 + last 4 characters).

## Alternative Flows

### AF1: Invalid IBAN checksum
- Step 4: System shows "Invalid IBAN: Checksum incorrect"
- Save blocked until corrected

### AF2: Invalid IBAN format
- Step 4: System shows "Invalid IBAN format"
- Save blocked until corrected

### AF3: IBAN removed (cleared)
- Step 3: Admin clears IBAN field
- Member cannot be included in SEPA settlements

### AF4: Member is anonymized
- Step 2: Edit not available
- IBAN already cleared via anonymization

## Postconditions

- Member IBAN updated
- Member eligible for SEPA settlement (if mandate reference also present)
- Audit log records change with masked values

## Terminal Sync Impact

IBAN is NOT synced to terminal (backend-only data).

## Test Scenarios

| ID | Scenario | Expected Result |
|----|----------|-----------------|
| T01 | Add valid German IBAN | IBAN saved |
| T02 | Add valid French IBAN | IBAN saved |
| T03 | Add IBAN with invalid checksum | Validation error |
| T04 | Add IBAN with wrong length | Validation error |
| T05 | Add IBAN with special characters | Validation error |
| T06 | IBAN with spaces | Spaces removed, validation passes |
| T07 | IBAN lowercase | Converted to uppercase |
| T08 | Clear existing IBAN | IBAN set to NULL |
| T09 | Update without authentication | Access denied (401) |
| T10 | Audit log IBAN masking | Only first 4 + last 4 visible |
| T11 | Real-time validation feedback | Shows valid/invalid while typing |
| T12 | Server-side validation | Validates even if client bypassed |
