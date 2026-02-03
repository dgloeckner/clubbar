/// Response from POST /sync/transactions (per api/terminal.yaml).
class TransactionSyncResponse {
  final List<String> acceptedIds;
  final TransactionSyncRejected rejected;
  final Map<String, int> memberBalances;

  TransactionSyncResponse({
    required this.acceptedIds,
    required this.rejected,
    required this.memberBalances,
  });

  factory TransactionSyncResponse.fromJson(Map<String, dynamic> json) {
    final rawAccepted = json['accepted_ids'] as List<dynamic>? ?? [];

    // PHP json_encode returns [] for empty arrays and {} for objects.
    // Handle both Map and List (empty array) for these fields.
    final rejectedRaw = json['rejected'];
    final balancesRaw = json['member_balances'];

    final Map<String, int> memberBalances;
    if (balancesRaw is Map) {
      memberBalances = balancesRaw.map(
          (key, value) => MapEntry(key.toString(), (value as num).toInt()));
    } else {
      memberBalances = {};
    }

    final TransactionSyncRejected rejected;
    if (rejectedRaw is Map<String, dynamic>) {
      rejected = TransactionSyncRejected.fromJson(rejectedRaw);
    } else {
      rejected = TransactionSyncRejected(count: 0, errors: []);
    }

    return TransactionSyncResponse(
      acceptedIds: rawAccepted
          .where((id) => id != null)
          .map((id) => id.toString())
          .toList(),
      rejected: rejected,
      memberBalances: memberBalances,
    );
  }
}

/// Rejected transactions section of the sync response.
class TransactionSyncRejected {
  final int count;
  final List<TransactionSyncError> errors;

  TransactionSyncRejected({
    required this.count,
    required this.errors,
  });

  factory TransactionSyncRejected.fromJson(Map<String, dynamic> json) {
    final rawErrors = json['errors'] as List<dynamic>? ?? [];
    return TransactionSyncRejected(
      count: (json['count'] as num?)?.toInt() ?? 0,
      errors: rawErrors
          .whereType<Map<String, dynamic>>()
          .map((e) => TransactionSyncError.fromJson(e))
          .toList(),
    );
  }
}

/// Individual transaction rejection error.
class TransactionSyncError {
  final String transactionId;
  final String reason;

  TransactionSyncError({
    required this.transactionId,
    required this.reason,
  });

  factory TransactionSyncError.fromJson(Map<String, dynamic> json) {
    return TransactionSyncError(
      transactionId: (json['transaction_id'] ?? json['id'] ?? '').toString(),
      reason: (json['reason'] ?? json['message'] ?? 'Unknown error').toString(),
    );
  }
}
