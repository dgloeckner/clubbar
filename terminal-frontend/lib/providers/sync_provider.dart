import 'dart:async';
import 'package:flutter/foundation.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/providers/products_provider.dart';
import 'package:clubbar_terminal/services/network_service.dart';
import 'package:clubbar_terminal/services/sync_service.dart';

/// Three-state connectivity status for the terminal.
enum ConnectionStatus { online, offline, error }

class SyncProvider extends ChangeNotifier {
  final SyncService _syncService;
  final MembersProvider _membersProvider;
  final ProductsProvider _productsProvider;
  final NetworkService _networkService;

  bool _isSyncing = false;
  DateTime? _lastSyncTime;
  DateTime? _lastSuccessfulTransactionSync;
  int _retryCount = 0;
  String? _lastError;
  Exception? _errorType;
  Timer? _backgroundTimer;
  ConnectionStatus _connectionStatus = ConnectionStatus.online;

  SyncProvider({
    required SyncService syncService,
    required MembersProvider membersProvider,
    required ProductsProvider productsProvider,
    required NetworkService networkService,
  })  : _syncService = syncService,
        _membersProvider = membersProvider,
        _productsProvider = productsProvider,
        _networkService = networkService;

  bool get isSyncing => _isSyncing;
  DateTime? get lastSyncTime => _lastSyncTime;
  DateTime? get lastSuccessfulTransactionSync => _lastSuccessfulTransactionSync;
  int get retryCount => _retryCount;
  String? get lastError => _lastError;
  Exception? get errorType => _errorType;
  ConnectionStatus get connectionStatus => _connectionStatus;

  /// Manually trigger sync
  Future<void> startSync() async {
    // Always run health check to keep connectionStatus accurate
    final healthy = await _networkService.checkHealth();
    if (!healthy) {
      final changed = _connectionStatus != ConnectionStatus.offline;
      _connectionStatus = ConnectionStatus.offline;
      _lastError = 'Backend unreachable';
      _retryCount++;
      if (changed) notifyListeners();
      return;
    }

    // Health passed — if we were offline, update immediately
    if (_connectionStatus == ConnectionStatus.offline) {
      _connectionStatus = ConnectionStatus.online;
      _lastError = null;
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
          _lastError = txnError;
          _errorType = null;
          _connectionStatus = ConnectionStatus.error;
        } else {
          _lastError = null;
          _errorType = null;
          _connectionStatus = ConnectionStatus.online;
        }
      } else {
        // Health passed but sync failed → error state
        final error = await _syncService.getLastError();
        _lastError = error;
        _retryCount++;
        _connectionStatus = ConnectionStatus.error;
      }
    } catch (e) {
      _lastError = 'Sync error: $e';
      _errorType = e as Exception?;
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
  void dispose() {
    stopSync();
    super.dispose();
  }
}
