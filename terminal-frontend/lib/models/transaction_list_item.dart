import 'dart:convert';

/// Transaction list item for displaying in member details modal
///
/// Represents a transaction with its sync status, settlement information,
/// and display data (product name, icon, amount, timestamp).
class TransactionListItem {
  final String id;
  final DateTime timestamp;
  final String details; // Product name or correction notes
  final int amountCents;
  final TransactionSyncStatus syncStatus;
  final String? productIcon; // Icon name (e.g., "beer", "coffee") or null for corrections
  final String? settlementId;
  final DateTime? settlementDate;

  const TransactionListItem({
    required this.id,
    required this.timestamp,
    required this.details,
    required this.amountCents,
    required this.syncStatus,
    this.productIcon,
    this.settlementId,
    this.settlementDate,
  });

  /// Create from local SQLite transaction (unsynced)
  factory TransactionListItem.fromLocalTransaction({
    required String id,
    required DateTime createdAt,
    required String productName,
    required int amountCents,
    String? productIcon,
  }) {
    return TransactionListItem(
      id: id,
      timestamp: createdAt,
      details: productName,
      amountCents: amountCents,
      syncStatus: TransactionSyncStatus.unsynced,
      productIcon: productIcon,
    );
  }

  /// Create from backend API response (synced transaction)
  factory TransactionListItem.fromBackendJson(Map<String, dynamic> json, String preferredLanguage) {
    // Parse product names JSON (multilingual)
    String details;
    if (json['transaction_type'] == 'correction') {
      // For corrections, use notes as details
      details = json['notes'] ?? 'Correction';
    } else {
      // For purchases, extract product name in user's language
      final productNames = json['product_names'];
      if (productNames is String) {
        // Parse JSON string to map using proper JSON decoder
        try {
          final namesMap = jsonDecode(productNames) as Map<String, dynamic>;
          details = namesMap[preferredLanguage] ??
                    namesMap['de'] ??
                    (namesMap.isNotEmpty ? namesMap.values.first : null) ??
                    'Unbekannt';
        } catch (e) {
          // Fallback to raw string if JSON parsing fails
          details = productNames;
        }
      } else if (productNames is Map) {
        final namesMap = productNames as Map<String, dynamic>;
        details = namesMap[preferredLanguage] ??
                  namesMap['de'] ??
                  (namesMap.isNotEmpty ? namesMap.values.first : null) ??
                  'Unbekannt';
      } else {
        details = 'Unbekannt';
      }
    }

    // Determine sync status based on settlement
    final settlementId = json['settlement_id'];
    final syncStatus = settlementId != null
        ? TransactionSyncStatus.settled
        : TransactionSyncStatus.open;

    return TransactionListItem(
      id: json['id'],
      timestamp: DateTime.parse(json['created_at']),
      details: details,
      amountCents: json['amount_cents'],
      syncStatus: syncStatus,
      productIcon: json['product_icon'],
      settlementId: settlementId,
      settlementDate: json['settlement_date'] != null
          ? DateTime.parse(json['settlement_date'])
          : null,
    );
  }
}

/// Sync status of a transaction
enum TransactionSyncStatus {
  /// Local transaction not yet uploaded to backend
  unsynced,

  /// Synced to backend but not yet settled
  open,

  /// Synced and included in a settlement
  settled,
}

/// Result of fetching transaction history
class TransactionHistoryResult {
  final List<TransactionListItem> transactions;
  final bool isOffline;

  const TransactionHistoryResult({
    required this.transactions,
    required this.isOffline,
  });
}
