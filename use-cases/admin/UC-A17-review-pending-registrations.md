# UC-A17: Review a Pending Registration

**Implementation Status**: Not implemented — specified

## Actors

- **Admin or Kassenwart** — reviews the inbox, corrects a typo, prints the
  mandate sheet, and approves or rejects.
- **Applicant** — the person who scanned the poster and filled in their own
  data ([UC-P01](../public/UC-P01-member-self-registration.md)). Has no
  account and no session, and — this is the invariant the whole use case turns
  on — **is not a member** until the approve step below runs. A pending
  registration creates no `members` row and no mandate, so it cannot appear on
  `GET /api/sync/members` and no terminal can recognise the applicant — not by
  policy, but because there is nothing yet to recognise.

## Motivation

UC-A11 exists because somebody has to type a new member's data into the
system. ADR-0052 lets the applicant type it themselves, from their own phone,
reading their own card — but a QR code with no gate on the other end would
turn a scanned poster straight into a member, and the only thing that made
that transcription trustworthy in the old flow was a human reading a signed
paper before typing. This use case is that human step, moved to where it
belongs: **after** the data exists, **before** it counts.

Everything an admin does here is either reversible (correcting a typo, moving
on to the next row) or immediate and final (approve, reject) — there is no
"save as draft" state beyond the row simply sitting in the inbox until it is
acted on or its TTL purges it (ADR-0052 decision 10).

## Preconditions

- Caller is signed in and holds `admin` or `kassenwart`. This is member
  management — ADR-0044's grant table already reads *members — read, create,
  edit* as `admin` + `kassenwart`, and approving a registration **is** a
  member create (ADR-0052 decision 8, row 1: list, edit, approve, reject and
  print are all one grant).
- At least one row exists in `pending_registrations` — i.e. self-registration
  has been switched on and somebody has scanned the poster
  ([UC-A69](./UC-A69-configure-self-registration.md)).

## Main Flow

1. The admin opens the registrations inbox. Each row shows:

   | Column | Content |
   |---|---|
   | Name | As submitted |
   | Submitted | The date the applicant filled in the form |
   | IBAN | Masked as `****3000` — `iban_last4` only. The full number is never shown, because the server holds no key that can decrypt it (ADR-0036, no exception for this store — ADR-0052 decision 3) |
   | Bank | Resolved from the BLZ at submission |
   | Duplicate flags | A matching `email` or a matching `iban_fingerprint` against existing members **and** against other pending rows (ADR-0052 decision 9) |
   | Purge in | Days remaining before the row's TTL deletes it unseen (submission date plus the configured window, default 14 days — decision 10) |

2. The admin opens one row. The detail view repeats everything from the list
   and adds the account holder name (when the applicant supplied one) and
   whether the submitted birth date makes the applicant a minor.
3. The admin reads the data against the signed paper in hand and corrects a
   typo. **First name, last name, date of birth, email, account holder name
   and preferred language** are ordinary editable fields — the same set
   UC-A11 collects, minus the two things that do not work the same way here:
   the IBAN, which is replace-only (below), and `mandate_reference`, which is
   never editable at all (it was minted at submission, decision 4, and rides
   unchanged to approval).
4. The admin prints the mandate sheet for the applicant's signature (or opens
   the one the applicant already printed themselves during registration).
   The sheet is rendered by filling the club's own mandate template, fetched
   from the URL configured in [UC-A69](./UC-A69-configure-self-registration.md)
   — or, when none is configured or it cannot be reached, the shipped
   DK-Muster default (ADR-0052 decisions 5 and 5a). Printed here, it carries:
   - A **blank IBAN line**, left empty for the applicant to hand-write, with
     the `endet auf ****3000` hint printed from `iban_last4` next to it — the
     server never held the plaintext IBAN to fill the line with in the first
     place.
   - A **legal-representative signature line**, present only when the
     submitted birth date makes the applicant a minor.
   - The **account holder's name** in the signature block whenever one was
     supplied — never the applicant's, because the account holder is who has
     to sign (ADR-0052 decisions 5 and 7).
   - Ort/Datum and every signature are never machine-filled — they are
     handwritten on the paper, always, and a valid template carries no fields
     for them.
5. The applicant (or their legal representative, or the account holder) signs
   the paper by hand, writing their IBAN into the blank line.
6. The admin approves, affirming the one attestation this step exists to
   record: **the signed paper is in hand, and the hand-written IBAN ends in
   the four digits the screen shows.** There is nothing else to check —
   everything else on the row was already typed by the applicant and
   corrected in step 3.
