# Terminal: why a registered chip is sometimes not detected

**Issue:** [#370](https://github.com/dgloeckner/clubbar/issues/370) — *Terminal sometimes does not detect a registered RFID chip* ("might need debug logs")
**Goal:** Every card tap ends in an outcome somebody can read afterwards, and the drop paths the review found that can be closed without guessing are closed.

**Related:** [UC-T01](../use-cases/terminal/UC-T01-book-product-to-tab.md) (the scan that starts every session), [ADR-0027](../adr/0027-terminal-session-lifecycle.md) (session lifecycle and the per-route scan policy), [#18](https://github.com/dgloeckner/clubbar/issues/18) (UID case), [#26](https://github.com/dgloeckner/clubbar/issues/26) (capture belongs to the shell), [#35](https://github.com/dgloeckner/clubbar/issues/35) (reader presence)

**Status:** Implemented (2026-08-16). 881 unit/widget tests green (853 before), `flutter analyze` clean on every touched file.

| Task | Status | Evidence |
|------|--------|----------|
| 1. Review the whole path from key event to member, and enumerate the outcomes that are invisible | `[x]` | Analysis below; the eight silent paths are listed in *What "not detected" can mean* |
| 2. `ScanLog`: bounded in-memory record of scan outcomes, lost taps to `error.log` | `[x]` | `lib/services/scan_log.dart`; 9 tests in `test/services/scan_log_test.dart` |
| 3. Capture stage records every branch that ends a keystroke's life | `[x]` | `ScanCapture._onKeyEvent`; 6 tests in `test/widgets/scan_capture_test.dart` (`ScanCapture diagnostics`) |
| 4. Resolution stage records accept / reject / refuse / drop | `[x]` | `RfidProvider`; 4 tests in `test/providers/rfid_provider_test.dart` (`RfidProvider scan log`) |
| 5. Fix: the keypad's Enter terminates a scan | `[x]` | `_terminatorKeys`; 2 tests in `test/widgets/scan_capture_test.dart` |
| 6. Fix: a card handed to another member no longer wedges the whole sync | `[x]` | `MembersRepository.upsertMembers`; 4 tests in `test/repository_test.dart` (`a card UID handed to another member (#370)`) — the first two fail on `main` with `SqliteException(2067)` |
| 7. Staff can read the last taps without a shell | `[x]` | *Letzte Chip-Erkennungen* in `status_info_modal.dart`; 3 tests in `test/widgets/status_info_modal_test.dart` |
| 8. Operator documentation: what each line means and what to do | `[x]` | `terminal-frontend/INSTALL.md` → *What the terminal saw of a card tap* + troubleshooting row |
| 9. Full unit/widget suite | `[x]` | `flutter test` → 881/881 |

---

## Analysis

### The path a tap takes

```
USB HID keyboard-wedge reader
  → HardwareKeyboard handler in ScanCapture (app shell, every route)
  → character buffer, terminated by Enter
  → RfidProvider.emitScan → RealRfidService stream
  → RfidProvider.handleCardScan  (gates: in-flight scan, critical operation)
  → MembersRepository.findByCardUid  (exact match against members_cache)
  → SessionController.startSession   (ADR-0027 rules 3, 4, 9)
  → /products
```

The member's whole report is "it didn't work", so the first question is which
half of that chain they are describing. **Everything from `emitScan` onward
already produces feedback**: a sound on every outcome, an inline error on the
idle screen, the `_ScanFeedbackBanner` on every other route, and a spinner on
the RFID button for as long as the lookup runs. So a tap that produces *no
reaction at all* — the literal reading of the issue — did not get that far, and
before this change nothing anywhere recorded why.

### What "not detected" can mean

Ranked by how well each matches "sometimes", with what it would look like:

| # | Cause | Symptom | Status |
|---|-------|---------|--------|
| 1 | The reader's terminator is the **keypad** Enter (HID `0x70058`), not Return. `_onKeyEvent` compared against `LogicalKeyboardKey.enter` only, so the UID sat in the buffer until the 500 ms gap timer threw it away | Nothing at all | **Fixed** — both Enter keys terminate |
| 2 | The reader types **keypad digits with NumLock off**: every digit arrives as a navigation key carrying no character, so nothing is ever buffered | Nothing at all | **Diagnosed** — logged as `unprintableKey` with the HID usage; the fix is NumLock or reader config, and synthesising digits from arrow keys would be worse than the bug |
| 3 | A **modifier stuck down** in the compositor. Every keystroke is discarded as "a shortcut, never reader output" while it is held | Nothing at all, until it clears by itself — a good match for "sometimes" | **Diagnosed** — logged as `modifierSuppressed`. Loosening the guard would let real shortcuts become UIDs |
| 4 | Characters arriving more than 500 ms apart, so the buffer is cleared mid-UID | Nothing, or "Unbekannter Chip" for the surviving fragment | **Diagnosed** — logged as `partialDiscarded` with the length |
| 5 | A second tap while the first is still resolving. The lookup waits up to 3 s on the backend for the member's balance (`MembersService.balanceRefreshTimeout`) | The spinner is visible, the second tap does nothing | **Diagnosed** — logged as `droppedBusy` |
| 6 | The chip **is not in the terminal's cache**: never synced, or the sync is broken | "Unbekannter Chip" — audible and visible, so probably not what was reported, but see below | **One cause fixed** (row 7) |
| 7 | A card UID **handed to another member** wedged the entire sync. `members_cache.card_uid` is UNIQUE and the upsert conflicts on the primary key only, so a delta naming the new holder before the old one's release raised `UNIQUE constraint failed` out of the *first* step of the cycle. The cursor only advances on success, so every later cycle re-fetched the same delta and failed identically — no new members, no product changes, no newly registered chip, until a wipe | A newly registered chip is never recognised, permanently | **Fixed**, with a failing test first |
| 8 | The reader is unplugged, asleep or dead | Since #35, a red pill and an idle screen that says so — but only on a terminal that has `rfidReader.*` configured | Pre-existing; worth checking `config.json` on the affected terminal |

Rows 1–5 all end in the same place: nothing on screen and nothing in any log.
That is why the issue's own note — *"might need debug logs"* — is the right
instinct, and why this change is mostly about making the invisible stage
report.

### Why the timestamped record, rather than more guessing

The terminal is a kiosk started from a `.desktop` autostart entry, so its
stdout is not kept anywhere; `ErrorFileOutput` writes `error.log` and filters to
`Level.error` and above. A lost tap *is* a fault, so the three kinds that leave
the member staring at an unchanged screen (`partialDiscarded`,
`captureNotReady`, `droppedBusy`) are logged at error level and land in that
file. Everything else is kept in memory and shown in the status modal, where
staff can read it out — the same audience the reader pill and the dispenser
sections were built for.

Repeats of one outcome are folded into a single entry with a count, so a
keyboard attached to the kiosk (or a reader stuck mid-burst) cannot push the
interesting lines out of a 25-entry buffer within seconds.

### What was deliberately not changed

- **The 500 ms inter-character gap.** It is what stops stray host keystrokes
  from being glued into a UID (#26). Lowering or raising it without knowing
  which reader is deployed would trade one silent failure for another; the log
  now says whether it is firing at all.
- **The modifier guard**, for the same reason: with the log, a stuck modifier is
  a one-line answer instead of a mystery.
- **Synthesising digits from keypad navigation keys.** It would turn arrow keys
  into card characters on every terminal to fix a configuration problem on one.
- **`_isScanning` dropping a tap silently.** ADR-0027 does not define feedback
  for it, and inventing a hint here would be a policy change; it is recorded
  instead.

### Verification

```bash
cd terminal-frontend
flutter test                       # 881/881
flutter analyze <touched files>    # no issues
```

The sync wedge (row 7) reproduces on `main`:

```
SqliteException(2067): UNIQUE constraint failed: members_cache.card_uid
  MembersRepository a card UID handed to another member (#370)
    the new holder gets the card and the previous one loses it
```

### Still open after this change

The review could not identify *the* cause from the report alone, and says so
rather than picking one: rows 1–5 are all consistent with "sometimes". The next
occurrence on the affected terminal is now readable — the status modal section
and `error.log` name the stage — and the follow-up is whatever that says, not
another guess.
