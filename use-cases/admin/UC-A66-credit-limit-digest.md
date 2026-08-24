# UC-A66: Receive the Near-Limit Digest

**Implementation Status**: Implemented ([ADR-0038](../../adr/0038-mail-outbox-and-scheduler.md),
[ADR-0047](../../adr/0047-configurable-credit-limits.md), migration `054_credit_limit_digest.sql`)

## Actor
Admin or Kassenwart — as a **recipient**, not as an operator

This is the one use case in this directory whose main flow nobody performs. The
treasurer's part is to configure a cadence once and then read mail; the flow is
carried out by the scheduler. It is written down anyway, because what arrives in
somebody's inbox on a schedule is a feature with acceptance criteria, and the
criteria that matter here are about *what is not sent* as much as what is.

## Preconditions
- Mail is configured (a sender address, and a transport in `config.php`)
- The scheduler runs `backend/bin/cron.php` (ADR-0038 rule 3 — the install gate
  and the heartbeat already require this)
- `credit_limit_digest_cadence` is not `off`

## Trigger
A scheduler tick lands in a window for which no digest has been queued yet.

## Why this exists

The dashboard's near-limit panel ([#385](https://github.com/dgloeckner/clubbar/issues/385))
already answers "who is close to their ceiling" — the moment somebody opens the
panel and asks. A treasurer who does not open the panel learns that a member has
been refused at the bar when the member tells them: on an evening, offline, with
a queue behind them.

This is the same list, pushed instead of pulled.

## Main Flow
1. A scheduler tick runs `bin/cron.php`
2. The scan reads the cadence; if it is `off`, nothing further happens
3. The scan names the window it is inside — `2026-08-24`, `2026-W35`, `2026-08`
4. The scan asks who is in the warning band **or past it**
5. If nobody is, nothing is queued and the run says so in its one line of output
6. Otherwise one message is queued per active `admin` and `kassenwart` account,
   keyed on `<window>:<adminUserId>`
7. The drain, in the same tick, renders each message from **live** data and sends it
8. Every later tick inside the same window queues nothing: the unique index
   `(kind, subject_id, dedup_key)` refuses it

## What the message says

One mail, listing every member in the band, fullest Deckel first:

| Per member | Why it is there |
|------------|-----------------|
| Name | The digest is addressed to the treasury offices, who can already open any of these members on the dashboard |
| Current Deckel | The unsettled sum — the same figure the member's own page reports |
| Their limit | Their own override where they have one, the club default where they do not. A balance without its ceiling is unreadable once ceilings differ ([ADR-0047](../../adr/0047-configurable-credit-limits.md)) |
| Share of the ceiling | What makes a list of mixed ceilings sortable by urgency rather than by size |

Plus, for the list as a whole: the total outstanding, how many members are
already **over** their ceiling (called out separately — they are being refused
at the till right now), and the club's own ceiling and warning band, so a row
carrying an override reads as an exception rather than as a bug.

## Cadence

| Value | Window |
|-------|--------|
| `off` | No digest. The club's only control, and a real one |
| `daily` | The club's calendar day |
| `weekly` | The ISO-8601 week — the default |
| `monthly` | The calendar month |

`weekly` is offered here and deliberately absent from `statement_cadence`
(UC-A67 / ADR-0039). The difference is the audience: a Deckelauszug goes to the
whole membership, where fifty-two a year turns "predictable" into "relentless".
This goes to the handful of people who run the club, about a condition that
changes inside a week — a tab that crossed 80 % on Tuesday is refused on
Saturday.

## Acceptance Criteria

- [x] A member whose Deckel has reached the warning band appears on the digest,
      with their name, their current Deckel and the limit that applies to them
- [x] A member with a generous override is **not** listed at an amount that
      would have listed them under the club default, and one with a tight
      override **is** listed at an amount that would not have
- [x] A member with no ceiling (`0`) is never listed, however large the tab
- [x] A member in credit is never listed
- [x] A deactivated member is never listed — the terminal is not serving them
- [x] **One mail, not one per member**
- [x] **No mail at all when nobody is in the band** — silence means "nobody is
      near their ceiling", not "the scheduler has stopped". The scheduler's own
      health is the heartbeat's job
- [x] Running the scheduler repeatedly inside one window delivers exactly one
      digest per recipient
- [x] The next window delivers a new one
- [x] The digest reaches `admin` and `kassenwart` and **not** `getraenkewart`
      ([#633](https://github.com/dgloeckner/clubbar/issues/633)) — member
      balances are outside the stock keeper's remit on every surface
- [x] A member never receives it
- [x] `off` stops it entirely
- [x] An installation with no mail configured queues nothing at all
- [x] The list names how many members it left out when it is capped

## Alternative Flows

**A1 — the list empties between queue and send.** The scan found members, the
digest was queued, and a settlement run cleared them before the drain rendered
it. The message is sent and says so in one sentence. The recipient is expecting
a digest; "nobody is near their ceiling" is a truthful answer, where a failed row
in the Notifications page would be a puzzle.

**A2 — more members than one mail should carry.** The list is capped
(`CreditLimitDigestService::MAX_LINES`) and **says how many it is not showing**,
pointing at the dashboard for the rest. A list that silently stopped would read
as "that is everybody".

**A3 — no account holds an eligible office.** The notice is escalated to the
club address if one is configured, and logged as unreachable if not
([#633](https://github.com/dgloeckner/clubbar/issues/633)).

## Data Handling

The queue row carries **no member data**: `subject_id` is the singleton
`credit_limit_config` row and `dedup_key` is a window key plus a recipient id.
Names and amounts exist only in the rendered message, rebuilt at send time
(ADR-0038 rule 5). Two consequences worth stating:

- A digest queued on Monday and sent on Tuesday describes **Tuesday**, and a
  member who settled up in between is not named at all.
- The row is outside the erasure scrub and the member delete cascade, because
  there is nothing in it for either to reach.

Delivered rows are pruned after 30 days (`MailRetention::DIGEST_SENT_DAYS`) —
the shortest window in the system. A digest proves nothing and is not the record
of anything; the condition it reports is live on the dashboard.

## Related
- [UC-A65: Configure Credit Limits](./UC-A65-configure-credit-limits.md) — where the ceilings come from
- [UC-A80: Dashboard](./UC-A80-dashboard.md) — the pull half of the same list
- [UC-T12: Credit limit at the terminal](../terminal/UC-T12-credit-limit.md) — the line this digest reports on
