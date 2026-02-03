import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/providers/sync_provider.dart';
import 'package:ruderbar_terminal/widgets/status_info_modal.dart';

class MockSyncProvider extends Mock implements SyncProvider {}

void main() {
  group('StatusInfoModal', () {
    late MockSyncProvider mockSyncProvider;

    setUp(() {
      mockSyncProvider = MockSyncProvider();
      when(() => mockSyncProvider.addListener(any())).thenReturn(null);
      when(() => mockSyncProvider.removeListener(any())).thenReturn(null);
    });

    Widget buildTestApp({required Widget child}) {
      return MaterialApp(
        home: ChangeNotifierProvider<SyncProvider>.value(
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
      expect(find.text('Last sync'), findsOneWidget);
      expect(find.text('Last transaction sync'), findsOneWidget);
      expect(find.text('2025-06-15 14:30:00'), findsOneWidget);
      expect(find.text('2025-06-15 14:25:00'), findsOneWidget);
      // No retry count shown when 0
      expect(find.text('Retry count'), findsNothing);
      // No error details when online
      expect(find.text('Error details'), findsNothing);
    });

    testWidgets('shows Offline status with error details', (tester) async {
      when(() => mockSyncProvider.connectionStatus).thenReturn(ConnectionStatus.offline);
      when(() => mockSyncProvider.lastSyncTime).thenReturn(null);
      when(() => mockSyncProvider.lastSuccessfulTransactionSync).thenReturn(null);
      when(() => mockSyncProvider.retryCount).thenReturn(3);
      when(() => mockSyncProvider.lastError).thenReturn('Backend unreachable');

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
      expect(find.text('Never'), findsNWidgets(2)); // both timestamps
      expect(find.text('Retry count'), findsOneWidget);
      expect(find.text('3'), findsOneWidget);
      expect(find.text('Error details'), findsOneWidget);
      expect(find.text('Backend unreachable'), findsOneWidget);
    });

    testWidgets('shows Error status with error details', (tester) async {
      when(() => mockSyncProvider.connectionStatus).thenReturn(ConnectionStatus.error);
      when(() => mockSyncProvider.lastSyncTime).thenReturn(DateTime(2025, 6, 15, 14, 30));
      when(() => mockSyncProvider.lastSuccessfulTransactionSync).thenReturn(null);
      when(() => mockSyncProvider.retryCount).thenReturn(1);
      when(() => mockSyncProvider.lastError).thenReturn('Sync failed: timeout');

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

      expect(find.text('Error'), findsOneWidget);
      expect(find.text('Error details'), findsOneWidget);
      expect(find.text('Sync failed: timeout'), findsOneWidget);
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

      await tester.tap(find.text('Dismiss'));
      await tester.pumpAndSettle();

      expect(find.text('Online'), findsNothing);
    });
  });
}
