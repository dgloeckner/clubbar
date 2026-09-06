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
| Email | Cannot be cleared while the member is active ([#362](https://github.com/dgloeckner/clubbar/issues/362)). **Changing it writes to both the old and the new address** ([UC-A67](./UC-A67-member-lifecycle-mail.md)) — but only once the member has a card |
| Card UID | 8–20 uppercase hex, unique. **The first one welcomes the member; any later one tells them the old card has stopped working** ([UC-A67](./UC-A67-member-lifecycle-mail.md)) |
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

## Status Strip — can this member use the Clubbar?

The dialog opens with **one** status region, captioned *Nutzung der Clubbar*
([#830](https://github.com/dgloeckner/clubbar/issues/830)). It replaced four
separate indicators that each answered a question about *fields* — a
requirements panel, a SEPA banner, a tick per satisfied label and a pill per
conditional one — and between them answered none of the three the admin
actually has.

Three tiles, grouped by **outcome** rather than by field:

| Tile | Derived from | Green reads |
|------|--------------|-------------|
| Terminal | `card_uid`, `date_of_birth` | "Can buy, all products" |
| SEPA direct debit | IBAN, `mandate_signed_at`, `mandate_reference` | "Mandate valid since &lt;date&gt;" |
| Reachable | `email` | "Email delivery possible" |

Every tile carries a gap by **naming its consequence first** ("No access") and
the field that fixes it second, as a link that puts the caret in that field.
The gaps are the four the roster's Datenqualität panel counts, so the dialog
and the roster cannot disagree about what "incomplete" means.

The caption row's right-hand end carries the one thing that can stop the save:
the required fields still empty, or the stored values this submit would delete
([#131](https://github.com/dgloeckner/clubbar/issues/131)). After a refused
submit it turns red and is announced as an alert; before that it is a status
region, because a running count that interrupts on every keystroke is a count
nobody hears.

**Every tile previews the save; none reports the load.** A banner announcing
"SEPA mandate valid" above a field announcing that the mandate is about to be
revoked is the contradiction this avoids, and the rule is applied to all three
tiles rather than only to SEPA — a strip where one tile means "after saving"
and the two beside it mean "as loaded" would be worse than either rule applied
consistently.

| Tone | Meaning |
|------|---------|
| Green | The capability is on, and saving will not change that |
| Orange | Off (or reduced — a member with no birth date may buy, minus anything age-restricted per [ADR-0045](../../adr/0045-age-restricted-products.md)), and still so after saving |
| Blue | Off now, on once this form is saved |
| Red | On now, off once this form is saved |

Applied to the mandate specifically:

| Status | Condition | Tile |
|--------|-----------|------|
| Valid | Saved state: IBAN, reference and signature date all present | Green: "Mandate valid since &lt;date&gt;" |
| Invalid | Saved state: one of the three missing | Orange: "No collection", naming the IBAN and/or the mandate date |
| Will become valid | Unsaved: a valid IBAN typed for a member who had none | Blue: "Becomes valid once saved" |
| Will become invalid | Unsaved: removal of the bank details, or the date cleared | Red: "Becomes invalid once saved", with the link that puts it back |

## Field markers and layout

- A marker on a field means **this field is why a tile is not green**: an
  orange "Pflicht" pill and an orange border on a missing required field, the
  border alone on a missing non-required one (the card UID). A satisfied field
  carries nothing.
- Long explanations sit behind an **i** beside the label, opening on hover and
  on tap; the short form is the field's placeholder.
- The dialog is a **pinned header, a scrolling body and a pinned footer**, so
  *Speichern* is on screen at any screen height. On a phone it is 44px and
  full width, the export moves to the end of the form, and once the strip
  scrolls out of view the header carries its conclusion: three dots in the
  tiles' colours plus the field that still blocks the save.

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
- Any member lifecycle notice the change earns is **queued, not sent**
  ([UC-A67](./UC-A67-member-lifecycle-mail.md), [ADR-0038](../../adr/0038-transactional-mail-outbox-on-shared-hosting.md)
  rule 3). A queue failure never fails the edit

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
- SEPA tile updates: add IBAN → tile turns blue ("becomes valid once saved"), and green after the save
- Terminal tile: a member with no card UID reads "No access", its link focuses the card field, filling it turns the tile blue, and the roster's card gap is gone after the save
- Field markers: a missing required field carries the pill and the orange border; a satisfied one carries neither
- Fold: at 1440×900 the dialog opens with *Speichern* in the viewport, and it is still there once the body is scrolled to its last field
- Mobile: *Speichern* is ≥44px and in the viewport before and after scrolling; the header shows the compact summary once the strip is out of view

## Related

- [UC-A82: Members Needing SEPA Data](./UC-A82-sepa-invalid-report.md) - Report for members without SEPA
- [ADR-0006: SEPA Mandate Reference Strategy](../../adr/0006-sepa-mandate-reference-strategy.md)
- [ADR-0020: SEPA Mandate Requirement](../../adr/0020-sepa-mandate-requirement-terminal-access.md)
