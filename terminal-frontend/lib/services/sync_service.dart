import 'package:logger/logger.dart';
import '../config/app_config.dart';
import '../repository/members_repository.dart';
import '../repository/products_repository.dart';
import '../repository/transactions_repository.dart';
import '../repository/sync_repository.dart';
import 'network_service.dart';

/// Result of a sync operation
enum SyncResult { success, failure, alreadyInProgress }

class SyncService {
  final NetworkService _networkService;
  final MembersRepository _membersRepo;
  final ProductsRepository _productsRepo;
  final TransactionsRepository _transactionsRepo;
  final SyncRepository _syncRepo;
  final Logger _logger;

  bool _isSyncing = false;
  DateTime? _lastSyncTime;

  SyncService({
    required NetworkService networkService,
    required MembersRepository membersRepo,
    required ProductsRepository productsRepo,
    required TransactionsRepository transactionsRepo,
    required SyncRepository syncRepo,
    Logger? logger,
  })  : _networkService = networkService,
        _membersRepo = membersRepo,
        _productsRepo = productsRepo,
        _transactionsRepo = transactionsRepo,
        _syncRepo = syncRepo,
        _logger = logger ?? Logger();

  /// Check if sync is currently in progress
  bool get isSyncing => _isSyncing;

  /// Get last successful sync time
  DateTime? get lastSyncTime => _lastSyncTime;

  /// Check if sync is needed based on interval
  Future<bool> isSyncNeeded() async {
    return _syncRepo.isSyncNeeded(syncInterval: AppConfig.syncInterval);
  }

  /// Perform full sync cycle: fetch members, products, and sync transactions
  Future<SyncResult> syncAll() async {
    if (_isSyncing) {
      _logger.w('Sync already in progress');
      return SyncResult.alreadyInProgress;
    }

    _isSyncing = true;
    try {
      _logger.i('Starting sync cycle');

      // Sync members
      await _syncMembers();

      // Sync products
      await _syncProducts();

      // Sync transactions (unsynced transactions to backend)
      await _syncTransactions();

      // Update last sync time
      final now = DateTime.now();
      await _syncRepo.setLastSyncTime(now.toIso8601String());
      _lastSyncTime = now;

      // Clear retry count and error on success
      await _syncRepo.resetSyncRetryCount();
      await _syncRepo.clearLastSyncError();

      _logger.i('Sync cycle completed successfully');
      return SyncResult.success;
    } catch (e) {
      _logger.e('Sync cycle failed', error: e);
      await _syncRepo.setLastSyncError(e.toString());
      await _syncRepo.incrementSyncRetryCount();
      return SyncResult.failure;
    } finally {
      _isSyncing = false;
    }
  }

  /// Sync members from backend
  Future<void> _syncMembers() async {
    try {
      _logger.i('Syncing members');
      final response = await _networkService.syncMembers();

      // Upsert members into local database
      await _membersRepo.upsertMembers(response.members);

      // Update sync timestamp
      final now = DateTime.now();
      await _syncRepo.setLastMembersSyncTime(now.toIso8601String());

      _logger.i('Members synced: ${response.members.length} items');
    } catch (e) {
      _logger.e('Members sync failed', error: e);
      rethrow;
    }
  }

  /// Sync products from backend
  Future<void> _syncProducts() async {
    try {
      _logger.i('Syncing products');
      final response = await _networkService.syncProducts();

      // Upsert categories into local database
      await _productsRepo.upsertCategories(response.categories);

      // Upsert products into local database
      await _productsRepo.upsertProducts(response.products);

      // Update sync timestamp
      final now = DateTime.now();
      await _syncRepo.setLastProductsSyncTime(now.toIso8601String());

      _logger.i('Products synced: ${response.products.length} items, ${response.categories.length} categories');
    } catch (e) {
      _logger.e('Products sync failed', error: e);
      rethrow;
    }
  }

  /// Sync unsynced transactions to backend via POST /sync/transactions
  Future<void> _syncTransactions() async {
    try {
      _logger.i('Syncing transactions');
      final unsyncedTxns = await _transactionsRepo.getUnsyncedTransactions();

      if (unsyncedTxns.isEmpty) {
        _logger.i('No unsynced transactions');
        return;
      }

      // Convert to API format per api/terminal.yaml
      final payloads = unsyncedTxns.map((t) => {
        'id': t.id,
        'member_id': t.memberId,
        'product_id': t.productId,
        'amount_cents': t.amountCents,
        'created_at': t.createdAt,
      }).toList();

      // POST to backend
      final response = await _networkService.syncTransactions(payloads);

      // Atomically mark accepted transactions as synced and update balances
      await _transactionsRepo.completeSyncAtomically(
        acceptedIds: response.acceptedIds,
        memberBalances: response.memberBalances,
        membersRepo: _membersRepo,
      );

      _logger.i('Transactions synced: ${response.acceptedIds.length} accepted');

      if (response.rejected.count > 0) {
        _logger.w('Transactions rejected: ${response.rejected.count}');
        for (final error in response.rejected.errors) {
          _logger.w('  Rejected ${error.transactionId}: ${error.reason}');
        }
      }
    } catch (e) {
      _logger.e('Transactions sync failed', error: e);
      rethrow;
    }
  }

  /// Reset sync state and cache (for logout or reset)
  Future<void> reset() async {
    _logger.i('Resetting sync state');
    await _syncRepo.clearAllSyncState();
    _isSyncing = false;
    _lastSyncTime = null;
  }

  /// Get current retry count
  Future<int> getRetryCount() async {
    return _syncRepo.getSyncRetryCount();
  }

  /// Get last sync error message
  Future<String?> getLastError() async {
    return _syncRepo.getLastSyncError();
  }
}