7. The system creates the `members` row, creates the `mandates` row carrying
   the sealed IBAN and the mandate reference across **unchanged** from the
   pending row (decision 4 — the reference was minted at submission because it
   had to be printed on the paper before it existed anywhere else), and
   deletes the pending row. The new member has no card, so — exactly as in
   UC-A11 — **no welcome mail is queued**; that waits for
   [UC-A67](./UC-A67-member-lifecycle-mail.md) once a card is assigned.

## Alternative Flow: replacing the IBAN instead of correcting it

The IBAN cannot be edited the way a name can, because there is nothing on
screen to edit — the server never held the plaintext and cannot show it back.

1. The admin notices, reading the paper, that the IBAN on the applicant's
   form does not match what was actually written down (a phone typo, a
   digit transposed before the checksum caught it, or simply a different
   account the applicant decided to use).
2. The admin enters the correct IBAN once, from the paper, into a dedicated
   "replace IBAN" action — not the ordinary edit form.
3. The system validates the checksum, resolves the bank name, seals the new
   number and **overwrites** `iban_ciphertext`, `iban_last4` and
   `iban_fingerprint` on the pending row in the same request. The old sealed
   value is gone; nothing keeps a history of a mistyped IBAN.
4. The mandate sheet's `****` hint updates from the new `iban_last4`. If the
   sheet was already printed with the old hint, it must be reprinted before
   the applicant signs — the attestation in step 6 above is only meaningful
   against the current hint.

## Alternative Flow: rejecting a registration

1. The admin decides the registration should not become a member — the
   applicant never returns with a signed paper, the paper the applicant
   returned does not match what was submitted, or the row is a duplicate the
   club does not want to onboard twice.
2. The admin rejects, entering a reason.
3. The pending row is **deleted immediately** — not marked rejected, not kept
   for a review trail of its own. This store holds nobody's accounting
   record (ADR-0052 decision 10): no money moved and no contract was
   performed, so nothing here earns the ten-year retention ADR-0029 gives an
   actual member.
4. One audit entry records the act, the admin, the reason, and the IBAN
   **masked** (ADR-0005) — never the number, and never anything the deleted
   row alone would have been the last copy of.

## Worked example: a minor's registration, guardian's account

| Step | What the reviewing Kassenwart sees |
|---|---|
| Inbox row | `Lena Brandt` · submitted 3 days ago · `****3000` · Sparkasse · no duplicate flags · purges in 11 days |
| Detail view | Date of birth makes Lena 15 — the "minor" note appears; account holder name `Petra Brandt` is filled in |
| Printed sheet | Blank IBAN line with `****3000` printed beside it; a legal-representative signature line under the applicant's own; signature block names **Petra Brandt**, not Lena |
| At the bar | Petra signs as legal representative and writes her IBAN by hand; it ends in `3000`, matching the hint |
| Approve | Kassenwart confirms the attestation: paper in hand, hand-written IBAN ends in `3000`. Lena becomes a member; the mandate is opened in Petra's name as account holder |

## Rules

| Rule | Why |
|------|-----|
| A pending registration is not a member, before or during review | ADR-0052's central invariant — nothing about *opening* a row for review may create `members` or `mandates` visibility |
| Roles: `[ADMIN, KASSENWART]` on the whole surface — list, edit, print, approve, reject | ADR-0044's grant table already reads member create/edit as `admin` + `kassenwart`; approval is a member create (ADR-0052 decision 8) |
| The Getränkewart sees none of this, on no page | Member data — and here, an IBAN's last four digits — is outside their remit on every surface (ADR-0045 invariant 5) |
| The IBAN is never displayed, only replaced | The server holds no key that can open `iban_ciphertext` (ADR-0036); showing it back is not a UI choice withheld, it is a thing that cannot be built |
| Correcting a typo does not touch `mandate_reference` | It was minted at submission specifically because it had to be printed on the paper before the mandate existed; approval carries it across unchanged (decision 4) |
| Duplicate flags never block approval | They are visibility for a human decision, not a gate — a member of the family joining a second time, or a returning member re-registering, is a legitimate outcome the admin decides on, not a state the system refuses (decision 9) |
| The admin-print sheet needs no plaintext IBAN | It is filled from the club's template (or the shipped default) with `iban` left empty and `iban_last4` printed as the hint — a blank line and a hint, never a filled-in number |
| Ort/Datum and every signature stay handwritten on the admin-print sheet | Never machine-filled, and not fields a valid template carries at all (ADR-0052 decision 5) |
| The legal-representative line appears only for a submitted birth date under the age of majority | The sheet must not silently ask a minor to sign alone |
| The signature block names the account holder, not the applicant, whenever one was supplied | The mandate must be signed by whoever owns the account (decision 7) |
| Rejection deletes the row immediately, not on a delay | Nothing here is a Beleg; a queue nobody empties is exactly how personal data about somebody who never joined would accumulate (decision 10) |
| Rejection's audit entry carries the IBAN masked, never in full | ADR-0005 — the same masking every IBAN change in this system is logged under |
| Approval creates no card and queues no welcome mail | Identical to UC-A11: a member with no card cannot start a Session, so there is nothing yet to welcome them to |

