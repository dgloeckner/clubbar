import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/widgets/status_info_modal.dart';
import '../test_helpers.dart';

class MockSyncProvider extends Mock implements SyncProvider {}

void main() {
  group('StatusInfoModal', () {
    late MockSyncProvider mockSyncProvider;

    setUp(() {
      mockSyncProvider = MockSyncProvider();
      when(() => mockSyncProvider.addListener(any())).thenReturn(null);
      when(() => mockSyncProvider.removeListener(any())).thenReturn(null);
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
