# RFID HardwareKeyboard Input Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace the hidden off-screen `TextField` RFID input approach with `HardwareKeyboard.instance` so card scanning works reliably on Linux/Wayland (Raspberry Pi) as well as macOS.

**Architecture:** The hidden `TextField` at `left: -1000` fails on Linux because the Wayland compositor doesn't route keyboard events to widgets outside the viewport. `HardwareKeyboard.instance.addHandler()` is Flutter's global low-level key event listener — it receives all keyboard input regardless of widget focus or screen position. The handler accumulates characters into a buffer and calls `rfidProvider.emitScan(uid)` when Enter is received. `RealRfidService` and `RfidProvider` are unchanged; only `IdleWaitingScreen` changes.

**Tech Stack:** `package:flutter/services.dart` (`HardwareKeyboard`, `KeyEvent`, `KeyDownEvent`, `LogicalKeyboardKey`).

---

### Task 1: Replace hidden TextField with HardwareKeyboard in IdleWaitingScreen

**Files:**
- Modify: `lib/screens/idle_waiting_screen.dart`
- Modify: `test/screens/idle_waiting_screen_test.dart`

---

#### Step 1: Add a failing test for keyboard input

Add `import 'package:flutter/services.dart';` to the top of `test/screens/idle_waiting_screen_test.dart`.

Add this new test inside the `group('IdleWaitingScreen', ...)` block, after the last existing test:

```dart
    testWidgets('keyboard input emits scan to rfidProvider on Enter', (WidgetTester tester) async {
      when(() => mockRfidProvider.addListener(any())).thenReturn(null);
      when(() => mockRfidProvider.removeListener(any())).thenReturn(null);
      when(() => mockRfidProvider.isScanning).thenReturn(false);

      await tester.pumpWidget(buildTestApp());
      await tester.pump(); // trigger addPostFrameCallback → registers HardwareKeyboard handler

      // Simulate RFID reader typing '0', '0', '3' then Enter
      await tester.sendKeyEvent(LogicalKeyboardKey.digit0);
      await tester.sendKeyEvent(LogicalKeyboardKey.digit0);
      await tester.sendKeyEvent(LogicalKeyboardKey.digit3);
      await tester.sendKeyEvent(LogicalKeyboardKey.enter);
      await tester.pump();

      verify(() => mockRfidProvider.emitScan('003')).called(1);
    });
```

#### Step 2: Run test to verify it fails

```bash
cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend
flutter test test/screens/idle_waiting_screen_test.dart
```

Expected: new test fails — `emitScan` is never called because the screen still uses the hidden TextField approach. Existing 4 tests still pass.

---

#### Step 3: Implement the HardwareKeyboard approach

Replace the entire content of `lib/screens/idle_waiting_screen.dart` with the version below. The changes are:

- Remove `_rfidFocusNode` (`FocusNode`) and `_rfidController` (`TextEditingController`)
- Remove the focus listener block in `initState`
- Add `HardwareKeyboard.instance.addHandler(_onKeyEvent)` in the `addPostFrameCallback`
- Add `StringBuffer _rfidBuffer` field
- Add `bool _onKeyEvent(KeyEvent event)` method
- Remove the off-screen `Positioned` `TextField` from `build()`
- Add `import 'package:flutter/services.dart'`

