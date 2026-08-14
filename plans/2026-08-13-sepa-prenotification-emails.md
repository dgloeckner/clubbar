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
| Validation is `Validator` rules in the controller, not a Form Request class | `backend/patterns/pattern-001` describes Form Requests, but the codebase contains **none** — every controller, `InstanceConfigController` included, validates this way. Consistency with the code that exists beat consistency with the pattern doc; worth reconciling one way or the other, but not in this PR |

### P2 — `mail_outbox`, Notifications module, transactional enqueue ([#402](https://github.com/dgloeckner/clubbar/issues/402))

- [ ] Migration `mail_outbox` per ADR-0038's schema table, with `UNIQUE (kind, settlement_id, member_id)` and **no body column**; `cron_heartbeat` singleton in the same migration
- [ ] `backend/src/Modules/Notifications/` per ADR-0018 and `backend/patterns/`: repository + service with `enqueueForSettlement`, `claimBatch`, `markSent`, `markFailed`, `supersedePending`
- [ ] Enqueue inside the **existing** `createSettlement` transaction — one row per collected member (`amount > 0`), language from `members.preferred_language` with `de` fallback; credit / zero / no-email members get no row
- [ ] `cancelSettlement`: supersede unsent announcements, enqueue `cancellation_notice` only where the announcement is `sent`
- [ ] Audit entries (ADR-0013) for enqueue and cancellation-notice creation
- Verify: PHPUnit — finalize twice yields exactly one row per member (by constraint, not by lookup); cancel-before-drain supersedes with zero notices; cancel-after-send notifies only those who got one; an enqueue failure rolls back the settlement with it

### P3 — Cron drain: CLI entrypoint, claim/backoff, `flock`, URL fallback ([#403](https://github.com/dgloeckner/clubbar/issues/403))

- [ ] `backend/bin/cron.php` beside `bin/import-bank-codes.php`: resolves the doc root as `dirname(__DIR__, 2)` so `DataDirectory::resolve()` works in both layouts; `flock` in the data directory; records the **CLI** PHP version and extensions into `cron_heartbeat`
- [ ] `DrainService`: claim → render → send → mark, with configurable batch size and wall-clock budget. Claim by `UPDATE … WHERE status='pending' AND next_attempt_at <= NOW() AND (claimed_at IS NULL OR claimed_at < NOW() - INTERVAL 5 MINUTE) LIMIT ?` then select by token — deliberately not `SKIP LOCKED` (MariaDB 10.6+, and the DB version is the host's)
- [ ] Transient failure → `attempts + 1` and backoff; cap at 3–5 → `failed` with `last_error`
- [ ] URL fallback route with a rotatable secret, header preferred; the bare query-string variant documented as degraded and scrubbed from the access log
- Verify: PHPUnit — two concurrent drains send exactly N, never N+1; retry then cap; a stale claim is reclaimable. Integration: `flock` blocks an overlapping run. API: the URL route rejects a wrong secret and returns no data on success

### P4 — Mail content: pre-notification, cancellation notice, de/en, preview script ([#404](https://github.com/dgloeckner/clubbar/issues/404))

- [ ] Pre-notification content: creditor name/address + Gläubiger-ID, mandate reference, exact amount, due date, masked IBAN (last 4), itemized statement (the § 7 Abs. 1 Abrechnungsübersicht), 6-week Beanstandung hint, reply-to Kassenwart
- [ ] Cancellation notice variant (*„Einzug entfällt"*)
- [ ] de/en per `preferred_language`, `de` fallback (ADR-0002)
- [ ] Preview script after `frgs-rewrite/scripts/preview-ruderkurs-mails.php`, writing every variant to `storage/mail-vorschau/` (gitignored)
- Verify: PHPUnit content assertions per field and per language; the text part states everything the HTML part states

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

P1 and P2 both depend only on P0 and can run in parallel — P0 and P1 shipped together, so P2 is unblocked and is now the head of the critical chain: **P2 → P3 → P9**.

## Open questions

- **HTTP-API transport** (Brevo/Postmark/…) is deliberately not built; the adapter interface reserves the seat. Routing member data through a processor is an AVV question, not a technical one.
- **The DSN including the SMTP password stays in `config.php`** and is not admin-editable, consistent with the DB password and the TOTP key (ADR-0031 decision 2). Changing the mail server is an installer or file operation.
