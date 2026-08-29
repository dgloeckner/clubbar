import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/providers/rfid_provider.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/screens/idle_waiting_screen.dart';
import 'package:clubbar_terminal/widgets/rfid_detector_button.dart';
import '../test_helpers.dart';

class MockRfidProvider extends Mock implements RfidProvider {}

class MockSyncProvider extends Mock implements SyncProvider {}

class FakeBuildContext extends Fake implements BuildContext {}

/// Issue #760: the idle screen must produce **no frames**.
///
/// A bar terminal sits on this screen for almost its entire uptime, and after
/// five idle minutes `scripts/blackscreen.py` covers the display entirely —
/// the app cannot see that, so anything still animating is rasterizing frames
/// nobody can look at. On the Pi 4B that cost 27.7 % of a core on the platform
/// thread and 6 % on the raster thread, worth roughly 7 °C: enough to cross
/// the SoC's 80 °C soft limit and record a throttling event, which slows the
/// till down exactly when the bar is busiest.
///
/// The guard is [WidgetTester.hasRunningAnimations] — non-zero transient frame
/// callbacks, which is precisely "something has asked to be told about the
/// next frame". It catches any ticker added to this screen later, not only the
/// glow controller that caused the original defect, which is the point of
/// asserting at the screen level rather than on one widget.
void main() {
  setUpAll(() {
    registerFallbackValue(FakeBuildContext());
  });

  group('RfidDetectorButton', () {
    late MockRfidProvider rfidProvider;

    setUp(() {
      rfidProvider = MockRfidProvider();
      when(() => rfidProvider.addListener(any())).thenReturn(null);
      when(() => rfidProvider.removeListener(any())).thenReturn(null);
      when(() => rfidProvider.isScanning).thenReturn(false);
    });

    Widget buildButton({bool isOffline = false}) {
      return createTestApp(
        child: ChangeNotifierProvider<RfidProvider>.value(
          value: rfidProvider,
          child: Scaffold(
            body: RfidDetectorButton(isOffline: isOffline),
          ),
        ),
      );
    }

    testWidgets('runs no animation while waiting for a card', (tester) async {
      await tester.pumpWidget(buildButton());
      await tester.pump(const Duration(seconds: 1));

      expect(
        tester.hasRunningAnimations,
        isFalse,
        reason: 'the glow used to pulse forever on the screen the terminal is '
            'almost always showing (#760)',
      );
    });

    testWidgets('runs no animation with the reader unplugged', (tester) async {
      await tester.pumpWidget(buildButton(isOffline: true));
      await tester.pump(const Duration(seconds: 1));

      expect(tester.hasRunningAnimations, isFalse);
    });

    testWidgets('still animates its spinner while scanning', (tester) async {
      when(() => rfidProvider.isScanning).thenReturn(true);

      await tester.pumpWidget(buildButton());
      await tester.pump(const Duration(milliseconds: 100));

      // Without this the quiescence assertions above would pass on a button
      // that had simply stopped rendering anything at all. A scan is bounded
      // by the scan, so this animation is not the waste #760 is about.
      expect(tester.hasRunningAnimations, isTrue);
      expect(find.byType(CircularProgressIndicator), findsOneWidget);
    });
  });

  group('IdleWaitingScreen', () {
    testWidgets('the whole screen is quiescent', (tester) async {
      final rfidProvider = MockRfidProvider();
      when(() => rfidProvider.addListener(any())).thenReturn(null);
      when(() => rfidProvider.removeListener(any())).thenReturn(null);
      when(() => rfidProvider.isScanning).thenReturn(false);
      when(() => rfidProvider.error).thenReturn(null);

      final syncProvider = MockSyncProvider();
      when(() => syncProvider.addListener(any())).thenReturn(null);
      when(() => syncProvider.removeListener(any())).thenReturn(null);
      when(() => syncProvider.startBackgroundSync(
          intervalSeconds: any(named: 'intervalSeconds'))).thenReturn(null);

      final config = createMockConfigService();
      // Demo mode adds a button, not a ticker — but the terminals that idle
      // for hours in a bar are the ones with a real reader, so assert on them.
      when(() => config.demoMode).thenReturn(false);

      await tester.pumpWidget(createTestApp(
        configService: config,
        child: MultiProvider(
          providers: [
            ChangeNotifierProvider<RfidProvider>.value(value: rfidProvider),
            ChangeNotifierProvider<SyncProvider>.value(value: syncProvider),
          ],
          child: const Scaffold(body: IdleWaitingScreen()),
        ),
      ));

      // Long enough for anything one-shot to have finished, and for a repeating
      // ticker to still be going.
      await tester.pump(const Duration(seconds: 3));

      expect(
        tester.hasRunningAnimations,
        isFalse,
        reason: 'every frame this screen renders is discarded — the display is '
            'usually blanked and nobody is looking at it (#760)',
      );
    });
  });
}
