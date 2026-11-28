# UC-A18: Print the Open Mandates

**Implementation Status**: Implemented
([ADR-0052](../../adr/0052-member-self-registration-via-qr-code.md) and
[ADR-0036](../../adr/0036-iban-encryption-sealed-box.md), both amended
2026-11-28) — `POST /api/admin/members/mandate-documents`, and "Offene Mandate
drucken" on the members page. No schema change.

## Actors

- **Admin or Kassenwart** — prints, hands the sheets out, and later records the
  signature dates ([UC-A12](./UC-A12-edit-member.md)).
- **Member** — has been accepted but has not signed their SEPA mandate yet. Is
  on the roster, and cannot buy: the terminal refuses a card whose mandate has
  no signature date ([ADR-0020](../../adr/0020-sepa-mandate-requirement-terminal-access.md)).

## Motivation

[UC-A17](./UC-A17-review-pending-registrations.md) lets a club accept a
registration before its paper is signed. Accepting deletes the pending
registration, and until now the registration was the only thing a mandate could
be printed *from* — so the members who most need a sheet printed had none.

And printing one sheet per person does not fit the evening this exists for. A
club that onboards forty people at once wants forty sheets from one click, in a
stack it can hand out by calling names.

The same evening is where a blank IBAN line stops being a safeguard. One
applicant copying their own IBAN onto their own form is fine; forty people
copying 22 characters each, and one admin checking forty sets of four digits, is
the transcription problem self-registration was built to remove. So the print
can carry the number — when the club brings its key.

## Preconditions

- Caller is signed in and holds `admin` or `kassenwart`. This is member
  management (ADR-0044); the full-IBAN variant is no wider than the SEPA export,
  which holds the same grant.
- The club's document URL is configured
  ([UC-A69](./UC-A69-configure-self-registration.md)) and reachable.
- For the full-IBAN variant only: the club's private key is at hand, and the
  encryption key is operational (not expired).

## Main Flow — the blank print

1. On the members page the admin chooses **"Offene Mandate drucken"**.
2. A dialog explains what will be produced and offers one choice, **"IBAN im
   Klartext drucken"**, unticked.
3. The admin confirms.
4. The system collects every member who is **active**, **not anonymized**, and
   whose **active mandate has no signature date**, ordered by last name, then
   first name.
5. It fetches the club's configured document once and produces **one PDF**: for
   each member the whole document, page 1 filled in and every later page as the
   club published it. A document of *n* pages and *m* members is *n × m* pages,
   each member's pages together.
6. Page 1 carries, per member, the same fields a registration's print carries
   (UC-A17 step 4): name, birth date, email, the account holder where one is on
   file, the mandate reference, and the IBAN line **left blank** with
   `endet auf ****3000` beside it.
7. The browser downloads the file. Nothing is stored — not the template, not the
   output (ADR-0037).
8. One audit entry per member records that their form was printed, with the
   masked IBAN.

## Alternative Flow — the print with the full IBAN

1. In the dialog the admin ticks **"IBAN im Klartext drucken"**. The dialog says
   that the IBANs will be decrypted once and that the printed sheets must not be
   left lying around.
2. The dialog now asks for what the SEPA export asks for
   ([UC-A31](./UC-A31-download-sepa-xml.md)): the club's **private key** (file
   or paste), the admin's **password**, and their **2FA code** when enrolled.
3. The admin confirms. The key travels in the request body for this one request.
4. The system verifies the step-up credential, then checks the key against the
   registered active key by its public half.
5. The document is produced as in the main flow, except that each member's IBAN
   is opened **as their sheet is drawn** and printed on the IBAN line. No list
   of IBANs is assembled, nothing is logged, and the key is wiped before the
   response is written.
6. The response is not cacheable.
7. One audit entry **per member** records a full-IBAN view, with the masked
   value only.

| Situation | Outcome |
|---|---|
| A member's mandate is sealed under a key that is not the active one (a rotation is under way) | That member's sheet is printed **blank**, with the hint. Everybody else's carries the IBAN. The page says how many sheets were printed without one |
| A member's IBAN is longer than the boxes the club's form provides | The same: that sheet is printed blank |
| The password or the 2FA code is wrong | Refused. The dialog stays open with the key still in it |
| The key is not the club's | Refused. The dialog stays open; nothing was decrypted |

## Alternative Flow — printing for named members only

The request may name the members to print. A member named who is not waiting
for a signature is simply not in the document. The panel does not use this
today; it is how the tests keep a print to the members they created.

## Rules

