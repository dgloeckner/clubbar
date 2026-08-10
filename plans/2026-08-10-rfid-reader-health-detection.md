# Terminal: RFID Reader Health Detection

**Issue:** [#35](https://github.com/dgloeckner/clubbar/issues/35)
**Goal:** An unplugged, dead or wedged RFID reader is detected within ~15 s, visibly changes the idle screen and the status header, and clears again on reconnect without a restart.

**Related:** [UC-T01](../use-cases/terminal/UC-T01-book-product-to-tab.md) (Book Product to Tab — the scan that starts every session), [ADR-0019](../adr/0019-frontend-access-token-configuration.md) (terminal configuration lives in `config.json`), [ADR-0027](../adr/0027-terminal-session-lifecycle.md) (session lifecycle — the idle screen is where every session begins)

**Status:** Implemented (2026-08-10). All tasks verified green.

| Task | Status | Evidence |
|------|--------|----------|
| 1. Presence probe over `/proc/bus/input/devices` | `[x]` | `lib/services/rfid_reader_probe.dart`; 11 tests in `test/services/rfid_reader_probe_test.dart` |
| 2. Polling health service with a three-state status | `[x]` | `lib/services/rfid_reader_health_service.dart`; 9 tests in `test/services/rfid_reader_health_service_test.dart` |
| 3. `config.json` keys + env overrides | `[x]` | `rfidReader.*` in `ConfigService`; 5 tests in `test/services/config_service_test.dart` |
| 4. Reader pill in the status header | `[x]` | `ClubBarHeader.readerStatus`; 4 tests in `test/widgets/clubbar_header_test.dart` |
| 5. Header pill wired through the app shell | `[x]` | `MainLayout`; 3 tests in `test/widgets/main_layout_test.dart` (new file) |
| 6. Idle screen says the reader is gone instead of inviting scans | `[x]` | `IdleWaitingScreen` + `RfidDetectorButton.isOffline`; 4 tests in `test/screens/idle_waiting_screen_test.dart` |
| 7. Reader section in the status modal (state + last seen, re-check on open) | `[x]` | `_buildReaderSection` in `status_info_modal.dart` |
| 8. Wiring in `main.dart`, off unless configured | `[x]` | `ConfigService.rfidReaderMonitoringEnabled` gates construction and the provider |
| 9. Full unit/widget suite | `[x]` | `bash scripts/run-tests.sh` → 582/582 passing |
| 10. `flutter analyze` clean for touched files | `[x]` | No new issues; the 14 remaining are pre-existing |

---

## Analysis

### Root cause

The reader is a USB HID keyboard wedge. Its entire contract is "type a UID, then
Enter", which means **absence produces the same signal as silence**: nothing.
Input arrives through the app-shell `HardwareKeyboard` handler in
`ScanCapture`, and `RealRfidService` is a bare `StreamController` — neither has
any notion of the device behind it.

So an unplugged reader left the terminal in its most inviting state forever: the
idle screen pulsing "Halte deinen Chip an den Scanner", taps doing nothing, no
error anywhere. Recovery required a member giving up, finding staff, and staff
restarting the app — and nothing in the UI pointed at the reader as the cause.

### Why presence, not staleness

The issue sketched a staleness heuristic as option 2 ("no scan for N minutes
during open hours"). Rejected: a quiet Tuesday and a dead reader are the same
observation, so the alert is either late or wrong, and the threshold is a
property of the venue rather than of the hardware. Presence is a fact the kernel
already knows.

`/proc/bus/input/devices` is the cheapest reliable place to ask on the Linux
kiosk `INSTALL.md` describes: no subprocess, no `libusb`, no platform channel.
Unplugging removes the device's block, plugging back in restores it — which is
what makes reconnect recovery fall out of the same poll rather than needing its
own mechanism.

### Why monitoring is opt-in

A keyboard-wedge reader is indistinguishable from a keyboard unless you know its
vendor/product id or its device name, and a kiosk may legitimately have a real
keyboard attached. Guessing would put "Scanner nicht verbunden" on a healthy
terminal and stop the bar — strictly worse than the bug being fixed.

So the terminal is *told* what its reader looks like, and the status is a third
state — `unknown` — until it is. `unknown` renders exactly as the app did before
this change: no pill, no idle-screen change, nothing. The same state covers
macOS and Windows, where the device list does not exist, and an unreadable
procfs.

`INSTALL.md` carries the one command that produces the ids.

---

## Changes

| File | Change |
|------|--------|
| `terminal-frontend/lib/services/rfid_reader_probe.dart` | **New.** `RfidReaderIdentity` (vendor/product/name matching, case- and `0x`-insensitive) and `InputDevicesRfidReaderProbe`, which parses `/proc/bus/input/devices`. Returns `null` — not `false` — when presence cannot be determined |
| `terminal-frontend/lib/services/rfid_reader_health_service.dart` | **New.** `ChangeNotifier` polling the probe on an interval; `connected` / `disconnected` / `unknown`, `lastSeenAt`, `checkNow()`, no overlapping checks |
| `terminal-frontend/lib/services/config_service.dart` | `rfidReader.{monitor,vendorId,productId,namePattern,pollIntervalSeconds}` + the five `RFID_READER_*` env overrides; `rfidReaderMonitoringEnabled` is false unless the reader is described |
| `terminal-frontend/lib/main.dart` | Builds and starts the service when configured; provides it only then |
| `terminal-frontend/lib/widgets/clubbar_header.dart` | `readerStatus` renders a third pill (green *Scanner OK* / red *Scanner fehlt*), sharing the badge's style and its tap target. Badge markup factored into `_pill` |
| `terminal-frontend/lib/widgets/main_layout.dart` | Watches the service when present and feeds the header |
| `terminal-frontend/lib/screens/idle_waiting_screen.dart` | Swaps title and subtitle to "Scanner nicht verbunden" / "Bitte Personal informieren" while the reader is gone |
| `terminal-frontend/lib/widgets/rfid_detector_button.dart` | `isOffline`: stops the pulse, drops the glow, greys the circle and shows a `sensors_off` icon — the animation is an invitation to tap, and there is nothing to tap into |
| `terminal-frontend/lib/widgets/status_info_modal.dart` | Card-reader section in the Overview tab (state + last seen), live via a listener, re-checked when the modal opens |
| `terminal-frontend/lib/l10n/app_de.arb`, `app_en.arb` (+ generated) | 7 new strings: idle copy, both pills, and the modal section |
| `terminal-frontend/INSTALL.md` | `rfidReader` in the config reference, env-override rows, a "Reader health monitoring" section with the `cat /proc/bus/input/devices` recipe, two troubleshooting rows |
| `terminal-frontend/RFID_IMPLEMENTATION.md` | "Reader Health Detection" section — how presence is checked, the three states, and why `unknown` exists |
| `terminal-frontend/test/services/rfid_reader_probe_test.dart` | **New**, 11 tests |
| `terminal-frontend/test/services/rfid_reader_health_service_test.dart` | **New**, 9 tests |
| `terminal-frontend/test/widgets/main_layout_test.dart` | **New**, 3 tests |
| `terminal-frontend/test/services/config_service_test.dart` | New `rfid reader monitoring` group (5 tests) |
| `terminal-frontend/test/widgets/clubbar_header_test.dart` | New reader-pill tests (4) |
| `terminal-frontend/test/screens/idle_waiting_screen_test.dart` | New `IdleWaitingScreen reader health` group (4 tests) |

---

## Acceptance criteria

| Criterion | Covered by |
|-----------|-----------|
| Disconnect detected within ~15 s | `rfidReader.pollIntervalSeconds` defaults to **5 s**, and the check runs immediately on start rather than one interval late — pinned by *"polls on the configured interval until stopped"* |
| …and visibly changes the idle screen message | *"says the reader is gone instead of inviting a scan forever"* asserts both new strings appear and both old ones are gone |
| …and the status header | *"surfaces a missing reader beside the connection status"* (through the real `MainLayout`) and the three `ClubBarHeader` pill tests |
| Reconnect clears the state without restart | *"recovers when the reader is plugged back in, without a restart"* (idle screen), *"updates the pill when the reader comes back"* (header), *"clears the disconnected state when the reader comes back"* (service) |

## Test Commands

```bash
cd terminal-frontend

bash scripts/run-tests.sh                                       # 582 passed
bash scripts/run-tests.sh test/services/rfid_reader_probe_test.dart
bash scripts/run-tests.sh test/services/rfid_reader_health_service_test.dart
bash scripts/run-tests.sh test/widgets/main_layout_test.dart
flutter analyze
```

The probe is tested against fixture copies of `/proc/bus/input/devices` written
to a temp file — the same machine with and without the reader's block — so the
parsing is exercised on the kernel's real layout without needing the hardware.

### Not covered

No `integration_test/` case. The one non-walkthrough integration file must stay
single (see the note in
[Checkout Double-Tap Guard](./2026-08-05-checkout-double-tap-guard.md#test-commands)),
and an integration test would add nothing the `MainLayout` widget test does not
already cover: below the probe there is only the kernel, which no test on this
side of the platform boundary can unplug. The one thing left for a human is
reading the reader's ids off a real kiosk and pulling the cable.
