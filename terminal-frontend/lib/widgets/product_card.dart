import 'package:flutter/material.dart';
import 'package:ruderbar_terminal/models/product_dto.dart';

class ProductCard extends StatelessWidget {
  final ProductDTO product;
  final String translatedName;
  final VoidCallback onAddPressed;

  const ProductCard({
    required this.product,
    required this.translatedName,
    required this.onAddPressed,
    Key? key,
  }) : super(key: key);

  String get _priceFormatted {
    final priceInEuros = product.priceCents / 100;
    return '€${priceInEuros.toStringAsFixed(2)}';
  }

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Product image placeholder
          Container(
            width: double.infinity,
            height: 120,
            color: Colors.grey[200],
            child: const Icon(Icons.image, color: Colors.grey),
          ),
          // Product info
          Padding(
            padding: const EdgeInsets.all(8.0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  translatedName,
                  style: const TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: 14,
                  ),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                const SizedBox(height: 4),
                Text(
                  _priceFormatted,
                  style: const TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.bold,
                    color: Colors.green,
                  ),
                ),
              ],
            ),
          ),
          // Add button
          Padding(
            padding: const EdgeInsets.all(8.0),
            child: SizedBox(
              width: double.infinity,
              child: IconButton(
                onPressed: onAddPressed,
                icon: const Icon(Icons.add_circle),
                color: Colors.blue,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
