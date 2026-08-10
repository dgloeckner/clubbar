import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/services/rfid_reader_health_service.dart';
import 'package:clubbar_terminal/services/rfid_reader_probe.dart';
import 'package:clubbar_terminal/widgets/main_layout.dart';
import '../test_helpers.dart';

class MockSyncProvider extends Mock implements SyncProvider {}

class MockSessionController extends Mock implements SessionController {}

class StubRfidReaderProbe implements RfidReaderProbe {
  bool? present;

  StubRfidReaderProbe(this.present);

  @override
  Future<bool?> isReaderPresent() async => present;
}

void main() {
  group('MainLayout reader pill', () {
    late MockSyncProvider syncProvider;
    late MockSessionController session;

    setUp(() {
      syncProvider = MockSyncProvider();
      when(() => syncProvider.addListener(any())).thenReturn(null);
      when(() => syncProvider.removeListener(any())).thenReturn(null);
      when(() => syncProvider.connectionStatus)
          .thenReturn(ConnectionStatus.online);
      when(() => syncProvider.isSyncing).thenReturn(false);

      session = MockSessionController();
      when(() => session.addListener(any())).thenReturn(null);
      when(() => session.removeListener(any())).thenReturn(null);
      when(() => session.showTimeoutWarning).thenReturn(false);
      when(() => session.recordActivity()).thenReturn(null);
    });

    Widget buildTestApp({RfidReaderHealthService? readerHealth}) {
      return createTestApp(
        child: MultiProvider(
          providers: [
            ChangeNotifierProvider<SyncProvider>.value(value: syncProvider),
            ChangeNotifierProvider<SessionController>.value(value: session),
            if (readerHealth != null)
              ChangeNotifierProvider<RfidReaderHealthService>.value(
                value: readerHealth,
              ),
          ],
          child: const MainLayout(child: SizedBox()),
        ),
      );
    }

    testWidgets('shows nothing about the reader when it is not monitored',
        (tester) async {
      await tester.pumpWidget(buildTestApp());

      expect(find.text('Scanner OK'), findsNothing);
      expect(find.text('Scanner fehlt'), findsNothing);
      expect(find.text('Online'), findsOneWidget);
    });

    testWidgets('surfaces a missing reader beside the connection status',
        (tester) async {
      final readerHealth =
          RfidReaderHealthService(probe: StubRfidReaderProbe(false));
      await readerHealth.checkNow();

      await tester.pumpWidget(buildTestApp(readerHealth: readerHealth));

      // A dead reader and a dead backend need different actions from staff, so
      // the reader gets its own pill rather than colouring the existing one.
      expect(find.text('Scanner fehlt'), findsOneWidget);
      expect(find.text('Online'), findsOneWidget);
    });

    testWidgets('updates the pill when the reader comes back', (tester) async {
      final probe = StubRfidReaderProbe(false);
      final readerHealth = RfidReaderHealthService(probe: probe);
      await readerHealth.checkNow();

      await tester.pumpWidget(buildTestApp(readerHealth: readerHealth));
      expect(find.text('Scanner fehlt'), findsOneWidget);

      probe.present = true;
      await readerHealth.checkNow();
      await tester.pump();

      expect(find.text('Scanner OK'), findsOneWidget);
      expect(find.text('Scanner fehlt'), findsNothing);
    });
  });
}
