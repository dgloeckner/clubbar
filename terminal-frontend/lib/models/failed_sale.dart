/// A sale the backend permanently refused to store.
///
/// The drink was served and the money is owed, but no record of it will ever
/// reach the club's books by itself — staff have to report it to an admin.
/// Everything a member of staff needs to do that is here: who bought, how
/// much, and when (issue #152).
class FailedSale {
  final String transactionId;
  final String memberId;

  /// Display name of the member, or null when the local cache no longer knows
  /// them (anonymized or never synced) — the id still identifies the sale.
  final String? memberName;

  final int amountCents;

  /// When the sale happened at the terminal, not when it failed to sync.
  final DateTime occurredAt;

  /// The rejection code the backend gave, kept verbatim so an admin can be
  /// told exactly what the server said.
  final String? reason;

  const FailedSale({
    required this.transactionId,
    required this.memberId,
    required this.memberName,
    required this.amountCents,
    required this.occurredAt,
    required this.reason,
  });
}
