# ADR-0038: Transactional Mail Outbox on Shared Hosting

**Status**: Accepted

**Date**: 2026-08-14

**Amends**: [ADR-0029](./0029-two-tier-retention-and-erasure.md) (the outbox is a second place a member address lives, and it is in scope for erasure), [ADR-0031](./0031-production-hardening-on-shared-hosting.md) (adds the first accepted hard dependency on a host feature), [ADR-0032](./0032-settlement-lifecycle.md) (enqueue belongs to the create transaction; cancellation supersedes)

---

## Context

The backend sends no email at all. `SettlementLeadTime::DAYS = 7` models the SEPA pre-notification as **date arithmetic** — an execution date at least seven days out — and stops there. Nobody is told anything.

Two commitments already made assume the opposite:

| Commitment | What it promises |
|---|---|
| Nutzungsordnung Vereinsbar § 7 Abs. 3 | Every collection is **announced by email at least 7 days before the due date** (the 14-day SEPA default, shortened to 7 by agreement) |
| Nutzungsordnung Vereinsbar § 7 Abs. 1 | An **Abrechnungsübersicht** per email — the itemised statement behind the amount |
| Registration form (statically hosted) | The **Mandatsreferenz** reaches the member *„mit der Vorabankündigung zum ersten Einzug per E-Mail"* — EPC requires creditor ID and mandate reference to reach the debtor before the first collection |

So the announcement is not a nice-to-have on top of the settlement; it is part of what makes the collection a legitimate one, and the club has written down that it happens.

### Why this is not just "call the mailer at finalize"

The deployment target is a mass-hosting account (ADR-0031): no root shell, no queue daemon, no supervisor. The naive design — loop over members inside the finalize request and send — fails on this host in three separate ways, and only the third is obvious.

| Failure | Why it bites here |
|---|---|
| **Gateway timeout** | Not `max_execution_time` — PHP does not count time spent in socket waits on Unix. It is the host's FastCGI/gateway **read** timeout, which is set outside our reach and cannot be raised from code. Fifty SMTP handshakes will exceed it on some tariffs and not others, and we do not get to find out which |
| **Greylisting** | Many receiving MTAs reject the *first* delivery attempt on purpose and expect a second one ~15 minutes later. This is ordinary operation, not an error. A send path with no retry loses those mails permanently |
| **Partial finalize** | An aborted request mid-loop leaves a settlement that exists with an unknown number of announcements out. There is no state to resume from, and a retry re-sends to whoever already got one |

The third is the one that decides the architecture. A finalize that performs network calls **can half-succeed**, and a half-succeeded finalize is not repairable — the club cannot tell who was told.

