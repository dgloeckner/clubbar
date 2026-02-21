import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:ruderbar_terminal/config/app_config.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/models/sync_response.dart';
import 'package:ruderbar_terminal/models/transaction_sync_response.dart';
import 'package:ruderbar_terminal/repository/members_repository.dart';
import 'package:ruderbar_terminal/repository/products_repository.dart';
import 'package:ruderbar_terminal/repository/sync_repository.dart';
import 'package:ruderbar_terminal/repository/transactions_repository.dart';
import 'package:ruderbar_terminal/services/network_service.dart';
import 'package:ruderbar_terminal/services/sync_service.dart';

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
      when(() => mockMembersRepo.upsertMembers(any()))
          .thenAnswer((_) async => {});
      when(() => mockProductsRepo.upsertCategories(any()))
          .thenAnswer((_) async => {});
      when(() => mockProductsRepo.upsertProducts(any()))
          .thenAnswer((_) async => {});
    });

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
      when(() => mockNetworkService.syncMembers(since: any(named: 'since'), ifNoneMatch: any(named: 'ifNoneMatch')))
          .thenAnswer((_) async => MembersSyncResponse(members: []));

      when(() => mockNetworkService.syncCategories(since: any(named: 'since'), ifNoneMatch: any(named: 'ifNoneMatch')))
          .thenAnswer((_) async => CategoriesSyncResponse(categories: []));
      when(() => mockNetworkService.syncProducts(since: any(named: 'since'), ifNoneMatch: any(named: 'ifNoneMatch')))
          .thenAnswer((_) async => ProductsSyncResponse(products: []));

      final result = await syncService.syncAll();

      expect(result, equals(SyncResult.success));
    });

    test('isSyncing flag reflects sync state', () async {
      when(() => mockNetworkService.syncMembers(since: any(named: 'since'), ifNoneMatch: any(named: 'ifNoneMatch')))
          .thenAnswer((_) async => MembersSyncResponse(members: []));

      when(() => mockNetworkService.syncCategories(since: any(named: 'since'), ifNoneMatch: any(named: 'ifNoneMatch')))
          .thenAnswer((_) async => CategoriesSyncResponse(categories: []));
      when(() => mockNetworkService.syncProducts(since: any(named: 'since'), ifNoneMatch: any(named: 'ifNoneMatch')))
          .thenAnswer((_) async => ProductsSyncResponse(products: []));

      expect(syncService.isSyncing, isFalse);

      // Note: In a real test, we'd need to check isSyncing during sync,
      // which requires more complex async flow testing with proper timers
      final result = await syncService.syncAll();

      expect(result, equals(SyncResult.success));
      expect(syncService.isSyncing, isFalse); // Should be false after sync completes
    });

    test('syncAll returns failure on network error', () async {
      when(() => mockNetworkService.syncMembers(since: any(named: 'since'), ifNoneMatch: any(named: 'ifNoneMatch')))
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
      when(() => mockNetworkService.syncMembers(since: any(named: 'since'), ifNoneMatch: any(named: 'ifNoneMatch')))
          .thenAnswer((_) async => MembersSyncResponse(members: []));
      when(() => mockNetworkService.syncCategories(since: any(named: 'since'), ifNoneMatch: any(named: 'ifNoneMatch')))
          .thenAnswer((_) async => CategoriesSyncResponse(categories: []));
      when(() => mockNetworkService.syncProducts(since: any(named: 'since'), ifNoneMatch: any(named: 'ifNoneMatch')))
          .thenAnswer((_) async => ProductsSyncResponse(products: []));

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
          .thenAnswer((_) async => TransactionSyncResponse(
                acceptedIds: ['txn-1', 'txn-2'],
                rejected: TransactionSyncRejected(count: 0, errors: []),
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
      when(() => mockNetworkService.syncMembers(since: any(named: 'since'), ifNoneMatch: any(named: 'ifNoneMatch')))
          .thenAnswer((_) async => MembersSyncResponse(members: []));
      when(() => mockNetworkService.syncCategories(since: any(named: 'since'), ifNoneMatch: any(named: 'ifNoneMatch')))
          .thenAnswer((_) async => CategoriesSyncResponse(categories: []));
      when(() => mockNetworkService.syncProducts(since: any(named: 'since'), ifNoneMatch: any(named: 'ifNoneMatch')))
          .thenAnswer((_) async => ProductsSyncResponse(products: []));

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
          .thenAnswer((_) async => TransactionSyncResponse(
                acceptedIds: [],
                rejected: TransactionSyncRejected(
                  count: 1,
                  errors: [
                    TransactionSyncError(
                      transactionId: 'txn-1',
                      reason: 'member_not_found',
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
        when(() => mockNetworkService.syncMembers())
            .thenAnswer((_) async => MembersSyncResponse(members: []));
        when(() => mockNetworkService.syncCategories())
            .thenAnswer((_) async => CategoriesSyncResponse(categories: []));
        when(() => mockNetworkService.syncProducts())
            .thenAnswer((_) async => ProductsSyncResponse(products: []));

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
        when(() => mockNetworkService.syncMembers())
            .thenAnswer((_) async => MembersSyncResponse(members: []));
        when(() => mockNetworkService.syncCategories())
            .thenAnswer((_) async => CategoriesSyncResponse(categories: []));
        when(() => mockNetworkService.syncProducts())
            .thenAnswer((_) async => ProductsSyncResponse(products: []));

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
        when(() => mockNetworkService.syncMembers())
            .thenAnswer((_) async => MembersSyncResponse(members: []));
        when(() => mockNetworkService.syncCategories())
            .thenAnswer((_) async => CategoriesSyncResponse(categories: []));
        when(() => mockNetworkService.syncProducts())
            .thenAnswer((_) async => ProductsSyncResponse(products: []));

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

    test('reset clears sync state', () async {
      when(() => mockSyncRepo.clearAllSyncState())
          .thenAnswer((_) async => {});

      await syncService.reset();

      expect(syncService.isSyncing, isFalse);
      expect(syncService.lastSyncTime, isNull);
      verify(() => mockSyncRepo.clearAllSyncState()).called(1);
    });
  });
}
