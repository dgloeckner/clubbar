import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:clubbar_terminal/services/display_power.dart';
import 'package:clubbar_terminal/widgets/screen_blanker.dart';

/// Records what the panel was asked to do, so a test can assert the *display*
/// was switched rather than just that a black box appeared.
class FakeDisplayPower implements DisplayPower {
  final List<String> calls = [];

  /// What the hardware reports back. `null` is the unreadable panel — no DRM
  /// node, or a read that failed — where the widget has nothing but its own
  /// flag to go on.
  bool? hardwareOn;

  FakeDisplayPower({this.hardwareOn});

  @override
  Future<void> on() async {
    calls.add('on');
    if (hardwareOn != null) hardwareOn = true;
  }

  @override
  Future<void> off() async {
    calls.add('off');
    if (hardwareOn != null) hardwareOn = false;
  }

  @override
  Future<bool?> isOn() async => hardwareOn;
}

void main() {
  const timeout = Duration(seconds: 300);

  /// The blank surface is the only [ColoredBox] painting pure black that this
  /// widget introduces, so its presence is the state under test.
  Finder blankSurface() => find.byWidgetPredicate(
        (w) => w is ColoredBox && w.color == Colors.black,
      );

  Widget harness({
    bool enabled = true,
    DisplayPower? displayPower,
    VoidCallback? onButtonPressed,
  }) {
    return MaterialApp(
      home: ScreenBlanker(
        enabled: enabled,
        timeout: timeout,
        displayPower: displayPower,
        child: Scaffold(
          body: Center(
            child: ElevatedButton(
              onPressed: onButtonPressed ?? () {},
              child: const Text('tap me'),
            ),
          ),
        ),
      ),
    );
  }

  group('ScreenBlanker', () {
    testWidgets('does not blank before the timeout elapses', (tester) async {
      await tester.pumpWidget(harness());

      await tester.pump(timeout - const Duration(seconds: 1));

      expect(blankSurface(), findsNothing);
    });

    testWidgets('blanks once the timeout elapses', (tester) async {
      await tester.pumpWidget(harness());

      await tester.pump(timeout);
      await tester.pump();

      expect(blankSurface(), findsOneWidget);
    });

    testWidgets('never blanks when disabled', (tester) async {
      await tester.pumpWidget(harness(enabled: false));

      await tester.pump(timeout * 3);
      await tester.pump();

      expect(blankSurface(), findsNothing);
    });

    testWidgets('a touch pushes the deadline out', (tester) async {
      await tester.pumpWidget(harness());

      // Just short of blanking, then touch: the clock restarts, so the
      // original deadline must pass without the screen going dark.
      await tester.pump(timeout - const Duration(seconds: 1));
      await tester.tap(find.text('tap me'));
      await tester.pump(const Duration(seconds: 2));

      expect(blankSurface(), findsNothing);
    });

    testWidgets('powers the panel down, not just the pixels', (tester) async {
      final power = FakeDisplayPower();
      await tester.pumpWidget(harness(displayPower: power));
      power.calls.clear(); // the startup reconcile, asserted on its own below

      await tester.pump(timeout);
      await tester.pump();

      expect(power.calls, ['off']);
    });

    testWidgets('paints black even with no DisplayPower', (tester) async {
      // The fallback for a panel that ignores signal loss: no power control
      // configured, but the screen must still go dark.
      await tester.pumpWidget(harness());

      await tester.pump(timeout);
      await tester.pump();

      expect(blankSurface(), findsOneWidget);
    });

    group('waking', () {
      testWidgets('a touch wakes the screen and powers the panel back on',
          (tester) async {
        final power = FakeDisplayPower();
        await tester.pumpWidget(harness(displayPower: power));
        power.calls.clear();

        await tester.pump(timeout);
        await tester.pump();
        expect(blankSurface(), findsOneWidget);

        await tester.tapAt(const Offset(400, 300));
        await tester.pump();

        expect(blankSurface(), findsNothing);
        expect(power.calls, ['off', 'on']);
      });

      testWidgets('the touch that wakes the screen does NOT reach the app',
          (tester) async {
        // Someone reaching for a dark terminal must not have their first tap
        // land on whatever button happens to be underneath.
        var pressed = 0;
        await tester.pumpWidget(harness(onButtonPressed: () => pressed++));

        await tester.pump(timeout);
        await tester.pump();

        await tester.tap(find.text('tap me'), warnIfMissed: false);
        await tester.pump();

        expect(blankSurface(), findsNothing, reason: 'the tap woke the screen');
        expect(pressed, 0, reason: 'but must not have pressed the button');
      });

      testWidgets('a key wakes the screen', (tester) async {
        final power = FakeDisplayPower();
        await tester.pumpWidget(harness(displayPower: power));
        power.calls.clear();

        await tester.pump(timeout);
        await tester.pump();
        expect(blankSurface(), findsOneWidget);

        await simulateKeyDownEvent(LogicalKeyboardKey.digit1);
        await tester.pump();

        expect(blankSurface(), findsNothing);
        expect(power.calls, ['off', 'on']);
      });

      testWidgets('a wake key still reaches other keyboard handlers',
          (tester) async {
        // The RFID reader is a keyboard-wedge device: a card tap arrives as
        // keystrokes, so a member must be able to wake this terminal by
        // presenting their card and simply be logged in — the wake key has to
        // reach ScanCapture, which registers a HardwareKeyboard handler of its
        // own. (Note this holds regardless of what the blanker's handler
        // returns: HardwareKeyboard runs every handler. The return value
        // governs whether the event is marked handled for the focus system,
        // which is asserted by the widget's own contract, not here.)
        final seen = <LogicalKeyboardKey>[];
        bool spy(KeyEvent e) {
          if (e is KeyDownEvent) seen.add(e.logicalKey);
          return false;
        }

        await tester.pumpWidget(harness());
        HardwareKeyboard.instance.addHandler(spy);
        addTearDown(() => HardwareKeyboard.instance.removeHandler(spy));

        await tester.pump(timeout);
        await tester.pump();

        await simulateKeyDownEvent(LogicalKeyboardKey.digit7);
        await tester.pump();

        expect(seen, contains(LogicalKeyboardKey.digit7));
      });
    });

    group('reconciling against the hardware (#920)', () {
      // The panel's power lives in the hardware, not in this widget. After an
      // OTA update's `systemctl stop` — SIGTERM, no dispose — the previous
      // process left the output off and this one starts believing it is on, so
      // nothing ever asks for it back and the terminal is unusable.

      testWidgets('startup asserts the panel is on, before any input',
          (tester) async {
        final power = FakeDisplayPower(hardwareOn: false);

        await tester.pumpWidget(harness(displayPower: power));

        expect(power.calls, ['on'],
            reason: 'exactly one on(), and nothing else, at startup');
        expect(blankSurface(), findsNothing);
      });

      testWidgets('startup without a DisplayPower does nothing and survives',
          (tester) async {
        await tester.pumpWidget(harness());
        await tester.pump();

        expect(blankSurface(), findsNothing);
      });

      testWidgets(
          'activity wakes a panel the hardware reports off, overlay or not',
          (tester) async {
        // The state the terminal was found in: app running, no overlay
        // painted, panel dark. This is the regression test for #920.
        final power = FakeDisplayPower(hardwareOn: false);
        await tester.pumpWidget(harness(displayPower: power));
        power.calls.clear();
        power.hardwareOn = false; // as if the startup on() had been lost

        await simulateKeyDownEvent(LogicalKeyboardKey.digit1);
        await tester.pump();

        expect(power.calls, ['on']);
        expect(blankSurface(), findsNothing,
            reason: 'there was never an overlay to clear');
      });

      testWidgets('activity on a panel that is on does not re-issue on',
          (tester) async {
        // Pins the fix to the reconcile: a card burst is dozens of key events
        // and must not become dozens of modeset requests.
        final power = FakeDisplayPower(hardwareOn: true);
        await tester.pumpWidget(harness(displayPower: power));
        power.calls.clear();

        await simulateKeyDownEvent(LogicalKeyboardKey.digit1);
        await simulateKeyDownEvent(LogicalKeyboardKey.digit2);
        await tester.tapAt(const Offset(400, 300));
        await tester.pump();

        expect(power.calls, isEmpty);
      });

      testWidgets('an unreadable panel falls back to the flag', (tester) async {
        // No DRM node, or a read that failed: behave exactly as before #920.
        final power = FakeDisplayPower(); // hardwareOn == null
        await tester.pumpWidget(harness(displayPower: power));
        power.calls.clear();

        await simulateKeyDownEvent(LogicalKeyboardKey.digit1);
        await tester.pump();
        expect(power.calls, isEmpty, reason: 'not blanked, state unknown');

        await tester.pump(timeout);
        await tester.pump();
        expect(blankSurface(), findsOneWidget);
        power.calls.clear();

        await simulateKeyDownEvent(LogicalKeyboardKey.digit2);
        await tester.pump();
        expect(power.calls, ['on'], reason: 'blanked, so the flag decides');
        expect(blankSurface(), findsNothing);
      });

      testWidgets('a reconciling wake still pushes the deadline out',
          (tester) async {
        final power = FakeDisplayPower(hardwareOn: false);
        await tester.pumpWidget(harness(displayPower: power));

        await tester.pump(timeout - const Duration(seconds: 1));
        await simulateKeyDownEvent(LogicalKeyboardKey.digit1);
        await tester.pump(const Duration(seconds: 2));

        expect(blankSurface(), findsNothing);
      });
    });

    testWidgets('restores the panel if it is disposed while blanked',
        (tester) async {
      // A terminal must never be left with its display switched off because
      // the app went away — the screen is the only thing the bar can see.
      final power = FakeDisplayPower();
      await tester.pumpWidget(harness(displayPower: power));
      power.calls.clear();

      await tester.pump(timeout);
      await tester.pump();
      expect(power.calls, ['off']);

      await tester.pumpWidget(const MaterialApp(home: SizedBox()));

      expect(power.calls, ['off', 'on']);
    });
  });
}
