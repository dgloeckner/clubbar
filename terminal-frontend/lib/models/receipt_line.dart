import 'dart:convert';

/// One line of the post-checkout receipt: a product, how many of it were
/// booked, and what that came to.
///
/// The terminal books one `transactions_local` row per unit (that is the
/// shape the backend API wants), so a line is what those rows fold back into
/// — `TransactionsRepository.getSessionLines` does the folding. The member
/// never sees a row; they see "2 × Pils".
class ReceiptLine {
  const ReceiptLine({
    required this.productId,
    required this.namesJson,
    required this.iconName,
    required this.quantity,
    required this.unitPriceCents,
    required this.totalCents,
    this.requestedQuantity,
  });

  final String productId;

  /// The product's names as the cache stores them: JSON keyed by language.
  final String namesJson;
  final String? iconName;

  /// Units actually booked — for a dispensed product, the tokens that came
  /// out, not the ones asked for.
  final int quantity;
  final int unitPriceCents;

  /// What this line was billed at: the sum of its rows' amounts.
  final int totalCents;

  /// Units the member asked the dispenser for, when this is a token line.
  /// Greater than [quantity] on a partial dispense; null for an ordinary
  /// product.
  final int? requestedQuantity;

  /// Whether fewer tokens came out than were asked for.
  bool get isPartial =>
      requestedQuantity != null && quantity < requestedQuantity!;

  /// The product's name in [language], falling back to German and then to a
  /// placeholder — the same fallback the product grid uses.
  String name(String language) {
    try {
      final names = jsonDecode(namesJson) as Map<String, dynamic>;
      return names[language]?.toString() ?? names['de']?.toString() ?? '?';
    } catch (_) {
      return '?';
    }
  }
}
