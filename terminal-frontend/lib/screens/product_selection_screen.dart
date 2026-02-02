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

                // Product grid (4 columns, fixed height items)
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: AppSpacing.lg),
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

    return GridView.builder(
      padding: EdgeInsets.zero,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 8,
        crossAxisSpacing: AppSpacing.md,
        mainAxisSpacing: AppSpacing.md,
        childAspectRatio: 0.9,
      ),
      itemCount: products.length,
      itemBuilder: (context, index) {
        final product = products[index];
        final name = productsProvider.getTranslatedName(product, 'de');

        return ProductCard(
          product: product,
          productName: name,
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
  }
}