## Postconditions

**On approve**
- A `members` row exists with the (possibly corrected) submitted data; tab
  balance 0; the member is active; no card assigned.
- A `mandates` row exists carrying the sealed IBAN and the mandate reference
  moved verbatim from the pending row.
- The pending row is deleted.
- Audit entries record the member create and the mandate create.

**On reject**
- The pending row is deleted.
- One audit entry records the rejection, its reason, and the masked IBAN.
- No `members` or `mandates` row was ever created — there is nothing left to
  undo.

**Unreviewed**
- The row stays in the inbox, visible with its purge countdown, until it is
  approved, rejected, or purged by `bin/cron.php` at its TTL (decision 10),
  which logs a count and no identity.

## Error Cases

### E1: The row was already approved or rejected by another admin
Two admins opening the same row is possible in a small team. The second
action is refused with the row's current state; nothing is applied twice.

### E2: The replacement IBAN fails validation
Checksum or format invalid — refused exactly as UC-A11's E2, before anything
is sealed or stored.

### E3: Approve is submitted without the attestation confirmed
Refused. There is no "approve anyway" — the attestation is the entire content
of this step, not a formality alongside it.

### E4: The row purges while it is open in the reviewer's tab
The TTL is enforced by the cron tick independent of anyone's open browser
tab. A subsequent action on that row is refused as not found; the applicant
must scan the poster again if they still want to join.

### E5: Reject is submitted with no reason
Refused. A deleted row with no recorded reason is a decision nobody can later
account for.

## Test Derivation

- Inbox lists a submitted row with masked IBAN, bank name, purge countdown,
  and no duplicate flags when none exist
- A matching email against an existing member flags the row; a matching
  `iban_fingerprint` flags it independently
- Opening a row never returns `iban_ciphertext` or any decryptable IBAN
- Editing name, email, date of birth, account holder name and language
  persists on the pending row without touching `mandate_reference`
- Replacing the IBAN overwrites `iban_ciphertext`, `iban_last4` and
  `iban_fingerprint`, and the sheet's hint reflects the new last four
- The admin-print sheet renders the club's configured template — or the
  shipped DK-Muster default when none is configured or it is unreachable —
  with a blank IBAN line plus the `****` hint, and never a filled-in number
- Ort/Datum and every signature line on the admin-print sheet are always
  blank, never machine-filled
- The admin-print sheet adds the legal-representative line only when the
  submitted birth date is under the age of majority
- The signature block names the account holder when one was supplied, the
  applicant otherwise
- Approve creates exactly one `members` row and one `mandates` row, carries
  the mandate reference and sealed IBAN unchanged, deletes the pending row,
  and queues no welcome mail
- Approve without the attestation confirmed is refused
- Reject deletes the row immediately and writes one audit entry naming the
  reason and the masked IBAN, never the plaintext
- Reject without a reason is refused
- A Getränkewart is refused on every verb here and shown no inbox
- A row past its TTL is gone from the inbox and any action on it is refused
  as not found
- The TTL purge (`bin/cron.php`) logs a count, never an identity

## Related

- [ADR-0052](../../adr/0052-member-self-registration-via-qr-code.md) — the
  decision this specifies
- [UC-P01](../public/UC-P01-member-self-registration.md) — the applicant's
  side of the same flow
- [UC-A69](./UC-A69-configure-self-registration.md) — switching
  self-registration on and printing the poster this inbox fills from
- [UC-A11](./UC-A11-create-member.md) — the flow approval ends up equivalent
  to; this use case's Rules table names every point where it differs
- [ADR-0044](../../adr/0044-tiered-admin-roles.md) — the role grant this
  surface reuses rather than inventing
- [ADR-0036](../../adr/0036-iban-encryption-sealed-box.md) — why the IBAN
  cannot be displayed
- [ADR-0005](../../adr/0005-iban-storage-and-validation.md) — the audit
  masking rejection uses
