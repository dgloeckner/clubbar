// Issue #26: the keyboard-wedge capture and the scan feedback surface live in
// the app shell, so a card tap works — and says something — on every route.
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/models/scan_hint.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/providers/rfid_provider.dart';
import 'package:clubbar_terminal/services/scan_log.dart';
import 'package:clubbar_terminal/widgets/scan_capture.dart';
import '../test_helpers.dart';

/// A real [ChangeNotifier] standing in for [RfidProvider] so the shell rebuilds
/// on notification the way it does in production.
class FakeRfidProvider extends ChangeNotifier implements RfidProvider {
  final List<String> emittedScans = [];
  int startListeningCalls = 0;
  TerminalError? _error;
  ScanHint? _hint;
  int _sequence = 0;

  @override
  String Function()? locationResolver;

  @override
  TerminalError? get error => _error;

  @override
  ScanHint? get hint => _hint;

  @override
  bool get isScanning => false;

  @override
  void emitScan(String cardUid) => emittedScans.add(cardUid);

  @override
  void startListening(BuildContext context) => startListeningCalls++;

  @override
  void stopListening() {}

  void failScan(TerminalErrorKey key) {
    _error = TerminalError(key: key, sequence: ++_sequence);
    _hint = null;
    notifyListeners();
  }

  void hintScan(ScanHintKey key) {
    _hint = ScanHint(key: key, sequence: ++_sequence);
    _error = null;
    notifyListeners();
  }

  @override
  void clearHint() {
    _hint = null;
    notifyListeners();
  }

