# Accept First, Print in Bulk, Sign Later

**Status**: In progress — see the milestones below.
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

### M2 — The welcome follows usability (backend) [ ]

- [ ] `MembersService` decides on card **and** mandate; bank-change guard
- [ ] `MemberLifecycleMailTest` rewritten around the new trigger
- [ ] `member_welcome.*` reviewed against the trigger (de, en)

### M3 — Open mandates as one PDF (backend) [ ]

- [ ] `MandateDocumentFiller::fillMany()`; `MandateDocumentService::forOpenMandates()`
- [ ] `POST /api/admin/members/mandate-documents`, blank and keyed, `TREASURY`
- [ ] `NO_OPEN_MANDATES` refusal; audit per member without a migration
- [ ] Unit + Feature tests

### M4 — Contract and admin UI [ ]

- [ ] `api/admin.yaml`, orval client regenerated
- [ ] "Alle aufnehmen" + "Ohne Unterschrift aufnehmen" on the registrations inbox
- [ ] "Offene Mandate drucken" on the members page, keyed variant behind step-up
- [ ] Both locale files; vitest

### M5 — End to end [ ]

- [ ] Batch acceptance yields members that are SEPA-invalid until a date is set
- [ ] Batch PDF has template pages × members pages; keyed carries the IBAN, blank does not
- [ ] A Getränkewart is refused on every new route
- [ ] `mail-member` chain: the welcome arrives on the save that completes the pair

### M6 — The record [ ]

- [ ] ADR-0052, ADR-0036, ADR-0051 amended (dated 2026-11-28)
- [ ] UC-A17, UC-A67 updated; UC-A18 added and indexed; UC-A11/A12/A13 where false
- [ ] `docs/` references that describe the old trigger or the old approval

---

## Verification

Filled in as milestones pass.
