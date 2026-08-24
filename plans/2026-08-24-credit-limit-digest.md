# Near-Limit Digest for the Treasurer

**Status**: Implemented — all milestones verified against a running stack.

**Request**: *"The treasurer should receive regular notifications if members are
close to their limit. Members, current Deckel Betrag plus their limit should be
reported in one aggregate mail."*

**References**: [ADR-0038](../adr/0038-transactional-mail-outbox-on-shared-hosting.md)
(the outbox and the scheduler) · [ADR-0047](../adr/0047-configurable-credit-limits.md)
(the ceilings) · [ADR-0044](../adr/0044-admin-roles.md) (who may read what) ·
[UC-A66](../use-cases/admin/UC-A66-credit-limit-digest.md)

---

## The shape of it

Who is near their ceiling is **already computed and already correct** — the
dashboard's near-limit panel ([#385](https://github.com/dgloeckner/clubbar/issues/385))
answers it on every page load. What it is not is *reachable*: a treasurer who
does not open the panel finds out that a member was refused at the bar when the
member tells them.

So this is not a new calculation. It is the same list, pushed instead of pulled,
and almost all of the design work was in deciding what **not** to build:

| Decision | Alternative rejected | Why |
|----------|---------------------|-----|
| One aggregate mail | One mail per member near their limit | Twelve mails on a Saturday show one name each and never the shape of the problem — and they would put member names into the queue, which this design keeps out of it entirely |
| A new `MailKind` on the existing outbox | A second queue and a second scheduler | ADR-0038's whole premise: a new notification type is a value and a builder. The install gate and the heartbeat verify exactly one scheduled command |
| The window in the `dedup_key` | A cursor, a "last sent" column, or a scan that remembers | `UNIQUE (kind, subject_id, dedup_key)` answers "have I already sent this?" without a lookup, and is therefore correct under two overlapping ticks where lookup-then-insert would have both passes insert |
| The near-limit query moved to `CreditLimits` | A second query for the digest | The boundary cent is an integer division. Two spellings of it disagree exactly at the edge — where every member on this list sits — and the panel and the mail would name different people |
| Nothing queued when the list is empty | A digest saying "0 members" | A recipient sent that fifty times a year has learned to file it unread by the time the fifty-first says eleven |
| `subject_id` = the `credit_limit_config` singleton | Inventing a per-digest entity, or naming one member | The digest is *about* the club's ceiling and everyone up against it. `EntityType::CREDIT_LIMIT_CONFIG` already existed, so its audit entries file next to the entries for changing the ceiling itself |

---

## Milestones

### M1 — Schema `[x]`

- [x] Migration `053_credit_limit_digest.sql`: the `credit_limit_digest` kind on
      `mail_outbox`, and `credit_limit_digest_cadence` on `mail_config`
- [x] Rollback `db/rollback/053_credit_limit_digest.down.sql`

**Verified**: applied against the dev stack; `SHOW COLUMNS` reports the enum
carrying `credit_limit_digest` and the cadence column defaulting to `weekly`.

**The one judgement call worth flagging in review.** Migration 039 set
`statement_cadence` to `off` on every installation, on the rule that *running a
migration must never, by itself, mail an entire membership*. This migration
leaves `credit_limit_digest_cadence` on `weekly`, and the difference is who
receives it: the active `admin` and `kassenwart` accounts, never a member — and
nothing at all in a week where nobody is near their ceiling. 039 also had a
mechanical reason this one does not: its builder shipped a release later. Both
sides of that argument are written into the migration header.

### M2 — One query, two consumers `[x]`

- [x] `CreditLimits\Repositories\NearLimitRepository` — the near-limit SQL, moved
      out of `DashboardRepository`
- [x] `DashboardService` reads it; its behaviour is unchanged
- [x] The query's feature tests moved with it to
      `Tests\Feature\Modules\CreditLimits\Repositories\NearLimitRepositoryTest`

