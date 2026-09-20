import 'package:drift/drift.dart' hide isNull, isNotNull;
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/repository/transactions_repository.dart';
import 'package:clubbar_terminal/services/cart_service.dart';
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/services/dispenser_client.dart';
import 'package:clubbar_terminal/services/dispenser_recovery_service.dart';

class MockDispenserClient extends Mock implements DispenserClient {}

class MockConfigService extends Mock implements ConfigService {}

void main() {
  late ClubBarDatabase db;
  late MockDispenserClient mockClient;
  late CartService cartService;
  late DispenserRecoveryService service;

  /// The id token [index] of dispense [txId] has, wherever it is written from.
  String txnId(String txId, int index) =>
      CartService.dispenserTransactionId(txId, index);

  /// A transaction row exactly as either billing path writes it — used to seed
  /// "these tokens were already billed" without going through a service.
  Future<void> seedBilledToken(DispenserOperation op, int index) async {
    await db.into(db.transactionsLocal).insert(TransactionsLocalCompanion(
          id: Value(txnId(op.dispenserTxId, index)),
          memberId: Value(op.memberId),
          productId: Value(op.productId),
          amountCents: Value(op.priceCents),
          transactionType: const Value('purchase'),
          createdAt: Value(op.createdAt),
          synced: const Value(0),
          dispenserTxId: Value(op.dispenserTxId),
          dispenserRequested: Value(op.requestedQty),
          unitPriceCents: Value(op.priceCents),
        ));
  }

  Future<DispenserOperation> operation(String txId) =>
      (db.select(db.dispenserOperations)
            ..where((t) => t.dispenserTxId.equals(txId)))
          .getSingle();

  setUp(() async {
    // Create in-memory database for testing
    db = ClubBarDatabase.forTesting(NativeDatabase.memory());
    mockClient = MockDispenserClient();
    cartService = CartService(
      database: db,
      repository: TransactionsRepository(db),
      configService: MockConfigService(),
    );
    service = DispenserRecoveryService(
      database: db,
      client: mockClient,
      cartService: cartService,
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

      // The two tokens the counter claims are really on the bill. They have to
      // be: billing is now "make sure rows 0..n-1 exist", so a counter with no
      // rows behind it is not a shortcut, it is a lie (#945).
      final op = await operation('disp-abc');
      await seedBilledToken(op, 0);
      await seedBilledToken(op, 1);

      // Run recovery
      await service.reconcile();

      // Verify: 1 additional transaction created (3 - 2 = 1 missing)
      final transactions = await db.select(db.transactionsLocal).get();
      expect(transactions.length, 3);
      expect(transactions.map((t) => t.id),
          containsAll([txnId('disp-abc', 0), txnId('disp-abc', 1),
              txnId('disp-abc', 2)]));
      final created =
          transactions.firstWhere((t) => t.id == txnId('disp-abc', 2));
      expect(created.memberId, 'member-1');
      expect(created.productId, 'prod-token');
      expect(created.amountCents, 200); // One token's price
      expect(created.dispenserTxId, 'disp-abc');
      expect(created.dispenserRequested, 3);
      expect(created.dispenserActual, 3);
      // No note naming the writer: the row is the same one checkout would have
      // written, and "created by recovery" would be a claim about a race.
      expect(created.notes, isNull);

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
      await service.reconcile();

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
      await service.reconcile();

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
      await service.reconcile();

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

      final op = await operation('disp-mno');
      await seedBilledToken(op, 0);
      await seedBilledToken(op, 1);

      // Run recovery
      await service.reconcile();

      // Verify: Only 2 transactions created (4 - 2 = 2 missing)
      final transactions = await db.select(db.transactionsLocal).get();
      expect(transactions.length, 4);
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

      await service.reconcile();

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

      await service.reconcile();

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

      await service.reconcile();

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
      await service.reconcile();

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
      await service.reconcile();

      // Verify: Tracking record unchanged (will retry later)
      final trackingRecords = await db.select(db.dispenserOperations).get();
      expect(trackingRecords.length, 1);
    });

    test('does nothing when no incomplete transactions exist', () async {
      // Run recovery on empty database
      await service.reconcile();

      // Should not make any client calls
      verifyNever(() => mockClient.getStatus(any()));
    });
  });

  /// #945: the tick and the dialog must not bill the same tokens.
  group('the periodic tick and a live dialog', () {
    Future<DispenserOperation> openDialogRow(String txId) async {
      await db.into(db.dispenserOperations).insert(
            DispenserOperationsCompanion.insert(
              dispenserTxId: txId,
              memberId: 'member-1',
              productId: 'prod-token',
              priceCents: 200,
              requestedQty: 5,
              createdAt: '2026-09-01T18:30:00.000Z',
              sessionId: const Value('session-42'),
              pollingActive: const Value(1),
              lastPolledAt: Value(DateTime.now()
                  .toUtc()
                  .subtract(const Duration(seconds: 40))
                  .toIso8601String()),
            ),
          );
      return operation(txId);
    }

    test('reconcile leaves polling_active alone', () async {
      await openDialogRow('disp-live');

      await service.reconcile();

      expect((await operation('disp-live')).pollingActive, 1,
          reason: 'the tick used to wipe the flag on every row, which is how '
              'it walked into a dialog that was still open');
      verifyNever(() => mockClient.getStatus(any()));
      expect(await db.select(db.transactionsLocal).get(), isEmpty);
    });

    test('the boot pass clears polling_active — there is no live dialog then',
        () async {
      await openDialogRow('disp-orphan');
      when(() => mockClient.getStatus('disp-orphan')).thenAnswer(
        (_) async => DispenseResult(
            txId: 'disp-orphan', state: 'done', quantity: 5, dispensed: 5),
      );

      await service.recoverAtStartup();

      // The orphaned flag is cleared, the tokens are billed once, the row goes.
      expect(await db.select(db.dispenserOperations).get(), isEmpty);
      expect(await db.select(db.transactionsLocal).get(), hasLength(5));
    });

    test('a tick that beat the dialog to it, then checkout: five rows, not ten',
        () async {
      final op = await openDialogRow('disp-race');
      when(() => mockClient.getStatus('disp-race')).thenAnswer(
        (_) async => DispenseResult(
            txId: 'disp-race', state: 'done', quantity: 5, dispensed: 5),
      );

      // The tick gets there first (the dialog's flag having been cleared by a
      // boot pass, or the dialog having closed between the two writes).
      await service.recoverAtStartup();
      // …and then the dialog finishes and checkout bills what it saw.
      await cartService.billDispensedTokens(op, upTo: 5);

      expect(await db.select(db.transactionsLocal).get(), hasLength(5));
    });

    test('checkout first, then a tick: still five rows', () async {
      final op = await openDialogRow('disp-race2');
      when(() => mockClient.getStatus('disp-race2')).thenAnswer(
        (_) async => DispenseResult(
            txId: 'disp-race2', state: 'done', quantity: 5, dispensed: 5),
      );

      await cartService.billDispensedTokens(op, upTo: 5);
      await service.recoverAtStartup();

      expect(await db.select(db.transactionsLocal).get(), hasLength(5));
      expect(await db.select(db.dispenserOperations).get(), isEmpty);
    });

    test('recovery rows carry the purchase time, unit price and session',
        () async {
      await openDialogRow('disp-late');
      when(() => mockClient.getStatus('disp-late')).thenAnswer(
        (_) async => DispenseResult(
            txId: 'disp-late', state: 'done', quantity: 5, dispensed: 2),
      );

      await service.recoverAtStartup();

      final billed = await db.select(db.transactionsLocal).get();
      expect(billed, hasLength(2));
      expect(billed.every((t) => t.createdAt == '2026-09-01T18:30:00.000Z'),
          isTrue,
          reason: 'the drink was bought then, not when the dispenser came back');
      expect(billed.every((t) => t.unitPriceCents == 200), isTrue);
      expect(billed.every((t) => t.sessionId == 'session-42'), isTrue);
    });

    test('bills the overrun the dispenser reports, not the quantity requested',
        () async {
      await openDialogRow('disp-over');
      when(() => mockClient.getStatus('disp-over')).thenAnswer(
        // Six fell although five were asked for
        // (dgloeckner/remote-token-dispenser#5).
        (_) async => DispenseResult(
            txId: 'disp-over', state: 'done', quantity: 5, dispensed: 6),
      );

      await service.recoverAtStartup();

      expect(await db.select(db.transactionsLocal).get(), hasLength(6));
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
