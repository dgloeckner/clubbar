# Dispenser flow suite (L3)

The money paths of a token purchase, driven end to end over **real HTTP**
against the Go mock from
[`dgloeckner/remote-token-dispenser`](https://github.com/dgloeckner/remote-token-dispenser),
asserted on rows in `transactions_local` and `dispenser_operations` — never on
the UI. It is layer **L3** of the test plan in epic
[#944](https://github.com/dgloeckner/clubbar/issues/944) and was built for
[#950](https://github.com/dgloeckner/clubbar/issues/950).

It exists because the flows that cost money — a dropout mid-dispense, the app
killed mid-dispense, a lost response, a jam — cross the dialog, `CartProvider`
and `DispenserRecoveryService`, and until now were exercised only by hand, on
the Pi, with real tokens. Each of those cycles costs a walk to the clubhouse;
this suite costs ten seconds.

## Running it

```bash
# A checkout of the mock repository next to this one:
scripts/flow-test.sh

# Somewhere else:
DISPENSER_REPO=~/src/remote-token-dispenser scripts/flow-test.sh

# A binary you already built:
CLUBBAR_DISPENSER_MOCK=/tmp/dispenser-mock scripts/flow-test.sh

# One scenario:
scripts/flow-test.sh --plain-name 'a clean dispense'
```

It is **not** part of `flutter test`, which runs `test/` only. That is
deliberate: the suite needs a binary from another repository, and a suite that
skips itself when that binary is missing is a green run that proves nothing.
Without the mock it fails, and says how to get it.

In CI it is the `Run the dispenser flow suite` step of the `build-terminal`
job, right after the unit tests. The mock is checked out **at a pinned commit**
and built there; the pin lives in `.github/workflows/build.yaml` and is bumped
deliberately, like any dependency.

## What is here

| File | What it is |
|---|---|
| `dispenser_flow_test.dart` | The scenarios. One test per row of #950's table. |
| `support/mock_dispenser.dart` | The mock as a real process: free port, `pause()` (SIGSTOP), `resume()`, `kill()`, `launch()` back on the same port. Scenario constants (`qtyPartialDispense` …) — the mock picks its scenario from the **quantity**. |
| `support/dispenser_proxy.dart` | A reverse proxy in front of it: counts requests, reports `maxInFlight`, and can lose a POST response after the dispenser received it. |
| `support/flow_harness.dart` | The terminal wired as the app wires it — in-memory drift, real `CartService`, real `CartProvider`, real `DispenserRecoveryService` — plus `billedTokens()`, `trackingRows()`, `reconcile()`, `ageTracking()`. |
| `support/flow_dispense_session.dart` | The dialog's state machine without the widget. **A stand-in, meant to disappear** — see below. |

## Two things to know before adding a scenario

**Time is moved, not waited for.** The recovery service ignores an operation
polled within the last 30 seconds, and the dispenser's own jam timeout is 5
seconds. `harness.ageTracking(Duration(seconds: 40))` backdates the tracking
row instead of spending 40 seconds of a 60-second budget. Durations that belong
to the terminal (poll interval, poll timeout, retry delay) are injected through
`DispenserFlowHarness.start`.

**The dialog is the one seam.** `DispensingProgressDialog` is a
`StatefulWidget`, and `flutter_test` replaces `HttpClient` and runs timers in a
fake zone — a widget cannot be driven against a real server from a plain
`test()`. `FlowDispenseSession` is a headless replica of its state machine, with
the widget's constants named in its doc comments. It is a copy, so it can
drift: **[#946](https://github.com/dgloeckner/clubbar/issues/946) should lift
that state machine out of the widget into a plain class, point this harness at
it, and delete the replica.** Until then, a change to the dialog is a change
here too.

## Scenarios that are skipped, and what un-skips them

Every skip names an issue. None of them is "this is flaky".

| Scenario | Why it is skipped |
|---|---|
| polling timeout, rest billed by reconcile (#946) | **Red today**: the timeout is reported as `done`, so checkout deletes the tracking row while the dispenser is still running. Nothing is left to reconcile with. |
| dispenser unreachable, nothing billed and nothing left over (#947) | **Red today**: the tracking row survives as `not_found` and becomes a permanent "manual reconciliation" entry for a dispense that never started. |
| reset mid-dispense, tokens still billed | Needs a newer **mock**: at the pinned commit `crash_after_first` clears the transaction without keeping history, so no terminal behaviour can recover the count. Unblocked by the persisted ring in `dgloeckner/remote-token-dispenser#3` plus a pin bump. |
| protocol 1 is unavailable, not degraded | Needs a newer **mock**: no `--protocol` flag and no `protocol` field in `/health` at the pinned commit. Lands with #948 once protocol 2 exists. |

The first two are the red tests the epic still owes. They are committed skipped
rather than failing, because this repository's Test Verification Policy is that
`main` stays green; the issue that owns each one removes its `skip:` in the same
pull request as its fix — as #945 did with the third, *dropout vs. recovery
tick, billed once*, which now runs on every pass of this suite.
