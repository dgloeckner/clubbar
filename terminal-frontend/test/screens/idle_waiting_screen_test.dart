import 'package:flutter/material.dart';
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

    // Keyboard-wedge capture moved to the app shell in issue #26 — it is
    // covered by test/widgets/scan_capture_test.dart.
  });

  group('IdleWaitingScreen error banner', () {
    late FakeRfidProvider rfidProvider;
    late MockSyncProvider mockSyncProvider;

    /// Resolved once so the finder below tracks the ARB, not a copied literal.
    late String unknownCardCopy;

    setUpAll(() async {
      unknownCardCopy = await errorCopy(TerminalErrorKey.unknownCard);
    });

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

    // Mirrors the screen's own display window and fade duration.
    const displayDuration = Duration(seconds: 5);
    const fadeDuration = Duration(milliseconds: 500);

    /// Opacity the error banner is actually *rendered* at — the animation's
    /// current value, not the target `AnimatedOpacity` is heading for. Null
    /// when no banner is in the tree at all.
    double? bannerOpacity(WidgetTester tester) {
      final banner = find.ancestor(
        of: find.text(unknownCardCopy),
        matching: find.byType(FadeTransition),
      );
      if (banner.evaluate().isEmpty) return null;
      return tester.widget<FadeTransition>(banner.first).opacity.value;
    }

    /// Signal a failed scan and settle the fade, so assertions afterwards see
    /// the rendered banner rather than a frame mid-animation. Two pumps: one
    /// for the provider notification, one for the screen's post-frame callback
    /// that (re)starts the dismiss timer.
    Future<void> failScan(WidgetTester tester) async {
      rfidProvider.failScan(TerminalErrorKey.unknownCard);
      await tester.pump();
      await tester.pump();
      await tester.pump(fadeDuration);
    }

    /// Let the display window and the following fade run out.
    Future<void> letBannerExpire(WidgetTester tester) async {
      await tester.pump(displayDuration);
      await tester.pump(fadeDuration + const Duration(milliseconds: 100));
    }

    testWidgets('shows the banner for a failed scan', (tester) async {
      await tester.pumpWidget(buildTestApp());
      await tester.pump();

      await failScan(tester);
      expect(bannerOpacity(tester), 1.0);

      await letBannerExpire(tester);
      expect(bannerOpacity(tester), isNull);
    });

    testWidgets('shows the banner again for a repeat of the same error',
        (tester) async {
      await tester.pumpWidget(buildTestApp());
      await tester.pump();

      await failScan(tester);
      expect(bannerOpacity(tester), 1.0);

      await letBannerExpire(tester);
      expect(bannerOpacity(tester), isNull);

      // Same card, same error key — must still be shown.
      await failScan(tester);
      expect(bannerOpacity(tester), 1.0);

      // ...and for the rest of the display window, not a truncated one.
      await tester.pump(const Duration(seconds: 4));
      expect(bannerOpacity(tester), 1.0);
    });

    testWidgets('a repeat scan during the fade-out is not cut short by the '
        'previous dismissal', (tester) async {
      await tester.pumpWidget(buildTestApp());
      await tester.pump();

      await failScan(tester);

      // Sit inside the fade-out: the dismissal has fired and its clear is
      // still pending, 100 ms out. (`failScan` already consumed `fadeDuration`
      // settling the fade-in.)
      await tester.pump(displayDuration - fadeDuration);
      await tester.pump(fadeDuration - const Duration(milliseconds: 100));
      expect(bannerOpacity(tester), lessThan(0.5));

      await failScan(tester);
      expect(bannerOpacity(tester), 1.0);

      // The stale clear from the first error must not wipe the new one.
      await tester.pump(const Duration(milliseconds: 600));
      expect(bannerOpacity(tester), 1.0);
    });

    testWidgets('does not throw when the screen is disposed with a pending '
        'dismiss timer', (tester) async {
      await tester.pumpWidget(buildTestApp());
      await tester.pump();

      await failScan(tester);

      await tester.pumpWidget(createTestApp(child: const SizedBox()));
      await tester.pump(const Duration(seconds: 6));

      expect(tester.takeException(), isNull);
    });
  });
}
