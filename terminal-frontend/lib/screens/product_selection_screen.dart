import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:collection/collection.dart';
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

  // Grid layout constants
  static const int _columns = 4;
  static const double _gridSpacing = 12.0;

  // Screen layout constants (1280x720 fixed window)
  static const double _screenHeight = 720.0;
  static const double _headerHeight = 56.0;
  static const double _memberBarHeight = 74.0;
  static const double _categoryTabsHeight = 60.0;
  static const double _horizontalPadding = 16.0;
  static const double _verticalSpacing = 12.0;

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
                    padding: const EdgeInsets.symmetric(
                      horizontal: _horizontalPadding,
                      vertical: _verticalSpacing,
                    ),
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
                const SizedBox(height: _verticalSpacing),

                // Product grid (4 columns, fills available space)
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: _horizontalPadding),
                  child: _buildProductGrid(
                    context,
                    categories[_selectedCategoryIndex],
                    productsProvider,
                    cartProvider,
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

    // Use LayoutBuilder to get actual available width
    return LayoutBuilder(
      builder: (context, constraints) {
        // Calculate aspect ratio from actual constraints
        final availableWidth = constraints.maxWidth;
        final rows = (products.length / _columns).ceil();

        // Available height for grid (from screen calculation)
        final availableHeight = _screenHeight
            - _headerHeight
            - _memberBarHeight
            - _categoryTabsHeight
            - (_verticalSpacing * 3);

        // Calculate item dimensions from actual width
        final totalHorizontalGaps = (_columns - 1) * _gridSpacing;
        final itemWidth = (availableWidth - totalHorizontalGaps) / _columns;

        final totalVerticalGaps = (rows - 1) * _gridSpacing;
        final itemHeight = (availableHeight - totalVerticalGaps) / rows;

        final aspectRatio = itemWidth / itemHeight;

        return GridView.builder(
          padding: EdgeInsets.zero,
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
            crossAxisCount: _columns,
            crossAxisSpacing: _gridSpacing,
            mainAxisSpacing: _gridSpacing,
            childAspectRatio: aspectRatio,
          ),
          itemCount: products.length,
          itemBuilder: (context, index) {
            final product = products[index];
            final name = productsProvider.getTranslatedName(product, 'de');

            // Get quantity from cart if product is already there
            final cartItem = cartProvider.items.firstWhereOrNull(
              (item) => item.productId == product.id,
            );
            final quantity = cartItem?.quantity ?? 0;

            return ProductCard(
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
            );
          },
        );
      },
    );
  }
}
