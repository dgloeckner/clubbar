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
    return TransactionSyncResponse(
      acceptedIds: (json['accepted_ids'] as List<dynamic>)
          .map((id) => id as String)
          .toList(),
      rejected: TransactionSyncRejected.fromJson(
          json['rejected'] as Map<String, dynamic>),
      memberBalances: Map<String, int>.from(
          (json['member_balances'] as Map).map(
              (key, value) => MapEntry(key as String, value as int))),
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
    return TransactionSyncRejected(
      count: json['count'] as int,
      errors: (json['errors'] as List<dynamic>)
          .map((e) =>
              TransactionSyncError.fromJson(e as Map<String, dynamic>))
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
      transactionId: json['transaction_id'] as String,
      reason: json['reason'] as String,
    );
  }
}
