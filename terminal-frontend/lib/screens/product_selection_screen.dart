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
import 'package:ruderbar_terminal/utils/design_tokens.dart';
import 'package:ruderbar_terminal/widgets/ruderbar_header.dart';
import 'package:ruderbar_terminal/widgets/member_bar.dart';
import 'package:ruderbar_terminal/widgets/styled_components/product_card.dart';
import 'package:ruderbar_terminal/widgets/styled_components/category_chip.dart';

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

  int _calculateOptimalColumns(int productCount) {
    if (productCount <= 0) return 1;
    if (productCount <= 2) return productCount;
    if (productCount <= 4) return 4;
    // For 5+ products, use 4 columns to fit multiple rows
    return 4;
  }

  double _calculateItemSize(int productCount) {
    // Available space for grid: 1280 (screen) - 32 (padding) = 1248px width
    // Available height: 720 - 56 (header) - 74 (member bar) - 12 - 44 (tabs) - 16 - 24 = 494px
    // Spacing between items: 12px

    if (productCount <= 0) return 0;

    // For 1-4 products: single row, use full width squares
    if (productCount <= 4) {
      // 303px width with 3 gaps of 12px = (303*4) + 36 = 1248px (perfect fit)
      return 303.0;
    }

    // For 5-8 products: 2 rows, use 241px squares
    // (241*4) + 36 gaps + 12 row gap = 1012 width, 494 height (perfect fit for 2 rows)
    if (productCount <= 8) {
      return 241.0;
    }

    // For 9+ products: would exceed available space without scrolling
    // Return best-fit size (would need scrolling enabled)
    return 200.0;
  }

  @override
  Widget build(BuildContext context) {
    return Consumer3<ProductsProvider, CartProvider, MembersProvider>(
      builder: (context, productsProvider, cartProvider, membersProvider, child) {
        final categories = productsProvider.categories;
        final selectedMember = membersProvider.selectedMember;

        if (categories.isEmpty) {
          return Scaffold(
            backgroundColor: const Color(0xff0a1628),
            body: const Center(
              child: Text(
                'No categories available',
                style: TextStyle(color: Colors.white),
              ),
            ),
          );
        }

        return Scaffold(
          backgroundColor: const Color(0xff0a1628),
          appBar: RuderbarHeader(isOnline: true),
          body: SingleChildScrollView(
            child: Column(
              children: [
                // Member bar
                if (selectedMember != null)
                  Padding(
                    padding: const EdgeInsets.all(12),
                    child: MemberBar(
                      member: selectedMember,
                      itemCount: cartProvider.itemCount,
                      onCartPressed: () => context.go('/cart'),
                      onLogoutPressed: () {
                        // Clear selected member and navigate to scan page
                        membersProvider.clearSelectedMember();
                        context.go('/idle');
                      },
                    ),
                  ),
                const SizedBox(height: 12),

                // Category tabs (horizontal scrollable)
                SingleChildScrollView(
                  scrollDirection: Axis.horizontal,
                  padding: const EdgeInsets.symmetric(horizontal: AppSpacing.lg),
                  child: Row(
                    children: List.generate(
                      categories.length,
                      (index) => Padding(
                        padding: const EdgeInsets.only(right: AppSpacing.md),
                        child: CategoryChip(
                          category: categories[index],
                          categoryName: _getCategoryName(categories[index]),
                          selected: _selectedCategoryIndex == index,
                          onSelected: () {
                            setState(() {
                              _selectedCategoryIndex = index;
                            });
                          },
                        ),
                      ),
                    ),
                  ),
                ),
                const SizedBox(height: AppSpacing.lg),

                // Product grid (4 columns, square items)
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: AppSpacing.lg),
                  child: Container(
                    width: double.infinity,
                    child: _buildProductGrid(
                      context,
                      categories[_selectedCategoryIndex],
                      productsProvider,
                      cartProvider,
                    ),
                  ),
                ),
                const SizedBox(height: AppSpacing.xl),
              ],
            ),
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
      return const Center(
        child: Text(
          'No products in this category',
          style: TextStyle(color: Color(0xff94a3b8)),
        ),
      );
    }

    // Calculate optimal grid dimensions based on product count
    final columns = _calculateOptimalColumns(products.length);
    final itemSize = _calculateItemSize(products.length);

    return GridView.builder(
      padding: EdgeInsets.zero,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: columns,
        crossAxisSpacing: AppSpacing.md,
        mainAxisSpacing: AppSpacing.md,
        childAspectRatio: 1.0, // Square items
      ),
      itemCount: products.length,
      itemBuilder: (context, index) {
        final product = products[index];
        final name = productsProvider.getTranslatedName(product, 'de');

        // Get quantity from cart if product is already there
        int quantity = 0;
        try {
          final cartItem = cartProvider.items.firstWhere(
            (item) => item.productId == product.id,
          );
          quantity = cartItem.quantity;
        } catch (e) {
          // Product not in cart
          quantity = 0;
        }

        return SizedBox(
          width: itemSize,
          height: itemSize,
          child: ProductCard(
            product: product,
            productName: name,
            quantity: quantity,
            onTap: () => cartProvider.addItem(
              product.id,
              name,
              product.priceCents,
              1,
              'de',
              iconName: product.iconName,
            ),
          ),
        );
      },
    );
  }
}
