import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'dart:convert';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/providers/cart_provider.dart';
import 'package:ruderbar_terminal/providers/products_provider.dart';
import 'package:ruderbar_terminal/providers/auth_provider.dart';
import 'package:ruderbar_terminal/providers/sync_provider.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/widgets/app_header.dart';

class ProductSelectionScreen extends StatefulWidget {
  const ProductSelectionScreen({super.key});

  @override
  State<ProductSelectionScreen> createState() => _ProductSelectionScreenState();
}

class _ProductSelectionScreenState extends State<ProductSelectionScreen> {
  int _selectedCategoryIndex = 0;

  String _getCategoryName(CategoriesCacheData category) {
    try {
      final names = jsonDecode(category.names) as Map<String, dynamic>;
      return names['de'] ?? 'Category';
    } catch (_) {
      return 'Category';
    }
  }

  @override
  Widget build(BuildContext context) {
    return Consumer2<ProductsProvider, CartProvider>(
      builder: (context, productsProvider, cartProvider, child) {
        final categories = productsProvider.categories;

        if (categories.isEmpty) {
          return const Scaffold(
            body: Center(child: Text('No categories available')),
          );
        }

        return Scaffold(
          appBar: AppHeader(
            title: 'Products',
            authProvider: context.read<AuthProvider>(),
            syncProvider: context.read<SyncProvider>(),
            membersProvider: context.read<MembersProvider>(),
          ),
          body: Column(
            children: [
              // Top bar with cart button
              Padding(
                padding: const EdgeInsets.all(16),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.end,
                  children: [
                    ElevatedButton(
                      onPressed: () {
                        context.go('/cart');
                      },
                      child: Text('Cart (${cartProvider.itemCount})'),
                    ),
                  ],
                ),
              ),
            // Category tabs
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: List.generate(
                  categories.length,
                  (index) => Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 8),
                    child: ChoiceChip(
                      label: Text(_getCategoryName(categories[index])),
                      selected: _selectedCategoryIndex == index,
                      onSelected: (selected) {
                        setState(() {
                          _selectedCategoryIndex = index;
                        });
                      },
                    ),
                  ),
                ),
              ),
            ),
            const SizedBox(height: 16),
              // Product grid
              Expanded(
                child: _buildProductGrid(
                  context,
                  categories[_selectedCategoryIndex],
                  productsProvider,
                  cartProvider,
                ),
              ),
            ],
          ),
        );
      },
    );
  }

  Widget _buildProductGrid(
    BuildContext context,
    CategoriesCacheData category,
    ProductsProvider productsProvider,
    CartProvider cartProvider,
  ) {
    final products = productsProvider.products
        .where((p) => p.categoryId == category.id)
        .toList();

    if (products.isEmpty) {
      return const Center(child: Text('No products in this category'));
    }

    return GridView.builder(
      padding: const EdgeInsets.all(16),
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 3,
        crossAxisSpacing: 16,
        mainAxisSpacing: 16,
      ),
      itemCount: products.length,
      itemBuilder: (context, index) {
        final product = products[index];
        final name = productsProvider.getTranslatedName(product, 'de');
        final cartItem = cartProvider.items
            .firstWhere((item) => item.productId == product.id, orElse: () => null as dynamic);

        return _buildProductTile(
          context,
          product,
          name,
          cartItem?.quantity ?? 0,
          () => cartProvider.addItem(
            product.id,
            name,
            product.priceCents,
            1,
            'de',
          ),
        );
      },
    );
  }

  Widget _buildProductTile(
    BuildContext context,
    ProductsCacheData product,
    String name,
    int quantity,
    VoidCallback onTap,
  ) {
    final priceEuros = product.priceCents / 100.0;
    return GestureDetector(
      onTap: onTap,
      child: Card(
        child: Stack(
          children: [
            Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Text(name, style: Theme.of(context).textTheme.bodySmall),
                const SizedBox(height: 8),
                Text('\$${priceEuros.toStringAsFixed(2)}', style: Theme.of(context).textTheme.bodyMedium),
              ],
            ),
            if (quantity > 0)
              Positioned(
                top: 8,
                right: 8,
                child: CircleAvatar(
                  backgroundColor: Colors.blue,
                  child: Text(
                    '$quantity',
                    style: const TextStyle(color: Colors.white),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
