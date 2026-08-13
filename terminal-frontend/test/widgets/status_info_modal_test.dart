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
import 'package:clubbar_terminal/widgets/status_info_modal.dart';
import '../test_helpers.dart';

class MockSyncProvider extends Mock implements SyncProvider {}

class MockDispenserHealthService extends Mock
    implements DispenserHealthService {}

void main() {
  group('StatusInfoModal', () {
    late MockSyncProvider mockSyncProvider;

    setUp(() {
      mockSyncProvider = MockSyncProvider();
      when(() => mockSyncProvider.addListener(any())).thenReturn(null);
      when(() => mockSyncProvider.removeListener(any())).thenReturn(null);
      when(() => mockSyncProvider.pairingMismatch).thenReturn(false);
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
