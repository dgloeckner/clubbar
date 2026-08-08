import 'dart:async';
import 'package:flutter/foundation.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/providers/error_signal.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/providers/products_provider.dart';
import 'package:clubbar_terminal/providers/quarantine_provider.dart';
import 'package:clubbar_terminal/services/network_service.dart';
import 'package:clubbar_terminal/services/sync_service.dart';

/// Three-state connectivity status for the terminal.
enum ConnectionStatus { online, offline, error }

class SyncProvider extends ChangeNotifier with ErrorSignal {
  final SyncService _syncService;
  final MembersProvider _membersProvider;
  final ProductsProvider _productsProvider;
  final NetworkService _networkService;

  /// Optional: absent in tests and in headless setups that have no UI to warn.
  final QuarantineProvider? _quarantineProvider;

  bool _isSyncing = false;
  bool _disposed = false;
  DateTime? _lastSyncTime;
  DateTime? _lastSuccessfulTransactionSync;
  int _retryCount = 0;
  Timer? _backgroundTimer;
  ConnectionStatus _connectionStatus = ConnectionStatus.online;

  SyncProvider({
    required SyncService syncService,
    required MembersProvider membersProvider,
    required ProductsProvider productsProvider,
    required NetworkService networkService,
    QuarantineProvider? quarantineProvider,
  })  : _syncService = syncService,
        _membersProvider = membersProvider,
        _productsProvider = productsProvider,
        _networkService = networkService,
        _quarantineProvider = quarantineProvider;

  bool get isSyncing => _isSyncing;
  DateTime? get lastSyncTime => _lastSyncTime;
  DateTime? get lastSuccessfulTransactionSync => _lastSuccessfulTransactionSync;
  int get retryCount => _retryCount;
  ConnectionStatus get connectionStatus => _connectionStatus;

  /// Manually trigger sync
  Future<void> startSync() async {
    // Always run health check to keep connectionStatus accurate
    final healthy = await _networkService.checkHealth();
    if (!healthy) {
      _connectionStatus = ConnectionStatus.offline;
      _retryCount++;
      // Emitted on every failed attempt, not just on the transition, so a
      // repeated outage still signals a fresh display event.
      emitError(TerminalErrorKey.backendUnreachable);
      notifyListeners();
      return;
    }

    // Health passed — if we were offline, update immediately
    if (_connectionStatus == ConnectionStatus.offline) {
      _connectionStatus = ConnectionStatus.online;
      resetError();
      _retryCount = 0;
      notifyListeners();
    }

    // Check if a full sync cycle is needed
    final needed = await _syncService.isSyncNeeded();
    if (!needed) {
      return;
    }

    _isSyncing = true;
    notifyListeners();

    try {
      final result = await _syncService.syncAll();

      if (result == SyncResult.success) {
        // Refresh other providers
        await _membersProvider.refreshMembers();
        await _productsProvider.refreshProducts();

        // A sync cycle is the only thing that can quarantine a sale, so this
        // is where the staff warning learns about one (issue #152).
        await _quarantineProvider?.refresh();

        _lastSyncTime = DateTime.now();
        _retryCount = 0;

        // Track transaction sync time from SyncService
        final txnSyncTime = _syncService.lastTransactionSyncTime;
        if (txnSyncTime != null) {
          _lastSuccessfulTransactionSync = txnSyncTime;
        }

        // Check if transaction sync had a non-fatal error
        final txnError = _syncService.lastTransactionSyncError;
        if (txnError != null) {
          emitError(TerminalErrorKey.transactionSyncFailed, cause: txnError);
          _connectionStatus = ConnectionStatus.error;
        } else {
          resetError();
          _connectionStatus = ConnectionStatus.online;
        }
      } else {
        // Health passed but sync failed → error state
        emitError(TerminalErrorKey.syncFailed,
            cause: await _syncService.getLastError());
        _retryCount++;
        _connectionStatus = ConnectionStatus.error;
      }
    } catch (e, stackTrace) {
      emitError(TerminalErrorKey.syncFailed, cause: e, stackTrace: stackTrace);
      _retryCount++;
      _connectionStatus = ConnectionStatus.error;
    } finally {
      _isSyncing = false;
      notifyListeners();
    }
  }

  /// Start background sync timer (every N seconds)
  void startBackgroundSync({int intervalSeconds = 60}) {
    // Stop existing timer if any
    _backgroundTimer?.cancel();

    // Run sync immediately
    startSync();

    // Then schedule periodic syncs
    _backgroundTimer = Timer.periodic(Duration(seconds: intervalSeconds), (_) {
      if (!_isSyncing) {
        startSync();
      }
    });
  }

  /// Stop background sync timer
  void stopSync() {
    _backgroundTimer?.cancel();
    _backgroundTimer = null;
  }

  @override
  void notifyListeners() {
    if (_disposed) return;
    super.notifyListeners();
  }

  @override
  void dispose() {
    _disposed = true;
    stopSync();
    super.dispose();
  }
}
