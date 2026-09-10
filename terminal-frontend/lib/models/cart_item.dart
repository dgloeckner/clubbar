import 'package:clubbar_terminal/utils/formatters.dart';

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

  /// The product's size in whole millilitres, or null when it has none
  /// (ADR-0056).
  ///
  /// Carried on the line for the same reason [minAge] is: the cart and the
  /// receipt print it beside the name, and re-reading the products cache to
  /// draw a label would make a synchronous render asynchronous.
  final int? volumeMl;

  CartItem({
    required this.productId,
    required this.productName,
    required this.priceCents,
    required this.quantity,
    required this.language,
    this.iconName,
    this.requiresDispenser = false,
    this.minAge,
    this.volumeMl,
  });

  /// The name as every surface prints it: the name, then the size (ADR-0056).
  String label(String locale) =>
      formatProductLabel(productName, volumeMl, locale);

  int get lineTotalCents => priceCents * quantity;

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is CartItem && runtimeType == other.runtimeType && productId == other.productId;

  @override
  int get hashCode => productId.hashCode;
}
