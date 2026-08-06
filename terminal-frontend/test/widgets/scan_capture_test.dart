// Issue #26: the keyboard-wedge capture and the scan feedback surface live in
// the app shell, so a card tap works — and says something — on every route.
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/models/scan_hint.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/providers/rfid_provider.dart';
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
