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
      `self_registration_config` drafted into `docs/erm-master.md`; this plan. **Decided here**: the club's
      document stays **one** — the combined four-page Anmeldung, fields on page 1,
      every page preserved by the fill. Clubbar pins no template:
      `sepa_config.mandate_template_url` (the column #360 already added) is the
      one pointer, fetched and never copied, because the backup dumper walks the
      schema only and a pinned file would survive an upgrade but vanish on
      restore. That same URL is what the page links for Art. 13, so the club's
      Datenschutz URL lives in `instance_config` — ADR-0034's category, and the
      Datenschutzhinweise need no second setting: they are pages 2+ of that very
      file. One URL, not translated, and no club-language setting to translate
      against; self-registration cannot be enabled without it
      (`document_url_missing`). An unapproved registration is purged after
      **30 days**. **The schema delta is two new tables and no new column.**
      *Verified*: `DocumentationIndexTest` green — every new ADR and use case is
      reachable from its index.
      **Gate**: the ADR needs the owner's approval, and the schema its explicit
      confirmation, before M1 starts. Three open questions are listed in the ADR
      (TTL length, schema, per-language documents), and the on-hosting spike
      run #777 asks for.
- [x] **M1 — Registrations module and the sealed pending store**
      ([#778](https://github.com/dgloeckner/clubbar/issues/778)).
      Migration `059` (+ rollback) for both tables. `POST /api/public/registrations`
      and the context endpoint: secret in the body, uniform refusal, disabled
      refusal carrying the club's reason, mod-97 + bcmath validation, bank-name
      resolution, mandate reference minted per ADR-0006, IBAN sealed into the
      `mandates` column shape, honeypot, body cap, two rate meters. TTL purge
      wired into `bin/cron.php` ahead of the drain.
      The enable gate has two conditions, not one: a poster secret and the
      configured club document URL, the second refused with a typed
      `document_url_missing`.
      *Verified*: PR [#787](https://github.com/dgloeckner/clubbar/pull/787).
      Unit tests for the gate, the validator, the meters and the purge;
      `e2etests/tests/api/self-registration.spec.ts` green for the three
      availability answers, the silent duplicate, and a submission that stores
      ciphertext and no plaintext.
      *Two things the tests found that reading did not.* The accepted-submission
      meter was calibrated for a login form and would have refused the sixth
      person at a signup evening — everyone scanning a clubhouse poster is
      behind one NAT address — so it is now 60/hour with the refusal meter left
      tight at 10/15 min. And enabling by a direct row write, which is what a
      restore does, got past the enable gate with no document URL: the service
      fails closed on that too, not only the admin write path.
      *One acceptance criterion could not be met as written.* #778 asks for the
      public route to be classified `public` in `RouteRoleMap`; adding it fails
      `RouteRoleMapCompletenessTest::test_the_map_classifies_nothing_outside_the_session_groups`,
      because that map governs `/api/admin/*` and `/api/auth/*` only. The gate is
      documented where the route is registered instead, and the existing test
      stands as the proof the map claims nothing about it.
- [x] **M2 — Admin review endpoints**
      ([#779](https://github.com/dgloeckner/clubbar/issues/779)).
      List (with duplicate flags by `email` and `iban_fingerprint`, and days to
      purge), edit-before-approve, approve, reject. `[ADMIN, KASSENWART]` in
      `RouteRoleMap`. Approve copies the ciphertext **verbatim** and carries the
      reference across; reject deletes at once. Audit rows for both, masked IBAN
      only.
      *Verified*: PR [#788](https://github.com/dgloeckner/clubbar/pull/788).
      Unit suite 2775 green (18 service specs, 19 controller specs, 8 repository
      specs); `RegistrationApprovalTest` against MariaDB for atomicity, for the
      copied ciphertext still opening to the submitted IBAN, and for a pending
      registration never reaching the terminal sync; 24 API specs green
      including the Kassenwart working the whole queue and the Getränkewart
      getting 403 on every route.
      *Design note.* The plaintext is gone by approval time, so the sealed
      quartet is copied verbatim — key id included, because relabelling the
      ciphertext with today's active key would produce a mandate nobody could
      collect on. `MembersRepository::createFromSealedMandate()` is the one
      write path in that class that never sees a plaintext IBAN.
      *Two silent failures closed.* `members.email` has no UNIQUE constraint, so
      approving a colliding address would have created a second member record
      for one person, found at the next settlement when both got a statement —
      now a typed refusal. And `audit_log.action` is a MariaDB ENUM while the
      approval audits *inside* its own transaction, so a case added without a
      migration reads as "approving is broken", against a real database only;
      `AuditActionSchemaTest` reads the migrations as text and fails in 0.2s
      naming the missing values.
- [x] **M3 — Fill the club's document, and keep every page**
      ([#780](https://github.com/dgloeckner/clubbar/issues/780)).
      Port the spike ([#786](https://github.com/dgloeckner/clubbar/pull/786)):
      enumerate AcroForm field names and rectangles from the raw PDF with
      `/Rect` corner order normalized, import **page 1** with FPDI so annotations
      are dropped and the output is flattened by construction, draw the values at
      the rects, then **append the remaining pages unchanged** — in that order,
      because FPDF cannot revisit a page it has moved past.
      `setasign/fpdf` + `setasign/fpdi` only; Latin-1 transliteration for core
      fonts. Vocabulary: required `mandatsreferenz`, `vorname`, `nachname`,
      `iban`, `iban_last4`; optional `geburtsdatum`, `email`, `kontoinhaber`;
      **never** Ort/Datum, signatures or checkboxes. Member variant filled
      in-request during `POST /api/public/registrations` and returned in that
      response, `Cache-Control: no-store`; admin variant identical but `iban`
      empty and `iban_last4` printed as the `endet auf ****XXXX` hint. The
      document comes from `sepa_config.mandate_template_url` — fetched once per
      request, never stored — with the shipped DK-Muster default when it is unset
      or unreachable.
      *Verified by*: fixtures for both the single-page DK-Muster default and the
      four-page FRGS Anmeldung; **page count and pages 2+ content preserved**;
      the member variant contains the full IBAN and the admin variant provably
      does not; Ort/Datum, signatures and checkboxes provably unfilled; zero
      `/Widget` annotations in either output; a `/Rect` regression test on a
      WeasyPrint-built fixture; optional fields filled when present and skipped
      when absent; no disk or database write on either render path.
- [x] **M4 — Public onboarding page**
      ([#781](https://github.com/dgloeckner/clubbar/issues/781)).
      Small self-contained bundle under the backend document root at `/register`,
      not the admin SPA. Reads the secret from the fragment and never puts it in
      a request line. Mobile-first, a **prominent link to the club's own
      Datenschutz document before any data entry** — no legal text embedded in
      Club Bar, in any language, and **no checkbox attached to it on screen**,
      since Art. 13 is a duty to inform rather than something to declare (the
      paper's Kenntnisnahme box is ticked by hand at signature) — with the URL
      shown recorded, the multi-page document arriving in the submission response
      and not re-fetchable on reload, the disabled state
      rendering the club's reason, the one-time PDF download, and a confirmation
      screen that says plainly: you are not a member yet, bring the signed sheet.
- [x] **M5 — Admin registrations inbox**
      ([#782](https://github.com/dgloeckner/clubbar/issues/782)).
      List page per `admin-frontend/patterns/table-implementation.md`
      (`useListQuery`, no hand-rolled paging state), review drawer, print,
      approve with the attestation, reject with a reason. The IBAN is never
      displayed — it can only be replaced, because the server cannot read it.
- [x] **M6 — Availability, secret, URLs and poster**
      ([#783](https://github.com/dgloeckner/clubbar/issues/783)).
      Generate the first secret (until then the feature is off), reprint the
      poster without rotating, rotate — all `[ADMIN]`, and rotation invalidates
      every poster on the wall, which the UI has to say. Switch off with a
      member-facing reason. Enabling requires **both** the secret and the
      document URL, and a missing one is named
      (`document_url_missing`) rather than greying the switch out. The
      configured URL —
      the club document (`sepa_config.mandate_template_url`) — is **one** URL,
      validated when saved: fetched once, `https://` required, and its required
      AcroForm fields enumerated, refused with the typed reason naming the
      missing field or telling the club to rebuild with `--uncompressed-pdf`.
      The same URL is what the public page links for Art. 13, so there is no
      second field to keep consistent with it.
      **No upload flow** — clubbar stores no template copy. Printable QR poster.
      *Verified by*: `api-tests` `self-registration.spec.ts` 44/44 — the
      settings key set asserted exactly (the guarantee is about what is
      **absent**), rotation killing the old secret observed from the public
      surface, reprint not rotating, the trimmed reason reaching the
      poster-holder's context, an unreachable URL refused **and not stored**,
      both preconditions named, and the Kassenwart reaching the review inbox
      but not this credential. `admin-chromium`
      `settings-self-registration.spec.ts` 4/4 for the screen, and 9 component
      tests for the gate on the switch — including that nothing on the tab can
      render the secret. Backend Unit 2880 green.

      **One deviation from the issue text, deliberate**: the DK-Muster default
      is not implemented, and there is nothing left to implement it *for*. Under
      the owner's one-document ruling the club's own published Anmeldung is both
      the Art. 13 notice and the print template, so a club with no URL has
      nothing to show an applicant before collecting their data — the surface
      fails closed rather than falling back to a generic mandate that would drop
      the Datenschutzhinweise the applicant was pointed at. Clearing the URL
      therefore switches the club off, which is the same decision stated once.
- [x] **M7 — E2E flow and privacy assertions**
      ([#784](https://github.com/dgloeckner/clubbar/issues/784)).
      Public form → pending row → admin approve → member exists → terminal sync
      sees them, and the same assertions one step earlier proving it did **not**.
      Plus: no plaintext IBAN anywhere in the database, no personal data in the
      public endpoint's logs, no mail queued by a submission, and a purge that
      removes an abandoned registration.
      *Verified by*: `registration-flow.spec.ts` carries one applicant from a QR
      scan to a member the till can serve, in two browser contexts — the
      applicant's phone has no admin cookie, which is what keeps the public
      surface's authorisation honest — while intercepting every JSON response
      the panel receives and asserting the IBAN is in none of them. The terminal
      gate is asserted from the terminal's own bearer token, before and after
      approval. The signature line is proved unfilled **geometrically**, with a
      control run that fills it first so a fixture rebuild cannot make the claim
      vacuous.

      **Three parallel-safety defects it found, all fixed here**: the nav count
      badge's test id started with `nav-`, so #782 made every nav enumeration
      see a phantom section (and `nav-overflow` was never told about the real
      one); four spec files each deleted the shared club-document fixture in
      their own `afterAll`, out from under the others; and four spec files write
      one singleton config row, which `mode: 'serial'` orders within a file and
      not across them — now serialised by `utils/registrationLock.ts`.

      Backend Unit **2883** green; `admin-chromium` + `register` **400/400** and
      `api-tests` **804/804**, both at 4 workers, in the shapes CI's `ui` and
      `api` lanes actually run. The default `npm test` list deliberately mixes
      lanes and still shows three pre-existing cross-lane contentions
      (`notifications-queue`, `settings-credentials` ×2) in files this slice does
      not touch — both are green inside their own lane.

- [x] **M8 — The onboarding page wears the club's mail design**.
      The page a QR code opens and the mail that follows it days later are the
      only two surfaces a member meets outside the clubhouse, and they looked
      like two different products. The page now mirrors
      `App\Shared\Mail\MailLayout` — the same palette, the same 600px sheet on
      its warm grey ground, the paper masthead over a red rule, the petrol
      footer, the red note box around the Art. 13 link — light-only, as the mail
      is, so it does not stop matching at dusk. **It says who is asking**: the
      instance name and `mail_config.logo_url` travel on the context answer, so a
      club that branded its mail has branded this page with nothing further to
      configure; a form that wants a birth date and an IBAN without naming the
      club is indistinguishable from a phishing page. Nothing is branded before
      the gate — a wrong secret is still the uniform 404 with no body. **And the
      confirmation screen no longer offers to print at the Theke**: the club
      cannot, so step 2 is now "hand the signed sheet to the Kassenwart" and
      nothing else.
      *Verified by*: backend Unit **2926** green (`php8.3`), including
      `PublicBrandingTest` on what may become an `img src` — `javascript:`,
      `data:`, a protocol-relative host and a mail-only `cid:` all dropped — and
      `PublicBrandingProviderTest` on the fail-soft path an installation with no
      `mail_config` row takes. `register` **22/22** and `api-tests`
      `self-registration.spec.ts` **47/47**: the club named in the masthead and
      the footer is the one `instance_config` holds, a paused club is still
      branded, a refused link is not branded at all, and a club with no mark
      renders no image rather than a broken one.

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
| 17 | No document template is stored by Club Bar; an unreachable one falls back to the shipped default rather than failing a registration | M3 + M6 |
| 18 | Both rendered variants keep every page of the template, pages 2+ byte-identical | M3 + M7 |

## External dependencies

The club has to publish two documents before this feature can be switched on
anywhere: its combined Anmeldung, carrying the AcroForm fields the fill addresses
and the Datenschutzhinweise on its later pages. Both sources are delivered in
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

## Settled, and what is still outstanding

| | |
|---|---|
| The document | **One** — the club's combined four-page Anmeldung; fields on page 1, every page preserved by the fill |
| The template | Not pinned: `sepa_config.mandate_template_url` is the pointer, and the same URL the page links for Art. 13 |
| Retention | **30 days** before an unapproved registration is purged |
| Translation | None, and no club-language setting exists |
| Optional consents | None — the document carries nothing to opt into |

**Blocking M1 inside this repository:** the owner's explicit confirmation of the
schema — two new tables (`pending_registrations`, `self_registration_config`) and
**no new column**, since `sepa_config.mandate_template_url` already exists and does
both URL jobs.

**Nothing is blocking outside it any more.** The fill was verified on the production
hosting on 2026-08-31, and the club has published the built document at its stable
URL; inspected as published it satisfies the whole contract — classic xref, four
pages, all eight widgets on page 1, every required and optional field present, and
no field behind Ort/Datum, the signatures or the Kenntnisnahme box.