  @override
  void clearDetection() {
    _error = null;
    _hint = null;
    notifyListeners();
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

void main() {
  late FakeRfidProvider rfid;

  setUp(() => rfid = FakeRfidProvider());

  Widget buildShell({String location = '/products'}) => createTestApp(
        child: ChangeNotifierProvider<RfidProvider>.value(
          value: rfid,
          child: ScanCapture(
            location: location,
            child: const Scaffold(body: Text('screen body')),
          ),
        ),
      );

  group('ScanCapture', () {
    testWidgets('subscribes to the reader once the shell mounts',
        (tester) async {
      await tester.pumpWidget(buildShell());
      await tester.pump();

      expect(rfid.startListeningCalls, 1);
      expect(rfid.locationResolver, isNotNull);
      expect(rfid.locationResolver!(), '/products');
    });

    testWidgets('buffers reader keystrokes and emits the UID on Enter — on a '
        'route other than idle', (tester) async {
      await tester.pumpWidget(buildShell());
      await tester.pump(); // post-frame callback installs the handler

      await tester.sendKeyEvent(LogicalKeyboardKey.digit0);
      await tester.sendKeyEvent(LogicalKeyboardKey.digit0);
      await tester.sendKeyEvent(LogicalKeyboardKey.digit3);
      await tester.sendKeyEvent(LogicalKeyboardKey.enter);
      await tester.pump();

      expect(rfid.emittedScans, ['003']);
    });

    testWidgets('an Enter with nothing buffered emits nothing', (tester) async {
      await tester.pumpWidget(buildShell());
      await tester.pump();

      await tester.sendKeyEvent(LogicalKeyboardKey.enter);
      await tester.pump();

      expect(rfid.emittedScans, isEmpty);
    });

    // Capturing app-wide means stray host keystrokes reach the buffer on every
    // screen, not just on the idle screen. A reader emits a whole UID as one
    // fast burst; anything else must not be glued into a scan.
    testWidgets('a keystroke with a modifier held is not reader output',
        (tester) async {
      await tester.pumpWidget(buildShell());
      await tester.pump();

      await tester.sendKeyDownEvent(LogicalKeyboardKey.metaLeft);
      await tester.sendKeyEvent(LogicalKeyboardKey.digit7);
      await tester.sendKeyUpEvent(LogicalKeyboardKey.metaLeft);
      await tester.sendKeyEvent(LogicalKeyboardKey.digit0);
      await tester.sendKeyEvent(LogicalKeyboardKey.digit3);
      await tester.sendKeyEvent(LogicalKeyboardKey.enter);
      await tester.pump();

      expect(rfid.emittedScans, ['03'],
          reason: 'the shortcut digit must not become part of the UID');
    });

    testWidgets('characters typed slowly are dropped, not glued onto the next '
        'scan', (tester) async {
      await tester.pumpWidget(buildShell());
      await tester.pump();

      await tester.sendKeyEvent(LogicalKeyboardKey.digit9);
      await tester.pump(const Duration(seconds: 2));
      await tester.sendKeyEvent(LogicalKeyboardKey.digit0);
      await tester.sendKeyEvent(LogicalKeyboardKey.digit3);
      await tester.sendKeyEvent(LogicalKeyboardKey.enter);
      await tester.pump();

      expect(rfid.emittedScans, ['03']);
    });

    // Issue #18: a reader that types lower-case hex used to produce a UID that
    // no exact-match lookup could resolve.
    testWidgets('a lower-case reader emits a canonical UID', (tester) async {
      await tester.pumpWidget(buildShell());
      await tester.pump();

      for (final key in [
        LogicalKeyboardKey.keyA,
        LogicalKeyboardKey.keyB,
        LogicalKeyboardKey.digit1,
      ]) {
        await tester.sendKeyEvent(key);
      }
      await tester.sendKeyEvent(LogicalKeyboardKey.enter);
      await tester.pump();

      expect(rfid.emittedScans, ['AB1']);
    });

    testWidgets('a stale partial UID is never emitted on a later Enter',
        (tester) async {
      await tester.pumpWidget(buildShell());
      await tester.pump();

      await tester.sendKeyEvent(LogicalKeyboardKey.digit9);
      await tester.pump(const Duration(seconds: 2));
      await tester.sendKeyEvent(LogicalKeyboardKey.enter);
      await tester.pump();

      expect(rfid.emittedScans, isEmpty);
    });

    // Issue #370: "send Enter" is the keypad's Enter on a good number of
    // keyboard-wedge readers, and that is a different key. Such a reader could
    // never finish a scan — the characters just aged out of the buffer, in
    // silence.
    testWidgets('the keypad Enter terminates a scan like Return does',
        (tester) async {
      await tester.pumpWidget(buildShell());
      await tester.pump();

      await tester.sendKeyEvent(LogicalKeyboardKey.digit0);
      await tester.sendKeyEvent(LogicalKeyboardKey.digit3);
      await tester.sendKeyEvent(LogicalKeyboardKey.numpadEnter);
      await tester.pump();

      expect(rfid.emittedScans, ['03']);
    });

    testWidgets('a terminator delivered only as a newline character still '
        'ends the scan', (tester) async {
      await tester.pumpWidget(buildShell());
      await tester.pump();

      await tester.sendKeyEvent(LogicalKeyboardKey.digit0);
      await tester.sendKeyEvent(LogicalKeyboardKey.digit3);
      await tester.sendKeyEvent(LogicalKeyboardKey.enter, character: '\n');
      await tester.pump();

      expect(rfid.emittedScans, ['03']);
    });
  });

  // Issue #370: none of the outcomes below makes a sound, shows a banner or
  // moves the spinner, so the scan log is the only place they can be seen.
  group('ScanCapture diagnostics', () {
    setUp(() => ScanLog.instance.clear());

    testWidgets('records a captured UID with the burst it arrived in',
        (tester) async {
      await tester.pumpWidget(buildShell());
      await tester.pump();

      await tester.sendKeyEvent(LogicalKeyboardKey.digit0);
      await tester.sendKeyEvent(LogicalKeyboardKey.digit3);
      await tester.sendKeyEvent(LogicalKeyboardKey.enter);
      await tester.pump();

      final event = ScanLog.instance.latest!;
      expect(event.kind, ScanEventKind.uidCaptured);
      expect(event.uid, '03');
      expect(event.detail, contains('2 chars'));
    });

    testWidgets('records the partial scan the gap timer throws away',
        (tester) async {
      await tester.pumpWidget(buildShell());
      await tester.pump();

      await tester.sendKeyEvent(LogicalKeyboardKey.digit9);
      await tester.pump(const Duration(seconds: 2));

      final event = ScanLog.instance.latest!;
      expect(event.kind, ScanEventKind.partialDiscarded);
      expect(event.detail, contains('1 chars'));
    });

    testWidgets('records a terminator that arrived with nothing buffered',
        (tester) async {
      await tester.pumpWidget(buildShell());
      await tester.pump();

      await tester.sendKeyEvent(LogicalKeyboardKey.enter);
      await tester.pump();

      expect(ScanLog.instance.latest!.kind, ScanEventKind.emptyTerminator);
    });

    // A modifier the compositor still believes is held silences the reader
    // completely — from the bar that looks exactly like a dead scanner.
    testWidgets('records a keystroke suppressed by a held modifier',
        (tester) async {
      await tester.pumpWidget(buildShell());
      await tester.pump();

      await tester.sendKeyDownEvent(LogicalKeyboardKey.controlLeft);
      await tester.sendKeyEvent(LogicalKeyboardKey.digit7);
      await tester.sendKeyUpEvent(LogicalKeyboardKey.controlLeft);
      await tester.pump();

      final event = ScanLog.instance.latest!;
      expect(event.kind, ScanEventKind.modifierSuppressed);
      expect(event.detail, contains('hid'));
    });

    testWidgets('a modifier press of its own is not reported as lost input',
        (tester) async {
      await tester.pumpWidget(buildShell());
      await tester.pump();

      await tester.sendKeyDownEvent(LogicalKeyboardKey.shiftLeft);
      await tester.sendKeyUpEvent(LogicalKeyboardKey.shiftLeft);
      await tester.pump();

      expect(ScanLog.instance.events, isEmpty);
    });

    // What a reader typing on the numeric keypad with NumLock off looks like:
    // every digit arrives as a navigation key with no character, and the scan
    // produces nothing whatsoever. The HID usage in the record is what
    // identifies it from a terminal in the field.
    testWidgets('records a key that carries no character, with its HID usage',
        (tester) async {
      await tester.pumpWidget(buildShell());
      await tester.pump();

      await tester.sendKeyEvent(
        LogicalKeyboardKey.arrowLeft,
        physicalKey: PhysicalKeyboardKey.numpad4,
      );
      await tester.pump();

      final event = ScanLog.instance.latest!;
      expect(event.kind, ScanEventKind.unprintableKey);
      expect(event.detail, contains('0x7005c'),
          reason: 'USB HID usage of keypad 4');
    });
  });

  group('ScanCapture feedback', () {
    testWidgets('shows a refusal hint over the current screen', (tester) async {
      await tester.pumpWidget(buildShell());
      await tester.pump();

      rfid.hintScan(ScanHintKey.logOutFirst);
      await tester.pump();

      expect(find.text('Bitte zuerst abmelden'), findsOneWidget);
      expect(find.text('screen body'), findsOneWidget);
      // Rendered into the root overlay, not into the shell's own stack — a
      // modal dialog belongs to the root navigator and would otherwise cover
      // the banner (rule 7's hint is shown precisely while such a dialog runs).
      expect(
        find.descendant(
          of: find.byType(ScanCapture),
          matching: find.text('Bitte zuerst abmelden'),
        ),
        findsNothing,
      );
    });

    testWidgets('is visible while a modal dialog holds the screen',
        (tester) async {
      await tester.pumpWidget(buildShell());
      await tester.pump();

      // Mirrors the dispensing progress dialog: root navigator, modal barrier.
      final shellContext = tester.element(find.text('screen body'));
      showDialog<void>(
        context: shellContext,
        barrierDismissible: false,
        builder: (_) => const Text('dispensing'),
      );
      await tester.pumpAndSettle();
      expect(find.text('dispensing'), findsOneWidget);

      rfid.hintScan(ScanHintKey.transactionInProgress);
      await tester.pump();

      expect(find.text('Bitte warten – Bezahlung läuft'), findsOneWidget);
    });

    testWidgets('shows a scan error on a non-idle route', (tester) async {
      await tester.pumpWidget(buildShell());
      await tester.pump();

      rfid.failScan(TerminalErrorKey.unknownCard);
      await tester.pump();

      expect(find.text(await errorCopy(TerminalErrorKey.unknownCard)),
          findsOneWidget);
    });

    testWidgets('a repeat of the same refusal is shown again', (tester) async {
      await tester.pumpWidget(buildShell());
      await tester.pump();

      rfid.hintScan(ScanHintKey.logOutFirst);
      await tester.pump();
      await tester.pump(const Duration(seconds: 6)); // first one faded out
      expect(find.text('Bitte zuerst abmelden'), findsNothing);

      rfid.hintScan(ScanHintKey.logOutFirst);
      await tester.pump();

      expect(find.text('Bitte zuerst abmelden'), findsOneWidget);
      await tester.pump(const Duration(seconds: 6));
    });

    testWidgets('the feedback fades away on its own', (tester) async {
      await tester.pumpWidget(buildShell());
      await tester.pump();

      rfid.hintScan(ScanHintKey.logOutFirst);
      await tester.pump();
      await tester.pump(const Duration(seconds: 6));

      expect(find.text('Bitte zuerst abmelden'), findsNothing);
    });

    testWidgets('stays silent on the idle route, which shows scan errors '
        'inline on the RFID button', (tester) async {
      await tester.pumpWidget(buildShell(location: '/idle'));
      await tester.pump();

      rfid.failScan(TerminalErrorKey.unknownCard);
      await tester.pump();

      expect(find.text(await errorCopy(TerminalErrorKey.unknownCard)), findsNothing);
    });
  });
}
