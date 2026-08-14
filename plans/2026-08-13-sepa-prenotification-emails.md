# SEPA Pre-Notification Emails — Transactional Outbox, Cron Drain, Mandate Reference

**Epic**: [#361](https://github.com/dgloeckner/clubbar/issues/361) · **ADR**: [0038](../adr/0038-transactional-mail-outbox-on-shared-hosting.md) · **Branch**: `claude/issue-361-milestone-qxbjkh` (P0 + P1)

Status legend: `[ ]` not started · `[~]` in progress · `[x]` passed (test verified) · `[!]` failed (reason documented)

## Why

The backend sends no email at all. `SettlementLeadTime::DAYS = 7` models the SEPA pre-notification as date arithmetic and stops there — nobody is told anything. Two commitments already assume otherwise:

- **Nutzungsordnung Vereinsbar § 7 Abs. 3** — every collection is announced by email at least 7 days before the due date (the 14-day SEPA default, shortened by agreement). § 7 Abs. 1 additionally promises an Abrechnungsübersicht per email.
- **The registration form** tells members the Mandatsreferenz arrives *„mit der Vorabankündigung zum ersten Einzug per E-Mail"* — and EPC requires the creditor ID and mandate reference to reach the debtor before the first collection.

The architecture (decided 2026-08-13, recorded in ADR-0038) exists because the naive design — send inside the finalize request — fails on mass hosting in three ways: the host's FastCGI/gateway **read** timeout (not `max_execution_time`, which does not count socket waits), **greylisting** (many MTAs reject the first attempt on purpose and expect a retry ~15 min later), and above all a **half-succeeded finalize** whose announcement state cannot be reconstructed.

So: finalize enqueues inside its own transaction and makes no network call; a scheduler drains the queue and is the only sender; the scheduler is a hard prerequisite, verified at install and gating finalize until a run has been observed.

## Milestones

### P0 — ADR-0038 + ADR amendments + plan scaffolding ([#400](https://github.com/dgloeckner/clubbar/issues/400))

- [x] `adr/0038-transactional-mail-outbox-on-shared-hosting.md` — transactional enqueue, DSN transport adapter, mandatory scheduler as the only sender, no scheduling in the UI, render-at-send, heartbeat/stall semantics, install gate, L0–L3 layer table, enqueue/drain/cancel diagrams, outbox schema
- [x] ADR-0032 amended (§10): enqueue is part of the create transaction; cancellation supersedes unsent rows and notifies only members whose announcement was `sent`
- [x] ADR-0029 amended: `mail_outbox.recipient` is operational-tier and in scope for erasure, sent rows are pruned, and the boundary against this ADR's own rejection of scheduled jobs is stated (moving a queue ≠ applying a retention policy)
- [x] ADR-0031 amended: M0–M3 mail/scheduler layers, the first accepted **hard** dependency on a host feature, and why it stays compatible with rule 3
- [x] `SettlementLeadTime::DAYS` docblock carries the Nutzungsordnung § 7 Abs. 3 coupling
- [x] `adr/README.md` indexes 0038 and records the three amendments
- [x] This plan file + `plans/INDEX.md` current-plan row
- Verify: docs review only; no behaviour change. `SettlementLeadTimeTest` still green (docblock-only edit)

> **ADR number**: #400 asks for 0037, but `0037-mandate-documents-not-retained.md` merged in the meantime (#396 / PR #435). This is **0038**.

### P1 — Mail foundation: transport adapter, layout port, `mail_config` ([#401](https://github.com/dgloeckner/clubbar/issues/401))

- [x] `composer require symfony/mailer symfony/mime` (7.4)
- [x] `backend/src/Shared/Mail/`: `MailTransport` interface, `SymfonyMailerTransport` (DSN-driven, transport built lazily on first send), `NullTransport` (no DSN → logs a warning, sends nothing, never throws; the test default so CI opens no socket), `MailTransportFactory` + `MailTransportStatus`, `MailDsn` parsing with `InvalidMailDsnException` the self-check can render, `MailMessage` / `MailSender` / `MailSendResult`
- [x] `MailLayout` ported from `frgs-website/site/plugins/frgs-ruderkurs/src/Mailvorlage.php` — framework-free, table layout, inline styles, MSO conditionals, preheader, header variant `paper` as the default, token comment *„Farben — Spiegel von tokens.css, dort mitpflegen"* kept verbatim. Plus an `itemTable()` the upstream has no use for and the Abrechnungsübersicht does
- [x] Multipart mandatory — `MailMessage` refuses an empty text part in its constructor
- [x] `adler-mail.png` at `backend/resources/mail/`; `MailBranding->logoSrc` takes a URL or a `cid:` reference, and a null logo renders the wordmark alone
- [x] `mail_config` singleton beside `sepa_config`: migration `024`, repository, service, DTO, controller, routes, `EntityType::MAIL_CONFIG`, OpenAPI — sender name/address, reply-to, header variant, Impressum block; club data, admin-editable
- [x] `mail.dsn` in `package/config.sample.php` + `package/index.php`, `MAIL_DSN` in `AppConfig`, `docker-compose.yml` and `.env.example`; absence disables mail silently, like `llm.provider`
- Verify: **passed** — 67 PHPUnit cases across `tests/Unit/Shared/Mail` and `tests/Unit/Modules/Notifications` (DSN matrix, redaction, transport resolution, layout constraints, escaping, multipart refusal); `mail-config.spec.ts` 7/7; full suite 1707/1708 (the one red, `ServiceFactoryTest::test_getRateLimitMiddleware_is_active_by_default`, is the dev compose's `DISABLE_LOGIN_RATE_LIMITING` and predates this work); api-tests 585/585

Three deviations from #401 worth knowing about:

| Deviation | Why |
|---|---|
| The class is `MailLayout`, not `Mailvorlage`, and it is **translated, not copied** | This repository's contributor rules require English identifiers and comments. Diffing against upstream will not be mechanical; the class docblock names its origin and both intentional divergences |
| Club identity is `MailBranding`, injected — no `ABSENDERNAME`-style constant survives the port | The 2026-08-11 decision on #361, plus ADR-0034: Club Bar is deployed by more than one club. A test asserts no FRGS string can reach the output |
| Validation is `Validator` rules in the controller, not a Form Request class | Every controller validates this way and the codebase contains no Form Request at all — only pattern-001's **filename** and its index entries still advertised one. Resolved rather than deferred: the pattern is now `pattern-001-input-validation.md`, rewritten against the `Validator` as it actually behaves, and says plainly that adding a Form Request would be the deviation |

### P2 — `mail_outbox`, Notifications module, transactional enqueue ([#402](https://github.com/dgloeckner/clubbar/issues/402))

- [x] Migration `025_mail_outbox.sql` with `UNIQUE (kind, settlement_id, member_id)` and **no body column**; `cron_heartbeat` singleton in the same migration; `audit_log.action` gains `mail_enqueued` and `mail_superseded`
- [x] `backend/src/Modules/Notifications/` per ADR-0018 and `backend/patterns/`: `MailKind` / `MailStatus` / `MailLanguage` / `MailSubject` enums, `MailOutboxRepository`, `NotificationsService` with `enqueueForSettlement`, `cancelSettlementNotifications`, `claimBatch`, `recordResult`, plus `supersedePending` / `markSent` / `markFailed` / `resetToPending` / `oldestPendingQueuedAt` on the repository
- [x] Enqueue inside the **existing** `createSettlement` transaction — one row per collected member (`amount > 0`), language frozen at enqueue from `members.preferred_language` with `de` fallback; credit / zero / no-email members get no row, and `direct_debit` only
- [x] `cancelSettlement`: supersede unsent announcements, enqueue `cancellation_notice` only where the announcement is `sent`
- [x] Audit entries (ADR-0013) for enqueue and cancellation-notice creation, keyed to the settlement
- [x] Generalised for [#438](https://github.com/dgloeckner/clubbar/issues/438) in migration `026` — see below
- [x] Test isolation: the outbox tests no longer assume a globally empty queue, and six member/dashboard tests no longer assume one page of 1000 covers the database — see below
- Verify: **passed** — `NotificationsServiceTest` (22), `MailOutboxSchemaTest` (8, incl. the unique constraint, the *absence* of a body column, and an admin-addressed warning about a credential), `MailOutboxRepositoryTest` (13, against MariaDB: two concurrent claims send N and never N+1, a stale claim is reclaimable, backoff then cap), `MailContentRegistryTest` (4), `SettlementAnnouncementTest` (10, the whole chain), plus 5 new cases in `SettlementsServiceTest` including the rollback

Two notes worth keeping:

| Decision | Why |
|---|---|
| `enqueue()` is `INSERT … ON DUPLICATE KEY UPDATE id = id`, not `INSERT IGNORE` | `INSERT IGNORE` downgrades *every* error to a warning — a foreign key pointing at a member who no longer exists would vanish silently instead of aborting the settlement it belongs to |
| `MailLanguage` is a separate enum from `SupportedLanguage` | A member may prefer French; there is no French announcement. The outbox column then states the language the mail **will** be in, rather than a preference that cannot be honoured |

#### The queue is not a settlement table

Migration 025 shipped the outbox in the shape ADR-0038 describes it in — `UNIQUE (kind, settlement_id, member_id)` — because the pre-notification is the first thing to use it. It is not the last: [#438](https://github.com/dgloeckner/clubbar/issues/438) wants encryption-key and terminal-token expiry warnings, which are **about a credential rather than a settlement**, **addressed to an admin rather than a member**, and **repeat per 90/30/7-day tier**. All three break the original key.

Migration `026_mail_outbox_generalised.sql` widens it, and backfills `dedup_key` from `member_id` so every existing row keeps exactly the identity the old key gave it:

| Was | Is | Why |
|---|---|---|
| `settlement_id` + FK | `subject_id`, no FK | Which table it points at is `MailKind::subjectType()` — one source, and no second column to disagree with it. A polymorphic foreign key cannot exist; `MailSubject` states the cost plainly |
| `member_id` inside the unique key | `dedup_key VARCHAR(64) NOT NULL` | Everything besides the subject that makes a message distinct. The announcement puts the member here; a warning puts the tier and the admin. **NOT NULL matters**: in MySQL a NULL never equals a NULL, so a single nullable column silently stops a unique index being unique |
| — | `admin_user_id`, nullable FK | Operational mail is addressed to whoever runs the club. `member_id` keeps its foreign key — it is how erasure (#408) finds the addresses this table holds, and the cascade that means a member's mail cannot outlive them |

`NotificationsService::warnAdmins(kind, subjectId, occasion)` is the resulting API: one message per active admin, with `UNIQUE` answering *"has this already been said?"*. That is the idempotent-notification storage #438 asks for, and a stronger answer than the `logOnceSince` dedup the issue names as the nearest precedent — that one is a time window, this is a constraint. #438 still owns the detection, the tiers and the content. Its send path is the mandatory scheduler this epic already installs, which also settles the *"shared hosting has no cron guarantee"* worry in the issue's own text.

Content dispatch moved behind `MailContentBuilder` + `MailContentRegistry`, so the drain (#403) can claim a mixed batch without knowing what any row means, and a new notification type is a new builder rather than a branch in the sending loop.

#### Test isolation against a shared database

Running the API suite before the backend suite against one database used to turn three PHPUnit tests red, each in a way that read as a broken query rather than as leaked fixture data. The common cause: `POST /api/sync/transactions accepts max batch size` books a hundred transactions against a fresh member every run, and ADR-0004 makes transactions append-only — so that member and their 350 € tab cannot be cleaned up afterwards, by design.

| Test | Assumed | Now |
|---|---|---|
| `DashboardRepositoryTest::…puts_the_biggest_tab_first…` | Nothing else in the database owes more | Compares the positions of its **own** two members; the limit is asserted separately |
| `MembersRepositoryTest::…orders_by_unsettled_balance` and 5 siblings | One page of 1000 covers every member | Gives its members a unique surname and passes it as the repository's `search`, so the page holds exactly its own rows |
| `MailOutboxRepositoryTest` (claim/backoff cases) | The queue is globally empty | Parks foreign due rows for the duration and restores them exactly as found |

Verified in the order that used to fail: api-tests, then the full backend suite, green apart from the pre-existing rate-limit case.

### P3 — Cron drain: CLI entrypoint, claim/backoff, `flock`, URL fallback ([#403](https://github.com/dgloeckner/clubbar/issues/403))

- [ ] `backend/bin/cron.php` beside `bin/import-bank-codes.php`: resolves the doc root as `dirname(__DIR__, 2)` so `DataDirectory::resolve()` works in both layouts; `flock` in the data directory; records the **CLI** PHP version and extensions into `cron_heartbeat`
- [ ] `DrainService`: claim → render → send → mark, with configurable batch size and wall-clock budget. Claim by `UPDATE … WHERE status='pending' AND next_attempt_at <= NOW() AND (claimed_at IS NULL OR claimed_at < NOW() - INTERVAL 5 MINUTE) LIMIT ?` then select by token — deliberately not `SKIP LOCKED` (MariaDB 10.6+, and the DB version is the host's)
- [ ] Transient failure → `attempts + 1` and backoff; cap at 3–5 → `failed` with `last_error`
- [ ] URL fallback route with a rotatable secret, header preferred; the bare query-string variant documented as degraded and scrubbed from the access log
- Verify: PHPUnit — two concurrent drains send exactly N, never N+1; retry then cap; a stale claim is reclaimable. Integration: `flock` blocks an overlapping run. API: the URL route rejects a wrong secret and returns no data on success

### P4 — Mail content: pre-notification, cancellation notice, de/en, preview script ([#404](https://github.com/dgloeckner/clubbar/issues/404))

- [x] Pre-notification content: creditor name/address + Gläubiger-ID, mandate reference, exact amount, due date, masked IBAN (last 4), itemized statement (the § 7 Abs. 1 Abrechnungsübersicht), 6-week Beanstandung hint, reply-to Kassenwart — `Notifications/Mail/PreNotificationMail`
- [x] Cancellation notice variant (*„Einzug entfällt"*), carrying **no** mandate reference and **no** creditor ID: those authorise a collection, and under "this will not be collected" they read as a second announcement
- [x] de/en per `preferred_language`, `de` fallback (ADR-0002) — `MailStrings` falls back **per key**, so an untranslated string arrives in German rather than as a gap where the amount belongs
- [x] `SettlementMailBuilder` assembles a message from a queued row at send time, from settlement data and never a stored body; the recipient is the one field taken from the outbox snapshot rather than re-read. It implements `MailContentBuilder` and claims every kind whose subject is a settlement, so #410's payment request lands as a branch here rather than a second builder
- [x] Preview script `backend/bin/preview-mails.php`, writing both kinds in both languages (HTML + text + an index) to `backend/storage/mail-preview/`, gitignored
- Verify: **passed** — `PreNotificationMailTest` (17), `CancellationNoticeMailTest` (9), `MailStringsTest` (10); every required field asserted by name in both languages **and in both parts**, the masked account never exceeds four characters, no IBAN-length digit run reaches the output, and a booking label cannot inject markup

Two deviations from #404:

| Deviation | Why |
|---|---|
| The directory is `storage/mail-preview/`, not `storage/mail-vorschau/` | Same contributor rule that made `Mailvorlage` into `MailLayout` in P1: identifiers and paths in this repository are English |
| German is the **Du-form** and members are addressed by first name alone | Maintainer's decision. It matches the terminal UI ([#42](https://github.com/dgloeckner/clubbar/issues/42)) and how the club talks to its own members; a bank's register would be borrowed formality. Every pronoun lives in `MailStrings`, and a test fails on any German string that slips back into the Sie-form. The envelope still carries the full name — that is what a mailbox lists |

### P5 — The scheduler is mandatory: install verification, finalize gate, missing-email warning ([#405](https://github.com/dgloeckner/clubbar/issues/405))

- [ ] Installer prerequisite step: the exact command/URL for this installation plus a **Prüfen** button polling `cron_heartbeat`; verified/unverified recorded in the install report
- [ ] Admin banner while unverified, carrying the same instructions
- [ ] Finalize blocked while no run has **ever** been observed, with a typed error naming cause and remedy; a stale heartbeat does **not** re-block
- [ ] **Seed `cron_heartbeat` in `backend/db/seed.sql` and the E2E fixtures in this same PR** — without it the gate fails every existing settlement test at once, reading as "settlement creation broken"
- [ ] Pre-finalize warning bucket for collected members without an email (defense-in-depth for legacy rows pending [#362](https://github.com/dgloeckner/clubbar/issues/362); never blocks)
- Verify: PHPUnit — refused on empty heartbeat, allowed after one run, not re-blocked when stale; the warning lists exactly the no-email members and blocks nothing; **the existing suite stays green**; review check that no second sending path exists

### P6 — Monitoring: heartbeat ping, stall detection, self-check, ops docs ([#406](https://github.com/dgloeckner/clubbar/issues/406))

- [ ] Provider-neutral push monitor: `/start` + `/ping` per run, `/fail` on **stall** (oldest unsent > 24 h) — a single hard bounce is explicitly not a `/fail`
- [ ] Self-check rows: last run, oldest backlog, transport, error count — the diagnosis surface, not the alarm
- [ ] Ops docs: scheduler setup per tariff, SPF/DKIM deliverability notes
- Verify: PHPUnit stall threshold; the self-check reports measured state (ADR-0031 rule 3), never intended state

### P7 — Admin UI: send status, retry, mail settings, test mail ([#407](https://github.com/dgloeckner/clubbar/issues/407))

- [ ] Per-member send status and timestamp in the settlement detail; failures visible with their reason
- [ ] A retry button that sets one row back to `pending` — and nothing that orchestrates timing (ADR-0038 rule 4: no polling, no batch loop, no progress driver)
- [ ] Mail settings page for `mail_config`; test-mail action
- [ ] Post-finalize wording is *"N announcements queued, sending"*, never *"N sent"*
- Verify: Playwright per `admin-frontend/patterns/` (test IDs, `useListQuery` where a list is involved); E2E asserts real persistence, not just that a form closed

### P8 — Privacy and retention: erasure covers `mail_outbox`, sent rows pruned ([#408](https://github.com/dgloeckner/clubbar/issues/408))

- [ ] Anonymisation clears `mail_outbox.recipient` in the same transaction that clears `members.email` — otherwise the erasure quietly did not happen
- [ ] Pruning of sent rows; the durable per-member proof stays the settlement-side sent timestamp
- Verify: PHPUnit — after anonymisation no outbox row holds the member's address; pruning leaves the settlement-side timestamp intact

### P9 — Test automation: Mailpit in dev/CI, E2E over the full chain ([#409](https://github.com/dgloeckner/clubbar/issues/409))

- [ ] Mailpit in the dev stack and CI; mirrored into `ghcr.io/dgloeckner/*` via `scripts/mirrored-images.txt` (Docker Hub rate limits — see CLAUDE.md)
- [ ] E2E: finalize → drain → captured mail asserting creditor ID, mandate reference, amount, masked IBAN, itemized statement and a due date ≥ 7 days out
- [ ] E2E: the finalize gate bites with an empty heartbeat and lifts after a run
- Verify: `cd e2etests && npx playwright test` green on all shards; `node scripts/report-failures.mjs` clean

### P10 — Follow-up: payment-request email for `bank_transfer` ([#410](https://github.com/dgloeckner/clubbar/issues/410))

- [ ] Enqueue `payment_request` for `bank_transfer` settlements — amount, club bank details, payment reference; **no** mandate reference, **no** creditor ID
- [ ] Decide whether `write_off` notifies at all (recommendation: no — no money moves, no member action)
- Verify: PHPUnit — a bank-transfer settlement enqueues only `payment_request` rows and the content carries no mandate reference; E2E via Mailpit

## Order and parallelism

P1 and P2 both depend only on P0 and can run in parallel. P0 + P1 shipped together, then P2 + P4 together — the two milestones P1 unblocked, and the pair that leaves the queue full and the content ready with nothing yet able to send it.

**P3 is now the whole of the remaining critical chain**: it is the only sender, and until it exists a finalized settlement queues announcements that stay `pending` for ever. Everything P3 needs is in place — `NotificationsService::claimBatch()` / `recordResult()` for the queue, `SettlementMailBuilder::build()` for the content, `MailTransport` for the sending, and the `cron_heartbeat` row for the run record. What is left is `bin/cron.php`, the `flock`, the wall-clock budget and the URL fallback route.

## Open questions

- **HTTP-API transport** (Brevo/Postmark/…) is deliberately not built; the adapter interface reserves the seat. Routing member data through a processor is an AVV question, not a technical one.
- **The DSN including the SMTP password stays in `config.php`** and is not admin-editable, consistent with the DB password and the TOTP key (ADR-0031 decision 2). Changing the mail server is an installer or file operation.