**Verified**: `NearLimitRepositoryTest` (24), `DashboardRepositoryTest`,
`DashboardServiceTest` — 74 tests, all passing.

### M3 — Cadence and window `[x]`

- [x] `DigestCadence` — `off | daily | weekly | monthly`
- [x] `DigestWindow` — the window key, cut in the **club's** timezone
- [x] `MailConfigDto` / `MailConfigRepository` / `MailConfigController` carry it

**Verified**: `DigestWindowTest` — 9 tests, including the one that motivated the
format. PHP's `W` is the ISO week number and `o` is the ISO week-numbering year;
pairing `W` with `Y` makes 29 December 2025 read as `2025-W01`, and the digest
would go silently missing at one turn of the year.

### M4 — The scan and the content `[x]`

- [x] `CreditLimitDigestService::collect()` — the report, shared by the scan and
      the builder
- [x] `CreditLimitDigestNotifier::run()` — cadence → window → report → fan-out;
      never throws
- [x] `CreditLimitDigestMailBuilder` + `CreditLimitDigestMail` — rendered at send
      time, German and English
- [x] `MailKind::CREDIT_LIMIT_DIGEST`, `MailSubject::CREDIT_LIMIT_CONFIG`,
      `MailRetention::DIGEST_SENT_DAYS`
- [x] Wired into `bin/cron.php` before the drain, so a digest queued by a tick
      leaves on that tick

**Verified**: `CreditLimitDigestServiceTest` (10), `CreditLimitDigestNotifierTest`
(7), `CreditLimitDigestMailTest` (19), `CreditLimitDigestTest` feature suite (12).

### M5 — The admin surface `[x]`

- [x] Settings → Mail gains the cadence select, beside the Deckelauszug one
- [x] German and English strings, including the Notifications page's kind label
- [x] OpenAPI + the generated client

**Verified**: `tests/api/mail-config.spec.ts` (14) and
`tests/admin/mail-settings.spec.ts` (10), all passing. `tsc --noEmit` and the
490 Vitest tests are clean.

### M6 — The chain, end to end `[x]`

- [x] `e2etests/tests/mail-digest/credit-limit-digest.spec.ts`, its own
      Playwright project, added to CI's `chain` lane

**Verified**: 2 tests passing —
`cadence on → bin/cron.php → Mailpit → the Kassenwart's inbox`, asserting the
member's name, their Deckel and their limit in both message parts; that one
aggregate mail arrives rather than one per member; that a second run inside the
window delivers nothing further; and that the Getränkewart's mailbox stays empty.

---

## Known limitation, deliberately not fixed here

**The URL-trigger fallback does not run this scan.** `POST /api/cron/drain`
(ADR-0031's answer to a tariff with no CLI cron) runs the terminal anomaly
detector and then drains. It does **not** run the periodic statement enqueue
(ADR-0039) or the credential expiry scan (#438) either — so an installation
triggered only by URL has never received those, and now will not receive this.

That is a pre-existing gap in a shared code path, and closing it means deciding
how three scans fit inside a trigger whose timeout the application cannot see
(`MailConfigDto::DEFAULT_DRAIN_BUDGET_SECONDS` is 25 seconds precisely because
that timeout is unknown). Widening it as a side effect of this feature would put
that decision in a change nobody would review it in. It belongs in its own issue.

## Test summary

| Suite | Count | Result |
|-------|-------|--------|
| Backend PHPUnit (whole suite) | 2,935 | Passing — one **pre-existing** failure, `ServiceFactoryTest::test_getRateLimitMiddleware_is_active_by_default`, which fails identically on a stashed tree because the dev container sets `DISABLE_LOGIN_RATE_LIMITING` in the process environment while the test only unsets `$_ENV` |
| Admin Vitest | 490 | Passing |
| `tsc --noEmit` (admin) | — | Clean |
| E2E `api-tests` (mail-config) | 14 | Passing |
| E2E `admin-chromium` (mail settings, dashboard, credit limits) | 25 | Passing |
| E2E `mail-digest` chain | 2 | Passing |
