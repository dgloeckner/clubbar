import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/providers/rfid_provider.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/screens/idle_waiting_screen.dart';
import '../test_helpers.dart';

class MockRfidProvider extends Mock implements RfidProvider {}
class MockSyncProvider extends Mock implements SyncProvider {}

/// A real [ChangeNotifier] standing in for [RfidProvider], so that the screen
/// rebuilds on notification the way it does in production. A mocked
/// `addListener` would swallow the rebuild and hide exactly the bug under test.
class FakeRfidProvider extends ChangeNotifier implements RfidProvider {
  TerminalError? _error;
  int _sequence = 0;

  @override
  TerminalError? get error => _error;

  @override
  bool get isScanning => false;

  /// Signal a failed scan, mirroring `ErrorSignal.emitError`: every occurrence
  /// gets a fresh sequence, so a repeat of the same key is a distinct event.
  void failScan(TerminalErrorKey key) {
    _error = TerminalError(key: key, sequence: ++_sequence);
    notifyListeners();
  }

  @override
  void clearDetection() {
    _error = null;
    notifyListeners();
  }

  @override
  void startListening(BuildContext context) {}

  @override
  void stopListening() {}

  @override
  void emitScan(String cardUid) {}

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

void main() {
  group('IdleWaitingScreen', () {
    late MockRfidProvider mockRfidProvider;
    late MockSyncProvider mockSyncProvider;

    setUp(() {
      mockRfidProvider = MockRfidProvider();
      mockSyncProvider = MockSyncProvider();
      when(() => mockSyncProvider.startBackgroundSync(intervalSeconds: any(named: 'intervalSeconds')))
          .thenReturn(null);
      when(() => mockSyncProvider.addListener(any())).thenReturn(null);
      when(() => mockSyncProvider.removeListener(any())).thenReturn(null);
    });

    Widget buildTestApp() {
      return createTestApp(
        child: MultiProvider(
          providers: [
            ChangeNotifierProvider<RfidProvider>.value(value: mockRfidProvider),
            ChangeNotifierProvider<SyncProvider>.value(value: mockSyncProvider),
          ],
          child: const Scaffold(body: IdleWaitingScreen()),
        ),
      );
    }

    testWidgets('displays welcome text', (WidgetTester tester) async {
      when(() => mockRfidProvider.addListener(any())).thenReturn(null);
      when(() => mockRfidProvider.removeListener(any())).thenReturn(null);
      when(() => mockRfidProvider.isScanning).thenReturn(false);

      await tester.pumpWidget(buildTestApp());

      expect(find.text('Durstig?'), findsOneWidget); // German translation
    });

    testWidgets('displays subtitle text', (WidgetTester tester) async {
      when(() => mockRfidProvider.addListener(any())).thenReturn(null);
      when(() => mockRfidProvider.removeListener(any())).thenReturn(null);
      when(() => mockRfidProvider.isScanning).thenReturn(false);

      await tester.pumpWidget(buildTestApp());

      expect(find.text('Halte deinen Chip an den Scanner'), findsOneWidget); // German translation
    });

    testWidgets('displays demo button', (WidgetTester tester) async {
      when(() => mockRfidProvider.addListener(any())).thenReturn(null);
      when(() => mockRfidProvider.removeListener(any())).thenReturn(null);
      when(() => mockRfidProvider.isScanning).thenReturn(false);

      await tester.pumpWidget(buildTestApp());

      expect(find.text('Demo: Chip scannen'), findsOneWidget); // German translation
    });

    testWidgets('demo button is disabled when scanning', (WidgetTester tester) async {
      when(() => mockRfidProvider.addListener(any())).thenReturn(null);
      when(() => mockRfidProvider.removeListener(any())).thenReturn(null);
      when(() => mockRfidProvider.isScanning).thenReturn(true);

      await tester.pumpWidget(buildTestApp());

      final button = tester.widget<ElevatedButton>(
        find.widgetWithText(ElevatedButton, 'Demo: Chip scannen'),
      );
      expect(button.onPressed, isNull); // Button disabled when scanning
    });

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
  });

  group('IdleWaitingScreen error banner', () {
    late FakeRfidProvider rfidProvider;
    late MockSyncProvider mockSyncProvider;

    setUp(() {
      rfidProvider = FakeRfidProvider();
      mockSyncProvider = MockSyncProvider();
      when(() => mockSyncProvider.startBackgroundSync(
          intervalSeconds: any(named: 'intervalSeconds'))).thenReturn(null);
      when(() => mockSyncProvider.addListener(any())).thenReturn(null);
      when(() => mockSyncProvider.removeListener(any())).thenReturn(null);
    });

    Widget buildTestApp() {
      return createTestApp(
        child: MultiProvider(
          providers: [
            ChangeNotifierProvider<RfidProvider>.value(value: rfidProvider),
            ChangeNotifierProvider<SyncProvider>.value(value: mockSyncProvider),
          ],
          child: const Scaffold(body: IdleWaitingScreen()),
        ),
      );
    }

    /// Opacity the error banner is currently rendered at; null when no banner
    /// is in the tree at all.
    double? bannerOpacity(WidgetTester tester) {
      final banner = find.ancestor(
        of: find.text('Unbekannter Chip'),
        matching: find.byType(AnimatedOpacity),
      );
      if (banner.evaluate().isEmpty) return null;
      return tester.widget<AnimatedOpacity>(banner).opacity;
    }

    testWidgets('shows the banner for a failed scan', (tester) async {
      await tester.pumpWidget(buildTestApp());
      await tester.pump();

      rfidProvider.failScan(TerminalErrorKey.unknownCard);
      await tester.pump(); // rebuild
      await tester.pump(); // post-frame callback → dismiss timer starts

      expect(bannerOpacity(tester), 1.0);

      // Let the 5 s display window and the 500 ms fade run out.
      await tester.pump(const Duration(seconds: 5));
      await tester.pump(const Duration(milliseconds: 600));
      expect(bannerOpacity(tester), isNull);
    });

    testWidgets('shows the banner again for a repeat of the same error',
        (tester) async {
      await tester.pumpWidget(buildTestApp());
      await tester.pump();

      rfidProvider.failScan(TerminalErrorKey.unknownCard);
      await tester.pump();
      await tester.pump();
      expect(bannerOpacity(tester), 1.0);

      await tester.pump(const Duration(seconds: 5));
      await tester.pump(const Duration(milliseconds: 600));
      expect(bannerOpacity(tester), isNull);

      // Same card, same error key — must still be shown.
      rfidProvider.failScan(TerminalErrorKey.unknownCard);
      await tester.pump();
      await tester.pump();
      expect(bannerOpacity(tester), 1.0);

      // ...and for the full 5 s, not a truncated window.
      await tester.pump(const Duration(seconds: 4));
      expect(bannerOpacity(tester), 1.0);
    });

    testWidgets('a repeat scan during the fade-out is not cut short by the '
        'previous dismissal', (tester) async {
      await tester.pumpWidget(buildTestApp());
      await tester.pump();

      rfidProvider.failScan(TerminalErrorKey.unknownCard);
      await tester.pump();
      await tester.pump();

      // Fade has just started; the previous dismissal still has a pending
      // clear scheduled 500 ms out.
      await tester.pump(const Duration(seconds: 5));
      expect(bannerOpacity(tester), 0.0);

      rfidProvider.failScan(TerminalErrorKey.unknownCard);
      await tester.pump();
      await tester.pump();
      expect(bannerOpacity(tester), 1.0);

      // The stale clear from the first error must not wipe the new one.
      await tester.pump(const Duration(milliseconds: 600));
      expect(bannerOpacity(tester), 1.0);
    });

    testWidgets('does not throw when the screen is disposed with a pending '
        'dismiss timer', (tester) async {
      await tester.pumpWidget(buildTestApp());
      await tester.pump();

      rfidProvider.failScan(TerminalErrorKey.unknownCard);
      await tester.pump();
      await tester.pump();

      await tester.pumpWidget(createTestApp(child: const SizedBox()));
      await tester.pump(const Duration(seconds: 6));

      expect(tester.takeException(), isNull);
    });
  });
}
