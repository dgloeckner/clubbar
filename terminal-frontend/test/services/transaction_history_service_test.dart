import 'dart:async';

import 'package:drift/drift.dart';
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/generated/terminal.swagger.dart';
import 'package:clubbar_terminal/models/transaction_list_item.dart';
import 'package:clubbar_terminal/services/network_service.dart';
import 'package:clubbar_terminal/services/transaction_history_service.dart';

class MockNetworkService extends Mock implements NetworkService {}

void main() {
  late ClubBarDatabase db;
  late MockNetworkService network;
  late TransactionHistoryService service;

  setUp(() async {
    db = ClubBarDatabase.forTesting(NativeDatabase.memory());
    network = MockNetworkService();
    service = TransactionHistoryService(
      networkService: network,
      database: db,
      // Real duration, just short enough that a hanging backend doesn't hang
      // the test suite too.
      requestTimeout: const Duration(milliseconds: 50),
    );

    await db.into(db.membersCache).insert(
          MembersCacheCompanion.insert(
            id: 'member-1',
            preferredLanguage: 'de',
            isSepaValid: 1,
            updatedAt: '2026-01-01T10:00:00Z',
          ),
        );
    await db.into(db.categoriesCache).insert(
          CategoriesCacheCompanion.insert(
            id: 'cat-1',
            names: '{"de":"Getränke"}',
            updatedAt: '2026-01-01T10:00:00Z',
          ),
        );
    await db.into(db.productsCache).insert(
          ProductsCacheCompanion.insert(
            id: 'product-1',
            categoryId: 'cat-1',
            names: '{"de":"Pils","en":"Lager"}',
            priceCents: 350,
            iconName: const Value('beer-pils'),
            updatedAt: '2026-01-01T10:00:00Z',
          ),
        );
  });

  tearDown(() async {
    await db.close();
  });

  Future<void> insertLocalPurchase({
    required String id,
    required String createdAt,
    int amountCents = 350,
    int synced = 0,
    String? quarantinedAt,
  }) {
    return db.into(db.transactionsLocal).insert(
          TransactionsLocalCompanion.insert(
            id: id,
            memberId: 'member-1',
            productId: const Value('product-1'),
            amountCents: amountCents,
            transactionType: 'purchase',
            createdAt: createdAt,
            synced: Value(synced),
            quarantinedAt: Value(quarantinedAt),
          ),
        );
  }

  // Issue #32: the balance already folds in local purchases, so history has to
  // show them too — otherwise the member is told their own beers don't exist.
  group('offline', () {
    setUp(() {
      when(() => network.checkHealth()).thenAnswer((_) async => false);
    });

    test('returns the purchases this terminal knows about locally', () async {
      await insertLocalPurchase(id: 'tx-1', createdAt: '2026-01-02T18:00:00Z');

      final result = await service.fetchTransactionHistory(
        memberId: 'member-1',
        preferredLanguage: 'de',
      );

      expect(result.isOffline, isTrue);
      expect(result.transactions, hasLength(1));
      expect(result.transactions.single.details, 'Pils');
      expect(result.transactions.single.syncStatus,
          TransactionSyncStatus.unsynced);
    });

    // Issue #417: the balance stopped counting quarantined sales, so history
    // has to stop showing them — otherwise it contradicts the balance again,
    // just from the other side.
    test('leaves out a sale the backend refused for good', () async {
      await insertLocalPurchase(id: 'tx-1', createdAt: '2026-01-02T18:00:00Z');
      await insertLocalPurchase(
        id: 'tx-quarantined',
        createdAt: '2026-01-02T19:00:00Z',
        quarantinedAt: '2026-01-02T19:05:00Z',
      );

      final result = await service.fetchTransactionHistory(
        memberId: 'member-1',
        preferredLanguage: 'de',
      );

      expect(result.transactions.map((t) => t.id), ['tx-1']);
    });

    test('never asks the backend', () async {
      await service.fetchTransactionHistory(
        memberId: 'member-1',
        preferredLanguage: 'de',
      );

      verifyNever(() => network.getTransactionHistory(any(),
          limit: any(named: 'limit')));
    });
  });

  // Issue #32: every remote transaction used to come back as `open` with no
  // icon, so a long-settled purchase looked identical to a running tab.
  group('online', () {
    TransactionHistoryResponse$Transactions$Item remoteItem({
      required String id,
      String? settlementId,
      String? productIcon,
    }) {
      return TransactionHistoryResponse$Transactions$Item(
        id: id,
        amountCents: 350,
        type: TransactionHistoryResponse$Transactions$ItemType.purchase,
        productId: 'product-1',
        productName: 'Pils',
        productIcon: productIcon,
        settlementId: settlementId,
        createdAt: DateTime.utc(2026, 1, 1, 18),
      );
    }

    void respondWith(List<TransactionHistoryResponse$Transactions$Item> items) {
      when(() => network.checkHealth()).thenAnswer((_) async => true);
      when(() => network.getTransactionHistory(any(),
              limit: any(named: 'limit')))
          .thenAnswer((_) async => TransactionHistoryResponse(
                memberId: 'member-1',
                count: items.length,
                transactions: items,
              ));
    }

    test('a settled transaction reads as settled', () async {
      respondWith([remoteItem(id: 'tx-remote-1', settlementId: 'settlement-1')]);

      final result = await service.fetchTransactionHistory(
        memberId: 'member-1',
        preferredLanguage: 'de',
      );

      expect(result.isOffline, isFalse);
      expect(result.transactions.single.syncStatus,
          TransactionSyncStatus.settled);
    });

    test('an unsettled transaction stays an open tab', () async {
      respondWith([remoteItem(id: 'tx-remote-1')]);

      final result = await service.fetchTransactionHistory(
        memberId: 'member-1',
        preferredLanguage: 'de',
      );

      expect(
          result.transactions.single.syncStatus, TransactionSyncStatus.open);
    });

    test('keeps the product icon the backend sent', () async {
      respondWith([remoteItem(id: 'tx-remote-1', productIcon: 'beer-pils')]);

      final result = await service.fetchTransactionHistory(
        memberId: 'member-1',
        preferredLanguage: 'de',
      );

      expect(result.transactions.single.productIcon, 'beer-pils');
    });

    test('unsynced local purchases still lead the merged list', () async {
      respondWith([remoteItem(id: 'tx-remote-1')]);
      await insertLocalPurchase(id: 'tx-1', createdAt: '2026-01-02T18:00:00Z');

      final result = await service.fetchTransactionHistory(
        memberId: 'member-1',
        preferredLanguage: 'de',
      );

      expect(result.transactions.map((t) => t.id), ['tx-1', 'tx-remote-1']);
    });

    // Issue #417: staff re-enter a quarantined sale in the admin panel, so it
    // comes back from the backend. Keeping the local row would show the member
    // one drink twice.
    test('a re-entered quarantined sale appears once, not twice', () async {
      respondWith([remoteItem(id: 'tx-remote-1')]);
      await insertLocalPurchase(
        id: 'tx-quarantined',
        createdAt: '2026-01-01T18:00:00Z',
        quarantinedAt: '2026-01-01T18:05:00Z',
      );

      final result = await service.fetchTransactionHistory(
        memberId: 'member-1',
        preferredLanguage: 'de',
      );

      expect(result.transactions.map((t) => t.id), ['tx-remote-1']);
    });
  });

  // Issue #32: the doc comment promised a timeout that was never wired up, so
  // a flaky wifi could leave the member staring at a spinner forever.
  group('slow backend', () {
    test('a hanging health check falls back to the local transactions',
        () async {
      when(() => network.checkHealth())
          .thenAnswer((_) => Completer<bool>().future);
      await insertLocalPurchase(id: 'tx-1', createdAt: '2026-01-02T18:00:00Z');

      final result = await service.fetchTransactionHistory(
        memberId: 'member-1',
        preferredLanguage: 'de',
      );

      expect(result.isOffline, isTrue);
      expect(result.transactions, hasLength(1));
    });

    test('a hanging history fetch surfaces as a fetch failure, not a hang',
        () async {
      when(() => network.checkHealth()).thenAnswer((_) async => true);
      when(() => network.getTransactionHistory(any(),
              limit: any(named: 'limit')))
          .thenAnswer((_) => Completer<TransactionHistoryResponse>().future);

      await expectLater(
        service.fetchTransactionHistory(
          memberId: 'member-1',
          preferredLanguage: 'de',
        ),
        throwsA(isA<TransactionFetchException>()),
      );
    });
  });
}
