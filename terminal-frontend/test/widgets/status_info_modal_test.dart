import 'package:drift/drift.dart' show Value;
import 'package:drift/native.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/services/dispenser_client.dart';
import 'package:clubbar_terminal/services/dispenser_health_service.dart';
import 'package:clubbar_terminal/services/scan_log.dart';
import 'package:clubbar_terminal/services/system_health_probe.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/widgets/status_info_modal.dart';
import '../test_helpers.dart';

class MockSyncProvider extends Mock implements SyncProvider {}

class MockDispenserHealthService extends Mock
    implements DispenserHealthService {}

/// A machine that answers whatever the test says it answers, with no sysfs
/// involved. Reads are counted so a test can prove the modal keeps looking
/// rather than freezing the first number it saw.
class FakeSystemHealthProbe implements SystemHealthProbe {
  SystemHealth health;
  int reads = 0;

  FakeSystemHealthProbe(this.health);

  @override
  Future<SystemHealth> read() async {
    reads++;
    return health;
  }
}

void main() {
  group('StatusInfoModal', () {
    late MockSyncProvider mockSyncProvider;

    setUp(() {
      mockSyncProvider = MockSyncProvider();
      when(() => mockSyncProvider.addListener(any())).thenReturn(null);
      when(() => mockSyncProvider.removeListener(any())).thenReturn(null);
      when(() => mockSyncProvider.pairingMismatch).thenReturn(false);
      // #395: MainLayout renders CredentialExpiredBanner above every
      // screen, and an unstubbed non-nullable getter throws rather than
      // reading as false.
      when(() => mockSyncProvider.credentialExpired).thenReturn(false);
    });

    // Issue #27: this used to render `statusLoadError(e.toString())` — the
    // exception text verbatim, inside a localized sentence.
    testWidgets('a failure to gather status shows localized copy, not the exception',
        (tester) async {
      await tester.pumpWidget(
        createTestApp(
          child: Builder(
            // No SyncProvider in the tree, so context.read throws exactly the
            // way a missing dependency would in production.
            builder: (context) => ElevatedButton(
              onPressed: () => showStatusInfoModal(context),
              child: const Text('Open'),
            ),
          ),
        ),
      );

      await tester.tap(find.text('Open'));
      await tester.pumpAndSettle();

      expect(
        find.text(await errorCopy(TerminalErrorKey.statusLoadFailed)),
        findsOneWidget,
      );
      expect(find.text('Schließen'), findsOneWidget);

      final rendered = tester
          .widgetList<Text>(find.byType(Text))
          .map((t) => t.data ?? '')
          .join('\n');
      expect(rendered, isNot(contains('ProviderNotFoundException')));
      expect(rendered, isNot(contains('SyncProvider')));
    });

    Widget buildTestApp({required Widget child}) {
      return createTestApp(
        child: ChangeNotifierProvider<SyncProvider>.value(
          value: mockSyncProvider,
          child: child,
        ),
      );
    }

    testWidgets('shows Online status with correct info', (tester) async {
      final syncTime = DateTime(2025, 6, 15, 14, 30, 0);
      final txnSyncTime = DateTime(2025, 6, 15, 14, 25, 0);

      when(() => mockSyncProvider.connectionStatus).thenReturn(ConnectionStatus.online);
      when(() => mockSyncProvider.lastSyncTime).thenReturn(syncTime);
      when(() => mockSyncProvider.lastSuccessfulTransactionSync).thenReturn(txnSyncTime);
      when(() => mockSyncProvider.retryCount).thenReturn(0);
      when(() => mockSyncProvider.lastError).thenReturn(null);

      await tester.pumpWidget(buildTestApp(
        child: Builder(
          builder: (context) => ElevatedButton(
            onPressed: () => showStatusInfoModal(context),
            child: const Text('Open'),
          ),
        ),
      ));

      await tester.tap(find.text('Open'));
      await tester.pumpAndSettle();

      expect(find.text('Online'), findsOneWidget);
      expect(find.text('Letzte Synchronisation'), findsOneWidget); // "Last sync" in German
      expect(find.text('Letzte Transaktions-Sync'), findsOneWidget); // "Last transaction sync" in German
      expect(find.text('2025-06-15 14:30:00'), findsOneWidget);
      expect(find.text('2025-06-15 14:25:00'), findsOneWidget);
      // No retry count shown when 0
      expect(find.text('Wiederholungsversuche'), findsNothing); // "Retry count" in German
      // No error details when online
      expect(find.text('Fehlerdetails'), findsNothing); // "Error details" in German
    });

    testWidgets('shows Offline status with error details', (tester) async {
      when(() => mockSyncProvider.connectionStatus).thenReturn(ConnectionStatus.offline);
      when(() => mockSyncProvider.lastSyncTime).thenReturn(null);
      when(() => mockSyncProvider.lastSuccessfulTransactionSync).thenReturn(null);
      when(() => mockSyncProvider.retryCount).thenReturn(3);
      when(() => mockSyncProvider.lastError).thenReturn(
          const TerminalError(key: TerminalErrorKey.backendUnreachable, sequence: 1));

      await tester.pumpWidget(buildTestApp(
        child: Builder(
          builder: (context) => ElevatedButton(
            onPressed: () => showStatusInfoModal(context),
            child: const Text('Open'),
          ),
        ),
      ));

      await tester.tap(find.text('Open'));
      await tester.pumpAndSettle();

      expect(find.text('Offline'), findsOneWidget);
      expect(find.text('Nie'), findsNWidgets(2)); // "Never" in German - both timestamps
      expect(find.text('Wiederholungsversuche'), findsOneWidget); // "Retry count" in German
      expect(find.text('3'), findsOneWidget);
      expect(find.text('Fehlerdetails'), findsOneWidget); // "Error details" in German
      expect(find.text(await errorCopy(TerminalErrorKey.backendUnreachable)),
          findsOneWidget);
    });

    testWidgets('shows Error status with error details', (tester) async {
      when(() => mockSyncProvider.connectionStatus).thenReturn(ConnectionStatus.error);
      when(() => mockSyncProvider.lastSyncTime).thenReturn(DateTime(2025, 6, 15, 14, 30));
      when(() => mockSyncProvider.lastSuccessfulTransactionSync).thenReturn(null);
      when(() => mockSyncProvider.retryCount).thenReturn(1);
      when(() => mockSyncProvider.lastError).thenReturn(
          const TerminalError(key: TerminalErrorKey.syncFailed, sequence: 1));

      await tester.pumpWidget(buildTestApp(
        child: Builder(
          builder: (context) => ElevatedButton(
            onPressed: () => showStatusInfoModal(context),
            child: const Text('Open'),
          ),
        ),
      ));

      await tester.tap(find.text('Open'));
      await tester.pumpAndSettle();

      expect(find.text('Fehler'), findsOneWidget); // "Error" in German
      expect(find.text('Fehlerdetails'), findsOneWidget); // "Error details" in German
      expect(find.text(await errorCopy(TerminalErrorKey.syncFailed)),
          findsOneWidget);
    });

    // Issue #40: the modal is reachable by any patron who taps the header
    // pill, so the plain-language line comes first and the engineering detail
    // hides behind an expander.
    group('technical details expander (issue #40)', () {
      Future<void> openDegradedModal(WidgetTester tester) async {
        when(() => mockSyncProvider.connectionStatus)
            .thenReturn(ConnectionStatus.offline);
        when(() => mockSyncProvider.lastSyncTime).thenReturn(null);
        when(() => mockSyncProvider.lastSuccessfulTransactionSync)
            .thenReturn(null);
        when(() => mockSyncProvider.retryCount).thenReturn(4);
        when(() => mockSyncProvider.lastError).thenReturn(const TerminalError(
            key: TerminalErrorKey.backendUnreachable, sequence: 1));
        when(() => mockSyncProvider.degradedSince)
            .thenReturn(DateTime(2026, 8, 10, 14, 32));

        await tester.pumpWidget(buildTestApp(
          child: Builder(
            builder: (context) => ElevatedButton(
              onPressed: () => showStatusInfoModal(context),
              child: const Text('Open'),
            ),
          ),
        ));

        await tester.tap(find.text('Open'));
        await tester.pumpAndSettle();
      }

      testWidgets('the outage summary names when it started', (tester) async {
        await openDegradedModal(tester);

        expect(find.text(await errorCopy(TerminalErrorKey.backendUnreachable)),
            findsOneWidget);
        expect(find.text('Besteht seit 14:32'), findsOneWidget);
      });

      testWidgets('the error code stays hidden until it is asked for',
          (tester) async {
        await openDegradedModal(tester);

        expect(find.text('Technische Details'), findsOneWidget);
        expect(find.text('backendUnreachable'), findsNothing);

        // The modal caps at 600 px and scrolls; at the kiosk type scale (#41)
        // the expander sits below that fold. Scroll to it first — tapping a
        // widget that is off-screen silently misses and leaves it collapsed.
        await tester.ensureVisible(find.text('Technische Details'));
        await tester.pumpAndSettle();

        await tester.tap(find.text('Technische Details'));
        await tester.pumpAndSettle();

        expect(find.text('Fehlercode'), findsOneWidget);
        expect(find.text('backendUnreachable'), findsOneWidget);
      });

      testWidgets('no expander at all while the terminal is healthy',
          (tester) async {
        when(() => mockSyncProvider.connectionStatus)
            .thenReturn(ConnectionStatus.online);
        when(() => mockSyncProvider.lastSyncTime).thenReturn(null);
        when(() => mockSyncProvider.lastSuccessfulTransactionSync)
            .thenReturn(null);
        when(() => mockSyncProvider.retryCount).thenReturn(0);
        when(() => mockSyncProvider.lastError).thenReturn(null);
        when(() => mockSyncProvider.degradedSince).thenReturn(null);

        await tester.pumpWidget(buildTestApp(
          child: Builder(
            builder: (context) => ElevatedButton(
              onPressed: () => showStatusInfoModal(context),
              child: const Text('Open'),
            ),
          ),
        ));

        await tester.tap(find.text('Open'));
        await tester.pumpAndSettle();

        expect(find.text('Technische Details'), findsNothing);
      });
    });

    // Issue #40: the badge used to simply never appear when the fetch failed,
    // leaving "slow" and "broken" indistinguishable.
    testWidgets('the backend version badge says so when it cannot be fetched',
        (tester) async {
      when(() => mockSyncProvider.connectionStatus)
          .thenReturn(ConnectionStatus.online);
      when(() => mockSyncProvider.lastSyncTime).thenReturn(null);
      when(() => mockSyncProvider.lastSuccessfulTransactionSync).thenReturn(null);
      when(() => mockSyncProvider.retryCount).thenReturn(0);
      when(() => mockSyncProvider.lastError).thenReturn(null);
      when(() => mockSyncProvider.degradedSince).thenReturn(null);

      await tester.pumpWidget(buildTestApp(
        child: Builder(
          builder: (context) => ElevatedButton(
            onPressed: () => showStatusInfoModal(context),
            child: const Text('Open'),
          ),
        ),
      ));

      await tester.tap(find.text('Open'));
      await tester.pumpAndSettle();

      // No NetworkService in the tree, so the version is unobtainable —
      // exactly what a failed fetch looks like to this widget.
      expect(find.text('Version unbekannt'), findsOneWidget);
    });

    // Issue #40: the pending-operation rows used to print `op.memberId` in
    // full — a member UUID, in a modal any patron can open from the header.
    group('pending operations do not leak identifiers (issue #40)', () {
      const memberUuid = '7c9e6679-7425-40de-944b-e07fc1f90ae7';
      const dispenserTxId = 'disp-4f2c9a1b-7e55-4c31-9d6a-0b8e2f3a1c44';

      late ClubBarDatabase db;
      late MockDispenserHealthService healthService;

      setUp(() async {
        db = ClubBarDatabase.forTesting(NativeDatabase.memory());
        await db.into(db.dispenserOperations).insert(
              DispenserOperationsCompanion.insert(
                dispenserTxId: dispenserTxId,
                memberId: memberUuid,
                productId: 'prod-token',
                priceCents: 200,
                requestedQty: 2,
                createdAt: '2026-08-10T14:32:00Z',
                lastKnownState: const Value('not_found'),
              ),
            );

        healthService = MockDispenserHealthService();
        when(() => healthService.addListener(any())).thenReturn(null);
        when(() => healthService.removeListener(any())).thenReturn(null);
        when(() => healthService.checkNow()).thenAnswer((_) async {});
        when(() => healthService.currentHealth).thenReturn(DispenserHealth(
          status: 'ok',
          dispenser: 'idle',
          totalDispenses: 1,
          successful: 1,
          jams: 0,
          successRate: 100,
        ));
      });

      tearDown(() async => db.close());

      Future<void> openDispenserTab(WidgetTester tester) async {
        when(() => mockSyncProvider.connectionStatus)
            .thenReturn(ConnectionStatus.online);
        when(() => mockSyncProvider.lastSyncTime).thenReturn(null);
        when(() => mockSyncProvider.lastSuccessfulTransactionSync)
            .thenReturn(null);
        when(() => mockSyncProvider.retryCount).thenReturn(0);
        when(() => mockSyncProvider.lastError).thenReturn(null);
        when(() => mockSyncProvider.degradedSince).thenReturn(null);

        await tester.pumpWidget(createTestApp(
          child: MultiProvider(
            providers: [
              ChangeNotifierProvider<SyncProvider>.value(value: mockSyncProvider),
              ChangeNotifierProvider<DispenserHealthService>.value(
                value: healthService,
              ),
              Provider<ClubBarDatabase>.value(value: db),
            ],
            child: Builder(
              builder: (context) => ElevatedButton(
                onPressed: () => showStatusInfoModal(context),
                child: const Text('Open'),
              ),
            ),
          ),
        ));

        await tester.tap(find.text('Open'));
        await tester.pumpAndSettle();
        await tester.tap(find.text('Ausgabegerät-Status'));
        await tester.pumpAndSettle();
      }

      /// Dismiss the dialog *inside* the test so the pending-operations stream
      /// is cancelled while the binding can still drain drift's cleanup timer.
      Future<void> closeModal(WidgetTester tester) async {
        await tester.tap(find.byIcon(Icons.close));
        await tester.pumpAndSettle();
      }

      String renderedText(WidgetTester tester) => tester
          .widgetList<Text>(find.byType(Text))
          .map((t) => t.data ?? '')
          .join('\n');

      testWidgets('no member UUID reaches the screen', (tester) async {
        await openDispenserTab(tester);

        final rendered = renderedText(tester);
        // The row is there…
        expect(rendered, contains('7c9e6679…'));
        // …but never the identifier itself.
        expect(rendered, isNot(contains(memberUuid)));

        await closeModal(tester);
      });

      testWidgets('the dispenser transaction id is truncated too',
          (tester) async {
        await openDispenserTab(tester);

        final rendered = renderedText(tester);
        expect(rendered, contains('disp-4f2…'));
        expect(rendered, isNot(contains(dispenserTxId)));

        await closeModal(tester);
      });

      testWidgets('the row reads in the member language, not in English',
          (tester) async {
        await openDispenserTab(tester);

        final rendered = renderedText(tester);
        expect(rendered, contains('Mitglied'));
        expect(rendered, isNot(contains('member:')));
        expect(rendered, isNot(contains('qty:')));

        await closeModal(tester);
      });
    });

    // Issue #370: "the chip was not recognised" is the only report a member
    // can make. This section is what turns it into an answer.
    group('recent card scans (#370)', () {
      setUp(() {
        ScanLog.instance.clear();
        when(() => mockSyncProvider.connectionStatus)
            .thenReturn(ConnectionStatus.online);
        when(() => mockSyncProvider.lastSyncTime).thenReturn(null);
        when(() => mockSyncProvider.lastSuccessfulTransactionSync)
            .thenReturn(null);
        when(() => mockSyncProvider.retryCount).thenReturn(0);
        when(() => mockSyncProvider.lastError).thenReturn(null);
      });

      Future<void> openModal(WidgetTester tester) async {
        await tester.pumpWidget(buildTestApp(
          child: Builder(
            builder: (context) => ElevatedButton(
              onPressed: () => showStatusInfoModal(context),
              child: const Text('Open'),
            ),
          ),
        ));
        await tester.tap(find.text('Open'));
        await tester.pumpAndSettle();
      }

      testWidgets('lists what the terminal made of the last taps',
          (tester) async {
        ScanLog.instance.record(ScanEventKind.partialDiscarded,
            detail: '6 chars, no terminator');
        ScanLog.instance.record(ScanEventKind.rejected,
            uid: 'ABCD1234', detail: 'unknownCard');

        await openModal(tester);

        expect(find.text('Letzte Chip-Erkennungen'), findsOneWidget);
        expect(
          find.textContaining('rejected ABCD1234 unknownCard'),
          findsOneWidget,
        );
        expect(
          find.textContaining('partialDiscarded 6 chars, no terminator'),
          findsOneWidget,
        );
      });

      testWidgets('says so when the reader has produced nothing at all',
          (tester) async {
        await openModal(tester);

        expect(find.text('Noch kein Chip erkannt'), findsOneWidget);
      });

      testWidgets('a scan arriving while the modal is open shows up in it',
          (tester) async {
        await openModal(tester);
        expect(find.text('Noch kein Chip erkannt'), findsOneWidget);

        ScanLog.instance.record(ScanEventKind.uidCaptured, uid: 'ABCD1234');
        await tester.pump();

        expect(find.textContaining('uidCaptured ABCD1234'), findsOneWidget);
      });
    });

    // Issue #767: thermal throttling slows the till exactly when the bar is
    // busiest, and undervoltage corrupts the SD card silently. Both were
    // previously visible only over SSH.
    group('Pi temperature and undervoltage (#767)', () {
      Future<void> openModal(
        WidgetTester tester,
        SystemHealthProbe probe,
      ) async {
        when(() => mockSyncProvider.connectionStatus)
            .thenReturn(ConnectionStatus.online);
        when(() => mockSyncProvider.lastSyncTime).thenReturn(null);
        when(() => mockSyncProvider.lastSuccessfulTransactionSync)
            .thenReturn(null);
        when(() => mockSyncProvider.retryCount).thenReturn(0);
        when(() => mockSyncProvider.lastError).thenReturn(null);

        await tester.pumpWidget(buildTestApp(
          child: Builder(
            builder: (context) => ElevatedButton(
              onPressed: () =>
                  showStatusInfoModal(context, systemHealthProbe: probe),
              child: const Text('Open'),
            ),
          ),
        ));

        await tester.tap(find.text('Open'));
        await tester.pumpAndSettle();
      }

      testWidgets('a cool terminal reports its temperature as normal',
          (tester) async {
        await openModal(
          tester,
          FakeSystemHealthProbe(
            const SystemHealth(temperatureCelsius: 58.9, undervoltage: false),
          ),
        );

        expect(find.text('Systemzustand'), findsOneWidget);
        // German decimal comma — the reader's own notation, not Dart's.
        expect(find.text('58,9 °C · Normal'), findsOneWidget);
        expect(find.text('Unterspannung: Netzteil ersetzen'), findsNothing);
      });

      testWidgets('a warm terminal says so without raising an alarm',
          (tester) async {
        await openModal(
          tester,
          FakeSystemHealthProbe(const SystemHealth(temperatureCelsius: 72.4)),
        );

        expect(find.text('72,4 °C · Warm'), findsOneWidget);
        // Warm is not yet actionable, so it gets no instruction.
        expect(
          find.textContaining('drosselt sich wegen Hitze'),
          findsNothing,
        );
      });

      testWidgets('a throttling terminal says what to do about it',
          (tester) async {
        await openModal(
          tester,
          FakeSystemHealthProbe(const SystemHealth(temperatureCelsius: 84.2)),
        );

        expect(find.text('84,2 °C · Gedrosselt'), findsOneWidget);
        expect(find.textContaining('drosselt sich wegen Hitze'), findsOneWidget);
      });

      testWidgets('undervoltage is a warning, not a number', (tester) async {
        await openModal(
          tester,
          FakeSystemHealthProbe(
            const SystemHealth(temperatureCelsius: 55.0, undervoltage: true),
          ),
        );

        expect(find.text('Unterspannung: Netzteil ersetzen'), findsOneWidget);
        expect(find.textContaining('beschädigt mit der Zeit'), findsOneWidget);
        // The one condition where the action is "replace the power supply"
        // must not be filed behind a healthy-looking section heading.
        final heading = tester.widget<Text>(find.text('Systemzustand'));
        expect(heading.style?.color, AppColors.semanticDanger);
      });

      testWidgets('a machine that cannot be asked shows no section at all',
          (tester) async {
        // Every developer laptop, and any Pi whose sysfs moved: absent beats
        // an error nobody standing at the bar can act on.
        await openModal(
          tester,
          FakeSystemHealthProbe(SystemHealth.unavailable),
        );

        expect(find.text('Systemzustand'), findsNothing);
        expect(find.text('Temperatur'), findsNothing);
      });

      testWidgets('the reading keeps refreshing while the modal is open',
          (tester) async {
        final probe = FakeSystemHealthProbe(
          const SystemHealth(temperatureCelsius: 58.9),
        );
        await openModal(tester, probe);

        expect(find.text('58,9 °C · Normal'), findsOneWidget);
        expect(probe.reads, 1);

        probe.health = const SystemHealth(temperatureCelsius: 81.0);
        await tester.pump(const Duration(seconds: 5));
        await tester.pumpAndSettle();

        expect(probe.reads, 2);
        expect(find.text('81,0 °C · Gedrosselt'), findsOneWidget);

        // And it stops when nobody is looking — a modal that leaves a timer
        // behind polls the machine for the rest of the shift.
        await tester.tap(find.byIcon(Icons.close));
        await tester.pumpAndSettle();

        await tester.pump(const Duration(seconds: 30));
        expect(probe.reads, 2);
      });
    });

    testWidgets('Dismiss button closes the modal', (tester) async {
      when(() => mockSyncProvider.connectionStatus).thenReturn(ConnectionStatus.online);
      when(() => mockSyncProvider.lastSyncTime).thenReturn(null);
      when(() => mockSyncProvider.lastSuccessfulTransactionSync).thenReturn(null);
      when(() => mockSyncProvider.retryCount).thenReturn(0);
      when(() => mockSyncProvider.lastError).thenReturn(null);

      await tester.pumpWidget(buildTestApp(
        child: Builder(
          builder: (context) => ElevatedButton(
            onPressed: () => showStatusInfoModal(context),
            child: const Text('Open'),
          ),
        ),
      ));

      await tester.tap(find.text('Open'));
      await tester.pumpAndSettle();

      expect(find.text('Online'), findsOneWidget);

      await tester.tap(find.byIcon(Icons.close)); // Close icon button
      await tester.pumpAndSettle();

      expect(find.text('Online'), findsNothing);
    });
  });
}
