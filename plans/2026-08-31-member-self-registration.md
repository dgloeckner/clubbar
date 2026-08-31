# Member Self-Registration via QR Code

**Epic**: [#776](https://github.com/dgloeckner/clubbar/issues/776) ·
**ADR**: [ADR-0052](../adr/0052-member-self-registration-via-qr-code.md) (Proposed) ·
**Use cases**: [UC-P01](../use-cases/public/UC-P01-member-self-registration.md),
[UC-A17](../use-cases/admin/UC-A17-review-pending-registrations.md),
[UC-A69](../use-cases/admin/UC-A69-configure-self-registration.md)

## Goal

A prospective member scans a QR code in the clubhouse, types their own details
on their own phone, and brings a signed SEPA mandate to the bar. The treasurer
checks the paper and approves — and that approval, not the submission, is what
creates the member and the mandate.

The IBAN stops being transcribed. Today it is typed twice: once by the applicant
on paper, once by a Kassenwart reading that handwriting into UC-A11. Its only
defence is a mod-97 checksum, which catches a transposition and accepts a digit
misread as another valid digit. Under this plan the person who owns the account
types it once, from the card in their hand.

**The invariant the whole design hangs on**: a pending registration is not a
member. It writes no `members` row and no mandate, so `GET /api/sync/members`
cannot return it and no terminal can recognise the person. Not by policy — there
is nothing to recognise. Membership comes into existence only through the
approve action, which is the admin's attestation that the signed mandate is in
hand (ADR-0020 and ADR-0021 still gate the terminal after that).

## Branch strategy

Per #776, the epic lands on the integration branch **`feat/user-onboarding`**,
cut from `main`; each milestone is a PR into that branch, and the epic closes
with one PR `feat/user-onboarding` → `main`. Tests green locally before every
milestone merge, and the **full** suite green on the integration branch before
the final PR.

## Milestones

- [~] **M0 — Specification** ([#777](https://github.com/dgloeckner/clubbar/issues/777)).
      ADR-0052 drafted **Proposed**; UC-P01, UC-A17, UC-A69 written with
      test-derivable criteria; `pending_registrations` and
      `self_registration_config` drafted into `docs/erm-master.md`; this plan.
      *Verified*: `DocumentationIndexTest` green — every new ADR and use case is
      reachable from its index.
      **Gate**: the ADR needs the owner's approval, and the schema its explicit
      confirmation, before M1 starts. Three open questions are listed in the ADR
      (optional consents, TTL length, schema).
- [ ] **M1 — Registrations module and the sealed pending store**
      ([#778](https://github.com/dgloeckner/clubbar/issues/778)).
      Migration `059` (+ rollback) for both tables. `POST /api/public/registrations`
      and the context endpoint: secret in the body, uniform refusal, disabled
      refusal carrying the club's reason, mod-97 + bcmath validation, bank-name
      resolution, mandate reference minted per ADR-0006, IBAN sealed into the
      `mandates` column shape, honeypot, body cap, two rate meters. TTL purge
      wired into `bin/cron.php` ahead of the drain.
      *Verified by*: unit tests for the gate, the validator and the purge;
      `e2etests/tests/api/self-registration.spec.ts` for the three availability
      answers, the silent duplicate, and a submission that stores ciphertext and
      no plaintext.
- [ ] **M2 — Admin review endpoints**
      ([#779](https://github.com/dgloeckner/clubbar/issues/779)).
      List (with duplicate flags by `email` and `iban_fingerprint`, and days to
      purge), edit-before-approve, approve, reject. `[ADMIN, KASSENWART]` in
      `RouteRoleMap`. Approve copies the ciphertext **verbatim** and carries the
      reference across; reject deletes at once. Audit rows for both, masked IBAN
      only.
      *Verified by*: `RouteRoleMapCompletenessTest`; a role test proving a
      Getränkewart gets 403 on every route here; an API test proving approval
      produces exactly what UC-A11 produces.
- [ ] **M3 — Mandate PDF, both variants**
      ([#780](https://github.com/dgloeckner/clubbar/issues/780)).
      One renderer, one flag. Member variant: fully pre-filled, rendered
      in-request from the plaintext the browser posts back with its download
      token, `Cache-Control: no-store`, nothing persisted. Admin variant: blank
      IBAN line with a `****last4` hint, the account holder in the signature
      block, a legal-representative line when the birth date says minor.
      *Verified by*: a test that the member variant refuses a wrong download
      token, an expired one, and an IBAN whose fingerprint does not match; a test
      that the admin variant contains the last four and never the full IBAN.
- [ ] **M4 — Public onboarding page**
      ([#781](https://github.com/dgloeckner/clubbar/issues/781)).
      Small self-contained bundle under the backend document root at `/register`,
      not the admin SPA. Reads the secret from the fragment and never puts it in
      a request line. Mobile-first, the Datenschutzhinweis displayed with an
      unticked acknowledgement box and its version recorded, the disabled state
      rendering the club's reason, the one-time PDF download, and a confirmation
      screen that says plainly: you are not a member yet, bring the signed sheet.
- [ ] **M5 — Admin registrations inbox**
      ([#782](https://github.com/dgloeckner/clubbar/issues/782)).
      List page per `admin-frontend/patterns/table-implementation.md`
      (`useListQuery`, no hand-rolled paging state), review drawer, print,
      approve with the attestation, reject with a reason. The IBAN is never
      displayed — it can only be replaced, because the server cannot read it.
- [ ] **M6 — Availability, secret and poster**
      ([#783](https://github.com/dgloeckner/clubbar/issues/783)).
      Generate the first secret (until then the feature is off), reprint the
      poster without rotating, rotate (`[ADMIN]` only — it invalidates every
      poster on the wall, and the UI has to say so), switch off with a
      member-facing reason. Printable QR poster.
- [ ] **M7 — E2E flow and privacy assertions**
      ([#784](https://github.com/dgloeckner/clubbar/issues/784)).
      Public form → pending row → admin approve → member exists → terminal sync
      sees them, and the same assertions one step earlier proving it did **not**.
      Plus: no plaintext IBAN anywhere in the database, no personal data in the
      public endpoint's logs, no mail queued by a submission, and a purge that
      removes an abandoned registration.

## Testable acceptance, epic-wide

These are the assertions that decide whether the epic is done, independent of
which milestone happens to implement them:

| # | Assertion | Where |
|---|---|---|
| 1 | A pending registrant never appears in `GET /api/sync/members` | M1 + M7 |
| 2 | No row in `pending_registrations` yields a readable IBAN without the private key | M1 |
| 3 | The ciphertext in `mandates` after approval is byte-identical to the pending row's | M2 |
| 4 | The UMR on the printed sheet is the UMR of the mandate that ends up stored | M2 + M3 |
| 5 | A request without the secret cannot tell that a valid secret exists | M1 |
| 6 | A submission queues no mail | M1 + M7 |
| 7 | Approval produces a member indistinguishable from one created via UC-A11 | M2 |
| 8 | A Getränkewart is refused on every route in this feature | M2 |
| 9 | An abandoned registration is gone after the TTL, and a rejected one at once | M1 + M2 |
| 10 | The audit trail names who approved, who rejected, and never a full IBAN | M2 |

## Open questions blocking M1

1. **Optional consents.** #776 decision 4 wants photo/newsletter consents stored
   with the registration; there is nowhere on `members` for them to go at
   approval. The ADR proposes recording only the Art. 13 acknowledgement in v1
   and building a `member_consents` store as its own issue. Needs a ruling.
2. **TTL length.** 14 days is calibrated on "the treasurer looks weekly".
3. **Schema confirmation.** `pending_registrations` and
   `self_registration_config` are drafted in `docs/erm-master.md` and, per
   project convention, need explicit confirmation before migration `059`.
