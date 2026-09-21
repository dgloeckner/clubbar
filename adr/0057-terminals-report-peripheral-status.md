# ADR-0057: Terminals Report Peripheral Status

**Status**: Accepted (extends 0054)

**Date**: 2026-09-21

**Deciders**: Architecture Team

---

## Context

A terminal now has a peripheral that can fail on its own: the Azkoyen token
dispenser, driven over HTTP by an ESP-based controller
([#944](https://github.com/dgloeckner/clubbar/issues/944)). It jams. It runs
empty. It gets unplugged. Its controller crashes and comes back. Its firmware is
released separately from this repository, so a club can be running a protocol
this terminal does not speak.

None of that is visible anywhere an admin looks. The Terminals page shows when
each terminal last synced and which version it runs (ADR-0054) and says nothing
about the machine bolted next to it. Today a dispenser fault is discovered by a
member at the kiosk, and the only way for an operator to learn anything is to
walk to the bar and open the terminal's diagnostics modal. Nobody is told; the
club finds out when somebody complains.

Four things about this deployment shape the answer.

1. **The backend cannot ask.** The dispenser is on the club's LAN behind
   whatever router the bar has. The terminal is the only thing that can reach
   it, and the terminal is the only thing that reaches the backend. Every fact
   about the dispenser arrives second-hand or not at all.
2. **Telemetry must never cost a sale.** The terminal's sync cycle is how
   members, products and prices reach the kiosk and how transactions leave it. A
   status report that can fail a sync cycle is a bar that cannot sell beer
   because a peripheral's JSON was malformed.
3. **The document will change.** The dispenser firmware has its own release
   cadence and its own counters, and it has already grown one
   (`metrics.filtered_pulses`) that the mock does not emit. Whatever the backend
   records has to survive a firmware that adds a field.
4. **Two questions look like one and are not.** *Can it serve a token?* is
   `state != fault`. *Does a human have to go there?* is `fault != none`. A
   controller that crashed and came back is `idle` / `none` with an `error`
   transaction behind it — available, and nobody needs to walk anywhere. A
   protocol mismatch is neither: nothing is wrong at the machine, and the errand
   is a deployment one. Folding these together is the defect this epic already
   found once (finding 13), where a protocol mismatch was reported as "offline"
   and sent somebody looking for a power cable.

## Decision

**A terminal reports the status of its peripherals; the backend records the last
report beside `last_sync_at` and serves it to the `admin` office. The report is
fail-open in every direction, and the availability verdict is derived by the
backend, not accepted from the wire.**

### Transport: a route of its own, not a header

ADR-0054 carries the terminal's own version in `X-Terminal-Version` on every
terminal-authenticated request. That works for one short string and does not
generalise: this document is a dozen fields, some of them nested, and the
sensible header encoding for it is JSON in a header value.

So: `PUT /api/sync/terminal-status`, bearer-authenticated, inside the existing
`/api/sync` group — which is what gives it the terminal token middleware and the
terminal rate limit with no new wiring, and what makes the terminal's identity
come from its token rather than from the body it sent. It is called on the sync
cadence and immediately on a state change.

`PUT`, not `POST`: the body is the terminal's whole current status, last write
wins, and sending it twice changes nothing. Nothing is created.

The envelope is `{ "dispenser": { … } }` rather than the dispenser document at
the top level. The route is named for the *terminal*, and the dispenser is the
first peripheral, not the only conceivable one.

### Storage: two columns on `terminals`, one of them JSON

| Column on `terminals` | Type | Description |
|---|---|---|
| `dispenser_status` | JSON, NULL | The last validated report, with the backend's derived verdict stamped in. NULL = never reported |
| `dispenser_status_at` | DATETIME, NULL | When the report was received (UTC, Pattern 020) |

A JSON column rather than a dozen typed ones, for the reason context 3 gives:
the document is display-only — nothing queries it by field, sorts on it or
aggregates it — and typed columns would mean a migration per firmware counter.

`dispenser_status_at` is separate from `last_sync_at` for the reason
`reported_version_at` is separate from it: reporting is fail-open, so a terminal
can keep syncing perfectly while reporting nothing.

**No history table.** The device's counters are cumulative, so a trend is
recoverable from two reads, and a history would be a second thing to prune with
no cron to prune it (ADR-0031).

### The vocabulary is the terminal's

The report uses the fields the terminal already parses from the device, with the
same meanings — `contact`, `state`, `fault` + `fault_code`. It does not invent a
flat five-way `state` enum that mixes the device's own state with how the
terminal reached it, because context 4 is precisely the distinction such an enum
destroys.

| Field | Values | Question it answers |
|---|---|---|
| `configured` | `true` / `false` | Is a dispenser attached at all? |
| `contact` | `reported`, `unreachable`, `protocol_mismatch` | Did we hear from it, and did we understand it? |
| `state` | `idle`, `dispensing`, `fault` | What is the machine doing? |
| `fault` | `none`, `jam`, `hopper_error` | Does a human have to go there? |
| `fault_code` | 0-255 | Which hopper error (Azkoyen 1-7) |

`configured: false` is a **report**, not an absence of one: it is what lets the
panel say *no dispenser* rather than *unknown*.

### The verdict is derived, never reported

The backend stamps three fields into the stored document:

| Field | Meaning |
|---|---|
| `available` | `configured` and nothing stops it serving |
| `unavailable_reason` | `offline`, `protocol_mismatch`, `jam`, `hopper_error`, `unspecified_fault`, or null |
| `state_since` | When this episode began |

`unavailable_reason` is the terminal's own `DispenserUnavailableReason`, so the
kiosk and the panel name the same condition the same way. It is derived rather
than accepted because a reason on the wire could contradict the `state` beside
it, and then two screens describe one machine differently — which is how a
club stops trusting either.

Precedence is contact, then fault, then state: a machine we did not reach has no
state worth believing, and a named fault outranks the generic `state: fault`.
`unspecified_fault` exists so a `state: fault` a conforming device never sends
without naming can still never be rendered as available.

`state_since` moves only when `configured`, `contact`, `state`, `fault` or
`fault_code` changes. A jam re-reported every thirty seconds for an hour is one
fault that started an hour ago — which is what the panel shows as *since …* and
what a notification's deduplication key is built on. It is stamped from the
**backend's** clock, not from the device's `observed_at`: a kiosk whose clock is
wrong must not be able to date a fault. It carries milliseconds, because a
terminal reports a state change immediately and two episodes can land in the
same second — at second precision the second one would inherit the first one's
stamp and the panel would date a jam to the moment the machine was still idle.

### Fail-open in every direction

A body that is oversized, unparseable, missing the envelope, or carrying a value
that is not in the protocol is **dropped and logged; the route answers `204`
regardless**, and the previously stored document is kept untouched. There is no
`422` on this route and no validation message on the wire; the reason lives in
the log, where the person asking the question is.

Two rules separate growth from disagreement:

- **An unknown key is dropped, never fatal.** The firmware grows. A backend that
  refused a document carrying a field it had not been taught would stop
  reporting at the first firmware release, silently.
- **A known key with a value outside the protocol drops the whole report.** A
  `fault` of `"stuck"` is a disagreement about what the words mean, not an
  extension, and storing the rest of a document whose verdict cannot be read
  would put a green cell in front of an admin over a machine nobody understood.

### Who may see it: the `admin` office alone

The fields ride the terminals list the Terminals page already fetches, so they
inherit that route's grant: every `/api/admin/terminals*` route is `ADMIN_ONLY`
in `RouteRoleMap`. Owner decision, 2026-09-20: dispenser state is the admin
office's. The Kassenwart and the Getränkewart neither see it, nor record a
refill against it, nor change its warning threshold, nor receive mail about it.
There is no second table of who-may-see-what; the notification mirrors the grant
on the surface it points at, which is what `CLAUDE.md`'s recipient rule already
requires.

### Counts only

The report carries counters and states. The terminal's own
`dispenser_operations` rows carry a member id, and none of that leaves the
kiosk. Nothing in this document may grow a field that identifies a person.

### There is no remote reset, and there must not be one

The device has no reset route: a jam is cleared by a power cycle (owner decision
3 of #944). So this surface reports and never commands. A "clear fault" button
in the admin panel would be a promise the machine cannot keep — it would change
a screen and not a hopper, and the next member would meet the same jam with the
panel showing green.

## Consequences

### Positive

- **An operator learns from the panel, not from a complaint.** The failure modes
  that used to be invisible — jam, empty, unplugged, wrong protocol — each name
  themselves on a page that is already open.
- **One vocabulary across three codebases.** The kiosk, the backend and the
  panel use the terminal's enums, so the three surfaces cannot describe one
  machine differently.
- **Telemetry cannot cost a sale.** Every failure path ends in `204`.
- **Firmware can grow without a migration.** A new counter arrives, is dropped
  until somebody decides an admin needs it, and adding it is one list entry.

### Negative

- **Everything is as old as the last sync.** The backend cannot poll the
  dispenser; a terminal that is off reports nothing, and a status with no age
  beside it would be a lie. Mitigation: `dispenser_status_at` is served with the
  document and the panel must render the age, never the status alone.
- **A dropped report is silent to the club.** It is a log line, by design — but
  a firmware whose document this backend refuses would freeze the cell with
  nothing on screen saying so. Mitigation: the age above is what shows it, and
  the drop is logged with the field that failed.
- **A JSON column cannot be queried.** "Show me every terminal whose dispenser
  is jammed" is a scan, not an index. Accepted: a club has one to three
  terminals.
- **Last write wins, so a fault that cleared itself between two reports is never
  seen.** Accepted: a fault the machine recovered from on its own is not an
  errand, and `lifetime.jams` still counts it.
- **`state_since` is the one fact a rollback loses.** Dropping the column and
  re-adding it re-stamps every episode as new.

## Alternatives considered

**Widen ADR-0054's header mechanism.** Put the document in a header on every
terminal-authenticated request, as `X-Terminal-Version` does. Rejected: a dozen
fields, some nested, is JSON in a header value; it would be sent on every
request rather than on a cadence; and it would make ADR-0054 — which is about
*which version a terminal runs* — the home of an unrelated concern.

**A dozen typed columns.** Rejected under context 3: a migration per firmware
counter, for a document nothing queries by field.

**A `dispenser_reports` history table.** Would give trends and a real
episode history. Rejected: the device's counters are already cumulative, there
is no cron on shared hosting to prune the table (ADR-0031), and the one fact a
history would add that cumulative counters do not — when an episode began — is
cheaper to carry as a field in the last report.

**The flat five-value `state` enum** (`idle | dispensing | fault | offline |
protocol_mismatch`) the issue sketched. Rejected: it merges the device's state
with the terminal's contact, so `state: fault, fault: none` (a crash that
recovered) and `state: idle` after an unreachable poll become the same kind of
thing, and the backend loses the ability to distinguish availability from "needs
a human" — context 4, and the conflation behind epic finding 13. The five
distinctions it wanted survive intact as `unavailable_reason`, derived.

**Accept `unavailable_reason` from the terminal.** It already computes one for
the kiosk. Rejected: a reason on the wire can contradict the `state` beside it,
and then the kiosk and the panel describe the same machine differently with
nothing to adjudicate. The backend derives it from the fields it stored.

**A separate route per peripheral** (`/api/sync/dispenser-status`). Rejected as
premature in the other direction: the report is about the terminal, and a second
peripheral would then be a second route, a second cadence and a second stamp.

**Push a fault to the backend only on change, with no periodic report.**
Rejected: silence would then mean both "nothing changed" and "the terminal is
gone", which is the failure this ADR exists to end. The cadence is what makes
`dispenser_status_at` meaningful.

## Related

- [#952](https://github.com/dgloeckner/clubbar/issues/952) — the issue this
  decides; [#944](https://github.com/dgloeckner/clubbar/issues/944) — the epic
- [ADR-0054](./0054-terminal-runs-its-backends-version.md) — the precedent: the
  terminal reports, the backend records beside `last_sync_at`, fail-open
- [ADR-0031](./0031-production-hardening-on-shared-hosting.md) — why there is no history
  table to prune
- [ADR-0033](./0033-terminal-sync-contract.md) — the authority rule that makes
  the bearer token, not the body, name the terminal
- [ADR-0044](./0044-tiered-admin-roles.md) — the grant this rides, and the
  fail-closed reading behind "the `admin` office alone"
