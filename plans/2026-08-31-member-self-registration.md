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
      test-derivable criteria; `pending_registrations`,
      `self_registration_config` and `instance_config.privacy_policy_urls`
      drafted into `docs/erm-master.md`; this plan. **Decided here**: clubbar
      pins no mandate template — `sepa_config.mandate_template_url` (the column
      #360 already added) is the one pointer, fetched and never copied, because
      the backup dumper walks the schema only and a pinned file would survive an
      upgrade but vanish on restore. And the club's
      Datenschutz URL lives in `instance_config` — ADR-0034's category, and the
      one config surface already readable without a session, which an anonymous
      phone at a poster needs — as a language-keyed map in ADR-0002's shape, and
      self-registration cannot be enabled without it.
      *Verified*: `DocumentationIndexTest` green — every new ADR and use case is
      reachable from its index.
      **Gate**: the ADR needs the owner's approval, and the schema its explicit
      confirmation, before M1 starts. Three open questions are listed in the ADR
      (TTL length, schema, per-language documents), and the on-hosting spike
      run #777 asks for.
- [ ] **M1 — Registrations module and the sealed pending store**
      ([#778](https://github.com/dgloeckner/clubbar/issues/778)).
      Migration `059` (+ rollback) for both tables. `POST /api/public/registrations`
      and the context endpoint: secret in the body, uniform refusal, disabled
      refusal carrying the club's reason, mod-97 + bcmath validation, bank-name
      resolution, mandate reference minted per ADR-0006, IBAN sealed into the
      `mandates` column shape, honeypot, body cap, two rate meters. TTL purge
      wired into `bin/cron.php` ahead of the drain.
      The enable gate has two conditions, not one: a poster secret and a
      configured Datenschutz URL, the second refused with a typed
      `datenschutz_url_missing`.
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
- [ ] **M3 — Mandate PDF: fill the club's template**
      ([#780](https://github.com/dgloeckner/clubbar/issues/780)).
      Port the spike ([#786](https://github.com/dgloeckner/clubbar/pull/786)):
      enumerate AcroForm field names and rectangles from the raw PDF with
      `/Rect` corner order normalized, import the page with FPDI so annotations
      are dropped and the output is flattened by construction, draw the values
      at the rects. `setasign/fpdf` + `setasign/fpdi` only; Latin-1
      transliteration for core fonts. Vocabulary: required `mandatsreferenz`,
      `vorname`, `nachname`, `iban`, `iban_last4`; optional `kontoinhaber`;
      **never** Ort/Datum or signatures. Member variant filled in-request during
      `POST /api/public/registrations` and returned in that response,
      `Cache-Control: no-store`; admin variant identical but `iban` empty and
      `iban_last4` printed as the `endet auf ****XXXX` hint. The template comes
      from `sepa_config.mandate_template_url` — fetched, never stored — with the
      shipped DK-Muster default (de/en) when it is unset or unreachable.
      *Verified by*: fixtures for both the DK-Muster default and the FRGS
      template; the member variant contains the full IBAN and the admin variant
      provably does not; Ort/Datum provably unfilled; zero `/Widget`
      annotations in either output; a `/Rect` regression test on a
      WeasyPrint-built fixture; optional fields filled when present and skipped
      when absent; no disk or database write on either render path.
- [ ] **M4 — Public onboarding page**
      ([#781](https://github.com/dgloeckner/clubbar/issues/781)).
      Small self-contained bundle under the backend document root at `/register`,
      not the admin SPA. Reads the secret from the fragment and never puts it in
      a request line. Mobile-first, a **prominent link to the club's own
      Datenschutz document before any data entry** — no legal text embedded in
      Club Bar, in any language, and **no checkbox attached to it**, since
      Art. 13 is a duty to inform rather than something to declare — with the
      URL shown recorded, the mandate PDF arriving in the submission response
      and not re-fetchable on reload, the disabled state
      rendering the club's reason, the one-time PDF download, and a confirmation
      screen that says plainly: you are not a member yet, bring the signed sheet.
- [ ] **M5 — Admin registrations inbox**
      ([#782](https://github.com/dgloeckner/clubbar/issues/782)).
      List page per `admin-frontend/patterns/table-implementation.md`
      (`useListQuery`, no hand-rolled paging state), review drawer, print,
      approve with the attestation, reject with a reason. The IBAN is never
      displayed — it can only be replaced, because the server cannot read it.
- [ ] **M6 — Availability, secret, URLs and poster**
      ([#783](https://github.com/dgloeckner/clubbar/issues/783)).
      Generate the first secret (until then the feature is off), reprint the
      poster without rotating, rotate — all `[ADMIN]`, and rotation invalidates
      every poster on the wall, which the UI has to say. Switch off with a
      member-facing reason. Enabling requires **both** the secret and the
      Datenschutz URL, and a missing one is named
      (`datenschutz_url_missing`) rather than greying the switch out. The two
      configured URLs — Datenschutz (`instance_config.privacy_policy_urls`) and
      the mandate template (`sepa_config.mandate_template_url`) — are validated
      **when saved**, but not alike: the Datenschutz URL is format-checked and
      stored, never fetched (it is displayed, and the member navigates to it
      themselves — ADR-0052 decision 6), while the template URL *is* fetched
      once and its required AcroForm fields enumerated, refused with the typed
      reason naming the missing field or telling the club to rebuild with
      `--uncompressed-pdf`.
      **No upload flow** — clubbar stores no template copy. Printable QR poster.
      *Verified by*: an unreachable or non-fillable template URL is refused with
      its typed reason and not saved; a valid one is accepted and audited; with
      none set the DK-Muster default renders.
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
| 11 | Self-registration cannot be enabled without a configured Datenschutz URL, and the refusal names it | M1 + M6 |
| 12 | No Datenschutz prose ships in this repository — the page links the club's document | M4 |
| 13 | Neither rendered sheet carries a live form field (zero `/Widget` annotations) | M3 |
| 14 | The member sheet contains the full IBAN; the admin sheet provably does not | M3 |
| 15 | Ort/Datum and the signatures are never machine-filled | M3 |
| 16 | Neither render path writes to disk or to the database | M3 + M7 |
| 17 | No mandate template is stored by Club Bar; an unreachable one falls back to the shipped default rather than failing a registration | M3 + M6 |

## External dependencies

The club has to publish two documents before this feature can be switched on
anywhere: the standalone Datenschutzhinweise, and the fillable mandate template
the sheet is rendered from. Both sources are delivered in
[frgs-website#33](https://github.com/dgloeckner/frgs-website/pull/33); what
remains is the owner publishing the built PDFs as Kirby files via SFTP, which is
what produces the stable URLs the configuration stores. Outside this repository
and outside `feat/user-onboarding`, so they block *enabling* rather than
*shipping* — and the enable gate is what keeps their absence from being silent.

One more owner action gates the ADR itself rather than the feature: **one run of
`spikes/pdf-form-fill/` ([#786](https://github.com/dgloeckner/clubbar/pull/786))
on the production hosting**. The mechanism is verified in the sandbox on PHP 8.4
matching IONOS; #777 asks for the on-host confirmation before ADR-0052 is
accepted.

## Open questions blocking M1

1. **TTL length.** 14 days is calibrated on "the treasurer looks weekly".
2. **Schema confirmation.** `pending_registrations`,
   `self_registration_config` and the new
   `instance_config.privacy_policy_urls` are drafted in `docs/erm-master.md`
   and, per project convention, need explicit confirmation before migration
   `059`. No column is added for the mandate template.
3. **Per-language documents.** The draft stores a language-keyed map and falls
   back to the default entry, telling the reader which language they are being
   shown. A club that publishes only German is therefore not blocked from
   onboarding an English-speaking member. Confirm that fallback, or require a
   document per offered language.

**Not open:** optional consents. The flow carries the SEPA mandate and nothing
else — #781's tick-boxes are conditional on a club using any, and this one uses
none.