| Rule | Why |
|------|-----|
| Only members with an **open, unsigned** mandate are printed | A member with no mandate has no reference to put on the sheet; one whose mandate is signed is done |
| Inactive and anonymized members are never printed | A deactivated member is not being onboarded, and an erased one has no name to print |
| The whole club document, once per member, pages in order | It is still the club's own Anmeldung, whole (ADR-0052 decision 6a) — pages 2 onward are what puts the Datenschutzhinweise and the Nutzungsordnung in the member's hands at the moment they sign |
| The sheet is printed on the club's **currently configured** document | A member row records no URL of the document they were shown; that lived on the registration and went with it |
| Blank is the default; the full IBAN is a choice per print | The blank print needs no decryption, no key and no step-up, and is the one that always works |
| The full-IBAN print is the SEPA export's path, step for step | Key in the JSON body, step-up, the active key verified by its public half, each IBAN opened where it is used, no caching, the same two named refusals, the same rate limit (ADR-0036 as amended) |
| One member never fails the batch | A mandate sealed under another key, or an IBAN that does not fit the form, costs that member's IBAN line and nothing else |
| Every sheet is audited against its member, with the masked IBAN only | A name, a birth date, an address and bank details leaving the building on paper. A sheet with the full IBAN is recorded as a full-IBAN view; a blank one as a print |
| Nobody waiting for a signature is a named refusal | An empty PDF opens as a viewer error, and "there is nothing to print" is all the admin needs to hear |
| Roles: `[ADMIN, KASSENWART]`; the Getränkewart is refused | Member data is outside the bar office's remit on every surface (ADR-0045 invariant 5) |
| Ort/Datum, the signatures and the Kenntnisnahme box stay handwritten | Unchanged from UC-A17 — a valid template has no fields for them |

## Postconditions

- The admin holds one PDF. The server holds nothing new except audit entries.
- No member changed. Each is still waiting for a signature until the date is
  recorded on them, which is what takes them out of the next print.
- After a full-IBAN print: paper carrying complete IBANs exists until it is
  signed and filed. That is the accepted cost of this variant (ADR-0036,
  *Amended 2026-11-28*).

## Error Cases

### E1: Nobody is waiting for a signature
Refused with a named reason (`no_open_mandates`), shown in the dialog.

### E2: The club's document cannot be fetched or used
Refused with the same named reasons UC-A17's print gives. Nothing is audited —
no document was produced.

### E3: The step-up credential is wrong
Refused (`invalid_credentials`). The attempt counts against the step-up rate
limit. The dialog keeps the key.

### E4: The key is not the club's active key
Refused (`private_key_mismatch`), by comparing public halves — never by trying
to decrypt with it.

### E5: The full-IBAN variant is chosen and no key is supplied
Refused. It is not answered with the blank print, which would look as though
the request had worked.

### E6: The encryption key has expired
The full-IBAN print is refused until the key is rotated, as the SEPA export is.
The blank print is unaffected.

## Test Derivation

- The print lists members whose active mandate is unsigned, and none who are
  signed, without a mandate, inactive or anonymized
- The PDF's page count is the template's page count times the number of members
- Each member's values are on their own sheet, and their pages are together and
  in order
- The template is fetched once and embedded once, however many members
- The blank print contains no IBAN and prints `endet auf ****last4`
- The blank print needs no step-up and never reads the sealed IBAN
- The full-IBAN print contains each member's IBAN, opened from the sealed value
  with the club's key
- A mandate sealed under a non-active key is printed blank while the others
  carry their IBAN; the response reports the count
- A wrong step-up credential answers 401 and decrypts nothing; a wrong key
  answers 422; an empty key is refused rather than printed blank
- The response is `Cache-Control: no-store`
- Each sheet writes one audit entry against the member — `iban_full_view` or
  `registration_printed` — carrying the masked IBAN only
- A render that fails writes no audit entry
- Nobody waiting answers `no_open_mandates`
- Recording the signature date takes a member out of the next print
- A Kassenwart may print both variants; a Getränkewart is refused on the route
- In the panel: the blank print downloads a PDF; choosing the full IBAN reveals
  the key input and the step-up; a rejected key keeps the dialog open with the
  key in it; the corrected key downloads a PDF carrying the IBAN

## Tests

| Level | File |
|---|---|
| Unit — many people in one document | `backend/tests/Unit/Modules/Registrations/Documents/MandateDocumentFillerTest.php`, `…/MandateDocumentServiceTest.php` |
| Unit — what is printed, refused and recorded | `backend/tests/Unit/Modules/Members/Services/OpenMandatePrintServiceTest.php` |
| Unit — the request boundary | `backend/tests/Unit/Modules/Members/Controllers/MandateDocumentsControllerTest.php` |
| Feature — who is in the document; a real sealed IBAN on the page | `backend/tests/Feature/Modules/Members/Services/OpenMandatePrintTest.php` |
| Component — the dialog | `admin-frontend/src/components/members/PrintOpenMandatesDialog.test.tsx` |
| E2E — API | `e2etests/tests/api/accept-then-print.spec.ts` |
| E2E — panel | `e2etests/tests/admin/accept-then-print.spec.ts` |

## Related

- [ADR-0052](../../adr/0052-member-self-registration-via-qr-code.md) — the
  amendment of 2026-11-28 this specifies
- [ADR-0036](../../adr/0036-iban-encryption-sealed-box.md) — why a full IBAN
  needs the club's key, and what printing one costs
- [ADR-0037](../../adr/0037-mandate-documents-not-retained.md) — why nothing is
  stored
- [UC-A17](./UC-A17-review-pending-registrations.md) — accepting a registration
  ahead of its signature, which is what leaves a mandate open
- [UC-A12](./UC-A12-edit-member.md) — recording the signature date
- [UC-A31](./UC-A31-download-sepa-xml.md) — the other place the club's private
  key is supplied
- [UC-A67](./UC-A67-member-lifecycle-mail.md) — the welcome, which follows the
  signature
