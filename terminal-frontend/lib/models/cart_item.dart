class CartItem {
  final String productId;
  final String productName;
  final int priceCents;
  int quantity;
  final String language;
  final String? iconName;
  final bool requiresDispenser;

  /// The minimum age this product requires, or null for unrestricted
  /// (ADR-0045).
  ///
  /// Carried on the line rather than re-read from the products cache at
  /// checkout, the same way `requiresDispenser` is: it keeps the cart
  /// service's check synchronous, and it is the shape the rest of this class
  /// already uses.
  final int? minAge;

  CartItem({
    required this.productId,
    required this.productName,
    required this.priceCents,
    required this.quantity,
    required this.language,
    this.iconName,
    this.requiresDispenser = false,
    this.minAge,
  });

  int get lineTotalCents => priceCents * quantity;

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is CartItem && runtimeType == other.runtimeType && productId == other.productId;

  @override
  int get hashCode => productId.hashCode;
}
