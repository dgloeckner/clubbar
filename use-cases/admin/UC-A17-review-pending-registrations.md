# UC-A17: Review a Pending Registration

**Implementation Status**: Backend implemented ([#779](https://github.com/dgloeckner/clubbar/issues/779)) — list, detail, edit-before-approve, approve and reject are live behind `/api/admin/registrations`, with the attestation refused rather than defaulted, the sealed IBAN copied verbatim to the mandate, and audit rows for every act. The panel screen that drives them is [#782](https://github.com/dgloeckner/clubbar/issues/782), and the printed document is [#780](https://github.com/dgloeckner/clubbar/issues/780). **Fully implemented** as of [#784](https://github.com/dgloeckner/clubbar/issues/784), which also asserts what the review must never leak: every JSON response the panel receives is intercepted during a real review, and the full IBAN is in none of them. **Amended 2026-11-28** ([ADR-0052](../../adr/0052-member-self-registration-via-qr-code.md), *Amended 2026-11-28*): a registration can be accepted before its paper is signed, and the registrations on screen can be accepted in one go — see the two alternative flows below. Implemented, with `backend/tests/Feature/Modules/Registrations/Services/RegistrationApprovalTest.php` and `e2etests/tests/{api,admin}/accept-then-print.spec.ts`.

## Actors

- **Admin or Kassenwart** — reviews the inbox, corrects a typo, prints the
  club's document, and approves or rejects.
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

Since the amendment of 2026-11-28 the human step may be split in two. An admin
can accept a registration *first* — creating a member who cannot yet buy or be
collected from — and attest to the paper *afterwards*, by recording the
signature date on the member. That is what lets a club accept a whole inbox,
print every open mandate at once ([UC-A18](./UC-A18-print-open-mandates.md))
and collect signatures in one evening. The main flow below is the one-step desk
flow and is unchanged.

Everything an admin does here is either reversible (correcting a typo, moving
on to the next row) or immediate and final (approve, reject) — there is no
"save as draft" state beyond the row simply sitting in the inbox until it is
acted on. Nothing deletes it by age (ADR-0052 decision 10, amended
2026-10-20): the signed paper can take weeks to reach the club, and the row
waits for it.

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
4. The admin prints the club's whole document — all pages, not a sheet this
   software composes — for the applicant's signature (or opens the one the
   applicant already printed themselves during registration). Page 1 is
   rendered by filling the club's own combined Anmeldung, fetched from the
   URL configured in [UC-A69](./UC-A69-configure-self-registration.md) — or,
   when none is configured or it cannot be reached, the shipped DK-Muster
   default (ADR-0052 decisions 5 and 5a) — and every later page is appended
   exactly as the club published it, which is what puts the
   Datenschutzhinweise and the Nutzungsordnung in the applicant's hands at
   the moment they sign (decision 6a). Printed here, page 1 carries:
   - A **blank IBAN line**, left empty for the applicant to hand-write, with
     the `endet auf ****3000` hint printed from `iban_last4` next to it — the
     server never held the plaintext IBAN to fill the line with in the first
     place.
   - A **legal-representative signature line**, present only when the
     submitted birth date makes the applicant a minor.
   - The **account holder's name** in the signature block whenever one was
     supplied — never the applicant's, because the account holder is who has
     to sign (ADR-0052 decisions 5 and 7).
   - Ort/Datum, every signature and the **Kenntnisnahme checkbox** are never
     machine-filled — they are done by hand on the paper, always, and a valid
     template carries no fields for any of them.
5. The applicant (or their legal representative, or the account holder) signs
   the paper by hand, ticks the Kenntnisnahme box, and writes their IBAN into
   the blank line.
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
   [UC-A67](./UC-A67-member-lifecycle-mail.md), which greets a member once
   they have both a card and a signed mandate.

## Alternative Flow: accepting ahead of the signature

*Added 2026-11-28.* For an applicant whose paper is not signed yet.

1. The admin opens the row and reviews it as in steps 1–3.
2. Instead of entering a signature date and attesting, the admin chooses
   **"Ohne Unterschrift aufnehmen"**. The panel says, before the click, that
   the member will not be able to buy until the signature date is recorded.
3. The system creates the `members` row and the `mandates` row exactly as in
   step 7 — the sealed IBAN and the reference carried across unchanged — with
   one difference: the mandate has **no signature date**. The pending row is
   deleted.
4. The new member is **not SEPA-valid**. The terminal refuses their card
   (ADR-0020), the roster shows the SEPA gap, and no settlement collects from
   them.
5. The paper is printed — one at a time from the member, or with everybody
   else's via [UC-A18](./UC-A18-print-open-mandates.md) — and signed.
6. The admin records the signature date on the member
   ([UC-A12](./UC-A12-edit-member.md)). **That is where the paper is attested
   to**: entering the date states that the signed mandate is in hand and the
   number on it matches the `****last4` on file. From that save on the member
   is SEPA-valid.

The signature is optional **as a pair**. A date still requires the attestation
exactly as in the main flow; a date sent without it, or an attestation sent
without a date, is refused.

## Alternative Flow: accepting the visible inbox at once

*Added 2026-11-28.*

1. The admin opens the inbox, optionally narrows it with the search box, and
   chooses **"Alle aufnehmen"**.
2. A confirmation states: how many registrations will become members; that
   they are created without a signature and cannot buy, or be collected from,
   until the signature date is recorded; and that possible duplicates are
   left in the inbox. When more registrations are pending than are shown, it
   says that only the ones shown are accepted.
3. On confirmation the registrations **on screen** are accepted, each as in
   the flow above. The request names them one by one — there is no "accept
   everything pending".
4. A registration flagged as a possible duplicate (matching email or IBAN) is
   **skipped** and stays in the inbox for a person to judge. So is one whose
   email already belongs to a member, and one that somebody else approved or
   rejected in the meantime.
5. The page reports how many were accepted and how many were skipped, with the
   reason for each group, and the inbox now shows what is left.

| Situation in a batch | Outcome |
|---|---|
| Two siblings registered on one parent's account | Both accepted. The duplicate flags are evaluated once, before anything is accepted, so the second is not flagged against the first |
| Two submissions at the same email address | The first is accepted; the second is skipped — the club already has a member at that address |
| A registration matching an **existing** member by email or IBAN | Skipped, left in the inbox |
| A registration that cannot be written | Skipped and still pending; the others are unaffected |

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
4. The document's `****` hint updates from the new `iban_last4`. If it was
   already printed with the old hint, it must be reprinted before the
   applicant signs — the attestation in step 6 above is only meaningful
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
| Inbox row | `Lena Brandt` · submitted 3 days ago · `****3000` · Sparkasse · no duplicate flags |
| Detail view | Date of birth makes Lena 15 — the "minor" note appears; account holder name `Petra Brandt` is filled in |
| Printed document | Page 1: blank IBAN line with `****3000` printed beside it; a legal-representative signature line under the applicant's own; signature block names **Petra Brandt**, not Lena. Pages 2–4 (Datenschutzhinweise, Nutzungsordnung) carried over untouched |
| At the bar | Petra ticks the Kenntnisnahme box, signs as legal representative, and writes her IBAN by hand; it ends in `3000`, matching the hint |
| Approve | Kassenwart confirms the attestation: paper in hand, hand-written IBAN ends in `3000`. Lena becomes a member; the mandate is opened in Petra's name as account holder |

## Rules

| Rule | Why |
|------|-----|
| A pending registration is not a member, before or during review | ADR-0052's central invariant — nothing about *opening* a row for review may create `members` or `mandates` visibility |
| Roles: `[ADMIN, KASSENWART]` on the whole surface — list, edit, print, approve, reject | ADR-0044's grant table already reads member create/edit as `admin` + `kassenwart`; approval is a member create (ADR-0052 decision 8) |
| The Getränkewart sees none of this, on no page | Member data — and here, an IBAN's last four digits — is outside their remit on every surface (ADR-0045 invariant 5) |
| The IBAN is never displayed, only replaced | The server holds no key that can open `iban_ciphertext` (ADR-0036); showing it back is not a UI choice withheld, it is a thing that cannot be built |
| Correcting a typo does not touch `mandate_reference` | It was minted at submission specifically because it had to be printed on the paper before the mandate existed; approval carries it across unchanged (decision 4) |
| Duplicate flags never block a single approval; a batch leaves a flagged row in the inbox | They are visibility for a human decision, not a gate — a member of the family joining a second time, or a returning member re-registering, is a legitimate outcome the admin decides on, not a state the system refuses (decision 9). In a batch nobody is looking at any single row, so the decision is left for when somebody is (amended 2026-11-28) |
| The signature is optional as a pair: a date needs the attestation, and no date needs neither | An admin holding the paper attests exactly as before. An admin without it creates a member the mandate gate keeps away from the bar (ADR-0020) until the date is recorded (amended 2026-11-28) |
| A batch names its registrations explicitly and never records a signature | Nothing is accepted that the admin did not have on screen, and forty attestations cannot honestly be made with one click |
| The admin-print copy needs no plaintext IBAN | It is filled from the club's document (or the shipped default) with `iban` left empty and `iban_last4` printed as the hint — a blank line and a hint, never a filled-in number |
| The admin prints the club's whole document, page 1 filled, every later page unchanged | There is no clubbar-composed sheet — reviewing the template means checking the club's own document, and pages 2+ put the Datenschutzhinweise and Nutzungsordnung in the applicant's hands at the moment they sign (ADR-0052 decision 6a) |
| Ort/Datum, every signature and the Kenntnisnahme checkbox stay handwritten on the admin-print document | Never machine-filled, and not fields a valid template carries at all (ADR-0052 decision 5). The checkbox being ticked by hand on paper is not a contradiction of UC-P01's rule that the onboarding *page* carries no checkbox — the two are different surfaces |
| The legal-representative line appears only for a submitted birth date under the age of majority | The document must not silently ask a minor to sign alone |
| The signature block names the account holder, not the applicant, whenever one was supplied | The mandate must be signed by whoever owns the account (decision 7) |
| Rejection deletes the row immediately, not on a delay | Nothing here is a Beleg; a queue nobody empties is exactly how personal data about somebody who never joined would accumulate (decision 10) |
| Rejection's audit entry carries the IBAN masked, never in full | ADR-0005 — the same masking every IBAN change in this system is logged under |
| Approval creates no card and queues no welcome mail | Identical to UC-A11: a member with no card cannot start a Session, so there is nothing yet to welcome them to. The welcome follows a card *and* a signed mandate (UC-A67) |

## Postconditions

**On approve**
- A `members` row exists with the (possibly corrected) submitted data; tab
  balance 0; the member is active; no card assigned.
- A `mandates` row exists carrying the sealed IBAN and the mandate reference
  moved verbatim from the pending row.
- The pending row is deleted.
- One audit entry records the approval against the registration: the member
  it produced, the masked IBAN, and — on both paths — the signature date and
  whether the paper was attested.

**On accepting ahead of the signature** (singly or in a batch)
- As above, except that the mandate has no signature date, the member is not
  SEPA-valid, and the audit entry records a null date and no attestation.
- Recording the date later completes the same mandate; no new one is opened
  and the reference on the paper stands.

**On reject**
- The pending row is deleted.
- One audit entry records the rejection, its reason, and the masked IBAN.
- No `members` or `mandates` row was ever created — there is nothing left to
  undo.

**Unreviewed**
- The row stays in the inbox until it is approved or rejected, however long
  that takes. `bin/cron.php` never deletes it (decision 10, amended
  2026-10-20). An abandoned registration is removed by rejecting it.

## Error Cases

### E1: The row was already approved or rejected by another admin
Two admins opening the same row is possible in a small team. The second
action is refused with the row's current state; nothing is applied twice.

### E2: The replacement IBAN fails validation
Checksum or format invalid — refused exactly as UC-A11's E2, before anything
is sealed or stored.

### E3: Approve is submitted with a signature date and without the attestation
Refused. A date is a claim about paper, and there is no "approve anyway" for
it. (Since 2026-11-28 the way to accept without the paper is to send no date
at all — a different, explicit action.)

### E3a: An attestation is submitted with no signature date
Refused. There is nothing to attach it to, and silently dropping it would
record "accepted without a signature" for an admin who said they held one.

### E3b: A batch names no registration, or more than 200
Refused before anything is accepted. There is no "all".

### E4: Reject is submitted with no reason
Refused. A deleted row with no recorded reason is a decision nobody can later
account for.

## Test Derivation

- Inbox lists a submitted row with masked IBAN, bank name, submission date,
  and no duplicate flags when none exist
- A matching email against an existing member flags the row; a matching
  `iban_fingerprint` flags it independently
- Opening a row never returns `iban_ciphertext` or any decryptable IBAN
- Editing name, email, date of birth, account holder name and language
  persists on the pending row without touching `mandate_reference`
- Replacing the IBAN overwrites `iban_ciphertext`, `iban_last4` and
  `iban_fingerprint`, and the document's hint reflects the new last four
- The admin-print document renders the club's configured template — or the
  shipped DK-Muster default when none is configured or it is unreachable —
  with a blank IBAN line plus the `****` hint, and never a filled-in number
- The admin-print document's page count matches the configured template's;
  every page after page 1 is byte-identical to the source document
- Ort/Datum, every signature line and the Kenntnisnahme checkbox on the
  admin-print document are always blank, never machine-filled
- The admin-print document adds the legal-representative line only when the
  submitted birth date is under the age of majority
- The signature block names the account holder when one was supplied, the
  applicant otherwise
- Approve creates exactly one `members` row and one `mandates` row, carries
  the mandate reference and sealed IBAN unchanged, deletes the pending row,
  and queues no welcome mail
- Approve with a signature date and no attestation is refused; an attestation
  with no date is refused
- Approve with neither creates the member and the mandate with no signature
  date; the member is not SEPA-valid until the date is recorded, and the same
  mandate — same reference — is then complete
- The approval's audit entry carries the signature date and the attestation on
  both paths (`null` / `false` for an unsigned acceptance)
- A batch accepts every named registration unsigned and reports
  registration → member
- A batch leaves a registration matching an existing member (email or IBAN) in
  the inbox with `registration_possible_duplicate`
- Two registrations sharing an IBAN inside one batch are both accepted
- A batch skips a registration whose email a member already holds — including
  one accepted earlier in the same batch — and one that has vanished
- One registration that cannot be written does not undo the others
- A batch with no ids, a non-list, or a non-id is refused
- "Alle aufnehmen" names exactly the registrations on screen, states the count
  and the consequence before confirming, and reports accepted and skipped
- Reject deletes the row immediately and writes one audit entry naming the
  reason and the masked IBAN, never the plaintext
- Reject without a reason is refused
- A Getränkewart is refused on every verb here — the batch included — and
  shown no inbox
- A row submitted long ago survives a `bin/cron.php` run and is still in the
  inbox, with no expiry on the wire

## Related

- [ADR-0052](../../adr/0052-member-self-registration-via-qr-code.md) — the
  decision this specifies
- [UC-P01](../public/UC-P01-member-self-registration.md) — the applicant's
  side of the same flow
- [UC-A69](./UC-A69-configure-self-registration.md) — switching
  self-registration on and printing the poster this inbox fills from
- [UC-A11](./UC-A11-create-member.md) — the flow approval ends up equivalent
  to; this use case's Rules table names every point where it differs
- [UC-A18](./UC-A18-print-open-mandates.md) — printing the mandates of
  members accepted ahead of their signature
- [UC-A12](./UC-A12-edit-member.md) — where the signature date is recorded
  afterwards
- [ADR-0044](../../adr/0044-tiered-admin-roles.md) — the role grant this
  surface reuses rather than inventing
- [ADR-0036](../../adr/0036-iban-encryption-sealed-box.md) — why the IBAN
  cannot be displayed
- [ADR-0005](../../adr/0005-iban-storage-and-validation.md) — the audit
  masking rejection uses
