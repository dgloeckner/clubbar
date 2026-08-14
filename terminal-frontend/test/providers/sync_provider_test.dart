import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/providers/products_provider.dart';
import 'package:clubbar_terminal/providers/quarantine_provider.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/services/network_service.dart';
import 'package:clubbar_terminal/services/sync_service.dart';

class MockSyncService extends Mock implements SyncService {}

class MockMembersProvider extends Mock implements MembersProvider {}

class MockProductsProvider extends Mock implements ProductsProvider {}

class MockNetworkService extends Mock implements NetworkService {}

class MockQuarantineProvider extends Mock implements QuarantineProvider {}

class MockConfigService extends Mock implements ConfigService {}

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

      // ADR-0035 default: no instance_id reported / nothing to compare
      // against, so ordinary sync proceeds unless a test says otherwise.
      when(() => mockNetworkService.fetchInstanceId())
          .thenAnswer((_) async => null);
      when(() => mockSyncService.checkPairing(any()))
          .thenAnswer((_) async => PairingResult.paired);
      // #395 default: the terminal's own credential is fine. Mirrored out of
      // the service after every cycle, so every test that reaches syncAll()
      // reads it.
      when(() => mockSyncService.credentialExpired).thenReturn(false);
    });

    tearDown(() {
      provider.stopSync();
    });

    test('a completed sync re-reads the quarantine', () async {
      final quarantine = MockQuarantineProvider();
      when(() => quarantine.refresh()).thenAnswer((_) async {});
      final providerWithQuarantine = SyncProvider(
        syncService: mockSyncService,
        membersProvider: mockMembersProvider,
        productsProvider: mockProductsProvider,
        networkService: mockNetworkService,
        quarantineProvider: quarantine,
      );
      addTearDown(providerWithQuarantine.stopSync);

      when(() => mockSyncService.isSyncNeeded()).thenAnswer((_) async => true);
      when(() => mockNetworkService.checkHealth()).thenAnswer((_) async => true);
      when(() => mockSyncService.syncAll())
          .thenAnswer((_) async => SyncResult.success);
      when(() => mockSyncService.lastTransactionSyncTime).thenReturn(null);
      when(() => mockSyncService.lastTransactionSyncError).thenReturn(null);
      when(() => mockMembersProvider.refreshMembers()).thenAnswer((_) async {});
      when(() => mockProductsProvider.refreshProducts())
          .thenAnswer((_) async {});

      await providerWithQuarantine.startSync();

      verify(() => quarantine.refresh()).called(1);
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

    // Issue #40: the status modal answers "how long has this been broken?",
    // which needs the start of the outage rather than the latest attempt.
    group('degradedSince', () {
      test('is null while the terminal is healthy', () {
        expect(provider.degradedSince, isNull);
      });

      test('is stamped when the backend goes unreachable', () async {
        when(() => mockNetworkService.checkHealth())
            .thenAnswer((_) async => false);

        final before = DateTime.now();
        await provider.startSync();

        expect(provider.connectionStatus, equals(ConnectionStatus.offline));
        expect(provider.degradedSince, isNotNull);
        expect(
          provider.degradedSince!.isBefore(before.subtract(
            const Duration(seconds: 1),
          )),
          isFalse,
        );
      });

      test('keeps the first failure time across repeated retries', () async {
        when(() => mockNetworkService.checkHealth())
            .thenAnswer((_) async => false);

        await provider.startSync();
        final first = provider.degradedSince;

        await Future.delayed(const Duration(milliseconds: 20));
        await provider.startSync();

        expect(provider.retryCount, equals(2));
        expect(provider.degradedSince, equals(first));
      });

      test('clears once the backend answers again', () async {
        when(() => mockNetworkService.checkHealth())
            .thenAnswer((_) async => false);
        await provider.startSync();
        expect(provider.degradedSince, isNotNull);

        when(() => mockNetworkService.checkHealth())
            .thenAnswer((_) async => true);
        when(() => mockSyncService.isSyncNeeded()).thenAnswer((_) async => false);
        await provider.startSync();

        expect(provider.connectionStatus, equals(ConnectionStatus.online));
        expect(provider.degradedSince, isNull);
      });

      test('is stamped when a sync cycle fails despite a healthy backend',
          () async {
        when(() => mockNetworkService.checkHealth())
            .thenAnswer((_) async => true);
        when(() => mockSyncService.isSyncNeeded()).thenAnswer((_) async => true);
        when(() => mockSyncService.syncAll())
            .thenAnswer((_) async => SyncResult.failure);
        when(() => mockSyncService.getLastError())
            .thenAnswer((_) async => 'boom');

        await provider.startSync();

        expect(provider.connectionStatus, equals(ConnectionStatus.error));
        expect(provider.degradedSince, isNotNull);
      });
    });

    // ADR-0034: the existing health-check cycle is the vehicle for
    // propagating the backend's instance name into ConfigService.
    group('instance name propagation (ADR-0034)', () {
      late MockConfigService mockConfigService;
      late SyncProvider providerWithConfig;

      setUp(() {
        mockConfigService = MockConfigService();
        when(() => mockConfigService.setBackendDisplayName(any()))
            .thenReturn(null);
        providerWithConfig = SyncProvider(
          syncService: mockSyncService,
          membersProvider: mockMembersProvider,
          productsProvider: mockProductsProvider,
          networkService: mockNetworkService,
          configService: mockConfigService,
        );
      });

      tearDown(() {
        providerWithConfig.stopSync();
      });

      test('a successful health check feeds the instance name into ConfigService',
          () async {
        when(() => mockNetworkService.checkHealth())
            .thenAnswer((_) async => true);
        when(() => mockNetworkService.fetchInstanceName())
            .thenAnswer((_) async => 'FRGS Ruderbar');
        when(() => mockSyncService.isSyncNeeded()).thenAnswer((_) async => false);

        await providerWithConfig.startSync();

        verify(() => mockConfigService.setBackendDisplayName('FRGS Ruderbar'))
            .called(1);
      });

      test('a failed health check never fetches or sets the instance name',
          () async {
        when(() => mockNetworkService.checkHealth())
            .thenAnswer((_) async => false);

        await providerWithConfig.startSync();

        verifyNever(() => mockNetworkService.fetchInstanceName());
        verifyNever(() => mockConfigService.setBackendDisplayName(any()));
      });

      test('a failed instance-name fetch does not break the sync cycle',
          () async {
        when(() => mockNetworkService.checkHealth())
            .thenAnswer((_) async => true);
        when(() => mockNetworkService.fetchInstanceName())
            .thenAnswer((_) async => null);
        when(() => mockSyncService.isSyncNeeded()).thenAnswer((_) async => true);
        when(() => mockSyncService.syncAll())
            .thenAnswer((_) async => SyncResult.success);
        when(() => mockSyncService.lastTransactionSyncTime).thenReturn(null);
        when(() => mockSyncService.lastTransactionSyncError).thenReturn(null);
        when(() => mockMembersProvider.refreshMembers())
            .thenAnswer((_) async {});
        when(() => mockProductsProvider.refreshProducts())
            .thenAnswer((_) async {});

        await providerWithConfig.startSync();

        expect(providerWithConfig.connectionStatus, equals(ConnectionStatus.online));
        verifyNever(() => mockConfigService.setBackendDisplayName(any()));
      });

      test('without a ConfigService, the instance name is never fetched',
          () async {
        when(() => mockNetworkService.checkHealth())
            .thenAnswer((_) async => true);
        when(() => mockSyncService.isSyncNeeded()).thenAnswer((_) async => false);

        // `provider` (from the outer setUp) has no configService.
        await provider.startSync();

        verifyNever(() => mockNetworkService.fetchInstanceName());
      });
    });

    // ADR-0035: a terminal must hard-block sync — no push, no pull — the
    // moment the backend reports a different instance_id than the one it
    // last synced with (#380), and never auto-clear the block.
    group('pairing mismatch (ADR-0035)', () {
      test('pairingMismatch defaults to false', () {
        expect(provider.pairingMismatch, isFalse);
        expect(provider.pairingMismatchTransactionCount, equals(0));
      });

      test('a mismatch hard-blocks the sync cycle', () async {
        when(() => mockNetworkService.checkHealth())
            .thenAnswer((_) async => true);
        when(() => mockNetworkService.fetchInstanceId())
            .thenAnswer((_) async => 'instance-b');
        when(() => mockSyncService.checkPairing('instance-b'))
            .thenAnswer((_) async => PairingResult.mismatch);
        when(() => mockSyncService.getUnsyncedCount())
            .thenAnswer((_) async => 3);

        await provider.startSync();

        expect(provider.pairingMismatch, isTrue);
        expect(provider.pairingMismatchTransactionCount, equals(3));
        verifyNever(() => mockSyncService.isSyncNeeded());
        verifyNever(() => mockSyncService.syncAll());
      });

      test('a matched pairing proceeds with sync as normal', () async {
        when(() => mockNetworkService.checkHealth())
            .thenAnswer((_) async => true);
        when(() => mockNetworkService.fetchInstanceId())
            .thenAnswer((_) async => 'instance-a');
        when(() => mockSyncService.checkPairing('instance-a'))
            .thenAnswer((_) async => PairingResult.paired);
        when(() => mockSyncService.isSyncNeeded()).thenAnswer((_) async => false);

        await provider.startSync();

        expect(provider.pairingMismatch, isFalse);
        verify(() => mockSyncService.isSyncNeeded()).called(1);
      });

      test('a failed health check never checks pairing', () async {
        when(() => mockNetworkService.checkHealth())
            .thenAnswer((_) async => false);

        await provider.startSync();

        verifyNever(() => mockNetworkService.fetchInstanceId());
        verifyNever(() => mockSyncService.checkPairing(any()));
      });

      test('acknowledgePairingMismatch re-pairs and unblocks sync', () async {
        when(() => mockNetworkService.checkHealth())
            .thenAnswer((_) async => true);
        when(() => mockNetworkService.fetchInstanceId())
            .thenAnswer((_) async => 'instance-b');
        when(() => mockSyncService.checkPairing('instance-b'))
            .thenAnswer((_) async => PairingResult.mismatch);
        when(() => mockSyncService.getUnsyncedCount())
            .thenAnswer((_) async => 1);
        await provider.startSync();
        expect(provider.pairingMismatch, isTrue);

        when(() => mockNetworkService.acknowledgePairing())
            .thenAnswer((_) async => 'instance-b');
        when(() => mockSyncService.acknowledgePairing('instance-b'))
            .thenAnswer((_) async {});

        await provider.acknowledgePairingMismatch();

        expect(provider.pairingMismatch, isFalse);
        verify(() => mockSyncService.acknowledgePairing('instance-b')).called(1);
      });

      test('a failed acknowledgement leaves the terminal blocked', () async {
        when(() => mockNetworkService.checkHealth())
            .thenAnswer((_) async => true);
        when(() => mockNetworkService.fetchInstanceId())
            .thenAnswer((_) async => 'instance-b');
        when(() => mockSyncService.checkPairing('instance-b'))
            .thenAnswer((_) async => PairingResult.mismatch);
        when(() => mockSyncService.getUnsyncedCount())
            .thenAnswer((_) async => 1);
        await provider.startSync();
        expect(provider.pairingMismatch, isTrue);

        when(() => mockNetworkService.acknowledgePairing())
            .thenThrow(NetworkException('offline'));

        await provider.acknowledgePairingMismatch();

        expect(provider.pairingMismatch, isTrue);
        verifyNever(() => mockSyncService.acknowledgePairing(any()));
      });

      // The backend's own /health contract is fail-soft: instance_id can be
      // null if instance_config isn't readable at that instant (e.g. a
      // transient PDOException), and the ack endpoint mirrors that. A null
      // is not a confirmed acknowledgement — clearing the block on it would
      // silently reopen the exact hole ADR-0035 exists to close.
      test('an ack response with a null instance_id leaves the terminal blocked',
          () async {
        when(() => mockNetworkService.checkHealth())
            .thenAnswer((_) async => true);
        when(() => mockNetworkService.fetchInstanceId())
            .thenAnswer((_) async => 'instance-b');
        when(() => mockSyncService.checkPairing('instance-b'))
            .thenAnswer((_) async => PairingResult.mismatch);
        when(() => mockSyncService.getUnsyncedCount())
            .thenAnswer((_) async => 1);
        await provider.startSync();
        expect(provider.pairingMismatch, isTrue);

        when(() => mockNetworkService.acknowledgePairing())
            .thenAnswer((_) async => null);

        await provider.acknowledgePairingMismatch();

        expect(provider.pairingMismatch, isTrue);
        verifyNever(() => mockSyncService.acknowledgePairing(any()));
      });

      // A getUnsyncedCount() failure must not swallow the mismatch signal —
      // the count is a nicety for the dialog, not a precondition for the
      // block itself or for staff being told about it at all.
      test('a count-fetch failure still surfaces the mismatch to the UI',
          () async {
        when(() => mockNetworkService.checkHealth())
            .thenAnswer((_) async => true);
        when(() => mockNetworkService.fetchInstanceId())
            .thenAnswer((_) async => 'instance-b');
        when(() => mockSyncService.checkPairing('instance-b'))
            .thenAnswer((_) async => PairingResult.mismatch);
        when(() => mockSyncService.getUnsyncedCount())
            .thenThrow(Exception('database is locked'));

        var notified = false;
        provider.addListener(() => notified = true);

        await provider.startSync();

        expect(provider.pairingMismatch, isTrue);
        expect(notified, isTrue);
      });

      // Closes the race the reviewer flagged: a background timer tick must
      // not be able to interleave with startSync()/acknowledgePairingMismatch()
      // and re-flag a mismatch the moment staff just cleared it.
      test('startSync no-ops while another sync cycle is already running',
          () async {
        final gate = Completer<bool>();
        when(() => mockNetworkService.checkHealth())
            .thenAnswer((_) => gate.future);
        when(() => mockSyncService.isSyncNeeded()).thenAnswer((_) async => false);

        final first = provider.startSync();
        final second = provider.startSync();

        gate.complete(false);
        await first;
        await second;

        // Only the first call's checkHealth() should ever have run.
        verify(() => mockNetworkService.checkHealth()).called(1);
      });

      test('acknowledgePairingMismatch no-ops while a sync cycle is running',
          () async {
        final gate = Completer<bool>();
        when(() => mockNetworkService.checkHealth())
            .thenAnswer((_) => gate.future);

        final syncing = provider.startSync();
        await provider.acknowledgePairingMismatch();

        verifyNever(() => mockNetworkService.acknowledgePairing());

        gate.complete(false);
        await syncing;
      });
    });

    /// #395 — the terminal's own credential expiring is a different thing to
    /// tell the staff than an outage: one they can wait out, one only an
    /// administrator can fix.
    group('credential expiry', () {
      void stubCycle({required bool credentialExpired, required SyncResult result}) {
        when(() => mockNetworkService.checkHealth()).thenAnswer((_) async => true);
        when(() => mockSyncService.isSyncNeeded()).thenAnswer((_) async => true);
        when(() => mockSyncService.syncAll()).thenAnswer((_) async => result);
        when(() => mockSyncService.credentialExpired).thenReturn(credentialExpired);
        when(() => mockSyncService.lastTransactionSyncTime).thenReturn(null);
        when(() => mockSyncService.lastTransactionSyncError).thenReturn(null);
        when(() => mockSyncService.getLastError()).thenAnswer((_) async => null);
        when(() => mockMembersProvider.refreshMembers()).thenAnswer((_) async {});
        when(() => mockProductsProvider.refreshProducts()).thenAnswer((_) async {});
      }

      test('starts out believing the credential is fine', () {
        expect(provider.credentialExpired, isFalse);
      });

      test('a cycle refused for an expired token raises the block', () async {
        stubCycle(credentialExpired: true, result: SyncResult.failure);

        await provider.startSync();

        expect(provider.credentialExpired, isTrue);
      });

      test('a successful cycle after a rotation clears the block', () async {
        stubCycle(credentialExpired: true, result: SyncResult.failure);
        await provider.startSync();
        expect(provider.credentialExpired, isTrue);

        // The new token has been entered at the terminal; nothing else has to
        // happen for selling to resume.
        stubCycle(credentialExpired: false, result: SyncResult.success);
        await provider.startSync();

        expect(provider.credentialExpired, isFalse);
      });

      test('an ordinary sync failure is not a credential problem', () async {
        stubCycle(credentialExpired: false, result: SyncResult.failure);

        await provider.startSync();

        expect(provider.credentialExpired, isFalse);
      });
    });
  });
}
