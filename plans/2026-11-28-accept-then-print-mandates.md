# Accept First, Print in Bulk, Sign Later

**Status**: Implemented — M1–M6 complete, each verified (counts under *Verification* below).
**Design**: ADR-0052 (amended 2026-11-28), ADR-0036 (amended 2026-11-28), ADR-0051 (amended 2026-11-28)
**Use cases**: UC-A17, UC-A18 (new), UC-A67
**Branch**: `claude/zealous-goldberg-5i64bw`

---

## What this buys

The club has a stack of pending self-registrations and wants to onboard many
members in one evening. The ADR-0052 flow does not fit that: approval demands
the signed paper *first*, printing is one registration at a time, and the admin
print leaves the IBAN line blank.

**After this plan** the order is the other way round:

1. **Accept** pending registrations — one at a time or the visible inbox in one
   go — creating members whose mandate has no signature date.
2. **Print** every open mandate as one PDF, optionally with the full IBAN.
3. **Record the signature date** on the member once the paper is signed. That is
   what unlocks the card (ADR-0020) and collection.

And the welcome mail goes out when a member has **both** a card and a signed
mandate, so cards can be assigned before the paper exists.

**Not contained here**:

- any schema change. The data model already carries an unsigned mandate
  (`mandates.signed_at` is nullable and `MandateCompleteness` reads it); no
  table, column or ENUM value is added;
- a "has been welcomed" marker on the member. The bank-change guard reads the
  mandate history instead (decision 6);
- re-printing a mandate that is already signed, and printing for members with
  no mandate at all — the print is for *open* mandates.

---

## Decisions taken

| # | Decision | Consequence |
|---|---|---|
| 1 | **The signature is optional as a pair.** A date still requires the attestation exactly as before; no date creates the member unsigned and asks for none. An attestation without a date is a validation error | The one-step desk flow is unchanged. `REGISTRATION_APPROVED` records which happened (`mandate_signed_at: null`, `signed_mandate_confirmed: false`) |
| 2 | **Batch acceptance takes explicit ids** (cap 200), one transaction per registration | Nothing is accepted that the admin did not see; one failure does not undo the rest |
| 3 | **A possible duplicate is skipped, never accepted in bulk.** Flags are evaluated once, against the members that existed before the batch | Two siblings on a parent's IBAN in one batch both pass; a registration matching an existing member stays in the inbox for a person to look at |
| 4 | **The keyed print mirrors the SEPA export** — private key in the JSON body, step-up, `withActivePrivateKey()`, each IBAN opened where it is drawn, `no-store` | One more place the key is supplied (ADR-0036 amended), no new mechanism |
| 5 | **A sheet that cannot carry its IBAN is printed blank, not refused.** A mandate sealed under a non-active key, or an IBAN longer than the template's comb | One member does not fail the batch; the response says how many sheets were left blank |
| 6 | **No audit ENUM value is added.** The keyed print writes `IBAN_FULL_VIEW` on the member; the blank print writes `REGISTRATION_PRINTED` on the member with a `variant` | No migration |
| 7 | **"Usable" replaces "has a card"** as the welcome trigger: a real card UID and a complete mandate | A member is greeted when they can actually buy. A bank change does not greet twice: the mandate-signed path is silent for a member who already had an earlier signed mandate |
| 8 | **The address-change notices follow the same gate** — sent to a member who was usable before the save | "The welcome is the first message a member ever receives" stays true |

---

## Milestones

`[ ]` not started · `[~]` in progress · `[x]` passed (test verified) · `[!]` failed.

### M1 — Accept without a signature, singly and in bulk (backend) [x]

- [x] `RegistrationReviewService::approve()` takes an optional date; the pair rule holds
- [x] `RegistrationReviewService::approveBatch()` — accepted map, skipped with reason codes
      (`registration_possible_duplicate`, `registration_member_email_exists`,
      `registration_gone`, `registration_not_accepted`)
- [x] `POST /api/admin/registrations/approve-batch`, `TREASURY`, above `{registrationId}`
- [x] Unit + Feature tests — Unit suite 3419/3419, `RegistrationApprovalTest` 14/14

### M2 — The welcome follows usability (backend) [x]

- [x] `MembersService` decides on card **and** mandate; bank-change guard
      (`MembersRepository::hasEarlierSignedMandate()`), welcome-only path
      (`NotificationsService::notifyMemberWelcome()`)
- [x] `MemberLifecycleMailTest` rewritten around the new trigger — 11 new cases
- [x] `member_welcome.*` reviewed against the trigger (de, en): the lede now
      says the signed mandate arrived; nothing else needed changing
- [x] Verified: Feature suite 903/903, Unit `Notifications|Members` 719/719

### M3 — Open mandates as one PDF (backend) [x]

- [x] `MandateDocumentFiller::fillMany()` (template embedded once, values consumed one
      person at a time); `MandateDocumentService::forOpenMandates()`;
      `MandateDocumentVariant::ADMIN_PRINT_FULL`
- [x] `POST /api/admin/members/mandate-documents`, blank and keyed, `TREASURY`, step-up
      rate limit on the route (`MandateDocumentsController`, `OpenMandatePrintService`)
- [x] `NO_OPEN_MANDATES` refusal; audit per member without a migration
      (`iban_full_view` / `registration_printed` on entity type `member`)
- [x] Verified: Unit suite 3462/3462, `OpenMandatePrintTest` (Feature, real sealed box) 8/8

### M4 — Contract and admin UI [x]

