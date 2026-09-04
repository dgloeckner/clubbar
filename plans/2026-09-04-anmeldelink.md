# Anmeldelink: mail the club's registration link to a prospective member

**Issue**: [#821](https://github.com/dgloeckner/clubbar/issues/821)
**ADR**: [ADR-0053](../adr/0053-anmeldelink-carries-no-credential.md) — new,
amends [ADR-0052](../adr/0052-member-self-registration-via-qr-code.md)
**Use case**: [UC-A70](../use-cases/admin/UC-A70-send-anmeldelink.md) — new;
[UC-A69](../use-cases/admin/UC-A69-configure-self-registration.md) amended for
the rotation consequence

## Context

The QR poster (ADR-0052) reaches exactly the people standing in the clubhouse. A
club that meets somebody at a regatta, or takes an enquiry by mail, has no path
at all — so a Kassenwart pastes the URL into their private WhatsApp: unbranded,
unrecorded, and from a person rather than from the club.

**The whole feature is a message and a button.** What made it worth an ADR is
that the obvious design for it is wrong in a way that looks like diligence:
everything else here that mails somebody a way in mails them a *credential*
(`admin_user_invitations` — minted, sealed, expiring, revocable, attributable),
and copying that shape would produce a `member_invitations` table, a TTL, a
purge job and a campaign view. Every one of those is machinery for a property
this link does not have. The secret is already printed on a wall the public
walks past.

## The data model does not change

**No table, no column, no index, and that is deliberate** — the absence is the
design, not an oversight to be corrected later. `docs/erm-master.md` is
therefore untouched.

`mail_outbox.recipient` *is* the invitation history: already retained under the
outbox's own window, already visible under Benachrichtigungen, already pruned.
An invitee table would be the queue nobody empties that ADR-0052 decision 10
exists to prevent.

**A migration still exists**, and issue #821's acceptance criterion 7 ("no
migration is added") could not hold. `mail_outbox.kind` and `audit_log.action`
are ENUM columns in this schema, so a `MailKind` with no value in the column is
refused by MariaDB at write time — the send would return 202 and queue nothing.
Migration `064_anmeldelink.sql` widens both enums and does nothing else. The
design intent ("no schema change") holds for tables, columns and indices; it was
never literally true of the file count.

---

## M1: The mail kind, and the third audience — `[x]`

**Why first**: everything downstream needs the enum to exist, and the enum is
where the interesting discovery was.

- [x] Migration `064_anmeldelink.sql` — widen `mail_outbox.kind` with
  `registration_link` and `audit_log.action` with `registration_link_sent`.
  Nothing else.
- [x] `MailSubject::SELF_REGISTRATION` → `EntityType::SELF_REGISTRATION`. Its
  own case rather than borrowing `INSTANCE_CONFIG`, which would fit without a
  line of new code and would make `subject_id` claim an Anmeldelink is a message
  about the club's configuration.
- [x] `MailKind::REGISTRATION_LINK` with all four exhaustive matches answered:
  `recipientRoles() []`, `addressesMember() false`, `addressesClub() false`,
  `subjectType() SELF_REGISTRATION`.
- [x] **`MailKind::addressesProspect()` — a fifth exhaustive match, and the
  finding this milestone existed to surface.** Until now there were two
  audiences, so "not addressed to a member" meant "fanned out to admins", and
  `MailKindTest` asserted exactly that. With a third audience,
  `recipientRoles() === []` became indistinguishable from a kind whose offices
  somebody forgot to classify — and `AdminNotifier::warnAdmins()` would have
  *accepted* this kind: no recipients found, the club-copy escalation skipped
  (it needs a non-empty role set), and a zero-count **success** returned. A
  notice that reached nobody, silently, which is what ADR-0044 rule 5 exists to
  prevent. `warnAdmins()` now refuses it by name.
- [x] `MailRetention` — the default ninety-day window, with its reasoning: this
  row is the *only* record, so the window is what the club gets for finding who
  it wrote to.
- [x] `MailRequestDto::forProspect()` + `addressedToProspect`. The first row with
  both id columns NULL and no club address either; its own flag rather than a
  reuse of `addressedToClub`, so a club notice cannot be queued to a stranger by
  passing the wrong boolean.

**Verify**: `php8.3 vendor/bin/phpunit --testsuite Unit` — 3014/3014 green
(including two new `MailKindTest` cases: the audiences are mutually exclusive,
and a prospect-addressed kind is not a fan-out).

---

## M2: The message — `[x]`

- [x] `RegistrationLinkMail` — German body. Names **no expiry**, because there is
  none; prints the link as text as well as a button; says in a box of its own
  that the form is printed, signed by hand and handed in.
- [x] `MailStrings` keys in both languages. English is present even though
  nothing queues an English one today: `MailStringsTest` requires both tables to
  define the same keys, and wording it now costs a paragraph against discovering
  it missing on the day #820 lands a club default.
- [x] `RegistrationLinkMailBuilder`, claiming its kind **by name** rather than by
  subject — a subject-wide claim is a standing offer to render the next kind
  filed under `SELF_REGISTRATION`, which the registry accepts silently.
- [x] The link is rebuilt at send time from the club's current secret. Nothing
  about it is stored in the queue row, which is what keeps rotation meaning one
  instant and total act.
- [x] The gate is re-checked at render: switched off, or an unreadable secret,
  **throws**. The drain records the failure where a Kassenwart sees it.

**Verify**: `RegistrationLinkMailBuilderTest` — 7/7 green.

---

## M3: The send — `[x]`

- [x] `RegistrationLinkService` — gate, enqueue, audit. Refuses with the
  availability switch's *own* typed reasons (`registration_disabled`,
  `registration_no_secret`, `document_url_missing`), so the admin is told which
  precondition to fix.
