import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:clubbar_terminal/config/app_config.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/generated/terminal.swagger.dart';
import 'package:clubbar_terminal/repository/members_repository.dart';
import 'package:clubbar_terminal/repository/products_repository.dart';
import 'package:clubbar_terminal/repository/sync_repository.dart';
import 'package:clubbar_terminal/repository/transactions_repository.dart';
import 'package:clubbar_terminal/services/network_service.dart';
import 'package:clubbar_terminal/services/sync_service.dart';

// Mock classes
class MockNetworkService extends Mock implements NetworkService {}

class MockMembersRepository extends Mock implements MembersRepository {}

class MockProductsRepository extends Mock implements ProductsRepository {}

class MockTransactionsRepository extends Mock implements TransactionsRepository {}

class MockSyncRepository extends Mock implements SyncRepository {}

void main() {
  group('SyncService', () {
    late MockNetworkService mockNetworkService;
    late MockMembersRepository mockMembersRepo;
    late MockProductsRepository mockProductsRepo;
    late MockTransactionsRepository mockTransactionsRepo;
    late MockSyncRepository mockSyncRepo;
    late SyncService syncService;

    setUpAll(() {
      registerFallbackValue(Duration.zero);
      registerFallbackValue(<Map<String, dynamic>>[]);
      registerFallbackValue(<String>[]);
      registerFallbackValue(<String, int>{});
      registerFallbackValue(<String, String>{});
      registerFallbackValue(MockMembersRepository());
    });

    setUp(() {
      mockNetworkService = MockNetworkService();
      mockMembersRepo = MockMembersRepository();
      mockProductsRepo = MockProductsRepository();
      mockTransactionsRepo = MockTransactionsRepository();
      mockSyncRepo = MockSyncRepository();

      syncService = SyncService(
        networkService: mockNetworkService,
        membersRepo: mockMembersRepo,
        productsRepo: mockProductsRepo,
        transactionsRepo: mockTransactionsRepo,
        syncRepo: mockSyncRepo,
      );

      // Register stubs for common operations
      when(() => mockSyncRepo.isSyncNeeded(syncInterval: any(named: 'syncInterval')))
          .thenAnswer((_) async => true);
      when(() => mockSyncRepo.setLastSyncTime(any()))
          .thenAnswer((_) async => {});
      when(() => mockSyncRepo.resetSyncRetryCount())
          .thenAnswer((_) async => {});
      when(() => mockSyncRepo.clearLastSyncError())
          .thenAnswer((_) async => {});
      when(() => mockSyncRepo.setLastMembersSyncTime(any()))
          .thenAnswer((_) async => {});
      when(() => mockSyncRepo.setLastProductsSyncTime(any()))
          .thenAnswer((_) async => {});
      when(() => mockSyncRepo.getSyncRetryCount())
          .thenAnswer((_) async => 0);
      when(() => mockSyncRepo.getLastSyncError())
          .thenAnswer((_) async => null);
      // Delta sync cursor stubs
      when(() => mockSyncRepo.getLastMembersSyncCursor())
          .thenAnswer((_) async => null);
      when(() => mockSyncRepo.setLastMembersSyncCursor(any()))
          .thenAnswer((_) async => {});
      when(() => mockSyncRepo.getLastCategoriesSyncCursor())
          .thenAnswer((_) async => null);
      when(() => mockSyncRepo.setLastCategoriesSyncCursor(any()))
          .thenAnswer((_) async => {});
      when(() => mockSyncRepo.getLastProductsSyncCursor())
          .thenAnswer((_) async => null);
      when(() => mockSyncRepo.setLastProductsSyncCursor(any()))
          .thenAnswer((_) async => {});
      when(() => mockTransactionsRepo.getUnsyncedTransactions())
          .thenAnswer((_) async => []);
      when(() => mockTransactionsRepo.quarantineTransactions(any()))
          .thenAnswer((_) async => {});
      when(() => mockTransactionsRepo.completeSyncAtomically(
            acceptedIds: any(named: 'acceptedIds'),
            memberBalances: any(named: 'memberBalances'),
            membersRepo: any(named: 'membersRepo'),
          )).thenAnswer((_) async => {});
      when(() => mockMembersRepo.upsertMembers(any()))
          .thenAnswer((_) async => {});
      // No open tabs unless a test says so — the #374 refresh is then a no-op.
      when(() => mockMembersRepo.getMemberIdsWithOpenBalance())
          .thenAnswer((_) async => []);
      when(() => mockMembersRepo.updateMemberBalance(any(), any()))
          .thenAnswer((_) async => {});
      when(() => mockProductsRepo.upsertCategories(any()))
          .thenAnswer((_) async => {});
      when(() => mockProductsRepo.upsertProducts(any()))
          .thenAnswer((_) async => {});
    });

    /// Reference-data sync is not what these tests are about; make it a no-op.
    void stubReferenceDataSync() {
      when(() => mockNetworkService.syncMembers(since: any(named: 'since')))
          .thenAnswer((_) async => MemberDeltaResponse(
              members: [], cursor: 0, count: 0, hasMore: false));
      when(() => mockNetworkService.syncCategories(since: any(named: 'since')))
          .thenAnswer((_) async => CategoryDeltaResponse(
              categories: [], cursor: 0, count: 0, hasMore: false));
      when(() => mockNetworkService.syncProducts(since: any(named: 'since')))
          .thenAnswer((_) async => ProductDeltaResponse(
              products: [], cursor: 0, count: 0, hasMore: false));
    }

    TransactionsLocalData unsyncedTransaction({
      required String id,
      String memberId = 'member-1',
      String? productId = 'prod-1',
    }) {
      return TransactionsLocalData(
        id: id,
        memberId: memberId,
        productId: productId,
        amountCents: 350,
        transactionType: 'purchase',
        notes: null,
        createdAt: '2025-02-01T12:00:00Z',
        synced: 0,
        sessionId: null,
        unitPriceCents: null,
      );
    }

    test('isSyncing is false initially', () {
      expect(syncService.isSyncing, isFalse);
    });

    test('lastSyncTime is null initially', () {
      expect(syncService.lastSyncTime, isNull);
    });

    test('isSyncNeeded delegates to sync repository', () async {
      await syncService.isSyncNeeded();

      verify(() => mockSyncRepo.isSyncNeeded(
            syncInterval: AppConfig.syncInterval,
          )).called(1);
    });

    test('syncAll returns success on successful sync', () async {
      // Setup mocks for sync
      when(() => mockNetworkService.syncMembers(since: any(named: 'since')))
          .thenAnswer((_) async => MemberDeltaResponse(
                members: [],
                cursor: 0,
                count: 0,
                hasMore: false,
              ));

      when(() => mockNetworkService.syncCategories(since: any(named: 'since')))
          .thenAnswer((_) async => CategoryDeltaResponse(
                categories: [],
                cursor: 0,
                count: 0,
                hasMore: false,
              ));
      when(() => mockNetworkService.syncProducts(since: any(named: 'since')))
          .thenAnswer((_) async => ProductDeltaResponse(
                products: [],
                cursor: 0,
                count: 0,
                hasMore: false,
              ));

      final result = await syncService.syncAll();

      expect(result, equals(SyncResult.success));
    });

    test('isSyncing flag reflects sync state', () async {
      when(() => mockNetworkService.syncMembers(since: any(named: 'since')))
          .thenAnswer((_) async => MemberDeltaResponse(
                members: [],
                cursor: 0,
                count: 0,
                hasMore: false,
              ));

      when(() => mockNetworkService.syncCategories(since: any(named: 'since')))
          .thenAnswer((_) async => CategoryDeltaResponse(
                categories: [],
                cursor: 0,
                count: 0,
                hasMore: false,
              ));
      when(() => mockNetworkService.syncProducts(since: any(named: 'since')))
          .thenAnswer((_) async => ProductDeltaResponse(
                products: [],
                cursor: 0,
                count: 0,
                hasMore: false,
              ));

      expect(syncService.isSyncing, isFalse);

      // Note: In a real test, we'd need to check isSyncing during sync,
      // which requires more complex async flow testing with proper timers
      final result = await syncService.syncAll();

      expect(result, equals(SyncResult.success));
      expect(syncService.isSyncing, isFalse); // Should be false after sync completes
    });

    test('syncAll returns failure on network error', () async {
      when(() => mockNetworkService.syncMembers(since: any(named: 'since')))
          .thenThrow(NetworkException('Network error'));

      when(() => mockSyncRepo.setLastSyncError(any()))
          .thenAnswer((_) async => {});
      when(() => mockSyncRepo.incrementSyncRetryCount())
          .thenAnswer((_) async => {});

      final result = await syncService.syncAll();

      expect(result, equals(SyncResult.failure));
    });

    test('getRetryCount delegates to sync repository', () async {
      when(() => mockSyncRepo.getSyncRetryCount()).thenAnswer((_) async => 3);

      final count = await syncService.getRetryCount();

      expect(count, equals(3));
    });

    test('getLastError delegates to sync repository', () async {
      const errorMsg = 'Sync failed';
      when(() => mockSyncRepo.getLastSyncError())
          .thenAnswer((_) async => errorMsg);

      final error = await syncService.getLastError();

      expect(error, equals(errorMsg));
    });

    test('syncAll posts unsynced transactions and completes atomically', () async {
      // Setup sync mocks
      when(() => mockNetworkService.syncMembers(since: any(named: 'since')))
          .thenAnswer((_) async => MemberDeltaResponse(
                members: [],
                cursor: 0,
                count: 0,
                hasMore: false,
              ));
      when(() => mockNetworkService.syncCategories(since: any(named: 'since')))
          .thenAnswer((_) async => CategoryDeltaResponse(
                categories: [],
                cursor: 0,
                count: 0,
                hasMore: false,
              ));
      when(() => mockNetworkService.syncProducts(since: any(named: 'since')))
          .thenAnswer((_) async => ProductDeltaResponse(
                products: [],
                cursor: 0,
                count: 0,
                hasMore: false,
              ));

      // Return unsynced transactions
      final unsyncedTxns = [
        TransactionsLocalData(
          id: 'txn-1',
          memberId: 'member-1',
          productId: 'prod-1',
          amountCents: -350,
          transactionType: 'PURCHASE',
          notes: null,
          createdAt: '2025-02-01T12:00:00Z',
          synced: 0,
          sessionId: null,
          unitPriceCents: null,
        ),
        TransactionsLocalData(
          id: 'txn-2',
          memberId: 'member-2',
          productId: 'prod-2',
          amountCents: -300,
          transactionType: 'PURCHASE',
          notes: null,
          createdAt: '2025-02-01T12:01:00Z',
          synced: 0,
          sessionId: null,
          unitPriceCents: null,
        ),
      ];
      when(() => mockTransactionsRepo.getUnsyncedTransactions())
          .thenAnswer((_) async => unsyncedTxns);

      // Mock syncTransactions response
      when(() => mockNetworkService.syncTransactions(any()))
          .thenAnswer((_) async => TransactionBatchResponse(
                acceptedIds: ['txn-1', 'txn-2'],
                rejected: const TransactionBatchResponse$Rejected(count: 0, errors: []),
                memberBalances: {'member-1': 4500, 'member-2': 1200},
              ));

      // Mock completeSyncAtomically
      when(() => mockTransactionsRepo.completeSyncAtomically(
            acceptedIds: any(named: 'acceptedIds'),
            memberBalances: any(named: 'memberBalances'),
            membersRepo: any(named: 'membersRepo'),
          )).thenAnswer((_) async => {});

      final result = await syncService.syncAll();

      expect(result, equals(SyncResult.success));

      // Verify syncTransactions was called with correct payloads
      final captured = verify(() => mockNetworkService.syncTransactions(captureAny()))
          .captured;
      final payloads = captured.first as List<Map<String, dynamic>>;
      expect(payloads.length, equals(2));
      expect(payloads[0]['id'], 'txn-1');
      expect(payloads[0]['member_id'], 'member-1');
      expect(payloads[0]['amount_cents'], -350);
      expect(payloads[1]['id'], 'txn-2');

      // Verify completeSyncAtomically was called with correct args
      verify(() => mockTransactionsRepo.completeSyncAtomically(
            acceptedIds: ['txn-1', 'txn-2'],
            memberBalances: {'member-1': 4500, 'member-2': 1200},
            membersRepo: mockMembersRepo,
          )).called(1);
    });

    test('syncAll handles rejected transactions gracefully', () async {
      when(() => mockNetworkService.syncMembers(since: any(named: 'since')))
          .thenAnswer((_) async => MemberDeltaResponse(
                members: [],
                cursor: 0,
                count: 0,
                hasMore: false,
              ));
      when(() => mockNetworkService.syncCategories(since: any(named: 'since')))
          .thenAnswer((_) async => CategoryDeltaResponse(
                categories: [],
                cursor: 0,
                count: 0,
                hasMore: false,
              ));
      when(() => mockNetworkService.syncProducts(since: any(named: 'since')))
          .thenAnswer((_) async => ProductDeltaResponse(
                products: [],
                cursor: 0,
                count: 0,
                hasMore: false,
              ));

      final unsyncedTxns = [
        TransactionsLocalData(
          id: 'txn-1',
          memberId: 'member-1',
          productId: 'prod-1',
          amountCents: -350,
          transactionType: 'PURCHASE',
          notes: null,
          createdAt: '2025-02-01T12:00:00Z',
          synced: 0,
          sessionId: null,
          unitPriceCents: null,
        ),
      ];
      when(() => mockTransactionsRepo.getUnsyncedTransactions())
          .thenAnswer((_) async => unsyncedTxns);

      when(() => mockNetworkService.syncTransactions(any()))
          .thenAnswer((_) async => TransactionBatchResponse(
                acceptedIds: [],
                rejected: const TransactionBatchResponse$Rejected(
                  count: 1,
                  errors: [
                    TransactionBatchResponse$Rejected$Errors$Item(
                      transactionId: 'txn-1',
                      error: 'member_not_found',
                    ),
                  ],
                ),
                memberBalances: {},
              ));

      when(() => mockTransactionsRepo.completeSyncAtomically(
            acceptedIds: any(named: 'acceptedIds'),
            memberBalances: any(named: 'memberBalances'),
            membersRepo: any(named: 'membersRepo'),
          )).thenAnswer((_) async => {});

      final result = await syncService.syncAll();

      expect(result, equals(SyncResult.success));

      // completeSyncAtomically still called (with empty accepted list)
      verify(() => mockTransactionsRepo.completeSyncAtomically(
            acceptedIds: [],
            memberBalances: {},
            membersRepo: mockMembersRepo,
          )).called(1);
    });

    test('a sale keeps the time it happened, and claims no server-owned fields',
        () async {
      stubReferenceDataSync();

      when(() => mockTransactionsRepo.getUnsyncedTransactions())
          .thenAnswer((_) async => [unsyncedTransaction(id: 'txn-offline')]);
      when(() => mockNetworkService.syncTransactions(any()))
          .thenAnswer((_) async => TransactionBatchResponse(
                acceptedIds: ['txn-offline'],
                rejected:
                    const TransactionBatchResponse$Rejected(count: 0, errors: []),
                memberBalances: {'member-1': 350},
              ));

      await syncService.syncAll();

      final payloads =
          verify(() => mockNetworkService.syncTransactions(captureAny()))
              .captured
              .first as List<Map<String, dynamic>>;

      // The sale happened offline hours before this sync; the upload carries
      // that moment, not the moment of upload (ruling #144).
      expect(payloads.single['created_at'], equals('2025-02-01T12:00:00.000Z'));
      expect(payloads.single['id'], equals('txn-offline'));
      // received_at, transaction_type and the terminal id are the server's to
      // set — a terminal that sends them is asserting authority it lacks.
      expect(payloads.single.keys,
          equals(['id', 'member_id', 'product_id', 'amount_cents', 'created_at']));
    });

    /// #259: the server caps a batch at 100 (`api/terminal.yaml`), the queue is
    /// unbounded, and an oversized batch is refused whole with a 400 — which
    /// this service retries unchanged, so an offline evening of more than 100
    /// sales would never sync again.
    test('an oversized queue is uploaded in batches the server will accept',
        () async {
      stubReferenceDataSync();

      final queued = List.generate(
        250,
        (i) => unsyncedTransaction(id: 'txn-$i'),
      );
      when(() => mockTransactionsRepo.getUnsyncedTransactions())
          .thenAnswer((_) async => queued);
      when(() => mockNetworkService.syncTransactions(any()))
          .thenAnswer((invocation) async {
        final batch =
            invocation.positionalArguments.first as List<Map<String, dynamic>>;
        return TransactionBatchResponse(
          acceptedIds: batch.map((t) => t['id'] as String).toList(),
          rejected:
              const TransactionBatchResponse$Rejected(count: 0, errors: []),
          memberBalances: const {'member-1': 350},
        );
      });

      await syncService.syncAll();

      final batches =
          verify(() => mockNetworkService.syncTransactions(captureAny()))
              .captured
              .cast<List<Map<String, dynamic>>>();

      expect(batches.length, equals(3));
      expect(batches.map((b) => b.length), equals([100, 100, 50]));

      // Every queued sale is uploaded exactly once, and none is dropped at a
      // batch boundary.
      final uploaded = batches.expand((b) => b).map((t) => t['id']).toList();
      expect(uploaded.length, equals(250));
      expect(uploaded.toSet().length, equals(250));
    });

    test('a permanently rejected sale is quarantined instead of retried',
        () async {
      stubReferenceDataSync();

      when(() => mockTransactionsRepo.getUnsyncedTransactions())
          .thenAnswer((_) async => [
                unsyncedTransaction(id: 'txn-ok'),
                unsyncedTransaction(id: 'txn-bad'),
              ]);

      when(() => mockNetworkService.syncTransactions(any()))
          .thenAnswer((_) async => TransactionBatchResponse(
                acceptedIds: ['txn-ok'],
                rejected: const TransactionBatchResponse$Rejected(
                  count: 1,
                  errors: [
                    TransactionBatchResponse$Rejected$Errors$Item(
                      transactionId: 'txn-bad',
                      error: 'unstorable',
                      message: 'Database refused the row',
                    ),
                  ],
                ),
                memberBalances: {'member-1': 350},
              ));

      final result = await syncService.syncAll();

      expect(result, equals(SyncResult.success));
      verify(() => mockTransactionsRepo
          .quarantineTransactions({'txn-bad': 'unstorable'})).called(1);
    });

    test('a transient whole-request failure quarantines nothing', () async {
      stubReferenceDataSync();

      when(() => mockTransactionsRepo.getUnsyncedTransactions())
          .thenAnswer((_) async => [unsyncedTransaction(id: 'txn-1')]);

      when(() => mockNetworkService.syncTransactions(any()))
          .thenThrow(NetworkException('Connection reset'));

      final result = await syncService.syncAll();

      expect(result, equals(SyncResult.success));
      verifyNever(() => mockTransactionsRepo.quarantineTransactions(any()));
    });

    test('a sale that can never be sent is quarantined, not skipped forever',
        () async {
      stubReferenceDataSync();

      when(() => mockTransactionsRepo.getUnsyncedTransactions())
          .thenAnswer((_) async => [
                unsyncedTransaction(id: 'txn-no-product', productId: null),
              ]);

      final result = await syncService.syncAll();

      expect(result, equals(SyncResult.success));
      verify(() => mockTransactionsRepo
          .quarantineTransactions({'txn-no-product': 'missing_product_id'}))
          .called(1);
      verifyNever(() => mockNetworkService.syncTransactions(any()));
    });

    // An admin deletes a product, category or member and the terminal learns
    // about it as a `deleted_at` tombstone in the next delta. The tombstone is
    // applied by the ordinary upsert — the row is flagged, never removed, so
    // that the local sales referencing it keep their foreign key. The
    // row-level effects are covered in repository_test.dart; these pin the
    // wiring: that a tombstone reaches the cache at all, and that a delete
    // landing mid-queue does not disturb the upload.
    group('tombstones', () {
      Member memberDto(String id, {DateTime? deletedAt}) => Member(
            id: id,
            cardUid: 'CARD-$id',
            firstName: 'Test',
            lastName: 'Member',
            preferredLanguage: 'de',
            isActive: true,
            isSepaValid: true,
            deletedAt: deletedAt,
            createdAt: DateTime.parse('2025-02-01T12:00:00Z'),
            updatedAt: DateTime.parse('2025-02-01T12:00:00Z'),
          );

      Category categoryDto(String id, {DateTime? deletedAt}) => Category(
            id: id,
            names: const {'de': 'Getränke'},
            isActive: true,
            deletedAt: deletedAt,
            createdAt: DateTime.parse('2025-02-01T12:00:00Z'),
            updatedAt: DateTime.parse('2025-02-01T12:00:00Z'),
          );

      Product productDto(String id, {DateTime? deletedAt}) => Product(
            id: id,
            categoryId: 'cat-1',
            names: const {'de': 'Pils'},
            priceCents: 350,
            isActive: true,
            deletedAt: deletedAt,
            createdAt: DateTime.parse('2025-02-01T12:00:00Z'),
            updatedAt: DateTime.parse('2025-02-01T12:00:00Z'),
          );

      final deletedOn = DateTime.parse('2025-02-02T09:00:00Z');

      test('a deleted product is handed to the cache, tombstone intact',
          () async {
        stubReferenceDataSync();
        when(() => mockNetworkService.syncProducts(since: any(named: 'since')))
            .thenAnswer((_) async => ProductDeltaResponse(
                products: [productDto('prod-1', deletedAt: deletedOn)],
                cursor: 0,
                count: 1,
                hasMore: false));

        expect(await syncService.syncAll(), equals(SyncResult.success));

        final upserted =
            verify(() => mockProductsRepo.upsertProducts(captureAny()))
                .captured
                .last as List<Product>;
        expect(upserted.single.deletedAt, equals(deletedOn));
      });

      test('a deleted category is handed to the cache, tombstone intact',
          () async {
        stubReferenceDataSync();
        when(() => mockNetworkService.syncCategories(since: any(named: 'since')))
            .thenAnswer((_) async => CategoryDeltaResponse(
                categories: [categoryDto('cat-1', deletedAt: deletedOn)],
                cursor: 0,
                count: 1,
                hasMore: false));

        expect(await syncService.syncAll(), equals(SyncResult.success));

        final upserted =
            verify(() => mockProductsRepo.upsertCategories(captureAny()))
                .captured
                .last as List<Category>;
        expect(upserted.single.deletedAt, equals(deletedOn));
      });

      // A deleted member used to be split out of the delta and passed to a
      // `deleteById` that SQLite refused, because `transactions_local` still
      // referenced the row. The throw escaped `_syncMembers` — the first step of
      // the cycle — so one anonymized member who had ever bought a drink here
      // stopped products *and* the transaction upload, every cycle, for good.
      test('a deleted member is upserted with the rest, not deleted', () async {
        stubReferenceDataSync();
        when(() => mockNetworkService.syncMembers(since: any(named: 'since')))
            .thenAnswer((_) async => MemberDeltaResponse(members: [
                  memberDto('member-1'),
                  memberDto('member-gone', deletedAt: deletedOn),
                ], cursor: 0, count: 2, hasMore: false));

        expect(await syncService.syncAll(), equals(SyncResult.success));

        final upserted =
            verify(() => mockMembersRepo.upsertMembers(captureAny()))
                .captured
                .last as List<Member>;
        expect(upserted.map((m) => m.id),
            containsAll(['member-1', 'member-gone']),
            reason: 'the tombstone travels the same path as any other change');
        expect(
            upserted.firstWhere((m) => m.id == 'member-gone').deletedAt,
            equals(deletedOn));
      });

      test('a member tombstone does not stop the rest of the cycle', () async {
        stubReferenceDataSync();
        when(() => mockNetworkService.syncMembers(since: any(named: 'since')))
            .thenAnswer((_) async => MemberDeltaResponse(
                members: [memberDto('member-gone', deletedAt: deletedOn)],
                cursor: 0,
                count: 1,
                hasMore: false));
        when(() => mockTransactionsRepo.getUnsyncedTransactions())
            .thenAnswer((_) async => [unsyncedTransaction(id: 'txn-queued')]);
        when(() => mockNetworkService.syncTransactions(any()))
            .thenAnswer((_) async => TransactionBatchResponse(
                  acceptedIds: const ['txn-queued'],
                  rejected:
                      const TransactionBatchResponse$Rejected(count: 0, errors: []),
                  memberBalances: const {'member-1': 350},
                ));

        expect(await syncService.syncAll(), equals(SyncResult.success));

        verify(() => mockProductsRepo.upsertCategories(any())).called(1);
        verify(() => mockProductsRepo.upsertProducts(any())).called(1);
        verify(() => mockNetworkService.syncTransactions(any())).called(1);
      });

      // ADR-0033 §1: by sync time the drink is in the member's hand. A product
      // deleted after the sale is not a reason to refuse the record of it, so
      // the queued row is uploaded unchanged and must not be quarantined.
      test('a sale queued before its product was deleted still uploads',
          () async {
        stubReferenceDataSync();
        when(() => mockNetworkService.syncProducts(since: any(named: 'since')))
            .thenAnswer((_) async => ProductDeltaResponse(
                products: [productDto('prod-1', deletedAt: deletedOn)],
                cursor: 0,
                count: 1,
                hasMore: false));
        when(() => mockTransactionsRepo.getUnsyncedTransactions()).thenAnswer(
            (_) async =>
                [unsyncedTransaction(id: 'txn-queued', productId: 'prod-1')]);
        when(() => mockNetworkService.syncTransactions(any()))
            .thenAnswer((_) async => TransactionBatchResponse(
                  acceptedIds: const ['txn-queued'],
                  rejected:
                      const TransactionBatchResponse$Rejected(count: 0, errors: []),
                  memberBalances: const {'member-1': 350},
                ));

        expect(await syncService.syncAll(), equals(SyncResult.success));

        final payloads =
            verify(() => mockNetworkService.syncTransactions(captureAny()))
                .captured
                .single as List<Map<String, dynamic>>;
        expect(payloads.single['product_id'], equals('prod-1'),
            reason: 'the sale still names the product it was sold as');
        verifyNever(() => mockTransactionsRepo.quarantineTransactions(any()));
      });
    });

    group('failed transaction logging', () {
      late Directory tempDir;
      late String failedTxnsPath;

      setUp(() {
        tempDir = Directory.systemTemp.createTempSync('sync_failed_txns_');
        failedTxnsPath = '${tempDir.path}/failed_transactions.json';
      });

      tearDown(() {
        if (tempDir.existsSync()) {
          tempDir.deleteSync(recursive: true);
        }
      });

      SyncService createSyncServiceWithFailedTxns() {
        return SyncService(
          networkService: mockNetworkService,
          membersRepo: mockMembersRepo,
          productsRepo: mockProductsRepo,
          transactionsRepo: mockTransactionsRepo,
          syncRepo: mockSyncRepo,
          failedTransactionsPath: failedTxnsPath,
        );
      }

      test('writes failed transaction details to JSON file on sync error', () async {
        final service = createSyncServiceWithFailedTxns();

        // Setup successful member/category/product sync
        when(() => mockNetworkService.syncMembers(since: any(named: 'since')))
            .thenAnswer((_) async => MemberDeltaResponse(
                  members: [],
                  cursor: 0,
                  count: 0,
                  hasMore: false,
                ));
        when(() => mockNetworkService.syncCategories(since: any(named: 'since')))
            .thenAnswer((_) async => CategoryDeltaResponse(
                  categories: [],
                  cursor: 0,
                  count: 0,
                  hasMore: false,
                ));
        when(() => mockNetworkService.syncProducts(since: any(named: 'since')))
            .thenAnswer((_) async => ProductDeltaResponse(
                  products: [],
                  cursor: 0,
                  count: 0,
                  hasMore: false,
                ));

        // Return unsynced transactions
        final unsyncedTxns = [
          TransactionsLocalData(
            id: 'txn-fail-1',
            memberId: 'member-1',
            productId: 'prod-1',
            amountCents: -350,
            transactionType: 'PURCHASE',
            notes: 'test note',
            createdAt: '2025-02-01T12:00:00Z',
            synced: 0,
            sessionId: null,
            unitPriceCents: null,
          ),
        ];
        when(() => mockTransactionsRepo.getUnsyncedTransactions())
            .thenAnswer((_) async => unsyncedTxns);

        // Make transaction sync fail
        when(() => mockNetworkService.syncTransactions(any()))
            .thenThrow(NetworkException('Connection refused'));

        final result = await service.syncAll();

        // Sync should still succeed (transaction sync is non-fatal)
        expect(result, equals(SyncResult.success));

        // Verify failed transactions file was written
        final file = File(failedTxnsPath);
        expect(file.existsSync(), isTrue);

        final contents = jsonDecode(file.readAsStringSync()) as List<dynamic>;
        expect(contents, hasLength(1));

        final entry = contents[0] as Map<String, dynamic>;
        expect(entry['error'], contains('Connection refused'));
        expect(entry['transaction_count'], equals(1));
        expect(entry['timestamp'], isNotNull);

        final txns = entry['transactions'] as List<dynamic>;
        expect(txns, hasLength(1));
        expect(txns[0]['id'], equals('txn-fail-1'));
        expect(txns[0]['member_id'], equals('member-1'));
        expect(txns[0]['product_id'], equals('prod-1'));
        expect(txns[0]['amount_cents'], equals(-350));
        expect(txns[0]['transaction_type'], equals('PURCHASE'));
        expect(txns[0]['notes'], equals('test note'));
        expect(txns[0]['created_at'], equals('2025-02-01T12:00:00Z'));
      });

      test('appends to existing failed transactions file', () async {
        final service = createSyncServiceWithFailedTxns();

        // Pre-populate the file with an existing entry
        final file = File(failedTxnsPath);
        file.writeAsStringSync(jsonEncode([
          {
            'timestamp': '2025-01-01T00:00:00Z',
            'error': 'Previous error',
            'transaction_count': 1,
            'transactions': [{'id': 'old-txn'}],
          }
        ]));

        // Setup sync
        when(() => mockNetworkService.syncMembers(since: any(named: 'since')))
            .thenAnswer((_) async => MemberDeltaResponse(
                  members: [],
                  cursor: 0,
                  count: 0,
                  hasMore: false,
                ));
        when(() => mockNetworkService.syncCategories(since: any(named: 'since')))
            .thenAnswer((_) async => CategoryDeltaResponse(
                  categories: [],
                  cursor: 0,
                  count: 0,
                  hasMore: false,
                ));
        when(() => mockNetworkService.syncProducts(since: any(named: 'since')))
            .thenAnswer((_) async => ProductDeltaResponse(
                  products: [],
                  cursor: 0,
                  count: 0,
                  hasMore: false,
                ));

        when(() => mockTransactionsRepo.getUnsyncedTransactions())
            .thenAnswer((_) async => [
                  TransactionsLocalData(
                    id: 'txn-fail-2',
                    memberId: 'member-2',
                    productId: 'prod-2',
                    amountCents: -200,
                    transactionType: 'PURCHASE',
                    notes: null,
                    createdAt: '2025-02-02T12:00:00Z',
                    synced: 0,
                    sessionId: null,
                    unitPriceCents: null,
                  ),
                ]);

        when(() => mockNetworkService.syncTransactions(any()))
            .thenThrow(NetworkException('Timeout'));

        await service.syncAll();

        final contents = jsonDecode(file.readAsStringSync()) as List<dynamic>;
        expect(contents, hasLength(2));
        expect(contents[0]['error'], equals('Previous error'));
        expect(contents[1]['error'], contains('Timeout'));
        expect(contents[1]['transactions'][0]['id'], equals('txn-fail-2'));
      });

      test('does not write file when no failedTransactionsPath configured', () async {
        // Use the default syncService which has no failedTransactionsPath
        when(() => mockNetworkService.syncMembers(since: any(named: 'since')))
            .thenAnswer((_) async => MemberDeltaResponse(
                  members: [],
                  cursor: 0,
                  count: 0,
                  hasMore: false,
                ));
        when(() => mockNetworkService.syncCategories(since: any(named: 'since')))
            .thenAnswer((_) async => CategoryDeltaResponse(
                  categories: [],
                  cursor: 0,
                  count: 0,
                  hasMore: false,
                ));
        when(() => mockNetworkService.syncProducts(since: any(named: 'since')))
            .thenAnswer((_) async => ProductDeltaResponse(
                  products: [],
                  cursor: 0,
                  count: 0,
                  hasMore: false,
                ));

        when(() => mockTransactionsRepo.getUnsyncedTransactions())
            .thenAnswer((_) async => [
                  TransactionsLocalData(
                    id: 'txn-fail-3',
                    memberId: 'member-1',
                    productId: 'prod-1',
                    amountCents: -100,
                    transactionType: 'PURCHASE',
                    notes: null,
                    createdAt: '2025-02-03T12:00:00Z',
                    synced: 0,
                    sessionId: null,
                    unitPriceCents: null,
                  ),
                ]);

        when(() => mockNetworkService.syncTransactions(any()))
            .thenThrow(NetworkException('Error'));

        await syncService.syncAll();

        // The default syncService has no path — no file should exist
        final file = File(failedTxnsPath);
        expect(file.existsSync(), isFalse);
      });
    });

    /// #374: a storno, a settlement or a sale on another terminal moves a
    /// balance without this terminal doing anything, and `member_balances` only
    /// reports members a request *names*. Without this the cached tab was left
    /// at its pre-storno value until the member happened to buy something.
    group('open balance refresh (#374)', () {
      TransactionBatchResponse balances(Map<String, dynamic> byMember) =>
          TransactionBatchResponse(
            acceptedIds: [],
            rejected: const TransactionBatchResponse$Rejected(),
            memberBalances: byMember,
          );

      test('re-asks for every open tab and stores what comes back', () async {
        stubReferenceDataSync();
        when(() => mockMembersRepo.getMemberIdsWithOpenBalance())
            .thenAnswer((_) async => ['member-1', 'member-2']);
        when(() => mockNetworkService.syncTransactions(any(),
                memberIds: any(named: 'memberIds')))
            .thenAnswer((_) async => balances({'member-1': 0, 'member-2': 750}));

        await syncService.syncAll();

        verify(() => mockNetworkService.syncTransactions(
              [],
              memberIds: ['member-1', 'member-2'],
            )).called(1);
        verify(() => mockMembersRepo.updateMemberBalance('member-1', 0))
            .called(1);
        verify(() => mockMembersRepo.updateMemberBalance('member-2', 750))
            .called(1);
      });

      test('asks about nobody when no tab is open', () async {
        stubReferenceDataSync();

        await syncService.syncAll();

        verifyNever(() => mockNetworkService.syncTransactions(any(),
            memberIds: any(named: 'memberIds')));
      });

      /// An id the backend does not know is omitted from the map rather than
      /// reported as 0. Writing that absence as a zero would wipe a real tab.
      test('leaves a member the backend did not report alone', () async {
        stubReferenceDataSync();
        when(() => mockMembersRepo.getMemberIdsWithOpenBalance())
            .thenAnswer((_) async => ['member-1']);
        when(() => mockNetworkService.syncTransactions(any(),
                memberIds: any(named: 'memberIds')))
            .thenAnswer((_) async => balances({}));

        await syncService.syncAll();

        verifyNever(() => mockMembersRepo.updateMemberBalance(any(), any()));
      });

      /// Same cap as an upload: the server refuses an oversized request whole,
      /// so a club with more than 100 open tabs must be asked in chunks or no
      /// balance would ever be refreshed again.
      test('splits an ask larger than the batch limit', () async {
        stubReferenceDataSync();
        final ids = List.generate(
            SyncService.maxSyncBatchSize + 5, (i) => 'member-$i');
        when(() => mockMembersRepo.getMemberIdsWithOpenBalance())
            .thenAnswer((_) async => ids);

        final asked = <List<String>>[];
        when(() => mockNetworkService.syncTransactions(any(),
            memberIds: any(named: 'memberIds'))).thenAnswer((invocation) async {
          asked.add(List<String>.from(
              invocation.namedArguments[#memberIds] as List<String>));
          return balances({});
        });

        await syncService.syncAll();

        expect(asked.map((chunk) => chunk.length).toList(),
            equals([SyncService.maxSyncBatchSize, 5]));
        expect(asked.expand((chunk) => chunk).toList(), equals(ids));
      });

      /// The refresh is a nicety; members and products are not. A backend that
      /// refuses the ask must not take the whole cycle down with it.
      test('a failed refresh does not fail the sync cycle', () async {
        stubReferenceDataSync();
        when(() => mockMembersRepo.getMemberIdsWithOpenBalance())
            .thenAnswer((_) async => ['member-1']);
        when(() => mockNetworkService.syncTransactions(any(),
                memberIds: any(named: 'memberIds')))
            .thenThrow(NetworkException('offline'));

        expect(await syncService.syncAll(), equals(SyncResult.success));
      });
    });

    test('reset clears sync state', () async {
      when(() => mockSyncRepo.clearAllSyncState())
          .thenAnswer((_) async => {});

      await syncService.reset();

      expect(syncService.isSyncing, isFalse);
      expect(syncService.lastSyncTime, isNull);
      verify(() => mockSyncRepo.clearAllSyncState()).called(1);
    });

    /// ADR-0035: a terminal must be able to tell "the backend I've always
    /// synced with" apart from one at the same URL with a different,
    /// discontinuous history (#380).
    group('checkPairing', () {
      test('proceeds when the backend has no instance_id yet (pre-migration)', () async {
        final result = await syncService.checkPairing(null);

        expect(result, equals(PairingResult.paired));
        verifyNever(() => mockSyncRepo.setPairedBackendInstanceId(any()));
      });

      test('trust-on-first-use: stores the id when nothing was paired before', () async {
        when(() => mockSyncRepo.getPairedBackendInstanceId())
            .thenAnswer((_) async => null);
        when(() => mockSyncRepo.setPairedBackendInstanceId(any()))
            .thenAnswer((_) async => {});

        final result = await syncService.checkPairing('instance-a');

        expect(result, equals(PairingResult.paired));
        verify(() => mockSyncRepo.setPairedBackendInstanceId('instance-a')).called(1);
      });

      test('is paired when the id matches what was stored before', () async {
        when(() => mockSyncRepo.getPairedBackendInstanceId())
            .thenAnswer((_) async => 'instance-a');

        final result = await syncService.checkPairing('instance-a');

        expect(result, equals(PairingResult.paired));
        verifyNever(() => mockSyncRepo.setPairedBackendInstanceId(any()));
      });

      test('is a mismatch when the id differs from what was stored before', () async {
        when(() => mockSyncRepo.getPairedBackendInstanceId())
            .thenAnswer((_) async => 'instance-a');

        final result = await syncService.checkPairing('instance-b');

        expect(result, equals(PairingResult.mismatch));
      });

      test('a mismatch never overwrites the stored pairing', () async {
        when(() => mockSyncRepo.getPairedBackendInstanceId())
            .thenAnswer((_) async => 'instance-a');

        await syncService.checkPairing('instance-b');

        verifyNever(() => mockSyncRepo.setPairedBackendInstanceId(any()));
      });
    });

    group('getUnsyncedCount', () {
      test('delegates to the transactions repository', () async {
        when(() => mockTransactionsRepo.getUnsyncedCount())
            .thenAnswer((_) async => 3);

        expect(await syncService.getUnsyncedCount(), equals(3));
      });
    });

    group('acknowledgePairing', () {
      test('stores the newly-confirmed instance id as paired', () async {
        when(() => mockSyncRepo.setPairedBackendInstanceId(any()))
            .thenAnswer((_) async => {});

        await syncService.acknowledgePairing('instance-b');

        verify(() => mockSyncRepo.setPairedBackendInstanceId('instance-b')).called(1);
      });
    });

    /// #395 — a 401 for an aged-out token is not an outage. It has to be told
    /// apart from one, because only one of the two is something staff at the
    /// bar can wait out.
    group('credentialExpired', () {
      void stubFailurePersistence() {
        when(() => mockSyncRepo.setLastSyncError(any())).thenAnswer((_) async => {});
        when(() => mockSyncRepo.incrementSyncRetryCount()).thenAnswer((_) async => {});
      }

      test('is false before anything has been attempted', () {
        expect(syncService.credentialExpired, isFalse);
      });

      test('a 401 terminal_token_expired raises it', () async {
        stubFailurePersistence();
        when(() => mockNetworkService.syncMembers(since: any(named: 'since')))
            .thenThrow(NetworkException(
          'Sync members failed: HTTP 401',
          statusCode: 401,
          errorCode: 'terminal_token_expired',
        ));

        expect(await syncService.syncAll(), equals(SyncResult.failure));
        expect(syncService.credentialExpired, isTrue);
      });

      test('an ordinary network failure does not', () async {
        stubFailurePersistence();
        when(() => mockNetworkService.syncMembers(since: any(named: 'since')))
            .thenThrow(NetworkException('Connection refused'));

        expect(await syncService.syncAll(), equals(SyncResult.failure));
        expect(syncService.credentialExpired, isFalse);
      });

      /// The flag describes the *last* attempt, so a rotation entered at the
      /// terminal makes the block go away by itself.
      test('a later successful cycle clears it', () async {
        stubFailurePersistence();
        when(() => mockNetworkService.syncMembers(since: any(named: 'since')))
            .thenThrow(NetworkException(
          'Sync members failed: HTTP 401',
          statusCode: 401,
          errorCode: 'terminal_token_expired',
        ));
        await syncService.syncAll();
        expect(syncService.credentialExpired, isTrue);

        when(() => mockNetworkService.syncMembers(since: any(named: 'since')))
            .thenAnswer((_) async => null);

        expect(await syncService.syncAll(), equals(SyncResult.success));
        expect(syncService.credentialExpired, isFalse);
      });
    });
  });
}
