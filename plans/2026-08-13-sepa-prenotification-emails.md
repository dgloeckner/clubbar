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

- [x] `backend/bin/cron.php` beside `bin/import-bank-codes.php`: resolves the doc root as `dirname(__DIR__, 2)` so `DataDirectory::resolve()` works in both layouts; `flock` in the data directory; records the **CLI** PHP version and extensions into `cron_heartbeat`
- [x] `DrainService`: claim → render → send → mark, with configurable batch size and wall-clock budget. Claim by `UPDATE … WHERE status='pending' AND next_attempt_at <= NOW() AND (claimed_at IS NULL OR claimed_at < NOW() - INTERVAL 5 MINUTE) LIMIT ?` then select by token — deliberately not `SKIP LOCKED` (MariaDB 10.6+, and the DB version is the host's)
- [x] Transient failure → `attempts + 1` and backoff; cap at 3–5 → `failed` with `last_error`
- [x] URL fallback route with a rotatable secret, header preferred; the bare query-string variant documented as degraded and scrubbed from the access log
- Verify: **passed** — `DrainServiceTest` unit (13) and Feature/MariaDB (8, incl. two drains sending exactly N, a mid-flight claim, a stale claim reclaimed, retry→cap, and budget release), `CronScriptTest` (5, over a real `bin/cron.php` subprocess incl. the `flock`), `CronDrainHttpTest` (8) + `CronDrainDisabledHttpTest` (2), `FileLockTest` (8), `ConfigFileTest` (7), `PhpRuntimeTest` (5); `cron-drain.spec.ts` 7/7 over HTTP; backend suite 1969 (1540 Unit + 429 Feature) with the one pre-existing red (`ServiceFactoryTest::test_getRateLimitMiddleware_is_active_by_default`, the dev compose's `DISABLE_LOGIN_RATE_LIMITING`)

Five decisions in P3 that the issue leaves open, and one thing it turned out to depend on:

| Decision | Why |
|---|---|
| The secret lives in `config.php` (`cron.secret`), generated by `install.php`, and rotation is an edit to that file | ADR-0031 decision 2 puts secrets there with the DB password and the TOTP key, and this is a bearer credential for an unauthenticated endpoint. Rotation takes effect on the next request and nothing derives from or caches it. An admin-editable version would need a second store and belongs with #407's settings page if it is wanted at all |
| Absent secret ⇒ the route answers **404**, not 403 | Most installations schedule the CLI entrypoint. Those should not also carry a public endpoint that drains their queue: unconfigured means switched off, not "mounted and always refusing" |
| A run with no transport, an unusable DSN or no sender address **claims nothing** | Claiming would burn an attempt on every queued message, and three ticks later the whole queue would be `failed` with a `last_error` blaming SMTP for a missing line in `config.php`. The heartbeat is still written — the scheduler genuinely ran, which is the only thing #405's gate asks |
| Rows the wall-clock budget did not reach are **released**, not left claimed | They would otherwise wait out the five-minute stale window for no reason: nothing was attempted on them, so nothing needs backing off. `releaseClaim()` deliberately does not touch `attempts`, unlike #407's retry button |
| `cron_heartbeat` gains `missing_extensions` (migration `027`) | The issue asks for "PHP version and required extensions" and 025 stores only the version. Version alone does not explain the failure that happens: a cron under a PHP without `openssl` fails every send while the same code in the browser works, and the heartbeat otherwise reports a healthy, liveness-green run |
| The drain holds a `MailContentRegistry`, not the settlement builder | #453 generalised the outbox for #438 while this was in review and introduced the registry *for* this milestone — "the drain holds one of these and nothing else about content". Taking the seam as offered means #410 and #438 register a builder instead of editing the sending loop, which is the part that has to stay boring |

| Dependency found | Resolution |
|---|---|
| `scripts/build-package.sh` never copied `backend/bin/`, so the only sender would not have shipped | `bin/cron.php` alone is copied — the rest of `bin/` is developer tooling, and everything under the document root becomes a URL the day `.htaccess` stops being honoured. `cron.php` refuses to run outside the CLI for the same reason |
| The `config.php` → `$_ENV` mapping lived inside `package/index.php`, and a CLI run has no front controller | Extracted to `Shared\Config\ConfigFile`, dependency-free and `require`d by path like `DataDirectory` — two copies would drift on the first key added, and the symptom would be a cron sending with the wrong sender |

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

- [x] Installer prerequisite step 5 (the wizard is six steps now): prints the resolved `php <docroot>/backend/bin/cron.php` and, where `cron.secret` exists, the URL form; a **Check** button behind `install.php?action=check_cron` reads `cron_heartbeat` directly and the outcome is repeated on the completion page — verified, or *"not yet verified"* with what that means
- [x] `SchedulerBanner` on every admin page while unverified, carrying the same command; renders nothing once verified, and nothing at all if the status read fails
- [x] Finalize refused while no run has **ever** been observed — `SchedulerNotVerifiedException` (409, `scheduler_not_verified`), whose message names both the promise it cannot keep and the command that fixes it; a stale heartbeat does **not** re-block
- [x] `cron_heartbeat` stamped in `backend/db/seed.sql` (dev + E2E) and by `DatabaseTestCase::ensureObservedSchedulerRun()` (CI's phpunit job applies migrations only, and migration 025 seeds the row with `last_run_at` NULL — the state that blocks)
- [x] Pre-finalize warning bucket: `members_without_email` on the preview, a subset of `eligible_members`, plus its own read-only section on the New Settlement screen (defense-in-depth for legacy rows pending [#362](https://github.com/dgloeckner/clubbar/issues/362); never blocks)
- Verify: **passed** — `SchedulerStatusServiceTest` (10), `SchedulerGateHttpTest` (6, over a real database and the real middleware: the typed 409, the same request succeeding past the gate once a run is recorded, a two-year-old heartbeat still verifying, and 401 without a session), `SettlementsServiceTest` +6 (refusal before any write, success after, a write-off unaffected, and the three warning-bucket cases), `scheduler-banner.spec.ts` (3). Backend suite **1991 tests, 6 failures — the same 6 the pristine tree produces in this sandbox** (`ServiceFactoryTest::test_getRateLimitMiddleware_is_active_by_default`, the dev compose's `DISABLE_LOGIN_RATE_LIMITING`, plus five `CronScriptTest` subprocess spawns that pass in isolation and fail only under the full run here); `api-tests` 607/607, `admin-chromium` 295/295 + 6 skipped, frontend unit 306/306

Four decisions #405 leaves open:

| Decision | Why |
|---|---|
| The gate is scoped to the methods that **enqueue**, not to every finalize | The issue's own rationale is that "the operation that is refused is precisely the one that makes an announcement promise the system could not keep". A `write_off` moves no money and announces nothing, so blocking it would refuse an operation this installation *can* honour, on the strength of a promise it never made. `bank_transfer` joins the gated branch the day [#410](https://github.com/dgloeckner/clubbar/issues/410) gives it a payment request — one line, and the same line that starts enqueueing for it |
| The refusal is **409 `scheduler_not_verified`**, not a 422 | Nothing about the submitted settlement is wrong, and re-sending it unchanged is exactly what should succeed once the cron is scheduled. The distinct error code is what lets the panel point at the setup instructions instead of at the member list without matching on prose |
| The bucket lists **collected** members only — a member closing out at 0.00 is not in it | They are settled but not collected from, so there is no announcement for them to miss. Excluded members (credit, hold, no mandate) are likewise left out: their own bucket already names the remedy, and the announcement they cannot receive was never going to be sent |
| The installer asks the **database** directly, and never triggers a drain | It holds the credentials it just wrote, so there is no session to invent. And a self-triggered test call would prove the endpoint answers — not that anything is scheduled to call it, which is the only thing the gate asks. The wizard does not block on the answer either: the first tick can be fifteen minutes away, and the outcome is recorded unverified rather than waited for |

| Dependency found | Resolution |
|---|---|
| The banner's browser-level check needs an *unverified* installation, and `cron_heartbeat` is a singleton every Playwright worker shares | `scheduler-banner.spec.ts` routes `/api/admin/scheduler` inside its own browser context instead of clearing the row. Clearing it would block settlement creation for every concurrently running worker — the shared-mutable-state failure Patterns 001 and 004 exist to prevent, in its intermittent form. The gate's server half is asserted over a real database in `SchedulerGateHttpTest`; the full chain through a real drain run stays [#409](https://github.com/dgloeckner/clubbar/issues/409)'s |
| `AppConfig` had no document root, and the setup instructions have to print an absolute path | `AppConfig::$documentRoot`, derived as `dirname(__DIR__, 4)` — the same way `bin/cron.php` derives it, and for the same reason: it is a fact about where the code was unpacked, and a configured copy of it could disagree with reality |

The piggyback middleware is **not** in this plan and never was — #405 dropped it, and the review check for a second sending path holds: `DrainService::run()` has exactly two callers, `bin/cron.php` and `CronController`.

### P6 — Monitoring: heartbeat ping, stall detection, self-check, ops docs ([#406](https://github.com/dgloeckner/clubbar/issues/406))

- [x] Provider-neutral push monitor (`HeartbeatPinger` + `Shared\Http\OutboundHttpClient`/`CurlHttpClient`): `/start` per run, the base URL on a finished run with the counts in the body, `/fail` on an unusable transport, a stalled queue or an aborted run. Configured by `cron.heartbeat_url`; absent = silent. A single hard bounce is explicitly **not** a `/fail`
- [x] Stall detection keys on **overdue**, not age (`QueueHealth`, ADR-0039 decision 5 amending ADR-0038 rule 6): liveness `now − last_run_at > interval × 2`, throughput `next_attempt_at ≤ now − interval × 3`. A row waiting out its backoff is due in the future and cannot trip either
- [x] The interval becomes a declared, verified fact: `mail_config.cron_interval` (`hourly | daily`, migration `029`), cross-checked against the gap between the last two runs (`cron_heartbeat.previous_run_at`); **`weekly` is refused** with a 422 naming Nutzungsordnung § 7 Abs. 3
- [x] Retry ladder in ticks (`RetrySchedule`): `1 × 2 × 4` of `cron_interval`, `MAX_ATTEMPTS = 4`, replacing the flat `RETRY_BACKOFF_SECONDS = 900` that an hourly cron makes dead letter. Applies to every `MailKind`, the Vorabankündigung included — a change to shipped behaviour, not an addition beside it
- [x] `mail_config.drain_batch_size` (default 100), so a club on a stricter relay lowers it without editing a file; `MAIL_DRAIN_BATCH_SIZE` still overrides
- [x] Self-check rows in a new `delivery` category (`MailDeliveryCheck`, appended by `SecurityCheckService`): transport + sender, last observed run, declared vs. observed interval, anything overdue, and how many messages were given up on — measured, never intended
- [x] Ops docs: `docs/deployment.md` gains "Outgoing Mail and the Scheduler" — scheduling per tariff, the CLI/URL forms, why weekly is refused, the heartbeat check and its recommended period/grace, the diagnosis surface, and SPF/DKIM/envelope-sender/bounce/send-limit notes
- Verify: **passed** — `RetryScheduleTest` (7), `QueueHealthTest` (15), `HeartbeatPingerTest` (10), `MailDeliveryCheckTest` (15), `SchedulerStatusServiceTest` +5, `NotificationsServiceTest` +2, `SecurityCheckServiceTest` +3, `DrainServiceTest` unit +9 (each ping path, one rejected address is not an alarm, a backed-off row is not an alarm, no address in a ping body, batch size from `mail_config`), `CronHeartbeatRepositoryTest` (3, over MariaDB — the `ON DUPLICATE KEY UPDATE` ordering that makes `previous_run_at` mean anything), `MailOutboxRepositoryTest` +2, `MailConfigHttpTest` +4 (the weekly refusal and its message, batch-size bounds incl. a numeric string). No test opens a socket and no test sleeps; every predicate takes `now` as a parameter. `security-check.spec.ts` +1 and `mail-config.spec.ts` +2 over HTTP (the five delivery rows in the report, the interval round-tripping through the row rather than the response, and the weekly refusal's message). Backend suite **1577 Unit + 451 Feature**, `api-tests` 615/615, `admin-chromium` 300/300 + 6 skipped, frontend unit 308/308 and `tsc --noEmit` clean — with the one pre-existing red this sandbox produces on a pristine tree (`ServiceFactoryTest::test_getRateLimitMiddleware_is_active_by_default`, the dev compose's `DISABLE_LOGIN_RATE_LIMITING`)

Five decisions #406 leaves open, and one it was written before:

| Decision | Why |
|---|---|
| `cron.queue_stall_hours` (the issue's 24 h dial) is **not** built | ADR-0039 replaced the age predicate with two interval-relative ones while this issue was open. A fixed hour count cannot be right for both an hourly and a daily host, and the thresholds now derive from the one fact that is already declared and cross-checked. One knob fewer, and the remaining one means something |
| A `/fail` reason comes from a **closed vocabulary**, never from the transport's error text | An SMTP rejection quotes the recipient back at you (`550 5.1.1 <someone@example.org> unknown`). The ping is meant to be a pure availability signal with no Art. 28 processing question attached, and a debugging session is exactly when somebody would otherwise paste a failing address in "to see which one it is". `intsOnly()` is the second line of the same defence |
| The success ping carries the counts in its **body** instead of a separate `/log` request | Same numbers, twice the requests, on a path some push providers do not implement. Every provider in the supported set stores and shows a ping body |
| `cron_heartbeat` gains `previous_run_at` rather than a run history table | Two timestamps answer "how far apart do runs actually arrive?", which is the whole of the cross-check. A growing log of cron ticks is precisely the table nobody prunes |
| The observed interval never overrides the declared one | A declaration that turns out to be wrong is a fact about the host somebody has to go and fix in a hosting panel. Silently re-deriving it would hide the disagreement, which is the only thing worth reporting. A scheduler *faster* than declared is not reported at all: it only makes every threshold conservative |
| `mail_config.cron_interval` lands **here** rather than in [#462](https://github.com/dgloeckner/clubbar/issues/462) | ADR-0039 assigns the column to the Deckelauszug migration, but P6 is the milestone that consumes it — the ladder and both stall thresholds are measured in it. Landing the column with its first consumer keeps the two from disagreeing; #462 finds it already there |

| Dependency found | Resolution |
|---|---|
| `SecuritySelfCheck` is dependency-free by contract — `install.php` loads it by path before Composer's autoloader exists — and the delivery rows need the database | The rows are produced by `MailDeliveryCheck` in the Notifications module and **appended** by `SecurityCheckService`, which takes it as an optional collaborator. The category name stays in `SecuritySelfCheck` so there is one list of categories, and a mail check that throws is swallowed: the one moment somebody needs this report is when the installation is broken |
| The retry ladder is a function of the attempt count, which only the row knows | `markFailed()` stopped deciding and started recording: `attemptsFor()` + an explicit `(attempts, retry, backoffSeconds)`. Policy moved to `NotificationsService`/`RetrySchedule` (patterns 004/005), and the repository test now asserts that the queue honours the decision it is handed rather than re-deriving one |
| `max:` in the shared `Validator` measures a *string's length*, so `drain_batch_size: "5000"` sailed past a ceiling of 1000 | Normalised in the controller before the rules run, and only for a string of digits — a looser cast would turn `"2.5"` into `2` and walk past the `integer` rule that exists to reject it ([#117](https://github.com/dgloeckner/clubbar/issues/117)) |

### P7 — Admin UI: send status, retry, mail settings, test mail ([#407](https://github.com/dgloeckner/clubbar/issues/407))

- [x] **Notifications page** (`/notifications`) listing `mail_outbox`, filterable by kind and status, searchable by recipient or member name — the fold-in's replacement for per-settlement-only status, because the Deckelauszug (#462) has no settlement to appear under. Built on `useListQuery` per `admin-frontend/patterns/table-implementation.md`
- [x] Backend to serve it: `GET /admin/notifications` (paginated, filtered, sorted; an unknown `kind`/`status` is **422, not ignored**) and `POST /admin/notifications/{id}/retry` (**409** for a `sent`/`pending`/`superseded` row, with the reason). `QueuedMailDto`, `MailOutboxRepository::search()/countMatching()`, `NotificationsService::search()/retry()/find()`
- [x] Per-member announcement state in the settlement detail's expandable breakdown — queued / sent / failed with the server's words / **no address at all**, which is the case that names who has to be rung up. `notifications[]` rides along on the single-settlement read beside `reversals[]`
- [x] A retry button that sets one row back to `pending` and nothing else. `reload()` after it rather than a local patch: the row's new state is the server's to state
- [x] `MailSettingsTab` on the Settings page: sender, reply-to, header variant, footer, plus `cron_interval` and `drain_batch_size` from P6 — and the **measured, read-only** transport panel. The DSN is not a field and is asserted absent
- [x] Test-mail action — `POST /admin/mail-config/test-mail`, to the **requesting admin's own address only**, fixed content, audited (`mail_test_sent`)
- [x] Post-finalize wording is *"N Ankündigungen eingereiht, Versand läuft"*, never *"versendet"*; the count comes from the create response, which now reads the settlement back so it carries what was queued
- [x] `audit_log.action` gains `mail_retried` and `mail_test_sent` (migration `030`, with a rollback that rewrites existing rows rather than dropping them)
- Verify: **passed** — `NotificationsHttpTest` (14, over a real database and the real routes: the joined member name, each filter, an unknown filter refused, retry asserted **against the row rather than the response body**, the audit entry, and the three not-retryable statuses), `TestMailServiceTest` (10, including the two boundaries that let this endpoint exist at all — the recipient comes from the session and the content is fixed), `notifications-queue.spec.ts` (7) and `mail-settings.spec.ts` (6) over the built frontend. Backend **1645 Unit + 500 Feature**, `api-tests` 615/615, `admin-chromium` 299/299, frontend unit 308/308 and `tsc --noEmit` clean — re-run after rebasing onto [#470](https://github.com/dgloeckner/clubbar/pull/470)'s terminal anomaly detection, on a **freshly seeded** database (the admin suite reads seeded terminal state that 615 API tests in the same database consume; CI gives each shard its own stack)

Four decisions #407 leaves open, and one thing it turned out to depend on:

| Decision | Why |
|---|---|
| The test mail sends **synchronously**, and that does not contradict ADR-0038 rule 3 | Rule 3 is about *queued* messages: announcements must not leave from inside a request, because a gateway timeout mid-loop leaves announcement state nobody can reconstruct, and because the queue is the record of what the club committed to. None of that applies to a message that is never queued, carries no member data, and whose entire value is the error text arriving **while the admin is still looking at the screen**. Queuing it would give the worst of both — an hour's wait for a diagnosis, and an outbox row corresponding to nothing anybody was promised. `DrainService::run()` still has exactly two callers |
| The test mail goes to the **session's** admin address, never to one in the body | Otherwise the endpoint is an authenticated open relay on the club's own domain, which is a considerably better prize than anything else in the panel. Enforced, not documented: there is no parameter |
| An unknown `kind` or `status` filter is **422**, not ignored | Silently dropping it answers a question nobody asked — the whole queue, presented as if it were the failures — and the caller cannot tell that from an empty result |
| `is_retryable` is computed **server-side** and returned on the row | The button and the endpoint would otherwise each decide which rows are eligible, and drift the day a status is added |

| Dependency found | Resolution |
|---|---|
| [#470](https://github.com/dgloeckner/clubbar/pull/470) (ADR-0041) landed mid-review, taking migrations **030 and 031** — and 031 rewrites the very `audit_log.action` enum this milestone extends | This migration is renumbered **032** and now lists 031's two terminal-anomaly actions alongside its own two. `MODIFY COLUMN … ENUM(...)` is a *replacement*, not an addition, so a migration naming only its own values would silently drop the anomaly actions and invalidate every row already written with one. The rollback restores the post-031 state rather than the pre-030 one |
| The same PR added a sixth `MailKind`, `terminal_anomaly_warning` | Added to the OpenAPI enum, the queue page's filter list and both locale files. A kind the server can queue and the queue page cannot name would render its own slug at somebody |
| `Feature\…\DrainServiceTest` asserted "this run failed exactly one message" against a **global** queue, and passed only while the queue happened to be near-empty. The new HTTP tests filled it, and the assertion started reading 101 | The suite now parks foreign `pending` rows a day out for its duration and restores them afterwards — the same measure `MailOutboxRepositoryTest` already takes, for the same reason (Patterns 001/004). A drain claims whatever is due; that is the point of it, and it makes any per-run count meaningless without isolation |

Two things #407 asks for that are **not** here, and why:

| Not built | Why |
|---|---|
| The retry **happy path** as a browser E2E | A `failed` row cannot be produced through the API on a stack with no mail transport: the drain refuses to claim anything, so nothing can fail. That chain needs Mailpit and belongs to [#409](https://github.com/dgloeckner/clubbar/issues/409). The transition itself is covered end to end in `NotificationsHttpTest` against a real database, asserted on the row |
| `statement_cadence` in the settings form, and its switch-on prompt | The column does not exist yet — it lands with [#462](https://github.com/dgloeckner/clubbar/issues/462) step 11.4, together with the migration that sets existing installations to `off`. Adding the control first would be a form field for a setting nothing reads |

### P8 — Privacy and retention: erasure covers `mail_outbox`, sent rows pruned ([#408](https://github.com/dgloeckner/clubbar/issues/408))

- [x] `settlement_announcements` (migration `033`, with rollback and a backfill from existing `sent` rows) — the durable per-member proof that outlives the queue row, carrying settlement, member, kind and the timestamp **copied from** the outbox row. The drain writes it at the moment of delivery
- [x] Anonymisation clears `mail_outbox.recipient` in the same transaction that clears `members.email`, and supersedes anything still `pending` for that member — otherwise the erasure quietly did not happen, or leaves a message the next drain fails to deliver to nobody
- [x] Pruning of `sent` rows at 90 days, keyed on `MailKind` ({@see `MailRetention`}), bounded per pass, run at the tail of a drain — the only unattended process there is
- [x] `GET /admin/settlements/{id}` returns `announcements[]` beside `notifications[]`; the member breakdown prefers the queue row and falls back to the durable one, so the announcement line survives the pruning
- [x] `docs/erm-master.md` gains `settlement_announcements`, `mail_outbox`, `mail_config` and `cron_heartbeat` — table definitions, ER diagram, relationships, referential integrity, the erasure mapping and the retention tiers
- Verify: **passed** — `MailRetentionTest` (7, over a real database, every assertion made by selecting the column rather than trusting the method: both kinds cleared, a bystander untouched, the member-column key that a `subject_id` shortcut would fail, `pending` superseded, `failed` left failed, pruning bounded on status/age/kind/limit), `MemberErasureMailTest` (2, through the real `MembersService::anonymizeMember()` and its transaction), `Feature\…\DrainServiceTest` +4 (the delivered announcement recorded with the queue's own timestamp, a failed send recording nothing, a run that prunes and keeps the settlement record, and a run with **no transport** that prunes anyway — retention does not wait for the mail server), `SettlementAnnouncementTest` +1 (the detail read after the queue row is deleted outright), `NotificationsServiceTest` +4 and the frontend's `settlementMembers` +4. Backend **1649 Unit + 514 Feature**, frontend unit 312/312, `tsc --noEmit` clean

Three decisions this milestone had to make:

| Decision | Why |
|---|---|
| The durable record is a **table**, not a column on `settlement_items` | ADR-0029 words it as "the `settlement_items`-side sent timestamp", which reads as a column. It cannot be one: `settlement_items` is one row per settled *transaction*, so a member with thirty bookings would carry thirty copies of one timestamp with no row meaning "this member was told" — and [ADR-0032](../adr/0032-settlement-lifecycle.md) §6 already rejected exactly that shape for reversals, in those words. `settlement_reversals` is the precedent this follows. **The ADR's phrasing is read as "settlement-side"** (which is what its own rule 2 calls it) rather than amended, since amending an ADR needs the maintainer |
| `recipient` is **cleared**, not the row deleted | The row is the record that the club queued a message; erasure removes the contact data, not the fact. The same split anonymisation already makes on `members`, where the row survives with its name nulled |
| Pruning runs **at the tail of a drain**, and is skipped when the run spent its budget | The scheduler is the only unattended process ADR-0038 allows, so it is the only thing that can carry out ADR-0029's pruning. Sending is the more urgent half; rows that are already ninety days old can wait a tick |

One thing #408 asks about that has **no test yet**: the fold-in comment wants "pruning removes `sent` statement rows past 90 days and leaves `sent` pre-notification rows of the same age untouched". `deckel_statement` does not exist until [#462](https://github.com/dgloeckner/clubbar/issues/462), so the divergence cannot be asserted. What is asserted instead is the property that makes it possible: `pruneSent()` takes a kind and a cutoff, `MailRetention::sentDaysFor()` is a `match` over `MailKind`, and `test_pruning_one_kind_leaves_another_alone` pins that a pass over one kind does not reach another. #462 adds one arm and one assertion.

> **`recipient` is no longer always a member's address.** The admin credential
> hardening ([PR #469](https://github.com/dgloeckner/clubbar/pull/469)) adds
> `admin_email_changed`, which stores an **admin's former** address with
> `member_id NULL` and `admin_user_id` set — the one kind whose recipient is
> deliberately an address the system no longer holds.
>
> This milestone's two mechanisms already handle it correctly, and for reasons
> worth keeping written down rather than rediscovering:
>
> - **Erasure keys on the member**, through `supersedePendingForMember()` and
>   `eraseMemberRecipients()`, so an admin's address is untouched by a member's
>   Art. 17 request. A sweep phrased "every row with a recipient" would have
>   cleared the only record of who a security notice reached.
> - **Pruning is keyed on the kind**, so the new kind had to name its own window
>   rather than inherit one silently. It takes `DEFAULT_SENT_DAYS`: what it holds
>   is an address, and the durable record of the change it announces is the
>   `email_changed` audit entry, which names both addresses and is not pruned.
>
> `MailRetention::sentDaysFor()` is an exhaustive `match` and `pruneDelivered()`
> iterates `MailKind::cases()`, so a kind added without an arm there is not a
> missed policy but an `UnhandledMatchError` on every drain tick. That is the
> right failure — loud, and at the first tick — but it is why adding a kind means
> touching this file.

### P9 — Test automation: Mailpit in dev/CI, E2E over the full chain ([#409](https://github.com/dgloeckner/clubbar/issues/409))

- [x] `axllent/mailpit:v1.30` in the dev stack and in CI, added to `scripts/mirrored-images.txt` (Docker Hub rate limits — see CLAUDE.md; the mirror workflow copies it with `buildx imagetools` when the list changes). Chaos enabled, because a 5xx is the only way to reach a *permanent* delivery failure through the real code
- [x] `scripts/dev-setup.sh` verifies Mailpit **and** that chaos is enabled — a container from an older compose file is healthy, reachable and still refuses the chaos call — and that `bin/cron.php` runs in the backend container
- [x] The drain the suite triggers is `backend/bin/cron.php` in the backend container (`utils/drain.ts`), never a test-only sending path
- [x] `tests/mail/prenotification-chain.spec.ts` (10) over the full chain: every promised field in the delivered message, the seven-day distance from the **server's** today, the text part carrying the same facts, `en` and the `fr`→`de` fallback, two drains leaving one message, the per-member send in the settlement detail, cancel-before/cancel-after, a 550 recorded and the retry after it delivering, and a real CLI run showing up as the verified scheduler
- [x] `utils/mailpit.ts` + `patterns/pattern-010-mail-assertions.md` — the harness [#462](https://github.com/dgloeckner/clubbar/issues/462)/[#463](https://github.com/dgloeckner/clubbar/issues/463) and P10 assert against
- [x] One flake fixed on the way past: `notifications-queue.spec.ts`'s finalize banner compared the banner's count against `data.length` of a **paged** endpoint, so once the suite had seeded more collectable members than fit on a page it compared the count against the page size. It reads `pagination.total` now
- Verify: **passed** — `api-tests` + `api-ordered` + `api-rotation` + `admin-chromium` + `mail-chain` at the default 4 workers with CI's retry policy, on a freshly seeded database: **928 passed, 0 failed, 0 skipped**, `node scripts/report-failures.mjs` clean, and all ten `mail-chain` specs `expected` rather than skipped. Ten of them run the whole chain against a real drain and a real SMTP server in 13s

Four decisions this milestone had to make, and one thing #409 asks for that is not here:

| Decision | Why |
|---|---|
| **The stack's `MAIL_DSN` stays empty; the DSN is handed to the drain that must deliver** (`docker compose exec -e MAIL_DSN=… backend php /app/bin/cron.php`) | #409 asks for the DSN on the stack, and that is the one part not taken literally. A drain claims the **whole** queue, so a transport on the long-running backend makes every drain in the suite a sender — including the six the URL-trigger spec fires while `notifications-queue.spec.ts` asserts a freshly queued announcement is still `pending`. Those assertions would go intermittent for a reason unrelated to what they test, and "cancelled before the drain sends nothing at all" would be unassertable at all. Nothing about the code under test changes: `bin/cron.php` reads the variable through the same `Env`/`AppConfig` path a real installation reads `config.php` with. `MAIL_DSN=smtp://mailpit:1025 docker compose up -d` gives a developer the whole stack delivering into the UI on :8025 |
| The chain runs in its **own ordered project** (`mail-chain`, after `api-tests` and `admin-chromium`) | Two pieces of state nobody owns: the queue, for the reason above, and `mail_config` — a singleton this suite writes the sender and Kassenwart reply-to into, and which `mail-settings.spec.ts` also writes and restores. One of them has to go second |
| The **permanent** failure comes from Mailpit answering **550**, not from an unreachable port | A refused connection is transient by design (`SymfonyMailerTransport` reads the first digit; an absent code counts as "not now"), so the message waits out a ladder measured in scheduler ticks — an hour at the shortest — and lands in `pending`, which the retry button correctly refuses. Only a 5xx produces the `failed` row that #407 left untestable. Mailpit's chaos API is a real server saying no, which is exactly the case being modelled |
| The itemised statement is asserted against **product names over two bookings**, not against a transaction note | `notes` is not on the terminal allowlist (#79, ruling #144 §3), so a synced purchase has none and `itemLabel()` falls back to the type — the settlement factory's note never reaches the mail. Two bookings rather than one because with a single line the line and the total are the same number, and "itemised" is unproven |

| Not built | Why |
|---|---|
| The gate's **refusing** half as an E2E: no `cron_heartbeat` row → finalize refused | The row is a singleton the whole backend shares, and `hasEverRun()` never re-closes. Deleting it would block every settlement any concurrently running project creates — the shared-mutable-state failure Patterns 001/004 exist to prevent, in its intermittent form. It is asserted where it can be: `SchedulerGateHttpTest` over a real database, and `scheduler-banner.spec.ts` over the rendered component. What was missing and *is* here is the other half, which no constructed test could show: a real `bin/cron.php` run is what the panel then reports as verified, `source=cli`, with the CLI's own PHP version — the field that exists because on mass hosting it is frequently not the web PHP |

### P10 — Withdrawn: there is no payment-request email ([#410](https://github.com/dgloeckner/clubbar/issues/410), closed unbuilt)

P10 asked for a *payment request* on `bank_transfer` settlements — amount, club bank details, payment reference — on the premise that "the member has to transfer the money themselves". **That premise is false in this system**, and three places said so before the issue was written:

| Source | Says |
|---|---|
| [ADR-0032](../adr/0032-settlement-lifecycle.md) §4 | `bank_transfer` \| cancellable: **never** \| *"the money already arrived"* |
| `CancellationGate` | refuses with *"This settlement records money the member has already transferred… Reverse it instead."* |
| [UC-A35](../use-cases/admin/UC-A35-manual-settlement.md) | manual settlement covers *"Payments **received** by bank transfer"* |

A `bank_transfer` settlement is the **closing record of money that has already arrived**, so a mail asking for it would ask the member to pay a second time. The implementation was stopped at that point rather than shipped.

The *need* behind the issue is real but small: SEPA can be closed for one member in two ways — no active mandate (`ineligible`), or a collection hold after a bank return (ADR-0032 §7, where the hold exists precisely so no run collects from them again). Both leave a live debt SEPA cannot take.

**Ruling (2026-08-15): the club contacts those members directly — by phone or email — and the system sends nothing.** For a club of this size it is a handful of members a year, and a template plus a trigger plus a retention tier is machinery around a conversation. `write_off` likewise mails nobody: no money moves and there is nothing for the member to do.

- [x] `CONTEXT.md` gains **Settlement method**, whose last line is the rule this cost a slice to learn: *nothing in the system ever asks a member to send money* — with `payment request` in its `_Avoid_` list
- [x] [ADR-0038](../adr/0038-transactional-mail-outbox-on-shared-hosting.md)'s scope table said the opposite (*"a payment request variant … the `kind` value is reserved for it"*) — that row is the sentence that generated #410, and now carries the ruling
- [x] `MailKind::PAYMENT_REQUEST` **removed entirely** rather than left reserved: migration `036` re-declares `mail_outbox.kind` and `settlement_announcements.kind` without it, and the enum case, the builder's arm, the retention arm, the OpenAPI enums, the regenerated frontend types, the queue page's filter and both locale files go with it. A filter offering a kind nothing can produce is a promise the product does not keep
- Verify: PHPUnit green; a queue filter for `payment_request` is refused as an unknown value like any other; `mail-chain` unaffected

### P11 — Deckelauszug: the as-of predicate, the period model, the scheduled enqueue ([#462](https://github.com/dgloeckner/clubbar/issues/462))

A **Deckelauszug** is a periodic statement of a member's Deckel — sent to every active member on a fixed calendar boundary regardless of what they owe, announcing nothing and collecting nothing. [#361](https://github.com/dgloeckner/clubbar/issues/361) §5 deferred it (*"statement mail optional, out of scope"*); [ADR-0039](../adr/0039-periodic-deckel-statement.md) brings it in scope, because the gap it leaves is not a comfort problem: `CreditLimit::LIMIT_CENTS` is €100 and the terminal refuses the next checkout past it, offline, in front of whoever is in the queue behind them.

This milestone is the machinery. **Content, cadence UI and the Mailpit chain are P12 ([#463](https://github.com/dgloeckner/clubbar/issues/463))**, which is why every step below can be verified with no mail server present at all.

- [x] **11.1 — [ADR-0039](../adr/0039-periodic-deckel-statement.md)** amending ADR-0038 (time-triggered enqueue; a *live* value in a queue whose no-body rule was justified by immutability; interval-relative alarming; retention by kind), ADR-0031 (the interval is a declared host fact, `weekly` refused) and ADR-0029 (retention varies by kind). Landed with the glossary entries in `CONTEXT.md`
- [x] **11.2 — `UnsettledTransactions::unsettledAsOf(\DateTimeImmutable $t)`** plus `balanceAsOf()`. Takes the instant explicitly and never reads the clock — the testability seam for everything below, since this codebase has no `Clock` to inject. `UnsettledAsOfTest`: the whole-table equivalence with `UNSETTLED` at `t = now` that ADR-0039 makes the condition of having two definitions at all, and the settle/cancel timeline asserted at 1 Aug (in), 5 Aug (out) and 15 Aug (in again)
- [x] **The reversal clause the equivalence test found.** A claim is released two ways — the settlement is cancelled (#142 §3), or *one member's* collection is reversed (#148 §1, `releaseMemberClaims()` nulls `active_transaction_id` and leaves the settlement live). A dated predicate that knew only about `settlements.cancelled_at` would keep reporting a reversed member as collected from, understating the Deckel of exactly the member whose money came back. `settlement_reversals` is unique per (settlement, member) and dates it
- [x] **11.3 — `StatementPeriod` + `StatementCadence`.** Pure, no clock, no I/O: cadence + instant → key (`2026-08`, `2026-Q3`), boundary (the period's *own start*, the „Stand: 1. August 2026" the mail prints), and `isCurrentAt()` — the catch-up cap that stops a scheduler which was off for a year producing twelve mails per member on the day it returns. `StatementPeriodTest` (23 cases with 11.2) covers month ends, the leap day, quarter edges, an instant arriving in a non-UTC zone, and refusing a key of the wrong shape for the cadence
- [x] **11.4 — migration `039_deckel_statement.sql`** (+ rollback): `mail_outbox.kind` gains `deckel_statement`; `mail_config` gains `statement_cadence ENUM('off','monthly','quarterly')`. `MailOutboxSchemaTest` gains the one-per-member-per-period pair; `MailConfigSchemaTest` is new. `cron_interval` and `drain_batch_size`, which #462 lists here too, arrived early with 029 in P6
- [x] **The column defaults to `monthly` and the migration then sets the row to `off`** — deliberately, and it is the one place #462 flagged for confirmation. The DEFAULT states ADR-0039 decision 3's intent (a feature nobody has to discover); the UPDATE states what running a migration may do, which is never "mail a live membership before anyone has read a release note". It reaches a fresh install too, because the singleton is created by 024 and every install runs every migration — the more conservative reading, and the right one while the content builder is still P12
- [x] **11.5 — `MailKind::DECKEL_STATEMENT`, `MailSubject::MEMBER`.** `addressesMember()` became an explicit `match`: it read `subjectType() === MailSubject::SETTLEMENT`, true of every kind that existed and **false** for the first member-addressed kind that is not about a settlement. `MailRetention` gains `STATEMENT_SENT_DAYS` — the same 90 days for a different reason, free to move without dragging the announcements with it (ADR-0039 decision 6)
- [x] **11.6 — `StatementRecipientsRepository`.** `StatementScopeTest`, one case per ruling: active+zero **in** (a statement that only arrives when you owe is a nudge wearing a statement's clothes), active+credit **in**, inactive+zero out, inactive+owing **in** (deactivating somebody does not cancel what they owe), no address → out with **no exception and no log line**. Scope is judged at the boundary, like the number it will state, so scope and content cannot disagree
- [x] **11.7 — `PeriodicEnqueueService`.** No transaction, no cursor, no resume state and no `statement_runs` entity: it inserts and lets `UNIQUE (kind, subject_id, dedup_key)` refuse the duplicate. A pass killed halfway is finished by the next tick at no cost. `PeriodicEnqueueServiceTest` asserts idempotency **at the database** — a test that checked the service "looked first" would pass against the very race the index exists to make impossible
- [x] **11.8 — `bin/cron.php` runs the enqueue before the drain**, so a statement queued by a tick leaves on that tick; `--period YYYY-MM` names the period explicitly. `CronScriptTest` gains four cases through the command a crontab actually runs, including two ticks leaving one row per member
- [x] `statement_cadence` reaches the API (`MailConfigRepository::UPDATABLE_COLUMNS`, the controller's rule list, `MailConfigDto`, both OpenAPI schemas). Without it the feature has no switch — P12 builds the UI on top
- Verify: **passed** — `docker compose exec -w /app backend ./vendor/bin/phpunit`, 2246 tests. Feature 554/554 green; Unit green but for `ServiceFactoryTest::test_getRateLimitMiddleware_is_active_by_default`, which fails identically on a clean checkout in this container because `docker-compose.yml` sets `DISABLE_LOGIN_RATE_LIMITING: "true"` and the test's `unset($_ENV[...])` cannot undo a real environment variable. No test opens a socket

**Deliberately not here** (P12/#463): the mail itself — itemised, netted, capped at 100 lines with the total computed over the full set — the cadence UI, and the Mailpit chain. Until P12 lands, `statement_cadence` is `off` everywhere and nothing enqueues, which is the state migration 039 leaves on purpose.

### P12 — Deckelauszug: the mail itself, the cadence switch, the Mailpit chain ([#463](https://github.com/dgloeckner/clubbar/issues/463))

P11 built the machinery and left it switched off. This is what a member actually reads, and the switch that lets a club turn it on.

- [x] **12.1 — `DeckelStatementService` + `DeckelStatementRepository`.** `(member, boundary)` in, one `DeckelStatementDataDto` out. **Netting, the cap and the total are one pass over one set**, so „the printed lines and the total cannot disagree" is a property of the shape of the code rather than an assertion somebody has to keep making. `DeckelStatementDataTest` is a timeline per rule, against real MariaDB: a netted pair vanishes from both the lines and the total; a Storno booked *after* the boundary does not net against the period being stated (and does on the next one); 150 bookings print 100 lines, report 50 omitted and total over all 150; a cleared tab produces no lines; a credit is a negative total
- [x] **The orphaned Storno names what it reverses.** A Storno whose original was collected in an earlier settlement has nothing left to net against, and its original is by definition *not* in the as-of set — so the label needs a second, small read: „Storno Bier (aus Abrechnung 07/2026)". Folding it into the first query would mean stating the dated predicate twice in one statement with its four bound values interleaved; as two reads it is legible, and the second usually does not run
- [x] **The invariant, asserted directly**: the sum of the netted lines equals `UnsettledTransactions::balanceAsOf()` for the same member and instant. Two independently written aggregates over the same money, pinned together — so a netting bug fails one test rather than whichever case somebody remembered
- [x] **12.2 — `DeckelStatementMail`, `MailStrings` de/en, `DeckelStatementMailBuilder`.** A **new builder registered in `MailContentRegistry`**, not a branch in the drain — this is the first kind whose `subject_id` is the *member*, and the seam ADR-0038 built for it holds without the sending loop changing. `DeckelStatementMailTest`: 21 cases across both languages and both parts, half of them **negative** — no mandate reference, no Gläubiger-ID, no IBAN, no Fälligkeit, no „Mahnung". A statement carrying any of those has not rendered a wrong field, it has become a collection notice
- [x] **`StatementPeriod::fromStoredKey()`** — the send direction reads a queued row's period *without* being told the cadence. `fromKey(cadence, …)` is right at enqueue, where the cadence is the question; at send it would strand every statement queued as `2026-08` the moment a club switched to quarterly, permanently, over a setting changed after the row was written
- [x] **The note box's heading travels with its sentence.** „Dein Limit: Es steht nichts offen" is a small piece of nonsense, and it is the first thing a member with a clear tab would read. Credit gets „Guthaben", zero gets „Offener Betrag", the three limit bands get „Dein Limit"
- [x] **12.3 — the preview script** gains five Deckelauszug variants per language (`ok`, `approaching`, `credit`, `empty`, `capped`). Every one but `capped` states the sum of what it prints, because a preview whose column does not add up is spent wondering whether the renderer is broken instead of judging the wording — and `capped` is the exception on purpose, since showing that the cap explains itself is the whole reason it exists
- [x] **12.4 — the cadence reaches the admin panel.** The DTO, the validator and both OpenAPI schemas arrived with P11; what was missing was the control, so an installation had no way to turn the feature on. `MailSettingsTab` gains a `off | monthly | quarterly` select (`settings-mail-statement_cadence`) with de/en strings, placed below the scheduler dials rather than beside them: it is the one field on that page whose effect is *mail to the entire membership*
- [x] **12.5 — the chain over Mailpit.** `tests/mail-statement/deckel-statement.spec.ts`: cadence on → `bin/cron.php --period` → the delivered message. The Stand date in the subject, the itemised lines, the total, the negative assertions again at the far end of the chain — and **a second cron run leaving exactly one message in the mailbox**, which is the only place the idempotency guarantee is observed the way a member would experience its failure. Plus the zero-tab statement and the `en` variant, the latter proving the language survives three hops: the member record, `mail_outbox.language`, and the builder reading it back
- [x] **Its own Playwright project, and that is the finding.** `fullyParallel: false` serialises the tests inside a file; Playwright still runs two files of a project on two workers. Harmless for announcements, which only reach the member a spec finalized against — **not** harmless here, because a Deckelauszug goes to every member in scope, so while the cadence is on, `prenotification-chain.spec.ts` would find two messages where it waits for one, and fail for a reason invisible in its own file. `mail-statement` depends on `mail-chain`, so the cadence window is this project and nothing else; CI lists it explicitly
- [x] **Bookings are backdated behind the boundary** in the E2E fixtures. A statement dated the 1st states the tab as it stood *then*, so a purchase written at test time is correctly absent from it — which is also the only way that file could have a non-empty list to assert on. Terminal sync stores `created_at` as sent, so it needs no database access
- [x] **The bug CI found, and the one the local run hid.** A delivered Deckelauszug tried to write a `settlement_announcements` row: `recordAnnouncement()` guarded on `addressesMember()` alone, which was sufficient while every member-addressed kind was *also* about a settlement — the same trap `MailKind::addressesMember()` documents one level up, sprung in the place that consumed it. The row put a member id in `settlement_id` under a `kind` the enum does not have, MariaDB's truncation warning came back as a `PDOException`, and `DrainService::run()` caught it as an aborted run **after the message had gone out**. One statement left per tick; the other 199 in the batch stayed claimed; the run reported `claimed=0 sent=0`. The guard now asks what the record actually is — member-addressed *and* about a settlement — and the write is wrapped, so the promise the docblock already made for a missing `sent_at` (*"failing the drain over a lost bookkeeping row would turn one missing record into a batch that never went out"*) covers the write itself
- [x] **An aborted run no longer prints an idle run's line.** `DrainResultDto::aborted()` carries the reason, `summary()` says `ABORTED …` instead of all-zero counters — zero would be a claim about the queue, and the run never established one — and `bin/cron.php` writes it to stderr. This is why the bug survived a local run: the E2E passed three times because the abort happened *after* the test's own message, and the command line said nothing. `drainMailQueue()` now captures stderr and **throws** on `ABORTED`, so no chain spec in either mail project can be green over a stopped queue again
- [x] Verified at CI's scale rather than the dev stack's: 430 members seeded, one run — `claimed=430 sent=430 budget_exhausted=no duration=4.95s`. Before the fix the same queue produced `claimed=0 sent=0` and one delivered message
- Verify: **passed** — Feature **569/569** and Unit **1774/1775** in the container (`ServiceFactoryTest::test_getRateLimitMiddleware_is_active_by_default` fails identically on a clean checkout, because `docker-compose.yml` sets `DISABLE_LOGIN_RATE_LIMITING`); `mail-statement` 3/3, `mail-chain` 10/10, `mail-settings` 6/6 on admin-chromium, mail API specs green, 312 frontend unit tests, `tsc --noEmit` clean. No test opens a socket except through the drain

### P13 — the scheduler dials describe the scheduler people actually have ([#473](https://github.com/dgloeckner/clubbar/issues/473))

Both dials were sized against one host. ADR-0031 names IONOS as the reference; migration 029 declared the interval as a fact and `DrainService` picked a 50-second budget under IONOS's own 60-second cron cap. The compatibility investigation in [PR #472](https://github.com/dgloeckner/clubbar/pull/472) then established what that panel actually offers — a webcron with a monthly/weekly/daily wizard and no field for a custom header — and therefore that a real installation is quite likely driven by an **external HTTP scheduler** instead: minute-level cadence and a genuine header field, but a request timeout of its own, commonly 30 seconds. Neither column could describe that host.

- [x] **13.1 — `CronInterval::FIFTEEN_MINUTES`** (900s), the cadence `bin/cron.php` has recommended since P3 and that until now had to be *declared* as `hourly`. That was safe — every threshold is `interval × ticks`, so a slower declaration only makes the ladder patient and the alarm late, and `intervalDisagrees()` deliberately never flags a cron *faster* than declared — but it described a machine four times slower than the real one, which is the gap 029 existed to close. `fromObservedGap()` gains the matching branch; the window in which a gap says nothing narrows from an hour to about eleven minutes, which costs nothing because the only consumer reports a cadence slower than declared
- [x] **`DEFAULT` deliberately stays `hourly`.** It is no longer the eagerest value and must not be: guessing *faster* than reality sets the liveness threshold to half an hour on an installation that genuinely runs hourly, and an alarm firing every hour on a healthy host is the wolf-crying ADR-0038 forbids. `hourly` is the value that is wrong in the survivable direction against both neighbours
- [x] **The tables, extended rather than assumed to generalise.** `RetryScheduleTest` gains the 15/30/60-minute ladder and the property that the whole ladder finishes inside one hourly rung; `QueueHealthTest` gains the 30-minute liveness and 45-minute throughput windows, the quarter-hour observed gap, the hand-run drain that now classifies as the fastest case and still disagrees with nothing, and the disagreement this makes reportable for the first time — declared `fifteen_minutes`, observed hourly. New `CronIntervalTest` pins the seconds table, the fastest-first ordering `intervalDisagrees()` compares by, and that `weekly` is still refused
- [x] **13.2 — `mail_config.drain_budget_seconds`**, the same shape as `drain_batch_size` in 029: column, `MailConfigDto` field with `DEFAULT`/`MIN`/`MAX`, controller validation (10–55), `UPDATABLE_COLUMNS`, and `DrainService` reading it through the same `$flag ?? $env ?? $config` precedence. `ServiceFactory` stops pinning the budget from the environment so the database layer is reachable at all
- [x] **The default drops 50 → 25, and the asymmetry is what decides it.** A budget the trigger's own timeout undercuts means a run killed *mid-send*, whose claimed rows come back after the five-minute stale window and are offered to the transport again — one announcement delivered twice. A budget that is too low costs nothing but throughput: the run stops cleanly, releases what it did not reach, and the next tick takes it. So the shipped default is safe under the **tightest** trigger timeout we know of rather than the most generous, and the 55-second ceiling keeps a raised value under IONOS's 60
- [x] **13.3 — migration `040`** (+ rollback) modifies the enum and adds the column. The rollback rewrites `fifteen_minutes` to `hourly` *before* the `MODIFY`, because `MODIFY COLUMN … ENUM(…)` truncates an omitted value to the empty string rather than refusing it — the same trap 039's rollback documents. `MailConfigSchemaTest` asserts the three-value enum, that `weekly` is still unstorable, and that the shipped default sits below 30 seconds
- [x] **13.4 — the admin panel and the API.** `CRON_INTERVALS`, a `settings-mail-drain_budget_seconds` control beside the batch size, de/en strings, both OpenAPI schemas and the regenerated orval client. `MailConfigHttpTest` gains the `fifteen_minutes` round-trip and the budget's bounds (including the numeric-string case `max:` would otherwise walk past); `mail-settings.spec.ts` sets both from the form and reads them back **from the row**, and the interval dropdown's option count moves from 2 to 3
- [x] **13.5 — the docs stop recommending a value they could not name.** `docs/deployment.md`'s "every 15 minutes is the recommendation" now wires to the case that says so, PR #472's cron-job.org note says *declare every 15 minutes* instead of *declare hourly*, the IONOS section's "50 seconds, nothing to configure" becomes 25 with a note that this is the one host where raising it buys a longer run, and `config.sample.php` says both dials now live in the panel and that a file entry overrides it
- **Task 3 of #473 is not here — it shipped early in [PR #472](https://github.com/dgloeckner/clubbar/pull/472)**, where the owner made the (a)/(b) call the issue asked for: **(b)**, admin-rotatable from `mail_config`, kept as a credential (hash-only, step-up gated, shown once) rather than a plain form field. `MailConfigService`'s precedence — a rotated hash supersedes `config.php`'s `cron.secret` — is orthogonal to both dials above and needed no revisiting
- Verify: **passed** — `phpunit` 2370 tests, failures only in the classes that spawn subprocesses (`CronScriptTest`, `CheckCoverageScriptTest`, `CheckPatchCoverageScriptTest`) plus `ServiceFactoryTest::test_getRateLimitMiddleware_is_active_by_default`; all four are environmental and reproduce on a clean checkout in this container — `CronScriptTest` passes 9/9 on a freshly recreated container, and the rate-limit one fails because `docker-compose.yml` sets `DISABLE_LOGIN_RATE_LIMITING`. Notifications + schema + ServiceFactory in isolation: 477 tests, that one failure. `api-tests` + `admin-chromium` **940/940**, `mail-chain` 10/10, `mail-statement` 3/3, frontend unit 327/327, `tsc --noEmit` and `eslint` clean. Migration 040 verified forward → rollback → reapply against the live MariaDB

## Order and parallelism

P1 and P2 both depend only on P0 and can run in parallel. P0 + P1 shipped together, then P2 + P4 together — the two milestones P1 unblocked, and the pair that leaves the queue full and the content ready with nothing yet able to send it.

**P3 is now the whole of the remaining critical chain**: it is the only sender, and until it exists a finalized settlement queues announcements that stay `pending` for ever. Everything P3 needs is in place — `NotificationsService::claimBatch()` / `recordResult()` for the queue, `SettlementMailBuilder::build()` for the content, `MailTransport` for the sending, and the `cron_heartbeat` row for the run record. What is left is `bin/cron.php`, the `flock`, the wall-clock budget and the URL fallback route.

## Open questions

- **HTTP-API transport** (Brevo/Postmark/…) is deliberately not built; the adapter interface reserves the seat. Routing member data through a processor is an AVV question, not a technical one.
- **The DSN including the SMTP password stays in `config.php`** and is not admin-editable, consistent with the DB password and the TOTP key (ADR-0031 decision 2). Changing the mail server is an installer or file operation.
