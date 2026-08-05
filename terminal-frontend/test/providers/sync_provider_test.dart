import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/providers/products_provider.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/services/network_service.dart';
import 'package:clubbar_terminal/services/sync_service.dart';

class MockSyncService extends Mock implements SyncService {}

class MockMembersProvider extends Mock implements MembersProvider {}

class MockProductsProvider extends Mock implements ProductsProvider {}

class MockNetworkService extends Mock implements NetworkService {}

void main() {
  group('SyncProvider', () {
    late MockSyncService mockSyncService;
    late MockMembersProvider mockMembersProvider;
    late MockProductsProvider mockProductsProvider;
    late MockNetworkService mockNetworkService;
    late SyncProvider provider;

    setUp(() {
      mockSyncService = MockSyncService();
      mockMembersProvider = MockMembersProvider();
      mockProductsProvider = MockProductsProvider();
      mockNetworkService = MockNetworkService();
      provider = SyncProvider(
        syncService: mockSyncService,
        membersProvider: mockMembersProvider,
        productsProvider: mockProductsProvider,
        networkService: mockNetworkService,
      );
    });

    tearDown(() {
      provider.stopSync();
    });

    test('initial state reflects no sync', () {
      expect(provider.isSyncing, isFalse);
      expect(provider.lastSyncTime, isNull);
      expect(provider.retryCount, equals(0));
      expect(provider.lastError, isNull);
      expect(provider.connectionStatus, equals(ConnectionStatus.online));
      expect(provider.lastSuccessfulTransactionSync, isNull);
    });

    test('startSync calls syncService and refreshes providers on success', () async {
      when(() => mockSyncService.isSyncNeeded())
          .thenAnswer((_) async => true);
      when(() => mockNetworkService.checkHealth())
          .thenAnswer((_) async => true);
      when(() => mockSyncService.syncAll())
          .thenAnswer((_) async => SyncResult.success);
      when(() => mockSyncService.lastTransactionSyncTime).thenReturn(null);
      when(() => mockSyncService.lastTransactionSyncError).thenReturn(null);
      when(() => mockMembersProvider.refreshMembers())
          .thenAnswer((_) async {});
      when(() => mockProductsProvider.refreshProducts())
          .thenAnswer((_) async {});

      await provider.startSync();

      expect(provider.isSyncing, isFalse);
      expect(provider.lastError, isNull);
      expect(provider.connectionStatus, equals(ConnectionStatus.online));
      verify(() => mockSyncService.syncAll()).called(1);
      verify(() => mockMembersProvider.refreshMembers()).called(1);
      verify(() => mockProductsProvider.refreshProducts()).called(1);
    });

    test('startSync sets offline when health check fails', () async {
      when(() => mockNetworkService.checkHealth())
          .thenAnswer((_) async => false);

      await provider.startSync();

      expect(provider.connectionStatus, equals(ConnectionStatus.offline));
      expect(provider.lastErrorKey, equals(TerminalErrorKey.backendUnreachable));
      expect(provider.retryCount, equals(1));
      verifyNever(() => mockSyncService.syncAll());
      verifyNever(() => mockSyncService.isSyncNeeded());
    });

    test('startSync sets error when health passes but sync fails', () async {
      when(() => mockSyncService.isSyncNeeded())
          .thenAnswer((_) async => true);
      when(() => mockNetworkService.checkHealth())
          .thenAnswer((_) async => true);
      when(() => mockSyncService.syncAll())
          .thenAnswer((_) async => SyncResult.failure);
      when(() => mockSyncService.getLastError())
          .thenAnswer((_) async => 'Sync failed');

      await provider.startSync();

      expect(provider.connectionStatus, equals(ConnectionStatus.error));
      expect(provider.lastErrorKey, equals(TerminalErrorKey.syncFailed));
      expect(provider.retryCount, equals(1));
    });

    test('startSync skips sync if not needed but still checks health', () async {
      when(() => mockNetworkService.checkHealth())
          .thenAnswer((_) async => true);
      when(() => mockSyncService.isSyncNeeded())
          .thenAnswer((_) async => false);

      await provider.startSync();

      verify(() => mockNetworkService.checkHealth()).called(1);
      verifyNever(() => mockSyncService.syncAll());
      expect(provider.connectionStatus, equals(ConnectionStatus.online));
    });

    test('startSync increments retryCount on failure', () async {
      when(() => mockSyncService.isSyncNeeded())
          .thenAnswer((_) async => true);
      when(() => mockNetworkService.checkHealth())
          .thenAnswer((_) async => true);
      when(() => mockSyncService.syncAll())
          .thenAnswer((_) async => SyncResult.failure);
      when(() => mockSyncService.getLastError())
          .thenAnswer((_) async => 'Error');

      expect(provider.retryCount, equals(0));

      await provider.startSync();
      expect(provider.retryCount, equals(1));

      await provider.startSync();
      expect(provider.retryCount, equals(2));
    });

    test('startSync clears error and returns to online on success', () async {
      // First set an error state
      when(() => mockSyncService.isSyncNeeded())
          .thenAnswer((_) async => true);
      when(() => mockNetworkService.checkHealth())
          .thenAnswer((_) async => true);
      when(() => mockSyncService.syncAll())
          .thenAnswer((_) async => SyncResult.failure);
      when(() => mockSyncService.getLastError())
          .thenAnswer((_) async => 'Network error');

      await provider.startSync();
      expect(provider.lastError, isNotNull);
      expect(provider.connectionStatus, equals(ConnectionStatus.error));

      // Then succeed
      when(() => mockSyncService.syncAll())
          .thenAnswer((_) async => SyncResult.success);
      when(() => mockSyncService.lastTransactionSyncTime).thenReturn(null);
      when(() => mockSyncService.lastTransactionSyncError).thenReturn(null);
      when(() => mockMembersProvider.refreshMembers())
          .thenAnswer((_) async {});
      when(() => mockProductsProvider.refreshProducts())
          .thenAnswer((_) async {});

      await provider.startSync();

      expect(provider.lastError, isNull);
      expect(provider.retryCount, equals(0));
      expect(provider.connectionStatus, equals(ConnectionStatus.online));
    });

    test('connectionStatus goes offline when health fails, then back to online', () async {
      // Fail health check
      when(() => mockNetworkService.checkHealth())
          .thenAnswer((_) async => false);

      await provider.startSync();
      expect(provider.connectionStatus, equals(ConnectionStatus.offline));

      // Health recovers, sync succeeds
      when(() => mockNetworkService.checkHealth())
          .thenAnswer((_) async => true);
      when(() => mockSyncService.isSyncNeeded())
          .thenAnswer((_) async => true);
      when(() => mockSyncService.syncAll())
          .thenAnswer((_) async => SyncResult.success);
      when(() => mockSyncService.lastTransactionSyncTime).thenReturn(null);
      when(() => mockSyncService.lastTransactionSyncError).thenReturn(null);
      when(() => mockMembersProvider.refreshMembers())
          .thenAnswer((_) async {});
      when(() => mockProductsProvider.refreshProducts())
          .thenAnswer((_) async {});

      await provider.startSync();
      expect(provider.connectionStatus, equals(ConnectionStatus.online));
    });

    test('lastSuccessfulTransactionSync is tracked from SyncService', () async {
      final txnTime = DateTime(2025, 6, 15, 12, 30);

      when(() => mockSyncService.isSyncNeeded())
          .thenAnswer((_) async => true);
      when(() => mockNetworkService.checkHealth())
          .thenAnswer((_) async => true);
      when(() => mockSyncService.syncAll())
          .thenAnswer((_) async => SyncResult.success);
      when(() => mockSyncService.lastTransactionSyncTime).thenReturn(txnTime);
      when(() => mockSyncService.lastTransactionSyncError).thenReturn(null);
      when(() => mockMembersProvider.refreshMembers())
          .thenAnswer((_) async {});
      when(() => mockProductsProvider.refreshProducts())
          .thenAnswer((_) async {});

      await provider.startSync();

      expect(provider.lastSuccessfulTransactionSync, equals(txnTime));
    });

    test('sets error state when sync succeeds but transaction sync failed', () async {
      when(() => mockSyncService.isSyncNeeded())
          .thenAnswer((_) async => true);
      when(() => mockNetworkService.checkHealth())
          .thenAnswer((_) async => true);
      when(() => mockSyncService.syncAll())
          .thenAnswer((_) async => SyncResult.success);
      when(() => mockSyncService.lastTransactionSyncTime).thenReturn(null);
      when(() => mockSyncService.lastTransactionSyncError)
          .thenReturn('NetworkException: HTTP 422');
      when(() => mockMembersProvider.refreshMembers())
          .thenAnswer((_) async {});
      when(() => mockProductsProvider.refreshProducts())
          .thenAnswer((_) async {});

      await provider.startSync();

      expect(provider.connectionStatus, equals(ConnectionStatus.error));
      expect(provider.lastErrorKey, equals(TerminalErrorKey.transactionSyncFailed));
      expect(provider.lastSyncTime, isNotNull);
    });

    test('background timer can be started and stopped', () async {
      when(() => mockSyncService.isSyncNeeded())
          .thenAnswer((_) async => true);
      when(() => mockNetworkService.checkHealth())
          .thenAnswer((_) async => true);
      when(() => mockSyncService.syncAll())
          .thenAnswer((_) async => SyncResult.success);
      when(() => mockSyncService.lastTransactionSyncTime).thenReturn(null);
      when(() => mockSyncService.lastTransactionSyncError).thenReturn(null);
      when(() => mockMembersProvider.refreshMembers())
          .thenAnswer((_) async {});
      when(() => mockProductsProvider.refreshProducts())
          .thenAnswer((_) async {});

      provider.startBackgroundSync(intervalSeconds: 1);
      await Future.delayed(Duration(milliseconds: 1500));

      verify(() => mockSyncService.isSyncNeeded()).called(greaterThan(0));

      provider.stopSync();
    });
  });
}