- [x] `api/admin.yaml`: `approveRegistration` (pair semantics), `approveRegistrationsBatch`,
      `printOpenMandates`; the three audit actions the panel renders added to the
      `AuditLogEntry.action` enum; orval client regenerated
- [x] "Alle aufnehmen" (`AcceptAllDialog`, `AcceptAllResult`) + "Ohne Unterschrift
      aufnehmen" in `RegistrationReviewPanel`
- [x] "Offene Mandate drucken" on the members page (`PrintOpenMandatesDialog`), keyed
      variant behind `StepUpConfirmDialog` + `PrivateKeyInput`, download via `downloadFile()`
- [x] `auditLogSummary.ts` renders `registration_approved` (signed / unsigned),
      `registration_printed` (registration / open mandate) and `iban_full_view`
- [x] Both locale files; vitest 813/813, `tsc --noEmit` clean, eslint 0 errors

### M5 — End to end [x]

- [x] Batch acceptance yields members that are SEPA-invalid until a date is set
      (`tests/api/accept-then-print.spec.ts`, `tests/admin/accept-then-print.spec.ts`)
- [x] Batch PDF has template pages × members pages; keyed carries the IBAN, blank does not
- [x] A Getränkewart is refused on every new route (the API spec, and two new rows in
      `role-access-matrix.spec.ts`)
- [x] `mail-member` chain: nothing for a card before the signature, one welcome on the
      save that completes the pair
- [x] Verified: `api-tests` 879 passed / 3 skipped, `admin-chromium` 413/413,
      `admin-mobile` 70/70, `mail-member` 6/6 — 0 unexpected, 0 flaky

### M6 — The record [x]

- [x] ADR-0052, ADR-0036, ADR-0051 amended (dated 2026-11-28); `adr/README.md`
- [x] UC-A17, UC-A67 updated; UC-A18 added and indexed (both READMEs); UC-A11 and
      UC-A12 corrected where they described the old trigger
- [x] `docs/notifications-and-mail.md`, `docs/security-concept.md`, `docs/erm-master.md`
      (the meaning of a NULL `mandates.signed_at`; no schema change)

---

## Verification

Run locally on the dev stack (`scripts/dev-setup.sh --with-frontend`), 2026-11-28.

| Suite | Result |
|---|---|
| Backend Unit (`php8.3 vendor/bin/phpunit --testsuite Unit`) | 3472 passed, 6 skipped (pre-existing) |
| Backend Feature (in the container) | 911 passed, 1 skipped (pre-existing) |
| Admin frontend: `tsc --noEmit`, eslint, `test:coverage`, `test:timezones` | clean; vitest 813/813 |
| E2E `api-tests` (4 workers) | 879 passed, 3 skipped (pre-existing) |
| E2E `admin-chromium` (4 workers) | 413 passed |
| E2E `admin-mobile` (4 workers) | 70 passed |
| E2E chain lane (`E2E_LANE=chain`, all 13 serial projects) | 48 passed — `mail-member` 6/6, `mail-lifecycle` 3/3 |
| E2E lint, typecheck, `check:ci-lanes` | clean |

0 unexpected, 0 flaky across the Playwright runs.

**One thing a local run can trip over, and CI cannot.** The `api-rotation` chain
project moves the installation onto the second dev keypair and leaves it there.
A Feature run started on that database before `EncryptionKeysHttpTest` has put
`dev-key-2026` back fails the tests that open a freshly sealed IBAN with the
published dev key — `RegistrationApprovalTest::test_the_copied_ciphertext_still_opens_to_the_submitted_iban`
(pre-existing) and, before they were given a named skip for exactly this state,
the two keyed tests in `OpenMandatePrintTest`. Reproduced by swapping the two
keys' status by hand; CI gives every job its own database.

**A flake found on the way, and fixed.** Every document test read drawn text
through its own `~stream\r?\n(.*?)\r?\nendstream~s`. The `\r?` before the
delimiter swallows the last byte of a compressed stream whenever that byte is a
carriage return — about one stream in 256 — after which the stream does not
inflate and its text is silently missing. Measured: 10 of 3000 fills of the
club fixture. Met: one local run failed `self-registration.spec.ts`'s "the
admin print leaves the IBAN blank and prints the hint" on exactly that. The new
print tests would have multiplied the exposure (one drawn stream per member),
so the pattern now lives once per language — `backend/tests/Support/PdfText.php`
(with `PdfTextTest` as its regression test) and `e2etests/utils/pdf.ts` — and
the five PHP tests and the one spec that carried a copy read through those.

---

## What was decided along the way

Recorded here because each is the owner's to confirm (they are in the PR too):

1. A batch never records a signature.
2. "Alle aufnehmen" accepts the registrations **on screen**, not the whole inbox.
3. A sheet whose IBAN cannot be opened (mandate under a non-active key, or an
   IBAN longer than the template's comb) is printed blank instead of failing the
   print; the response and the page report the count.
4. The welcome's opening sentence now says the signed mandate arrived (de, en).
5. Address-change notices are silent for a member with a card but no signed
   mandate — the gate moved with the trigger.
6. A member is printed on the club's currently configured document; the URL an
   applicant was shown lived on the registration and is gone with it.
7. `UC-A17` and ADR-0052 decision 7 describe a legal-representative line and an
   account-holder signature block. No code composes either — the fill draws
   `kontoinhaber` where the template has the field. The member-sourced print is
   equivalent to that; the ADR amendment says so and nothing was changed.
