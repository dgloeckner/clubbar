class CartItem {
  final String productId;
  final String productName;
  final int priceCents;
  int quantity;
  final String language;
  final String? iconName;

  CartItem({
    required this.productId,
    required this.productName,
    required this.priceCents,
    required this.quantity,
    required this.language,
    this.iconName,
  });

  int get lineTotalCents => priceCents * quantity;

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is CartItem && runtimeType == other.runtimeType && productId == other.productId;

  @override
  int get hashCode => productId.hashCode;
}
