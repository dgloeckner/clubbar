import 'package:drift/drift.dart';
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/services/dispenser_client.dart';
import 'package:clubbar_terminal/services/dispenser_recovery_service.dart';

class MockDispenserClient extends Mock implements DispenserClient {}

void main() {
  late ClubBarDatabase db;
  late MockDispenserClient mockClient;
  late DispenserRecoveryService service;

  setUp(() async {
    // Create in-memory database for testing
    db = ClubBarDatabase.forTesting(NativeDatabase.memory());
    mockClient = MockDispenserClient();
    service = DispenserRecoveryService(
      database: db,
      client: mockClient,
    );
  });

  tearDown(() async {
    service.dispose();
    await db.close();
  });

  group('Periodic Reconciliation', () {
    test('detects and creates missing transactions (ESP crash scenario)', () async {
      // Scenario: User shown "2 tokens", ESP actually dispensed 3, crashed
      // Tracking record shows 2 transactions created, ESP8266 reports 3 dispensed

      final now = DateTime.now().toUtc().toIso8601String();

      // Create tracking record with 2 transactions already created
      await db.into(db.dispenserOperations).insert(
        DispenserOperationsCompanion.insert(
          dispenserTxId: 'disp-abc',
          memberId: 'member-1',
          productId: 'prod-token',
          priceCents: 200, // €2 per token
          requestedQty: 3,
          createdAt: now,
          transactionsCreated: const Value(2), // 2 already created
          lastKnownState: const Value('done'),
          lastKnownDispensed: const Value(2),
          pollingActive: const Value(0), // Not actively polling
          lastPolledAt: Value(DateTime.now().toUtc().subtract(const Duration(minutes: 5)).toIso8601String()),
        ),
      );

      // ESP8266 reports 3 tokens actually dispensed
      when(() => mockClient.getStatus('disp-abc')).thenAnswer(
        (_) async => DispenseResult(
          txId: 'disp-abc',
          state: 'done',
          quantity: 3,
          dispensed: 3, // ESP says 3 dispensed
        ),
      );

      // Run recovery
      await service.recoverIncompleteDispenses();

      // Verify: 1 additional transaction created (3 - 2 = 1 missing)
      final transactions = await db.select(db.transactionsLocal).get();
      expect(transactions.length, 1);
      expect(transactions[0].memberId, 'member-1');
      expect(transactions[0].productId, 'prod-token');
      expect(transactions[0].amountCents, 200); // One token's price
      expect(transactions[0].dispenserTxId, 'disp-abc');
      expect(transactions[0].dispenserRequested, 3);
      expect(transactions[0].dispenserActual, 3);
      expect(transactions[0].notes, 'Auto-created by recovery service');

      // Verify: Tracking record cleaned up (state was "done")
      final trackingRecords = await db.select(db.dispenserOperations).get();
      expect(trackingRecords.isEmpty, true);

      verify(() => mockClient.getStatus('disp-abc')).called(1);
    });

    test('skips operations that were recently polled', () async {
      // Tracking record with recent lastPolledAt (recovery resets pollingActive,
      // but respects the 30-second recency window)
      final recentPoll = DateTime.now().toUtc().subtract(const Duration(seconds: 5));
      await db.into(db.dispenserOperations).insert(
        DispenserOperationsCompanion.insert(
          dispenserTxId: 'disp-def',
          memberId: 'member-1',
          productId: 'prod-token',
          priceCents: 200,
          requestedQty: 2,
          createdAt: DateTime.now().toUtc().toIso8601String(),
          pollingActive: const Value(1), // Will be reset by recovery
          lastPolledAt: Value(recentPoll.toIso8601String()), // Recently polled
        ),
      );

      // Run recovery
      await service.recoverIncompleteDispenses();

      // Verify: No ESP8266 queries made (recently polled, skipped)
      verifyNever(() => mockClient.getStatus(any()));

      // Verify: Tracking record NOT cleaned up
      final trackingRecords = await db.select(db.dispenserOperations).get();
      expect(trackingRecords.length, 1);
    });

    test('skips operations polled within last 30 seconds', () async {
      // Tracking record polled 10 seconds ago
      final recentPoll = DateTime.now().toUtc().subtract(const Duration(seconds: 10));

      await db.into(db.dispenserOperations).insert(
        DispenserOperationsCompanion.insert(
          dispenserTxId: 'disp-ghi',
          memberId: 'member-1',
          productId: 'prod-token',
          priceCents: 200,
          requestedQty: 2,
          createdAt: DateTime.now().toUtc().toIso8601String(),
          pollingActive: const Value(0),
          lastPolledAt: Value(recentPoll.toIso8601String()), // Recent poll
        ),
      );

      // Run recovery
      await service.recoverIncompleteDispenses();

      // Verify: No ESP8266 queries made (too recent)
      verifyNever(() => mockClient.getStatus(any()));
    });

    test('processes operations polled more than 30 seconds ago', () async {
      // Tracking record polled 60 seconds ago (stale)
      final stalePoll = DateTime.now().toUtc().subtract(const Duration(seconds: 60));

      await db.into(db.dispenserOperations).insert(
        DispenserOperationsCompanion.insert(
          dispenserTxId: 'disp-jkl',
          memberId: 'member-1',
          productId: 'prod-token',
          priceCents: 200,
          requestedQty: 1,
          createdAt: DateTime.now().toUtc().toIso8601String(),
          transactionsCreated: const Value(0),
          pollingActive: const Value(0),
          lastPolledAt: Value(stalePoll.toIso8601String()), // Stale poll
        ),
      );

      // ESP8266 reports completed
      when(() => mockClient.getStatus('disp-jkl')).thenAnswer(
        (_) async => DispenseResult(
          txId: 'disp-jkl',
          state: 'done',
          quantity: 1,
          dispensed: 1,
        ),
      );

      // Run recovery
      await service.recoverIncompleteDispenses();

      // Verify: ESP8266 queried (stale poll, safe to process)
      verify(() => mockClient.getStatus('disp-jkl')).called(1);
    });

    test('creates only missing transactions, not all', () async {
      // Scenario: 5 tokens requested, 2 already created, ESP reports 4 dispensed
      // Should create 2 more (4 - 2 = 2 missing)

      await db.into(db.dispenserOperations).insert(
        DispenserOperationsCompanion.insert(
          dispenserTxId: 'disp-mno',
          memberId: 'member-1',
          productId: 'prod-token',
          priceCents: 200,
          requestedQty: 5,
          createdAt: DateTime.now().toUtc().toIso8601String(),
          transactionsCreated: const Value(2), // 2 already created
          pollingActive: const Value(0),
          lastPolledAt: Value(DateTime.now().toUtc().subtract(const Duration(minutes: 1)).toIso8601String()),
        ),
      );

      // ESP8266 reports 4 dispensed
      when(() => mockClient.getStatus('disp-mno')).thenAnswer(
        (_) async => DispenseResult(
          txId: 'disp-mno',
          state: 'done',
          quantity: 5,
          dispensed: 4,
        ),
      );

      // Run recovery
      await service.recoverIncompleteDispenses();

      // Verify: Only 2 transactions created (4 - 2 = 2 missing)
      final transactions = await db.select(db.transactionsLocal).get();
      expect(transactions.length, 2);
      expect(transactions.every((t) => t.dispenserTxId == 'disp-mno'), true);
      expect(transactions.every((t) => t.amountCents == 200), true);
    });

    test('cleans up tracking record when state is "done"', () async {
      await db.into(db.dispenserOperations).insert(
        DispenserOperationsCompanion.insert(
          dispenserTxId: 'disp-pqr',
          memberId: 'member-1',
          productId: 'prod-token',
          priceCents: 200,
          requestedQty: 1,
          createdAt: DateTime.now().toUtc().toIso8601String(),
          transactionsCreated: const Value(1),
          pollingActive: const Value(0),
        ),
      );

      // ESP8266 reports state "done"
      when(() => mockClient.getStatus('disp-pqr')).thenAnswer(
        (_) async => DispenseResult(
          txId: 'disp-pqr',
          state: 'done', // Final state
          quantity: 1,
          dispensed: 1,
        ),
      );

      await service.recoverIncompleteDispenses();

      // Verify: Tracking record removed
      final trackingRecords = await db.select(db.dispenserOperations).get();
      expect(trackingRecords.isEmpty, true);
    });

    test('cleans up tracking record when state is "error"', () async {
      await db.into(db.dispenserOperations).insert(
        DispenserOperationsCompanion.insert(
          dispenserTxId: 'disp-stu',
          memberId: 'member-1',
          productId: 'prod-token',
          priceCents: 200,
          requestedQty: 3,
          createdAt: DateTime.now().toUtc().toIso8601String(),
          transactionsCreated: const Value(2), // Partial
          pollingActive: const Value(0),
        ),
      );

      // ESP8266 reports state "error" (e.g., jammed after 2 tokens)
      when(() => mockClient.getStatus('disp-stu')).thenAnswer(
        (_) async => DispenseResult(
          txId: 'disp-stu',
          state: 'error', // Final state
          quantity: 3,
          dispensed: 2,
        ),
      );

      await service.recoverIncompleteDispenses();

      // Verify: Tracking record removed
      final trackingRecords = await db.select(db.dispenserOperations).get();
      expect(trackingRecords.isEmpty, true);
    });

    test('keeps tracking record when state is "dispensing"', () async {
      await db.into(db.dispenserOperations).insert(
        DispenserOperationsCompanion.insert(
          dispenserTxId: 'disp-vwx',
          memberId: 'member-1',
          productId: 'prod-token',
          priceCents: 200,
          requestedQty: 5,
          createdAt: DateTime.now().toUtc().toIso8601String(),
          transactionsCreated: const Value(0),
          pollingActive: const Value(0),
        ),
      );

      // ESP8266 still dispensing
      when(() => mockClient.getStatus('disp-vwx')).thenAnswer(
        (_) async => DispenseResult(
          txId: 'disp-vwx',
          state: 'dispensing', // Not final
          quantity: 5,
          dispensed: 2, // Partial progress
        ),
      );

      await service.recoverIncompleteDispenses();

      // Verify: Tracking record NOT removed (will retry later)
      final trackingRecords = await db.select(db.dispenserOperations).get();
      expect(trackingRecords.length, 1);
    });

    test('handles ESP8266 not found error', () async {
      await db.into(db.dispenserOperations).insert(
        DispenserOperationsCompanion.insert(
          dispenserTxId: 'disp-yz1',
          memberId: 'member-1',
          productId: 'prod-token',
          priceCents: 200,
          requestedQty: 2,
          createdAt: DateTime.now().toUtc().toIso8601String(),
          transactionsCreated: const Value(0),
          pollingActive: const Value(0),
        ),
      );

      // ESP8266 doesn't have this transaction
      when(() => mockClient.getStatus('disp-yz1'))
          .thenThrow(DispenserNotFoundException());

      // Run recovery (should not crash)
      await service.recoverIncompleteDispenses();

      // Verify: Tracking record NOT cleaned up (kept for manual reconciliation)
      final trackingRecords = await db.select(db.dispenserOperations).get();
      expect(trackingRecords.length, 1);

      // Verify: No transactions created
      final transactions = await db.select(db.transactionsLocal).get();
      expect(transactions.isEmpty, true);
    });

    test('handles dispenser offline during recovery', () async {
      await db.into(db.dispenserOperations).insert(
        DispenserOperationsCompanion.insert(
          dispenserTxId: 'disp-234',
          memberId: 'member-1',
          productId: 'prod-token',
          priceCents: 200,
          requestedQty: 1,
          createdAt: DateTime.now().toUtc().toIso8601String(),
          pollingActive: const Value(0),
        ),
      );

      // ESP8266 is offline
      when(() => mockClient.getStatus('disp-234'))
          .thenThrow(DispenserException('Connection timeout'));

      // Run recovery (should not crash)
      await service.recoverIncompleteDispenses();

      // Verify: Tracking record unchanged (will retry later)
      final trackingRecords = await db.select(db.dispenserOperations).get();
      expect(trackingRecords.length, 1);
    });

    test('does nothing when no incomplete transactions exist', () async {
      // Run recovery on empty database
      await service.recoverIncompleteDispenses();

      // Should not make any client calls
      verifyNever(() => mockClient.getStatus(any()));
    });
  });

  group('Timer Lifecycle', () {
    test('startPeriodicReconciliation initializes timer', () {
      // Start periodic reconciliation
      service.startPeriodicReconciliation();

      // Timer should be active (can't directly test Timer.isActive in unit test,
      // but we verify it doesn't crash and cleanup works)

      // Cleanup
      service.stopPeriodicReconciliation();
    });

    test('stopPeriodicReconciliation cancels timer', () {
      // Start then stop
      service.startPeriodicReconciliation();
      service.stopPeriodicReconciliation();

      // Should not crash, timer should be null
      // (Timer is private, so we can't directly verify, but dispose should not crash)
    });

    test('dispose cleans up timer', () {
      // Start periodic reconciliation
      service.startPeriodicReconciliation();

      // Dispose service
      service.dispose();

      // Should not crash - timer cleaned up
    });
  });
}
