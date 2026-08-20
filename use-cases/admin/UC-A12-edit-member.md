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
| Date of birth | Not in the future. May be corrected but not cleared — a blank one means an anonymized member ([ADR-0045](../../adr/0045-age-restricted-products.md)) |
| Email | |
| IBAN | Overwrite-only — see *Banking fields* below. Validation on change |
| Mandate date | Required if IBAN set |
| Mandate reference | SEPA identifier; assigned by the server unless one is supplied |
| Preferred language | |

## Banking Fields

The IBAN and the mandate reference are not ordinary text inputs, because
neither is a value the admin routinely retypes.

**IBAN** — a stored IBAN is sealed under the club's public key and the server
cannot read it back ([ADR-0036](../../adr/0036-iban-encryption-sealed-box.md));
the API returns only its last four characters. The field therefore has three
states:

| State | When | Shown |
|-------|------|-------|
| Stored | Editing a member who has bank details | The masked account (`****3000 · Commerzbank`) in place of the input, with *Change* and *Remove bank details* |
| Entry | Creating a member, a member with no bank details, or after *Change* | The input, empty. After *Change* it also offers *Cancel*, which restores the stored account |
| Removing | After *Remove bank details* | A pending-revocation notice with *Undo* |

An empty IBAN field never clears the stored account — it is the normal state of
a save that was about something else. Removal is its own action.

**Mandate reference** — minted by the server when the mandate is opened, as a
UUID without hyphens
([ADR-0006](../../adr/0006-sepa-mandate-reference-strategy.md)). Supplying one
exists for mandates that already carry a reference on paper or in a previous
system.

| State | When | Shown |
|-------|------|-------|
| Auto | No mandate yet | "Assigned on save", with *Enter an existing reference* |
| Assigned | The mandate has a reference | The reference itself, with a copy button (it is what a bank quotes back on a returned collection) and *Change* |
| Entry | After either action | The input, with *Cancel* |

## Read-Only Fields

| Field | Reason |
|-------|--------|
| UUID | Immutable identifier |
| Created date | Historical |
| Tab balance | Changed via transactions only |

## SEPA Status Display

The form shows SEPA status (derived, not editable directly) as a preview of
what *this submit* would leave behind — not as the state that was loaded. A
banner announcing "SEPA mandate valid" above a field announcing that the
mandate is about to be revoked is the contradiction this avoids.

| Status | Condition | Display |
|--------|-----------|---------|
| Valid | Saved state: IBAN present AND mandate_reference present | Green: "SEPA mandate valid" |
| Invalid | Saved state: IBAN missing OR mandate_reference missing | Red: "SEPA mandate missing", with what is needed |
| Will become valid | Unsaved: a valid IBAN typed for a member who had none | Info: "SEPA mandate becomes valid on save", marked as not yet saved |
| Will become invalid | Unsaved: removal of the bank details is pending | Warning: "SEPA mandate becomes invalid on save", marked as not yet saved |

To revoke SEPA access: use *Remove bank details*. The IBAN field cannot be
cleared — the stored value is sealed and never returned, so a blank field means
"keep" ([ADR-0036](../../adr/0036-iban-encryption-sealed-box.md)). Changing the
IBAN instead ends the current mandate and opens a new one with a new reference;
the old mandate row is retained so returned collections stay matchable. See
[ADR-0006](../../adr/0006-sepa-mandate-reference-strategy.md).

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
- Edit IBAN: *Change* → valid IBAN → save → new account stored, SEPA still valid
- Abandon an IBAN change: *Change* → type → *Cancel* → save → original account unchanged
- Save without touching the IBAN: stored account unchanged (a blank field means "keep")
- Remove IBAN: *Remove bank details* → save → SEPA status becomes invalid, member blocked from terminal
- Undo a removal: *Remove bank details* → *Undo* → save → account unchanged
- Mandate reference on create: left automatic → server assigns a UUID without hyphens
- Edit mandate reference: *Change* → custom reference saved
- Validation errors: same as create
- Audit log: changes logged with before/after
- Concurrent edit: modify same record → conflict detected
- Cancel edit: discard changes → original values
- SEPA indicator updates: add IBAN → indicator changes to green

## Related

- [UC-A82: Members Needing SEPA Data](./UC-A82-sepa-invalid-report.md) - Report for members without SEPA
- [ADR-0006: SEPA Mandate Reference Strategy](../../adr/0006-sepa-mandate-reference-strategy.md)
- [ADR-0020: SEPA Mandate Requirement](../../adr/0020-sepa-mandate-requirement-terminal-access.md)
