import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/providers/products_provider.dart';
import 'package:ruderbar_terminal/providers/sync_provider.dart';
import 'package:ruderbar_terminal/services/sync_service.dart';

class MockSyncService extends Mock implements SyncService {}

class MockMembersProvider extends Mock implements MembersProvider {}

class MockProductsProvider extends Mock implements ProductsProvider {}

void main() {
  group('SyncProvider', () {
    late MockSyncService mockSyncService;
    late MockMembersProvider mockMembersProvider;
    late MockProductsProvider mockProductsProvider;
    late SyncProvider provider;

    setUp(() {
      mockSyncService = MockSyncService();
      mockMembersProvider = MockMembersProvider();
      mockProductsProvider = MockProductsProvider();
      provider = SyncProvider(
        syncService: mockSyncService,
        membersProvider: mockMembersProvider,
        productsProvider: mockProductsProvider,
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
    });

    test('startSync calls syncService and refreshes providers', () async {
      when(() => mockSyncService.isSyncNeeded())
          .thenAnswer((_) async => true);
      when(() => mockSyncService.syncAll())
          .thenAnswer((_) async => SyncResult.success);
      when(() => mockMembersProvider.refreshMembers())
          .thenAnswer((_) async => null);
      when(() => mockProductsProvider.refreshProducts())
          .thenAnswer((_) async => null);

      await provider.startSync();

      expect(provider.isSyncing, isFalse);
      expect(provider.lastError, isNull);
      verify(() => mockSyncService.syncAll()).called(1);
      verify(() => mockMembersProvider.refreshMembers()).called(1);
      verify(() => mockProductsProvider.refreshProducts()).called(1);
    });

    test('startSync handles sync failure non-blocking', () async {
      when(() => mockSyncService.isSyncNeeded())
          .thenAnswer((_) async => true);
      when(() => mockSyncService.syncAll())
          .thenAnswer((_) async => SyncResult.failure);
      when(() => mockSyncService.getLastError())
          .thenAnswer((_) async => 'Network error');

      await provider.startSync();

      expect(provider.isSyncing, isFalse);
      expect(provider.lastError, contains('Network error'));
      expect(provider.retryCount, equals(1));
    });

    test('startSync skips if not needed', () async {
      when(() => mockSyncService.isSyncNeeded())
          .thenAnswer((_) async => false);

      await provider.startSync();

      verifyNever(() => mockSyncService.syncAll());
    });

    test('startSync increments retryCount on failure', () async {
      when(() => mockSyncService.isSyncNeeded())
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

    test('startSync clears error on success', () async {
      // First set an error
      when(() => mockSyncService.isSyncNeeded())
          .thenAnswer((_) async => true);
      when(() => mockSyncService.syncAll())
          .thenAnswer((_) async => SyncResult.failure);
      when(() => mockSyncService.getLastError())
          .thenAnswer((_) async => 'Network error');

      await provider.startSync();
      expect(provider.lastError, isNotNull);

      // Then succeed
      when(() => mockSyncService.syncAll())
          .thenAnswer((_) async => SyncResult.success);
      when(() => mockMembersProvider.refreshMembers())
          .thenAnswer((_) async => null);
      when(() => mockProductsProvider.refreshProducts())
          .thenAnswer((_) async => null);

      await provider.startSync();

      expect(provider.lastError, isNull);
      expect(provider.retryCount, equals(0)); // Reset on success
    });

    test('background timer can be started and stopped', () async {
      when(() => mockSyncService.isSyncNeeded())
          .thenAnswer((_) async => true);
      when(() => mockSyncService.syncAll())
          .thenAnswer((_) async => SyncResult.success);
      when(() => mockMembersProvider.refreshMembers())
          .thenAnswer((_) async => null);
      when(() => mockProductsProvider.refreshProducts())
          .thenAnswer((_) async => null);

      provider.startBackgroundSync(intervalSeconds: 1);
      await Future.delayed(Duration(milliseconds: 1500));

      // Should have called sync at least once due to timer
      verify(() => mockSyncService.isSyncNeeded()).called(greaterThan(0));

      provider.stopSync();
    });
  });
}
