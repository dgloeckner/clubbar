import 'package:drift/drift.dart';
import '../database/database.dart';

class SyncRepository {
  final ClubBarDatabase _db;

  SyncRepository(this._db);

  /// Get sync state value by key
  Future<String?> getSyncState(String key) async {
    final state = await (_db.select(_db.syncState)
          ..where((s) => s.key.equals(key)))
        .getSingleOrNull();
    return state?.value;
  }

  /// Set sync state value
  Future<void> setSyncState(String key, String value) async {
    await _db.into(_db.syncState).insertOnConflictUpdate(
      SyncStateCompanion(
        key: Value(key),
        value: Value(value),
      ),
    );
  }

  /// Get last sync timestamp for members (ISO 8601 string)
  Future<String?> getLastMembersSyncTime() async {
    return getSyncState('last_members_sync_time');
  }

  /// Set last sync timestamp for members
  Future<void> setLastMembersSyncTime(String timestamp) async {
    return setSyncState('last_members_sync_time', timestamp);
  }

  /// Get last sync timestamp for products (ISO 8601 string)
  Future<String?> getLastProductsSyncTime() async {
    return getSyncState('last_products_sync_time');
  }

  /// Set last sync timestamp for products
  Future<void> setLastProductsSyncTime(String timestamp) async {
    return setSyncState('last_products_sync_time', timestamp);
  }

  /// Get last sync cursor for categories (Unix timestamp in milliseconds, stored as string)
  Future<String?> getLastCategoriesSyncCursor() async {
    return getSyncState('last_categories_sync_cursor');
  }

  /// Set last sync cursor for categories
  Future<void> setLastCategoriesSyncCursor(String cursor) async {
    return setSyncState('last_categories_sync_cursor', cursor);
  }

  /// Get last sync cursor for members (Unix timestamp in milliseconds, stored as string)
  Future<String?> getLastMembersSyncCursor() async {
    return getSyncState('last_members_sync_cursor');
  }

  /// Set last sync cursor for members
  Future<void> setLastMembersSyncCursor(String cursor) async {
    return setSyncState('last_members_sync_cursor', cursor);
  }

  /// Get last sync cursor for products (Unix timestamp in milliseconds, stored as string)
  Future<String?> getLastProductsSyncCursor() async {
    return getSyncState('last_products_sync_cursor');
  }

  /// Set last sync cursor for products
  Future<void> setLastProductsSyncCursor(String cursor) async {
    return setSyncState('last_products_sync_cursor', cursor);
  }

  /// Get last successful sync timestamp (overall)
  Future<String?> getLastSyncTime() async {
    return getSyncState('last_sync_time');
  }

  /// Set last successful sync timestamp (overall)
  Future<void> setLastSyncTime(String timestamp) async {
    return setSyncState('last_sync_time', timestamp);
  }

  /// Check if sync is needed (compare with syncInterval)
  Future<bool> isSyncNeeded({required Duration syncInterval}) async {
    final lastSync = await getLastSyncTime();
    if (lastSync == null) {
      return true; // Never synced
    }

    try {
      final lastSyncTime = DateTime.parse(lastSync);
      final now = DateTime.now();
      return now.difference(lastSyncTime) > syncInterval;
    } catch (e) {
      return true; // Parse error, force sync
    }
  }

  /// Get sync error message (if any)
  Future<String?> getLastSyncError() async {
    return getSyncState('last_sync_error');
  }

  /// Set sync error message
  Future<void> setLastSyncError(String error) async {
    return setSyncState('last_sync_error', error);
  }

  /// Clear sync error message
  Future<void> clearLastSyncError() async {
    await (_db.delete(_db.syncState)
          ..where((s) => s.key.equals('last_sync_error')))
        .go();
  }

  /// Get all sync state entries (for debugging)
  Future<List<SyncStateData>> getAllSyncState() async {
    return (_db.select(_db.syncState)).get();
  }

  /// Clear all sync state (for logout or reset)
  Future<void> clearAllSyncState() async {
    await _db.delete(_db.syncState).go();
  }

  /// Get sync retry count
  Future<int> getSyncRetryCount() async {
    final count = await getSyncState('sync_retry_count');
    try {
      return int.parse(count ?? '0');
    } catch (e) {
      return 0;
    }
  }

  /// Increment sync retry count
  Future<void> incrementSyncRetryCount() async {
    final current = await getSyncRetryCount();
    await setSyncState('sync_retry_count', (current + 1).toString());
  }

  /// Reset sync retry count
  Future<void> resetSyncRetryCount() async {
    await setSyncState('sync_retry_count', '0');
  }
}
