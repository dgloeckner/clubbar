import 'package:drift/drift.dart';
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/services/dispenser_client.dart';
import 'package:ruderbar_terminal/services/dispenser_recovery_service.dart';

class MockDispenserClient extends Mock implements DispenserClient {}

void main() {
  late RuderbarDatabase db;
  late MockDispenserClient mockClient;
  late DispenserRecoveryService service;

  setUp(() async {
    // Create in-memory database for testing
    db = RuderbarDatabase.forTesting(NativeDatabase.memory());
    mockClient = MockDispenserClient();
    service = DispenserRecoveryService(
      database: db,
      client: mockClient,
    );
  });

  tearDown(() async {
    await db.close();
  });

  group('DispenserRecoveryService', () {
    test('recovers completed dispense transaction', () async {
      // Insert incomplete transaction in DB
      await db.into(db.transactionsLocal).insert(
        TransactionsLocalCompanion.insert(
          id: 'tx-local-123',
          memberId: 'member-1',
          amountCents: 900, // 3 tokens at €3 each
          transactionType: 'purchase',
          createdAt: DateTime.now().toIso8601String(),
          synced: const Value(0),
          dispenserTxId: const Value('disp-abc'),
          dispenserRequested: const Value(3),
          dispenserActual: const Value(null), // Not yet completed
        ),
      );

      // ESP8266 reports completed dispense
      when(() => mockClient.getStatus('disp-abc')).thenAnswer(
        (_) async => DispenseResult(
          txId: 'disp-abc',
          state: 'done',
          quantity: 3,
          dispensed: 3,
        ),
      );

      // Run recovery
      await service.recoverIncompleteDispenses();

      // Verify transaction was updated
      final recovered = await (db.select(db.transactionsLocal)
            ..where((t) => t.id.equals('tx-local-123')))
          .getSingle();

      expect(recovered.dispenserActual, 3);
      expect(recovered.synced, 1); // Marked as synced
      verify(() => mockClient.getStatus('disp-abc')).called(1);
    });

    test('recovers partial dispense transaction', () async {
      // Insert incomplete transaction
      await db.into(db.transactionsLocal).insert(
        TransactionsLocalCompanion.insert(
          id: 'tx-local-456',
          memberId: 'member-1',
          productId: const Value('product-token'),
          amountCents: 900, // 3 tokens at €3 each
          transactionType: 'purchase',
          createdAt: DateTime.now().toIso8601String(),
          synced: const Value(0),
          dispenserTxId: const Value('disp-def'),
          dispenserRequested: const Value(3),
          dispenserActual: const Value(null),
        ),
      );

      // Insert product for price lookup
      await db.into(db.productsCache).insert(
        ProductsCacheData(
          id: 'product-token',
          categoryId: 'cat-1',
          names: '{"de": "Token"}',
          descriptions: null,
          priceCents: 300,
          isActive: 1,
          requiresDispenser: 1,
          iconName: null,
          updatedAt: DateTime.now().toIso8601String(),
        ),
      );

      // ESP8266 reports partial dispense (only 2 of 3)
      when(() => mockClient.getStatus('disp-def')).thenAnswer(
        (_) async => DispenseResult(
          txId: 'disp-def',
          state: 'error',
          quantity: 3,
          dispensed: 2, // Only 2 dispensed
        ),
      );

      // Run recovery
      await service.recoverIncompleteDispenses();

      // Verify transaction was adjusted
      final recovered = await (db.select(db.transactionsLocal)
            ..where((t) => t.id.equals('tx-local-456')))
          .getSingle();

      expect(recovered.dispenserActual, 2);
      expect(recovered.amountCents, 600); // Adjusted from 900 to 600 (2 tokens)
      verify(() => mockClient.getStatus('disp-def')).called(1);
    });

    test('handles transaction not found on dispenser', () async {
      // Insert incomplete transaction
      await db.into(db.transactionsLocal).insert(
        TransactionsLocalCompanion.insert(
          id: 'tx-local-789',
          memberId: 'member-1',
          amountCents: 300,
          transactionType: 'purchase',
          createdAt: DateTime.now().toIso8601String(),
          synced: const Value(0),
          dispenserTxId: const Value('disp-ghi'),
          dispenserRequested: const Value(1),
          dispenserActual: const Value(null),
        ),
      );

      // ESP8266 doesn't have this transaction (expired from ring buffer)
      when(() => mockClient.getStatus('disp-ghi'))
          .thenThrow(DispenserNotFoundException());

      // Run recovery (should not crash)
      await service.recoverIncompleteDispenses();

      // Verify transaction unchanged (left for manual reconciliation)
      final unchanged = await (db.select(db.transactionsLocal)
            ..where((t) => t.id.equals('tx-local-789')))
          .getSingle();

      expect(unchanged.dispenserActual, null);
      expect(unchanged.synced, 0);
      verify(() => mockClient.getStatus('disp-ghi')).called(1);
    });

    test('does nothing when no incomplete transactions exist', () async {
      // Run recovery on empty database
      await service.recoverIncompleteDispenses();

      // Should not make any client calls
      verifyNever(() => mockClient.getStatus(any()));
    });

    test('handles dispenser offline during recovery', () async {
      // Insert incomplete transaction
      await db.into(db.transactionsLocal).insert(
        TransactionsLocalCompanion.insert(
          id: 'tx-local-999',
          memberId: 'member-1',
          amountCents: 300,
          transactionType: 'purchase',
          createdAt: DateTime.now().toIso8601String(),
          synced: const Value(0),
          dispenserTxId: const Value('disp-jkl'),
          dispenserRequested: const Value(1),
          dispenserActual: const Value(null),
        ),
      );

      // ESP8266 is offline
      when(() => mockClient.getStatus('disp-jkl'))
          .thenThrow(DispenserException('Connection timeout'));

      // Run recovery (should not crash)
      await service.recoverIncompleteDispenses();

      // Verify transaction unchanged
      final unchanged = await (db.select(db.transactionsLocal)
            ..where((t) => t.id.equals('tx-local-999')))
          .getSingle();

      expect(unchanged.dispenserActual, null);
      expect(unchanged.synced, 0);
      verify(() => mockClient.getStatus('disp-jkl')).called(1);
    });
  });
}
