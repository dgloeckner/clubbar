import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:flutter/material.dart';
import 'package:clubbar_terminal/models/cart_item.dart';

class CartItemRow extends StatelessWidget {
  final CartItem item;
  final VoidCallback onRemovePressed;
  final Function(int) onQuantityChanged;

  const CartItemRow({
    required this.item,
    required this.onRemovePressed,
    required this.onQuantityChanged,
    super.key,
  });

  String get _pricePerUnitFormatted {
    final priceInEuros = item.priceCents / 100;
    return '€${priceInEuros.toStringAsFixed(2)}';
  }

  String get _lineTotalFormatted {
    final lineTotalCents = item.lineTotalCents;
    final lineTotalEuros = lineTotalCents / 100;
    return '€${lineTotalEuros.toStringAsFixed(2)}';
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8.0, horizontal: 16.0),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  item.productName,
                  style: TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: AppFontSizes.lg,
                  ),
                ),
                Text(
                  '$_pricePerUnitFormatted each',
                  style: TextStyle(
                    color: Colors.grey[600],
                    fontSize: AppFontSizes.xs,
                  ),
                ),
              ],
            ),
          ),
          Row(
            children: [
              IconButton(
                icon: const Icon(Icons.remove),
                onPressed: () {
                  if (item.quantity > 1) {
                    onQuantityChanged(item.quantity - 1);
                  }
                },
              ),
              Text(
                '${item.quantity}',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
              ),
              IconButton(
                icon: const Icon(Icons.add),
                onPressed: () => onQuantityChanged(item.quantity + 1),
              ),
            ],
          ),
          SizedBox(
            width: 80,
            child: Text(
              _lineTotalFormatted,
              style: TextStyle(
                fontWeight: FontWeight.bold,
                fontSize: AppFontSizes.lg,
                color: Colors.green,
              ),
              textAlign: TextAlign.right,
            ),
          ),
          IconButton(
            icon: const Icon(Icons.delete, color: Colors.red),
            onPressed: onRemovePressed,
          ),
        ],
      ),
    );
  }
}
