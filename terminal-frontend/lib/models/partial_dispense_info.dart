/// Information about a partial dispense scenario
///
/// Used when the dispenser dispensed fewer tokens than requested,
/// requiring adjusted transaction amounts.
class PartialDispenseInfo {
  /// Number of tokens requested
  final int requestedQuantity;

  /// Number of tokens actually dispensed
  final int actualDispensed;

  /// Original cart total before adjustment (in cents)
  final int originalTotalCents;

  const PartialDispenseInfo({
    required this.requestedQuantity,
    required this.actualDispensed,
    required this.originalTotalCents,
  });

  /// Whether this represents a partial dispense (not all tokens dispensed)
  bool get isPartial => actualDispensed < requestedQuantity;

  /// Number of tokens that failed to dispense
  int get missedQuantity => requestedQuantity - actualDispensed;
}