```dart
import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/l10n/app_localizations.dart';
import 'package:ruderbar_terminal/providers/rfid_provider.dart';
import 'package:ruderbar_terminal/providers/sync_provider.dart';
import 'package:ruderbar_terminal/utils/design_tokens.dart';
import 'package:ruderbar_terminal/widgets/rfid_detector_button.dart';

class IdleWaitingScreen extends StatefulWidget {
  const IdleWaitingScreen({super.key});

  @override
  State<IdleWaitingScreen> createState() => _IdleWaitingScreenState();
}

class _IdleWaitingScreenState extends State<IdleWaitingScreen> {
  final StringBuffer _rfidBuffer = StringBuffer();
  Timer? _errorDismissTimer;
  String? _lastError;
  double _errorOpacity = 1.0;
  late RfidProvider _rfidProvider;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      context.read<SyncProvider>().startBackgroundSync();

      // Save reference to RfidProvider for safe disposal
      _rfidProvider = context.read<RfidProvider>();

      // Start listening for real RFID scans (stream subscription)
      _rfidProvider.startListening(context);

      // Capture all keyboard input globally — works on Linux/Wayland and macOS
      HardwareKeyboard.instance.addHandler(_onKeyEvent);
    });
  }

  @override
  void dispose() {
    _errorDismissTimer?.cancel();
    HardwareKeyboard.instance.removeHandler(_onKeyEvent);
    _rfidProvider.stopListening();
    super.dispose();
  }

  /// Accumulate characters from the RFID reader (USB keyboard emulation).
  /// Emits the buffered UID when Enter is received.
  bool _onKeyEvent(KeyEvent event) {
    if (event is! KeyDownEvent) return false;
    if (event.logicalKey == LogicalKeyboardKey.enter) {
      final uid = _rfidBuffer.toString().trim();
      _rfidBuffer.clear();
      if (uid.isNotEmpty) {
        _rfidProvider.emitScan(uid);
      }
    } else if (event.character != null && event.character!.isNotEmpty) {
      _rfidBuffer.write(event.character);
    }
    return false; // don't consume — let other widgets handle events normally
  }

  /// Translate RFID error key to localized message
  String _getLocalizedError(BuildContext context, String errorKey) {
    final l10n = AppLocalizations.of(context)!;

    switch (errorKey) {
      case 'rfidErrorUnknownCard':
        return l10n.rfidErrorUnknownCard;
      case 'rfidErrorAccountInactive':
        return l10n.rfidErrorAccountInactive;
      case 'rfidErrorSepaMissing':
        return l10n.rfidErrorSepaMissing;
      case 'rfidErrorDatabaseError':
        return l10n.rfidErrorDatabaseError;
      default:
        return errorKey;
    }
  }

  /// Start auto-dismiss timer for error message
  void _startErrorDismissTimer(String error) {
    _errorDismissTimer?.cancel();

    setState(() {
      _errorOpacity = 1.0;
      _lastError = error;
    });

    _errorDismissTimer = Timer(const Duration(seconds: 5), () {
      setState(() {
        _errorOpacity = 0.0;
      });

      Timer(const Duration(milliseconds: 500), () {
        if (mounted) {
          context.read<RfidProvider>().clearDetection();
        }
      });
    });
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;

    return Container(
      decoration: const BoxDecoration(
        gradient: RadialGradient(
          center: Alignment.center,
          radius: 0.7,
          colors: [
            Color(0x143b82f6),
            Colors.transparent,
          ],
        ),
      ),
      child: Center(
        child: SingleChildScrollView(
          child: Padding(
            padding: const EdgeInsets.symmetric(
              vertical: AppSpacing.xxxl,
              horizontal: AppSpacing.lg,
            ),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                // RFID button with error overlay (no layout jump)
                Consumer<RfidProvider>(
                  builder: (context, rfidProvider, child) {
                    if (rfidProvider.error != null && rfidProvider.error != _lastError) {
                      WidgetsBinding.instance.addPostFrameCallback((_) {
                        _startErrorDismissTimer(rfidProvider.error!);
                      });
                    }

                    return SizedBox(
                      width: 300,
                      height: 182,
                      child: Stack(
                        alignment: Alignment.center,
                        children: [
                          RfidDetectorButton(
                            hasError: rfidProvider.error != null,
                            errorOpacity: _errorOpacity,
                          ),
                          if (rfidProvider.error != null)
                            Positioned(
                              bottom: 0,
                              left: 0,
                              right: 0,
                              child: AnimatedOpacity(
                                opacity: _errorOpacity,
                                duration: const Duration(milliseconds: 500),
                                child: Container(
                                  padding: const EdgeInsets.symmetric(
                                    horizontal: AppSpacing.md,
                                    vertical: AppSpacing.sm,
                                  ),
                                  decoration: BoxDecoration(
                                    color: const Color(0xffef4444).withValues(alpha: 0.95),
                                    borderRadius: BorderRadius.circular(AppBorderRadius.md),
                                    border: Border.all(
                                      color: const Color(0xffef4444),
                                      width: 1,
                                    ),
                                  ),
                                  child: Text(
                                    _getLocalizedError(context, rfidProvider.error!),
                                    textAlign: TextAlign.center,
                                    style: const TextStyle(
                                      color: Colors.white,
                                      fontSize: AppFontSizes.sm,
                                      fontWeight: FontWeight.w600,
                                    ),
                                  ),
                                ),
                              ),
                            ),
                        ],
                      ),
                    );
                  },
                ),
                const SizedBox(height: AppSpacing.xxxl),

                Text(
                  l10n.idleTitle,
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                    color: Color(0xfff1f5f9),
                    fontSize: 42,
                    fontWeight: FontWeight.bold,
                  ),
                ),
                const SizedBox(height: AppSpacing.md),

                Text(
                  l10n.idleSubtitle,
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                    color: Color(0xff94a3b8),
                    fontSize: 21,
                    fontWeight: FontWeight.w400,
                  ),
                ),
                Consumer<RfidProvider>(
                  builder: (context, rfidProvider, child) {
                    return ElevatedButton(
                      onPressed: !rfidProvider.isScanning
                          ? () => rfidProvider.simulateCardDetection(context)
                          : null,
                      style: ElevatedButton.styleFrom(
                        backgroundColor: const Color(0xff3b82f6),
                        disabledBackgroundColor: const Color(0xff334155),
                        padding: const EdgeInsets.symmetric(
                          horizontal: AppSpacing.xl,
                          vertical: AppSpacing.md,
                        ),
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(AppBorderRadius.md),
                        ),
                      ),
                      child: Text(
                        l10n.demoScanCard,
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: AppFontSizes.base,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    );
                  },
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
```

