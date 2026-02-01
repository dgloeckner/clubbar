import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:ruderbar_terminal/config/app_config.dart';
import 'package:ruderbar_terminal/models/sync_response.dart';
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
      // Register fallback for Duration
      registerFallbackValue(Duration.zero);
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
      when(() => mockNetworkService.syncMembers())
          .thenAnswer((_) async => MembersSyncResponse(members: []));

      when(() => mockNetworkService.syncProducts())
          .thenAnswer((_) async => ProductsSyncResponse(
                categories: [],
                products: [],
              ));

      final result = await syncService.syncAll();

      expect(result, equals(SyncResult.success));
    });

    test('isSyncing flag reflects sync state', () async {
      when(() => mockNetworkService.syncMembers())
          .thenAnswer((_) async => MembersSyncResponse(members: []));

      when(() => mockNetworkService.syncProducts())
          .thenAnswer((_) async => ProductsSyncResponse(
                categories: [],
                products: [],
              ));

      expect(syncService.isSyncing, isFalse);

      // Note: In a real test, we'd need to check isSyncing during sync,
      // which requires more complex async flow testing with proper timers
      final result = await syncService.syncAll();

      expect(result, equals(SyncResult.success));
      expect(syncService.isSyncing, isFalse); // Should be false after sync completes
    });

    test('syncAll returns failure on network error', () async {
      when(() => mockNetworkService.syncMembers())
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
