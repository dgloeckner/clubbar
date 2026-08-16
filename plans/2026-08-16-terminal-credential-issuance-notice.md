# Terminal Credential Issuance Notice

**ADR**: [ADR-0043](../adr/0043-terminal-credential-issuance-is-announced.md) (the decision and the rejected alternatives), [ADR-0038](../adr/0038-transactional-mail-outbox-on-shared-hosting.md) (the outbox and the mandatory scheduler), [ADR-0041](./../adr/0041-terminal-credential-anomaly-detection.md) (alert-don't-enforce, and the gap this fills)
**Status**: Implemented (M1–M6 done; backend unit + feature suites green, `api-tests` 634/634, `mail-issuance` 4/4, `mail-credentials` 3/3)

---

## Why

Minting a terminal token is the hardest-gated write in the admin API — the
caller's own password, their own fresh TOTP code, a rate limiter, and two
first-class audit actions — and it was the only credential event in the system
that **created a secret and told nobody**. The audit entry it leaves is *pull*:
somebody has to decide to go and look.

The attack that exploits the gap needs a fully compromised admin — session,
password and a live TOTP code. From there a token that reads the whole member
roster exists for `API_TOKEN_TTL_DAYS`, and ADR-0041's detectors are
structurally blind to it: a freshly minted credential has no cursor history to
contradict and, for a newly enrolled fake terminal, no second device to overlap
with.

The gate is right. What it lacked was a witness, and the witness has to be **out
of band** with respect to the credential that was compromised — which the other
admins' inboxes are.

## The two decisions worth stating

**Both mint paths, not only rotation.** Rotating an existing terminal's token is
the noisier attack: it stages a replacement somebody eventually has to type in
at the till, and the club notices. Enrolling a brand-new terminal nobody stands
next to produces a working credential and no operational symptom at all.
Announcing only renewal would announce only the attack nobody would choose.

**The actor is not in the message.** ADR-0043's contents table names "who
acted", and the implementation deliberately does not carry it — see M4 below.
The outbox row records who was *told*; ADR-0038 rule 5 has builders re-derive
their content from current state at send time, and the actor is not in that
state. Recovering it would mean guessing at the audit log's most recent matching
entry, and naming the wrong colleague in a security notice is worse than naming
none. The message points at the audit log, which is exact. **This divergence is
open for a decision: either the ADR's line is amended to match, or the lookup is
built.**

---

## Milestones

### M1 — The kind and its storage `[x]`

- `[x]` `MailKind::TERMINAL_TOKEN_ISSUED`, with `subjectType()` → `TERMINAL` and
  `addressesMember()` → false, both stated explicitly rather than inherited
  (`MailKindTest` enforces that for every kind)
- `[x]` Migration `041_mail_outbox_terminal_token_issued.sql` + its rollback,
  restating the whole ENUM as 026/030/036/038/039 did
- `[x]` `MailRetention::sentDaysFor()` gains its arm at the 90-day default — what
  the row holds past delivery is an address, and the durable record is the audit
  entry
- **Verified**: `MailKindTest`, `MailRetentionTest` green; the column accepts the
  new value in the dev database

### M2 — The occasion `[x]`

- `[x]` `TerminalTokenIssuedMailBuilder::occasion()` / `::parseOccasion()` own
  both halves of the format, so the service that queues and the builder that
  renders cannot drift
- `[x]` `enrolled:<stamp>` / `rotated:<stamp>`, the stamp being
  `CredentialExpiryNotifier::tokenGeneration()` — the same generation the expiry
  warnings key on, so both features name a token's generation identically
- `[x]` The key fits: 23 + 1 + 36 = 60 of `dedup_key`'s 64, asserted rather than
  argued
- **Verified**: `TerminalTokenIssuedMailBuilderTest` — the occasion it builds is
  the occasion it parses; two issuances of one terminal produce two keys

### M3 — The message `[x]`

- `[x]` `TerminalTokenIssuedDataDto`, `TerminalTokenIssuedMail`, de/en strings
- `[x]` `MailFormat::dateTime()` — a date alone is enough for a deadline and not
  enough for something that *happened*; "was that me?" is answered to the minute
- `[x]` Names the till, the device id, the event, the moment and the new expiry;
  names the recovery step as the panel labels it ("Zugang sperren"); says why it
  reached everyone; carries no token material and says so
- `[x]` A missing date drops its line rather than printing a blank label
- **Verified**: `TerminalTokenIssuedMailTest` (7 tests, both languages)

### M4 — Rendering at send time `[x]`

- `[x]` `TerminalTokenIssuedMailBuilder`, registered in `MailContentRegistry`
- `[x]` The expiry is looked up **by generation**, across both the pending and
  active columns, because a rotation's token moves from one to the other when the
  terminal first presents it. A stamp matching neither means the credential is
  gone — revoked, or superseded — and the line is dropped rather than filled from
  whichever token happens to be on the row now
- `[x]` A missing terminal, or a key naming no known event, throws rather than
  inventing a security notice around the gap
- `[!]` **The actor is not carried.** ADR-0043 lists it under contents; see "The
  two decisions worth stating" above. Resolution pending: amend the ADR line, or
  add an audit-log lookup to the builder
- **Verified**: `TerminalTokenIssuedMailBuilderTest` 10/10, including the
  promoted-between-enqueue-and-drain case and the no-token-material assertion

### M5 — Queuing it, and the dependency that surfaced `[x]`

- `[x]` `TerminalsService::createTerminal()` and `::rotateToken()` announce in the
  same call that audits
- `[x]` Best effort: anything thrown is logged and swallowed. Rotation is the fix
  for a till that cannot sell, so a queue failure must not put a second outage on
  the recovery path
- `[x]` **`AdminNotifier` extracted from `NotificationsService`.** Wiring the
  whole notifications service into terminals made enrolling a terminal depend on
  `MembersRepository` → `IbanSealedBox` → a required `IBAN_FINGERPRINT_KEY`;
  `ServiceFactoryTest::test_getTerminalsService_*` failed on any run without that
  key configured, which CI's backend job is. The admin fan-out now holds exactly
  what it needs — the queue, the admin list, the audit log — and
  `NotificationsService` no longer has `warnAdmins()` at all rather than keeping a
  forwarding shim
- `[x]` `CredentialExpiryNotifier` and `TerminalAnomalyDetector` moved to the
  narrow collaborator with it; its tests moved to `AdminNotifierTest` unchanged
- **Verified**: `TerminalsServiceTest` (4 new tests: both paths announce their own
  generation, a queue failure does not cost the caller its token, an unknown
  terminal announces nothing), `AdminNotifierTest` 7/7, `ServiceFactoryTest` green
  with no IBAN key present

### M6 — The chain, asserted at the mailbox `[x]`

- `[x]` `e2etests/tests/mail-issuance/terminal-token-issued.spec.ts` — mint →
  `bin/cron.php` → an admin who minted nothing; the rotation as its own second
  message; both notices free of both real tokens; revoking announcing nothing
- `[x]` Its own Playwright project after `mail-credentials`, because both address
  every active admin and two files of one project run on two workers
- `[x]` `MailpitClient.waitForMessagesAbout()` — an admin mailbox now holds more
  than one class of message, so the existing expiry spec narrows to its own
  subject instead of claiming the whole mailbox, keeping the exactly-one rule that
  catches a duplicate
- `[x]` CI's project list extended; a project left out is dropped from CI silently
- **Verified**: `mail-issuance` 4/4, `mail-credentials` 3/3 (both `--no-deps`
  against the dev stack), `api-tests` 634/634

---

## Known environment noise (not this change)

Two failures in a full in-container `phpunit` run predate this work and were
confirmed against a clean tree:

- `ServiceFactoryTest::test_getRateLimitMiddleware_is_active_by_default` —
  `docker-compose.yml` sets `DISABLE_LOGIN_RATE_LIMITING: "true"`, which the test
  cannot unset because it reads through `getenv()`. Passes on the host.
- `CronScriptTest` (9 tests) — `proc_open` fails late in a long run in this
  sandbox. Passes in isolation, on a clean tree and with this change alike.
