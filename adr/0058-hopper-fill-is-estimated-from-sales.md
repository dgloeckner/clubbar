# ADR-0058: The Hopper's Fill Level Is Estimated From Sales, Not Measured

**Status**: Accepted (extends 0057)

**Date**: 2026-09-21

**Deciders**: Architecture Team

---

## Context

An empty hopper is the most ordinary failure a token dispenser has, and it is
also the most expensive one. The dispense that finds it empty does not fail
quietly: it runs into the firmware's 5 s jam timeout, the device goes to
`fault`, token sales stop, and clearing it costs a walk to the plug — the
device has no reset route, and a jam is cleared by a power cycle (owner
decision 3 of [#944](https://github.com/dgloeckner/clubbar/issues/944)). Nobody
is warned beforehand. The club finds out when a member cannot buy a token.

**The hardware signal is not available and is not coming.** The hopper's *empty*
switch is a factory option this unit does not have, and it has never produced a
signal here. Owner decision 7 removed `hopper_low` from the protocol rather than
publish a field that always says "fine" — a sensor reading that is always the
same is worse than no sensor, because a screen will eventually be built on it.

So the question is not *how do we read the fill level*. It is *what do we know
that is related to it*, and the answer is: exactly how many tokens went out.
Every dispensed token is one `purchase` transaction on a `requires_dispenser`
product, carrying the terminal that sold it and the moment the bar sold it.
`CartService.billDispensedTokens` writes **one row per token**, so the count is
a row count, not a quantity field.

Four facts about the available data shape the decision, and three of them are
traps.

1. **The device's counters are cumulative and RAM-only.** `lifetime`'s
   `requested_tokens` / `dispensed_tokens` are lifetime totals since the
   controller last booted — and a reboot zeroes them. Firmware F7's WiFi
   supervisor restarts an unreachable controller *on purpose*, so this is a
   routine event, not a fault. A counter that went **down** since the last
   observation therefore means the device restarted; it never means tokens
   reappeared.
2. **There is no history to difference against.** ADR-0057 rejected a
   `dispenser_reports` table, and the stored document is last-write-wins. Two
   readings exist only if somebody happened to look twice.
3. **Fields are absent, not zero, whenever `contact != reported`** — the whole
   `lifetime` object included, and `filtered_pulses` even on a healthy report.
   Anything built on `?? 0` claims a measurement for a machine nobody reached.
4. **`dispensed_tokens` may legitimately exceed `requested_tokens`.** Firmware
   F5 counts tokens that coast out after the motor stop and records the
   difference in `overrun_tokens`. Those tokens really left the hopper and were
   never billed.

## Decision

**The fill level is arithmetic, computed on read from a counted refill and the
sales since it. The device's counters are not an input, the estimate is never
stored, and it says on every surface that it is an estimate.**

```
estimated_left = tokens counted in at the last refill
               − token purchases this terminal booked since that moment
```

### What is stored: three columns on `terminals`

| Column | Type | Description |
|---|---|---|
| `dispenser_refilled_at` | DATETIME, NULL | When the hopper was last counted into (UTC, Pattern 020). NULL = never recorded |
| `dispenser_refill_tokens` | INT, NULL | Tokens counted in at that moment |
| `dispenser_low_threshold` | INT, NOT NULL, DEFAULT 20 | Warn at or below this estimate |

Only the **anchor** is stored. No `tokens_sold` counter exists, because the
sales are already rows: a counter beside them would be a second copy of a number
the database holds, kept in step by hand across an offline terminal's late sync,
with no way to tell which copy was right when they disagreed.

`dispenser_low_threshold` is per terminal because how large a hopper is and how
fast a bar sells are properties of that bar. It is NOT NULL with a usable
default so the warning exists from the first refill with nothing to configure.

### What the subtraction counts, and what it deliberately does not

`COUNT(*)` of `transactions` where the terminal is this one, the product has
`requires_dispenser`, the type is `purchase`, and `occurred_at >=
dispenser_refilled_at`. Each clause excludes something that was a plausible
reading of "tokens sold since the refill":

- **Rows, never `SUM(dispenser_actual)`.** One row is one token. The
  `dispenser_requested` / `dispenser_actual` columns carry the *operation's*
  totals on every one of its rows, so summing them turns a five-token dispense
  into twenty-five.
- **`occurred_at`, not `received_at`.** A terminal sells offline all evening and
  uploads in the morning, after somebody has already refilled. Those tokens came
  out of the old load and must not be charged to the new one.
- **Purchases only.** A storno returns money to a member; it does not return a
  token to the hopper — that one is in somebody's pocket. Counting it back would
  make the estimate drift *upward* exactly when a mistake was corrected.
- **Dispenser products only.** A beer sold at the same terminal is not a token.

A sale timestamped inside the same second as the refill counts against the new
load, because the anchor has second precision and `>=` is the plainer rule. The
refill response re-counts rather than assuming zero, so it can never disagree
with the next read of the same row.

### The device's counters are not an input, and that is the point

Nothing in the estimate reads `lifetime`. A reboot that zeroes the counters, an
unreachable machine whose fields are absent, and a report this backend refused
all leave the estimate exactly as it was — still anchored to the last count,
still counting rows in a table that only ever grows. The traps in context 1-3
cannot reach it.

The counters remain on the detail panel as what they are: what the machine says
about itself, each rendered through `hasCounter()` so an absent one shows an em
dash rather than a zero.

### Recording a refill: an exact count, by the `admin` office, and never an acknowledgement

`POST /api/admin/terminals/{id}/dispenser-refill` with `{ "tokens": N }`,
classified `ADMIN_ONLY` in `RouteRoleMap` like every other dispenser surface
(owner decision, 2026-09-20). The Kassenwart and the Getränkewart neither see
the estimate, nor record a refill, nor change the threshold, nor are mailed
about it.

**An exact count, not an increment** (owner decision 8). The admin enters what
is in the hopper *now*, and that number replaces the estimate. "Added N" and
"filled to the top" both build on a figure nobody has checked; the point of a
refill is to put the estimate back on a known value. Zero is a legitimate count
— a hopper emptied for maintenance.

It writes an audit row carrying **the estimate as it stood a moment before,
beside the count that replaced it**. The difference between the two is the
drift, and this is the only place it is ever written down.

**It clears nothing.** No surface in this system commands the dispenser, and a
refill must not become the acknowledgement this epic has already refused once.
The control therefore lives on the terminal row's actions rather than inside the
dispenser detail — a "record refill" button under a jam badge reads as a button
that answers the jam — and the dialog says in words that a jam is cleared at the
machine.

### The estimate never overrides the backend's `available`

The panel gains a fifth display state, `low`, between `available` and
`unavailable`: a machine that is working and will stop soon. It is amber, and it
appears only when the backend already said the dispenser can serve. An estimate
is a guess about a hopper somebody may have topped up without telling anyone; it
is never evidence against a machine that is currently serving tokens, and
ADR-0057's rule that the availability verdict is derived once, server-side,
stands unchanged.

The one place the two facts meet is a jam with an exhausted estimate. The kiosk
says **„Stau oder leer"** because the machine genuinely cannot tell the two
apart; the panel adds *„Schätzung aufgebraucht — wahrscheinlich leer"* beside
it. A sentence, not a new verdict.

### It says it is an estimate, everywhere

Absent is not zero, on every surface: no refill recorded renders as *no
estimate*, never as "0 tokens left" — those send somebody on opposite errands.
The estimate is floored at zero rather than going negative, because a negative
number would be false precision about a drift nobody measured. The detail panel
carries one line naming what the arithmetic cannot see, and the refill dialog
asks for a count rather than offering the current estimate to nod at.

## Consequences

### Positive

- **A warning exists where the hardware provides none**, and it costs no
  firmware, no wiring and no device round trip.
- **It cannot be broken by the device.** A reboot, an unreachable controller or
  a refused report changes nothing about it.
- **The drift is bounded by one hopper load.** Every refill is a count, so
  whatever the arithmetic missed is discarded at the next fill rather than
  accumulating.
- **The drift is visible.** The audit row's old estimate against the new count
  is a measurement of how wrong the arithmetic was, gathered for free, every
  time.

### Negative

- **It is not a measurement, and a hurried operator may read it as one.**
  Mitigation: the word *geschätzt* is in the section heading, the detail line
  names what it cannot see, and the badge says *about N*. Nothing prints an
  estimate as a bare stock figure.
- **It undercounts what left the hopper.** Overrun tokens (F5) and any dispense
  billed while `count_reliable` was false are real tokens that never became
  rows, so the true level is at or below the estimate. That direction is the
  safe one — the warning comes early — and it is the reason the estimate is
  floored at zero rather than presented as precise.
- **It lags an offline terminal.** Sales reach the backend at the next sync, so
  a terminal that has been offline all evening shows an estimate that is too
  high until it syncs. Mitigation: the row already renders the age of the
  terminal's last report beside the status (ADR-0057), and the estimate is read
  beside it.
- **A hopper topped up without recording it reads as emptier than it is**, and
  will warn. That is the failure mode to prefer over the reverse.
- **A `COUNT(*)` per page load.** One grouped query for the whole page, over an
  indexed `created_by_terminal_id`, for a club with one to three terminals.

## Alternatives considered

**Difference the device's `lifetime.dispensed_tokens` between reports.** The
device counts what it dispensed; subtract two readings and you have the sales,
with no dependence on the billing path at all. Rejected on context 1-3: the
counters are RAM-only and a reboot zeroes them, so a decrease means "restarted"
rather than "tokens returned", and with no history table there is no earlier
reading to difference against except whatever the last report happened to carry.
The failure is silent and it favours the dangerous direction — after a reboot
the estimate would jump *up*, reporting a fuller hopper than there is.

**Use `dispensed_tokens` since the refill, anchored by a counter snapshot taken
at refill time.** Same objection, one step later: the snapshot is invalidated by
any reboot between the refill and the read, and the reboot is not detectable
from the document alone (`reset_reason` says why it last came up, never how many
times).

**Sum `dispenser_actual` instead of counting rows.** It is the field that names
tokens, so it looks like the right one. Rejected: it is the *operation's* total,
repeated on each of that operation's rows, so summing multiplies a dispense by
its own size. This is the defect a reviewer will look for, which is why the
query carries the reason in a comment and a repository test pins it.

**Count `dispenser_requested` rather than the rows that were billed.** Requested
is what the terminal asked for; the rows are what it billed, which is what came
out. Rejected: on a partial dispense the difference is exactly the tokens that
stayed in the hopper.

**Store a running `tokens_remaining` counter and decrement it on sync.**
Rejected: it is a second copy of a number the transactions already hold, it
needs its own idempotency against a re-synced batch, and an offline terminal's
late upload would have to be applied to whichever load it belonged to — which is
precisely the `occurred_at` comparison the computed version does for free.

**"Added N" instead of an exact count.** Kinder to the person at the machine,
who knows how many bags they poured in. Rejected by owner decision 8: adding to
an estimate keeps whatever drift the estimate already had, forever. A count ends
it, and the audit row turns that moment into a measurement of the drift.

**A "hopper is empty" button for the person who finds it empty.** Rejected: it
is an acknowledgement in a different coat, and the epic has already ruled that
no surface commands or clears anything about this device (ADR-0057). If the
hopper is empty, the honest act is to fill it and record the count.

**Let the terminal compute and report the estimate.** Rejected: the kiosk does
not know about refills, and the backend already holds both halves of the
subtraction. It would also make a display-only figure a part of the sync
contract.

**A club-wide threshold instead of one per terminal.** Rejected: the number is
about a particular hopper in a particular bar, and a club with a busy terminal
and a quiet one would have to pick the wrong number for one of them.

## Related

- [#955](https://github.com/dgloeckner/clubbar/issues/955) — the issue this
  decides; [#944](https://github.com/dgloeckner/clubbar/issues/944) — the epic,
  finding 18
- [ADR-0057](./0057-terminals-report-peripheral-status.md) — the report this
  sits beside, the `admin`-only grant it inherits, and the rule that the
  availability verdict is derived once, server-side
- [ADR-0031](./0031-production-hardening-on-shared-hosting.md) — why there is no
  cron to compute or prune anything
- [ADR-0004](./0004-immutable-transaction-storage.md) — why a storno is a new
  row rather than an edit, which is what makes "count the purchases" stable
- [ADR-0044](./0044-tiered-admin-roles.md) — the default-deny grant behind
  "the `admin` office alone"
