import 'dart:async';
import 'package:flutter/foundation.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/providers/products_provider.dart';
import 'package:ruderbar_terminal/services/sync_service.dart';

class SyncProvider extends ChangeNotifier {
  final SyncService _syncService;
  final MembersProvider _membersProvider;
  final ProductsProvider _productsProvider;

  bool _isSyncing = false;
  DateTime? _lastSyncTime;
  int _retryCount = 0;
  String? _lastError;
  Exception? _errorType;
  Timer? _backgroundTimer;

  SyncProvider({
    required SyncService syncService,
    required MembersProvider membersProvider,
    required ProductsProvider productsProvider,
  })  : _syncService = syncService,
        _membersProvider = membersProvider,
        _productsProvider = productsProvider;

  bool get isSyncing => _isSyncing;
  DateTime? get lastSyncTime => _lastSyncTime;
  int get retryCount => _retryCount;
  String? get lastError => _lastError;
  Exception? get errorType => _errorType;

  /// Manually trigger sync
  Future<void> startSync() async {
    // Check if sync is needed first
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
        _lastError = null;
        _errorType = null;
        _retryCount = 0;
      } else {
        // Sync failed, get error message
        final error = await _syncService.getLastError();
        _lastError = error;
        _retryCount++;
      }
    } catch (e) {
      _lastError = 'Sync error: $e';
      _errorType = e as Exception?;
      _retryCount++;
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
