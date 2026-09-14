# ADR-0006: SEPA Mandate Reference Strategy

**Status**: Accepted (amended 2026-08-07 and 2026-09-14 — see the notes below; corrected 2026-08-23: the XML sample names pain.008.001.08, the format exported since [ADR-0008](./0008-sepa-xml-export-format-selection.md)'s 2026-08-04 amendment)

**Date**: 2025-01-23

---

## Context

SEPA Direct Debit transfers require a mandate reference for each debtor (member). The mandate reference, together with the creditor ID (Gläubiger-ID), uniquely identifies the mandate within the SEPA system.

Key considerations:

- **Uniqueness**: Mandate reference must be unique per member (per creditor)
- **Maximum length**: 35 characters (SEPA standard)
- **Default generation**: Automatically generate from member UUID
- **Editability**: Allow admins to override reference if existing mandate has different reference
- **External mandate management**: The system does NOT track mandate lifecycle (expiry, revocation, signature dates) - mandates are managed outside the system

The system stores a single editable mandate_reference field per member. All mandate-related data (signatures, dates, revocations, expiry tracking) is managed outside the system on paper.

---

## Decision

**Mandate reference is stored as an editable field in the members table, initialized with member UUID with hyphens removed (32 characters) at member creation time. Admins can override this reference if an existing mandate has a different reference. The system does not track mandate metadata (signature dates, revocation status, lifecycle states) - all mandate management is performed outside the system.**

> ### ⚠️ Amended 2026-08-07 — a mandate is now a record
>
> **What changed:** the mandate reference moves out of `members` into a dedicated `mandates` record, and the **signature date becomes tracked and required**. See [#164](https://github.com/dgloeckner/clubbar/issues/164).
>
> ```
> mandate:  member · reference · iban · signed_at · document (optional) · active
> ```
>
> A member has **at most one active mandate**. Rows are **append-only**: a bank change or revocation *ends* the current mandate and creates a new one; nothing is mutated in place.
>
> **Why the original position could not hold.** pain.008 **requires** a mandate signature date. With nothing tracked, the exporter had to invent one — `SepaExportService.php:110` fell back to the settlement date, telling the bank a member signed on a day they signed nothing. "Manage mandates outside the system" was never fully achievable while also generating collection files from inside it.
>
> Two facts unknown when this ADR was written (2025-01-23) settle it:
> - A collection made **without a valid mandate is reclaimable for 13 months**, not the usual 8 weeks ([ADR-0028](./0028-legal-constraints-on-money-handling.md) §3).
> - A returned collection is matched by `MREF+`. With the reference mutable on `members`, a member changing bank destroys the only key that resolves a return arriving for the previous collection ([#165](https://github.com/dgloeckner/clubbar/issues/165)). An append-only mandate record fixes this by construction.
>
> **What survives.** The auto-generated reference (Core Principles 1, 2, 6) is unchanged — it is simply generated at *mandate* creation rather than member creation, and it stays admin-editable. The instinct behind Principles 4, 5 and 7 also survives: there is still **no status enum, no expiry logic, no amendment history, no revocation reasons, no sequence-type tracking**. Only the three fields that *constitute* a mandate are tracked, plus an active flag.
>
> **What is overturned.** Principle 3's placement (`members` table), and the claim that signature dates are tracked outside the system. See the revised Alternative 3 below.

> ### ⚠️ Amended 2026-09-14 — the reference is minted from a counter
>
> **What changed:** a newly opened mandate's reference is
> `<PREFIX>-<zero-padded number>` — `CB-000042` — drawn from a per-install
> counter, instead of a UUID with its hyphens removed. See
> [#936](https://github.com/dgloeckner/clubbar/issues/936).
>
> **Why the UUID could not stay.** The reference is the one identifier a member
> *reads*: it is the **Mandatsreferenz** on their own Kontoauszug, the string
> they read aloud to the Kassenwart when a collection is queried, and it is
> printed on the paper a self-registering member signs ([ADR-0052](./0052-member-self-registration-via-qr-code.md)
> decision 4). Thirty-two hex characters are unreadable in all three places, and
> nothing in SEPA ever asked for them: the UMR must be unique **per creditor**,
> at most 35 characters, in the SEPA character set. Principle 2's "UUID ensures
> uniqueness by design" answered a coordination problem this system does not
> have — one install is one Gläubiger-ID, every reference is minted on the
> backend, the terminal never mints one, and self-registration is a synchronous
> call.
>
> **Why a counter row and not a sequence.** `CREATE SEQUENCE` exists only on
> MariaDB >= 10.3, while `docs/deployment.md` promises MySQL 5.7 / MariaDB 10.5
> and [ADR-0038](./0038-shared-hosting-deployment-constraints.md) leaves the
> database version to the host. A sequence is also non-transactional, whereas a
> counter row rolls back with the transaction that drew from it — so the paper
> printed from a pending registration and the stored row can never name
> different numbers. The draw is the portable idiom, run inside the minting
> transaction:
>
> ```
> UPDATE mandate_reference_counter SET value = LAST_INSERT_ID(value + 1) WHERE id = 1;
> SELECT LAST_INSERT_ID();
> ```
>
> Gaps are harmless — a rejected or purged registration takes its number with
> it, and nothing reads a reference as a position.
>
> **What survives.** Principles 3, 4, 5, 6 and 7 are untouched. The reference is
> still minted at mandate creation, still admin-editable, still just a field,
> still SEPA-compliant. **Existing references are never re-minted**: they are on
> signed paper and in every collection already sent to the bank, and a return is
> matched by `MREF+` months later ([#165](https://github.com/dgloeckner/clubbar/issues/165)).
> A mixed population of 32-hex and short references is correct, expected, and
> fine for the bank.
>
> **What is overturned.** Principle 1's *"UUID minus hyphens"* and Principle 2's
> reasoning, for newly opened mandates only.
>
> **The prefix.** `sepa_config.mandate_reference_prefix`, default `CB`,
> validated against the SEPA character set and short enough that prefix +
> separator + number stays inside 35 characters for every number the counter can
> reach. It exists mainly so that references an admin types in for mandates
> carried over from a previous system cannot collide with the club's own
> sequence.

### Core Principles

1. **Direct initialization**: ~~Initialize with UUID minus hyphens on member
   creation~~ — since 2026-09-14, minted at mandate creation as
   `<PREFIX>-<number>` from a per-install counter (see the amendment above)
2. **Automatic uniqueness**: ~~UUID ensures uniqueness by design~~ — the counter
   ensures it, which is all SEPA asks: the UMR is unique per creditor, and one
   install is one Gläubiger-ID
3. **Admin editable**: Can override if needed for existing mandates
4. **Simple field**: Just store the reference; no lifecycle tracking
5. **Lightweight**: No mandate date, active flag, or expiry logic in system
6. **SEPA compliant**: Reference format max 35 chars; allowed chars: `0-9 a-z A-Z + ? / - : ( ) . , '`
7. **External lifecycle**: Mandate signature dates, revocation status, expiry tracked outside system

### Data Structures

#### Members Table - Mandate Reference Field

| Column | Type | Description |
|--------|------|-------------|
| mandate_reference | VARCHAR(35) | SEPA mandate identifier; default = `<PREFIX>-<number>` from the per-install counter (`CB-000042`); editable; used in SEPA XML exports |
| id | BINARY(16) | Member UUID (example: `550e8400-e29b-41d4-a716-446655440000`) |

**Field Details:**
- **Default value**: the club's prefix and the next number from
  `mandate_reference_counter`, zero-padded to six digits
  - Example: `CB-000042`
  - Before 2026-09-14 this was the member UUID with hyphens removed
    (`550e8400e29b41d4a716446655440000`). Those are **never re-minted**, so both
    shapes exist on an upgraded install
- **Editable**: Admin can change if member has existing mandate with different reference
- **Allowed characters**: `0-9 a-z A-Z + ? / - : ( ) . , '` (SEPA standard)
- **Nullable**: Optional; set to NULL if member not enrolled in SEPA
- **Index**: Required for SEPA export lookups

#### SEPA XML Output

Mandate reference appears in SEPA Direct Debit XML (pain.008.001.08) as:

```xml
<MndtRltdInf>
  <MndtId>CB-000042</MndtId>
</MndtRltdInf>
```

A mandate opened before 2026-09-14 still exports the reference it was signed
under — `<MndtId>550e8400e29b41d4a716446655440000</MndtId>` — unchanged. The
exporter never normalizes, pads or truncates a stored reference: it is the key a
return quotes back as `MREF+`.

#### Pre-Settlement Validation Requirements

When creating a settlement, system validates all included members have:
- IBAN present and valid (checksum verification per ISO 13616)
- Mandate reference present (not NULL)

Members missing either field are excluded from settlement with reason logged.

---

## Consequences

### Positive

✅ **Simple**: Single editable field; no helper functions or lifecycle tracking
✅ **Direct initialization**: mandate_reference set directly on member creation
✅ **Flexible**: Admin can override reference for existing external mandates
✅ **Lightweight**: No mandate date, active flag, or expiry logic in system
✅ **SEPA compliant**: Reference format valid for SEPA XML export
✅ **Readable**: a member can read `CB-000042` off their bank statement and back
   to the Kassenwart over the phone
✅ **Audit trail**: Changes to mandate_reference logged via standard audit system
✅ **Low maintenance**: Mandate management handled outside system (on paper)
✅ **Separation of concerns**: System focus is transactions/settlements, not mandate admin

### Negative

❌ **Manual mandate management**: Admins must manage actual mandates outside the system
❌ **No lifecycle validation**: System cannot check if mandate is expired or revoked
❌ **No sequence type tracking**: Cannot determine FRST vs RCUR automatically
❌ **Admin responsibility**: Must ensure real-world mandates match system records

### Mitigations

1. **Admin documentation**: Clear guide on mandate management procedures
2. **Pre-settlement checklist**: Remind admins to verify mandates offline before settlement
3. **Audit trail**: Log all mandate_reference changes for compliance
4. **Settlement warnings**: Identify members missing IBAN or mandate reference before finalization

---

## Alternatives Considered

### Alternative 1: Mandate Management in System

Track full mandate lifecycle: signature date, revocation status, expiry, first-use timestamp.

**Pros**: Complete data model; system can enforce rules
**Cons**:
- Complex: requires mandate date validation, revocation logic, expiry checks
- Burden: admins must manually enter all mandate metadata
- Inflexible: cannot handle edge cases (mandate restored, customer disputes, etc.)
- Over-engineered: for small organizations with few members, external management simpler

**Rejected**: Mandates are legal/compliance matter best handled outside system on paper.

### Alternative 2: Helper Function with Caching

Create `getDefaultMandateReference()` helper function that calculates UUID-derived reference on demand.

**Pros**: Centralized logic; easy to change generation algorithm
**Cons**:
- Unnecessary indirection (just a string replacement)
- Extra function call on every member creation
- Still requires manual invocation in code

**Rejected**: Direct initialization simpler; no need for helper function.

### Alternative 3: Store Mandate in Separate Table

Create dedicated sepa_mandates table (1:1 with members).

**Pros**: Separates mandate data from member data
**Cons**:
- Additional table and JOINs
- No benefit over member table (mandate is 1:1 with member)
- Complicates queries and migration

~~**Rejected**: Mandate reference better stored directly in members table.~~

> **✅ Adopted 2026-08-07.** The rejection rested on "mandate is 1:1 with member" — and that premise is false over time. A member who changes bank or revokes and re-signs has **several** mandates across their membership, of which one is active. The relationship is 1:many, and modelling it as 1:1 is what makes a returned collection unmatchable after a bank change.
>
> The stated cost — an extra table and a join — is real and accepted. What it buys is larger: partial states become structurally impossible (the row exists or it does not), and `is_sepa_valid` collapses from a multi-field expression written four inconsistent ways across the codebase into a single lookup.
>
> The "complicates migration" objection does not apply: the system is pre-launch, with no mandates to migrate.

---

## Related Decisions

- [ADR-0004: Immutable Transaction Storage](./0004-immutable-transaction-storage.md) - Settlement workflow
- [ADR-0005: IBAN Storage and Validation](./0005-iban-storage-and-validation.md) - IBAN validation (for SEPA collections)
- [ADR-0008: SEPA XML Export Format Selection](./0008-sepa-xml-export-format-selection.md) - Mandate reference used in SEPA XML

---

## References

- **SEPA Standards**:
  - [EPC SEPA Rulebook](https://www.europeanpaymentscouncil.eu/document-library) - Official SEPA Direct Debit rules
  - [ISO 20022 XML Standard](https://www.iso20022.org/) - XML message format (pain.008)

- **Mandate Reference Specification**:
  - Max 35 characters per SEPA standard
  - Allowed characters: `0-9 a-z A-Z + ? / - : ( ) . , '`
  - Unique per mandate per creditor (per Gläubiger-ID)