- [x] `dedup_key` is a per-send nonce. The unique index makes a repeating *scan*
  idempotent; there is no scan here, and a key of the bare address would refuse
  the re-send that answers "I never got it" — silently, from the database,
  behind a 202.
- [x] `POST /api/admin/registrations/link` on the registrations controller, 202.
- [x] `RouteRoleMap` → `TREASURY`. Beside `ADMIN_ONLY` routes on the same
  surface, and that difference is the point: the mail points at the review inbox
  (TREASURY), not at the credential (reading the poster *is* reading the secret).
- [x] `AuditAction::REGISTRATION_LINK_SENT`, carrying the address.
- [x] OpenAPI: the endpoint, plus the `MailKind` enum — which had drifted, and is
  now closed for `admin_invitation` as well as the new kind.

**Verify**: `RegistrationLinkServiceTest` 9/9 and `AdminControllerTest` (5 new
cases) green; `api-tests self-registration.spec.ts` 56/56 including 9 new specs —
the row shape, the double-send, each refusal, the roles and the audit entry.

---

## M4: The panel — `[x]`

- [x] `SendRegistrationLinkModal` — the double-click guard lives here, because
  the backend deliberately does not deduplicate and this is where the mistake
  happens. Editing the address re-arms it; a failed send leaves it armed.
- [x] Primary control in the `/registrations` page header. **Not** in Settings
  beside the poster, which is the conceptually natural home: that tab is
  `ADMIN_ONLY`, so one button there would lock out the Kassenwart whose queue
  this is, and splitting the tab's role set is the drift ADR-0044's default-deny
  prevents. The accepted cost is an outbound verb on an inbox.
- [x] Secondary CTA in the empty state — the feature's only discovery point, and
  where a Kassenwart is looking when they would want it.
- [x] Refusals through `useApiError()`, never the backend's English `message`.
- [x] Both locale files: the subtitle and empty state named only the poster and
  became wrong the moment a second route existed; the notifications filter and
  its labels; the rotation warning, which now says sent links die too and where
  to find who was written to.
- [x] **Pre-existing bug fixed, unrelated and folded in because this issue was
  already on the page**: the header row put three bare `<button>`s beside three
  `<th>`s, so CSS table fixup collected them into one anonymous cell — four cells
  against six columns, with the buttons stacked vertically inside it because they
  are `display: flex`. Every other list page wraps them; this one now does too.

**Verify**: `vitest` 598/598 including 6 new component cases;
`admin-chromium registrations-inbox.spec.ts` 12/12 including three new specs —
the send end to end, the refusal in German, and one header cell per column.

---

## M5: The chain — `[x]`

- [x] New `mail-anmeldelink` Playwright project, last in the chain, added to the
  CI `chain` lane. Unlike the chains above it, this one drives a **browser**.
- [x] The spec asserts on what a real drain delivered to Mailpit (Pattern 010),
  then reads the link **out of the delivered message**, opens it in a context
  holding no session, fills the form in and finds the resulting row in the admin
  inbox. If the mailed URL ever drifted from the poster's, every step after the
  first fails.
- [x] **`configureSelfRegistration()` fixed while here.** It wrote `secret_hash`
  and left `secret_cipher` NULL — a state no real path produces, since
  `replaceSecret()` always writes both. The application noticed: the builder
  reads the secret *back*, so the fixture produced "the club has no readable
  poster secret", which reads as a broken feature rather than a half-written
  fixture. Sealing now runs inside the backend container through the
  application's own `SymmetricSecretBox`, rather than reimplementing the
  ciphertext format in TypeScript against a copied key.

**Verify**: `mail-anmeldelink --no-deps` 3/3 green.

---

## M6: Documentation — `[x]`

- [x] ADR-0053, focused on the part that is genuinely a trade-off: why this link
  carries no credential, what that buys, and what it costs.
- [x] UC-A70, with test-derivable acceptance criteria.
- [x] UC-A69 amended — the rotation flow, its worked-example table, its rules and
  its postconditions now say that sent links die too, and name the recovery.
- [x] `CONTEXT.md` — **Anmeldelink** was added on this branch before the
  implementation started (`ee180b5`), together with the amendment pointing
  *Einladung*'s *Avoid* list at it so the two cannot drift back together. This
  plan added only the two cross-references that entry could not carry yet:
  ADR-0053 and UC-A70.
- [x] `docs/erm-master.md` — deliberately unchanged; see "The data model does not
  change" above.
- [x] This plan, and `plans/INDEX.md`.

---

## Deliberately not built

Recorded so it is not re-proposed. The full reasoning is in ADR-0053's
alternatives table.

- A per-member invitation credential, and the `member_invitations` table, TTL,
  purge and revoke-on-reissue that come with it.
- Double opt-in on the recipient address.
- Prefilled forms, invitee tracking, a campaign view.
- A per-send daily cap.
- `instance_config.default_language` — a real gap and a real decision, deferred
  to [#820](https://github.com/dgloeckner/clubbar/issues/820) rather than
  invented as a side effect of adding a button.
- Renaming `/registrations` to cover both verbs (*Neuaufnahmen*) — raised in
  design and deliberately deferred: a naming question worth its own thought.

## Open

- **EN wording**: the English strings say "registration link" rather than
  carrying *Anmeldelink* across. *Deckel* earns an untranslated slot because
  English has no word for it; *Anmeldelink* does not. Flagged in #821 as needing
  confirmation, and implemented that way.