Decided in [#361](https://github.com/dgloeckner/clubbar/issues/361) (2026-08-13), implemented across [#400](https://github.com/dgloeckner/clubbar/issues/400)–[#409](https://github.com/dgloeckner/clubbar/issues/409).

## Decision

**Finalizing a settlement enqueues messages inside the settlement's own database transaction and makes no network call. A scheduler drains the queue, and it is the only sender.**

### 1. Enqueue at `createSettlement`, in the same transaction

`createSettlement` is what [#361](https://github.com/dgloeckner/clubbar/issues/361) calls "finalize" — ADR-0032 has no such term — and it is the single point where `SettlementLeadTime::DAYS = 7` guarantees the announcement distance **by construction**: the execution date it accepts is already at least seven days out, so a message enqueued now cannot be late by the club's own rule, whenever it actually leaves.

Settlement, items and outbox rows commit together or not at all. Two consequences follow, and both are the point:

- There is no half-finalize. The queue either describes a settlement that exists, or neither exists.
- **Idempotency lives on the message, not on the settlement**: `UNIQUE (kind, settlement_id, member_id)`. A finalize retried after a lost response cannot produce a second announcement, and it does not need a lookup-then-insert race to avoid one — the database refuses.

### 2. Transport is an adapter behind one DSN

One configuration field, `mail.dsn`, selects the transport, mirroring the existing `LlmClients` / `VisionClients` shape:

| DSN | Transport | Where it applies |
|---|---|---|
| `smtp://user:pass@host:port` | SMTP over `symfony/mailer` | The normal case; the club's own mailbox |
| `sendmail://default`, `native://default` | The host's local MTA | Tariffs that provide one and block outbound 587 |
| *(absent)* | `NullTransport` — logs a warning, sends nothing, never throws | Unconfigured installs and CI. **No test opens a socket** |
| `api://…` (later) | HTTP API bridge (Brevo, Postmark, …) | Interface reserved, **not built** — routing member data through a processor is an AVV question, not a technical one |

The DSN including the SMTP password stays in `config.php` in the data directory, alongside the DB password and the TOTP key (ADR-0031 decision 2). It is deliberately **not** admin-editable: changing the mail server is an installer or file operation. What *is* admin-editable is club data — sender name, sender address, reply-to (Kassenwart), header variant, Impressum block — in a `mail_config` singleton next to `sepa_config`.

### 3. A scheduler is a hard prerequisite, and the only sender

Not a fallback layer, not best-effort infrastructure. An installation with no scheduler cannot keep the § 7 Abs. 3 promise, so **it must not pretend to**.

Satisfied by any one of:

| Mechanism | Preference |
|---|---|
| Panel cron → CLI entrypoint (`backend/bin/cron.php`) | **Preferred** — no gateway timeout, no secret in a URL |
| Panel cron → URL endpoint with a rotatable secret, header preferred | Supported; the bare query-string variant is documented as degraded and scrubbed from the access log |
| External HTTP scheduler calling the same URL | Supported — this is what keeps the hosting set from actually narrowing |

**There is exactly one sending path.** No inline send, no piggyback drain, no UI-driven batch loop. Three candidates were considered and dropped:

- *Inline send after `fastcgi_finish_request()`* — runs only under FPM, so it needs feature detection, and it would execute perhaps twelve times a year. That is the path that rots unnoticed while the cron path is proven every fifteen minutes. Seconds instead of minutes is worth nothing against a seven-day deadline.
- *A self-disabling piggyback drain* on admin requests, active only when the last cron run is older than 30 minutes. Rejected on 2026-08-13 with the scheduler decision: building a workaround for a requirement you have just declared non-negotiable converts a hard failure into a soft one and removes the pressure to fix it.
- *A UI-driven drain.* See rule 4.

### 4. No scheduling logic in the UI

The line is: **the UI may change state; it may never orchestrate time.**

| Allowed | Forbidden |
|---|---|
| A "retry" button that sets one row back to `pending` | A loop that requests batches and counts progress |
| Showing per-member status as it stands right now | Polling until the queue empties |

So after a finalize the admin panel says *"47 announcements queued, sending"* — not *"47 sent"*, because that is the only statement the server can support at that moment.

### 5. Render at send; store no body

The outbox has no body column. Content is rendered from settlement data at send time.

This is safe precisely because ADR-0032 makes the settlement append-only: the amount, the line items and the execution date cannot drift between enqueue and send. And the GoBD requirement stated in #361 is that the per-member Abrechnung be **reproducible from stored settlement data** for the retention period — not that the email be archived.

What *is* snapshotted is the **recipient address**, because that is the one fact that is not reproducible later: it is proof of who was announced to, and `members.email` can change or be erased afterwards.

### 6. Best effort per message, never invisible in aggregate

Delivery of any single mail is explicitly best effort. A late or failed pre-notification does not make the collection unauthorised — the member's eight-week no-questions refund right (§ 675x BGB) stands regardless, so there is no blocking re-verification and no delivery guarantee.

The price of rules 1–3 is that a **stalled queue silently erodes the very distance the feature exists to promise** — a synchronous send could not fail that way, because it would fail loudly, in the treasurer's face. So monitoring is part of this decision, not an extra:

| Signal | Semantics |
|---|---|
| Heartbeat `/start` + `/ping` | Every drain run, from an external push monitor (healthchecks.io or equivalent, configured provider-neutrally) |
| `/fail` on **stall** | Oldest unsent message older than 24 h. A cron that starts reliably and fails at the SMTP handshake every time would otherwise hold the check green — liveness alone is not health |
| **Not** `/fail` | A single hard bounce. A switch that cries wolf for three weeks is a switch someone turns off |

The alarm channel must not depend on what it monitors: *"SMTP is dead"* delivered over the dead SMTP arrives nowhere. Hence an external monitor for notification, and the in-app self-check (last run, oldest backlog, transport, error count) purely as the **diagnosis** surface — an alarm with no diagnosis attached only produces a phone call.

### 7. The scheduler is verified at install, and it gates finalize

A scheduler cannot be "tested" by the installer: it runs once, interactively, while the first tick may be up to fifteen minutes away, and a self-triggered call would only prove the endpoint answers — not that anything is scheduled to call it. So verification is split in two:

| Stage | Behaviour |
|---|---|
| Installer prerequisite step | Shows the exact command/URL for this installation and a **Prüfen** button polling `cron_heartbeat`; green once a real run has been observed |
| Finishing without a green check | Allowed — nobody should have to stare at an installer — but recorded as an explicitly unverified state |
| Admin panel while unverified | Banner with the instructions, and **settlement finalize is blocked** |
| After the first observed run | The gate lifts permanently |

The gate keys on *"a run has **ever** been observed"*. A scheduler that dies **later** does not re-block finalize — that is #406's warning, not a lockout. Refusing to collect at the moment the treasurer needs to is a worse failure than announcing late.

### The resulting layers

In the style of ADR-0031's own table:

| Layer | Mechanism | What belongs here | If it is missing |
|---|---|---|---|
| L0 | Outbox row written inside the settlement transaction | The intent to announce, and its uniqueness key | Cannot be missing — it commits with the settlement or the settlement does not exist |
| L1 | Scheduler (CLI cron, URL cron, external) → `DrainService` | Claim, render, send, retry with backoff | **Nothing is ever sent.** Blocked at install (rule 7), alarmed in operation (L2) |
| L2 | External heartbeat + stall detection | The alarm that L1 stopped or never worked | Silent erosion of the 7-day promise — which is why L2 is part of the decision |
| L3 | In-app self-check + per-member status in the settlement detail | Diagnosis and the Kassenwart's manual remedy | An alarm nobody can act on |

### Enqueue, drain, cancel

```mermaid
sequenceDiagram
    participant K as Kassenwart
    participant S as SettlementsService
    participant DB as Database
    participant C as Cron (the only sender)
    participant M as MTA

    K->>S: createSettlement(members, execution_date ≥ today+7)
    S->>DB: BEGIN
    S->>DB: settlement + items
    S->>DB: mail_outbox row per collected member (pending)
    S->>DB: COMMIT
    S-->>K: "47 announcements queued, sending"
    Note over S,M: no network call has happened yet

    loop every scheduler tick
        C->>DB: claim a batch (token + claimed_at)
        C->>C: render from settlement data (no stored body)
        C->>M: send
        alt delivered
            C->>DB: status = sent, sent_at, message_id
        else transient (greylisting, reset)
            C->>DB: attempts + 1, next_attempt_at = backoff
        else attempts exhausted
            C->>DB: status = failed, last_error
        end
        C->>DB: cron_heartbeat (source, counts, PHP version)
    end
```

Cancellation splits on what the member already knows:

```mermaid
flowchart TD
    A[cancelSettlement] --> B{announcement row status}
    B -- pending --> C[superseded — never sent, nothing to retract]
    B -- sent --> D[enqueue cancellation_notice<br/>&quot;Einzug entfällt&quot;]
    B -- failed --> C
```

Enqueueing a cancellation notice for a `pending` row would tell a member a collection is called off that they were never told about — which is worse than saying nothing.

### The outbox

| Column | Type | Meaning |
|---|---|---|
| `id` | CHAR(36) | UUID |
| `kind` | ENUM | `sepa_prenotification`, `cancellation_notice`, `payment_request` |
| `settlement_id`, `member_id` | CHAR(36) | What this message is about, and to whom |
| `recipient` | VARCHAR | **Snapshot** of the address — proof of who was announced to (rule 5); in scope for erasure |
| `language` | CHAR(2) | From `members.preferred_language`, `de` fallback (ADR-0002) |
| `status` | ENUM | `pending`, `sent`, `failed`, `superseded` |
| `attempts`, `next_attempt_at`, `last_error` | | Retry state and the reason the Kassenwart sees |
| `claim_token`, `claimed_at` | | Concurrency: claim by `UPDATE`, then select by token |
| `queued_at`, `sent_at`, `message_id` | | Timeline and the transport's handle |

`UNIQUE (kind, settlement_id, member_id)`. **No body column.**

Claiming is `UPDATE … WHERE status = 'pending' AND next_attempt_at <= NOW() AND (claimed_at IS NULL OR claimed_at < NOW() - INTERVAL 5 MINUTE) LIMIT ?` followed by a select on the token — deliberately **not** `SELECT … FOR UPDATE SKIP LOCKED`, which requires MariaDB 10.6+, and on mass hosting the database version belongs to the host. The stale-claim window is what stops a killed run from stranding rows forever.

A `cron_heartbeat` singleton (`last_run_at`, `source`, `sent`, `failed`, `php_version`) is written by every run. The PHP version is recorded because **the panel's CLI PHP is frequently not the web PHP** — different version, different extensions, different ini — and that difference should be visible rather than mysterious.

### Scope

| In | Out |
|---|---|
| `direct_debit` settlements: pre-notification and cancellation notice | `bank_transfer` / `write_off`: a *payment request* variant (amount, club bank details, no mandate reference) — [#410](https://github.com/dgloeckner/clubbar/issues/410), the `kind` value is reserved for it |
| Members collected at `amount > 0` | Members in credit or at zero — no collection, so no announcement |
| The member's own address, no CC | Any third-party recipient |

## Consequences

**Positive**

- A finalize cannot half-succeed. It is a database transaction and nothing else, which is also why it stays fast enough for a gateway that we do not control.
- Retry safety is a database constraint rather than a code path that has to remember: `UNIQUE (kind, settlement_id, member_id)`.
- Greylisting becomes ordinary rather than fatal, because a retry path exists at all.
- The 7-day promise is guaranteed at the point of *enqueue*, where the lead-time rule already lives, so delivery latency cannot break it — only a total stall can, and that is alarmed.
- The transport is swappable behind one DSN, and CI never opens a socket.

**Negative**

- **A stalled queue erodes the announcement distance silently.** This is the real cost of asynchrony, and it is mitigated rather than removed: stall detection at 24 h, an external alarm path, an install gate, and a self-check. If the monitor is never wired up, the mitigation is gone and nothing says so — the heartbeat's own absence is only visible in the monitor that is missing.
- **The supported hosting set narrows** to tariffs that can schedule something. In practice this excludes nobody, since the URL endpoint can be driven by an external scheduler, but it is now a stated limit rather than one to be discovered. This is the **first accepted hard dependency on a host feature**; it is compatible with ADR-0031 rule 3 only because it is enforced at install and measured in operation — rule 3 forbids *silent* dependence, not dependence.
- Blocking finalize on an unverified scheduler will fail **every existing settlement test at once** when it lands, with an error that reads like "settlement creation is broken". Mitigation: seeding `cron_heartbeat` in `seed.sql` and in the E2E fixtures ships in the *same* PR as the gate (#405), with the counter-test in #409.
- The treasurer cannot change the SMTP server from the admin panel. Consistent with the DB password and the TOTP key, and a deliberate acceptance that an SMTP change is a file or installer operation.
- The outbox is a **second place a member's address lives**. Without the erasure work in #408, anonymisation would clear `members.email` and leave `mail_outbox.recipient` behind — a GDPR leak created by this design and closed as part of it.

**Neutral**

- "Best effort" is a real decision, not a euphemism: nothing here guarantees delivery, and nothing obliges anyone to chase a failure. What is guaranteed is that a failure is *visible* to the Kassenwart, who decides whether to pick up the phone.

## Alternatives considered

**Synchronous send inside finalize.** Rejected: the host's gateway read timeout is unreachable from code, and a half-completed loop leaves a settlement whose announcement state cannot be reconstructed.

**Inline send after `fastcgi_finish_request()`.** Rejected: FPM-only, so it needs feature detection, and it would run about twelve times a year — the code path that rots unnoticed while the cron path is exercised every fifteen minutes.

**Self-disabling piggyback drain on admin requests.** Rejected 2026-08-13. It degrades a missing scheduler from "hard failure" to "slow but working", which sounds kind and removes the only pressure that would ever get the scheduler fixed. Also a second sending path, and rule 3 permits exactly one.

**Storing the rendered body in the outbox.** Rejected: the settlement is append-only, so the content is reproducible, and GoBD asks for reproducibility from the stored record rather than an archived email. Storing bodies would multiply the copies of member PII for no evidentiary gain.

**A `sent` flag on `settlement_items` instead of an outbox table.** Rejected: retry state, backoff, claim tokens and failure reasons are message properties, not settlement properties, and a cancellation notice has no item to hang on. The durable per-member *proof* still belongs settlement-side, which is why the sent timestamp is surfaced there while the queue row is prunable.

**`SELECT … FOR UPDATE SKIP LOCKED` for claiming.** Rejected: MariaDB 10.6+, and the database version is the host's decision, not ours.

## Related decisions

- [ADR-0002](./0002-product-internationalization.md) — per-member `preferred_language`, which selects the mail language with a `de` fallback
- [ADR-0009](./0009-settlement-lead-times-bank-working-days.md) — the 7-day lead time this announcement rides on
- [ADR-0013](./0013-audit-logging.md) — enqueue and cancellation-notice creation are audited events
- [ADR-0018](./0018-modular-admin-interface-architecture.md) — the `Notifications` module this decision creates
- [ADR-0028](./0028-legal-constraints-on-money-handling.md) — the GoBD framing behind "reproducible from stored data"
- [ADR-0029](./0029-two-tier-retention-and-erasure.md) — amended here: `mail_outbox.recipient` joins the operational tier
- [ADR-0031](./0031-production-hardening-on-shared-hosting.md) — amended here: the mail/scheduler layers, and the first accepted hard host dependency
- [ADR-0032](./0032-settlement-lifecycle.md) — amended here: enqueue is part of the create transaction; cancellation supersedes or notifies

## References

- Nutzungsordnung Vereinsbar (Entwurf) § 7 Abs. 1 and Abs. 3 — the Abrechnungsübersicht and the 7-day announcement (club document, not held in this repository)
- [§ 675x BGB](https://www.gesetze-im-internet.de/bgb/__675x.html) — the eight-week refund right that makes best-effort delivery legally survivable
- EPC SDD Core Rulebook — creditor identifier and mandate reference must reach the debtor before the first collection
- [#361](https://github.com/dgloeckner/clubbar/issues/361) — the epic, and the 2026-08-13 architecture and scheduler rulings
- `plans/2026-08-13-sepa-prenotification-emails.md` — the phased implementation