Key structural changes vs. the old version:
- No `FocusNode`, no `TextEditingController`, no `Positioned` off-screen `TextField`
- The outermost `Stack` in `build()` is gone — only the `Container` with the radial gradient remains (previously it was the outer `Stack` child)
- `_onKeyEvent` is the new entry point for RFID input

---

#### Step 4: Run tests

```bash
cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend
flutter test test/screens/idle_waiting_screen_test.dart
```

Expected: all 5 tests pass (4 existing + 1 new).

If the new keyboard test fails with `emitScan` not called: check that `await tester.pump()` after `pumpWidget` is present (needed to trigger `addPostFrameCallback` where the handler is registered). If `character` is null for digit keys in the test environment, use `tester.sendKeyDownEvent` with explicit character data — but `sendKeyEvent` should work for standard ASCII keys.

#### Step 5: Run full suite

```bash
flutter test
```

Expected: same count as before + 1 new passing test (310 pass → 311 pass; 5 pre-existing unrelated failures unchanged).

#### Step 6: Run flutter analyze

```bash
flutter analyze lib/screens/idle_waiting_screen.dart
```

Expected: no issues.

#### Step 7: Commit

```bash
cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend
git add lib/screens/idle_waiting_screen.dart test/screens/idle_waiting_screen_test.dart
git commit -m "fix: replace hidden TextField with HardwareKeyboard for RFID input on Linux"
```

---

### Task 2: Update RFID_IMPLEMENTATION.md

**Files:**
- Modify: `RFID_IMPLEMENTATION.md`

#### Step 1: Update the architecture description

In `RFID_IMPLEMENTATION.md`, find the entry for component 3 (`IdleWaitingScreen`) and replace:

```
3. **IdleWaitingScreen** (`lib/screens/idle_waiting_screen.dart`)
   - Hidden TextField to capture USB keyboard input
   - Auto-focus management (prevents focus loss)
   - Error display for scan failures
   - Optional demo button for testing without hardware
```

with:

```
3. **IdleWaitingScreen** (`lib/screens/idle_waiting_screen.dart`)
   - `HardwareKeyboard.instance` handler captures USB keyboard input globally
   - Works on Linux/Wayland and macOS (no widget focus dependency)
   - Buffers characters, emits on Enter
   - Error display for scan failures
   - Optional demo button for testing without hardware
```

#### Step 2: Update the flow diagram

Find the "USB Keyboard Emulation Flow" section and replace steps 3–4:

Old:
```
3. Hidden TextField captures input (always focused)
   ↓
4. TextField's onSubmitted callback fires
```

New:
```
3. HardwareKeyboard handler receives key events
   ↓
4. Characters buffered; Enter triggers emitScan
```

#### Step 3: Update the "Hidden TextField Details" section

Replace the entire "### Hidden TextField Details" subsection with:

```markdown
### HardwareKeyboard Handler Details

The keyboard handler:
- Registered via `HardwareKeyboard.instance.addHandler()` when the idle screen mounts
- Receives **all** key events regardless of which widget (if any) has focus
- Accumulates characters in a `StringBuffer`; on `Enter` emits the buffered UID
- Returns `false` (does not consume events) — other handlers continue to work
- Unregistered in `dispose()` — no resource leak
- Works on Linux/Wayland, macOS, and Windows without platform-specific code

This replaces the previous approach of a hidden `TextField` at `left: -1000`, which failed
on Linux/Wayland because the compositor does not route keyboard events to widgets outside
the visible viewport.
```

#### Step 4: Update the Debugging section

Replace the "Check hidden TextField focus" bullet with:

```markdown
2. **Test reader output**:
   - Open a text editor
   - Scan a card
   - Verify UID appears followed by newline

3. **Check HardwareKeyboard handler**:
   - Add `debugPrint('key: ${event.character}')` in `_onKeyEvent`
   - Scan card
   - Verify characters appear in console followed by the UID being emitted
```

Also remove the "Disabling Auto-Focus" section under Configuration (it no longer applies).

#### Step 5: Commit

```bash
cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend
git add RFID_IMPLEMENTATION.md
git commit -m "docs: update RFID_IMPLEMENTATION.md for HardwareKeyboard approach"
```

---

## Verification

After both tasks, run the full suite one more time:

```bash
flutter test
```

Expected: all tests pass (with the same 5 pre-existing unrelated failures in `status_info_modal_test.dart` and `dispenser_recovery_service_test.dart`).

Manual smoke test on Raspberry Pi:
1. Deploy the updated app
2. Open idle screen
3. Scan an RFID card → app navigates to product selection
4. Scan an unknown card → error banner appears

On macOS dev machine:
1. Run app (`flutter run -d macos`)
2. With a USB RFID reader, scan a card → should work as before
