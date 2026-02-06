import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:collection/collection.dart';
import 'dart:convert';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/l10n/app_localizations.dart';
import 'package:ruderbar_terminal/providers/cart_provider.dart';
import 'package:ruderbar_terminal/providers/products_provider.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
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

  String _getCategoryName(CategoriesCacheData category, String language, String fallback) {
    try {
      final names = jsonDecode(category.names) as Map<String, dynamic>;
      return names[language] ?? names['de'] ?? fallback;
    } catch (_) {
      return fallback;
    }
  }

  // Grid layout constants
  static const int _columns = 4;
  static const double _gridSpacing = 12.0;
  static const double _horizontalPadding = 16.0;
  static const double _verticalSpacing = 12.0;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;

    return Consumer3<ProductsProvider, CartProvider, MembersProvider>(
      builder: (context, productsProvider, cartProvider, membersProvider, child) {
        final categories = productsProvider.categories;
        final selectedMember = membersProvider.selectedMember;

        if (categories.isEmpty) {
          return Center(
            child: Text(
              l10n.noCategories,
              style: const TextStyle(color: Colors.white),
            ),
          );
        }

        // Body content only - MainLayout provides Scaffold and header
        return Column(
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
                    deckelCents: membersProvider.memberDeckel,
                    onCartPressed: () => context.go('/cart'),
                    onLogoutPressed: () {
                      // Clear selected member and navigate to scan page
                      membersProvider.clearSelectedMember();
                      context.go('/idle');
                    },
                  ),
                ),

              // Category tabs (full width, 2 tabs)
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: _horizontalPadding),
                child: Row(
                  children: List.generate(
                    categories.length,
                    (index) {
                      final memberLang = selectedMember?.preferredLanguage ?? 'de';
                      return Expanded(
                        child: Padding(
                          padding: EdgeInsets.only(
                            right: index < categories.length - 1 ? _gridSpacing : 0,
                          ),
                          child: CategoryChip(
                            category: categories[index],
                            categoryName: _getCategoryName(categories[index], memberLang, l10n.categoryDefault),
                            selected: _selectedCategoryIndex == index,
                            onSelected: () {
                              setState(() {
                                _selectedCategoryIndex = index;
                              });
                            },
                          ),
                        ),
                      );
                    },
                  ),
                ),
              ),
              const SizedBox(height: _verticalSpacing),

              // Product grid (4 columns, fills remaining space)
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.only(
                    left: _horizontalPadding,
                    right: _horizontalPadding,
                    bottom: 10,
                  ),
                  child: _buildProductGrid(
                    context,
                    categories[_selectedCategoryIndex],
                    productsProvider,
                    cartProvider,
                  ),
                ),
              ),
            ],
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
    final l10n = AppLocalizations.of(context)!;
    final products = productsProvider.products
        .where((p) => p.categoryId == category.id)
        .toList();

    if (products.isEmpty) {
      return Center(
        child: Text(
          l10n.noProductsInCategory,
          style: const TextStyle(color: Color(0xff94a3b8)),
        ),
      );
    }

    // Get member's preferred language
    final memberLang = context.read<MembersProvider>().selectedMember?.preferredLanguage ?? 'de';

    // Use LayoutBuilder to get actual available dimensions
    return LayoutBuilder(
      builder: (context, constraints) {
        // Calculate aspect ratio from actual constraints
        final availableWidth = constraints.maxWidth;
        final availableHeight = constraints.maxHeight;
        final rows = (products.length / _columns).ceil();

        // Calculate item dimensions from actual constraints
        final totalHorizontalGaps = (_columns - 1) * _gridSpacing;
        final itemWidth = (availableWidth - totalHorizontalGaps) / _columns;

        final totalVerticalGaps = (rows - 1) * _gridSpacing;
        final itemHeight = (availableHeight - totalVerticalGaps) / rows;

        final aspectRatio = itemWidth / itemHeight;

        return GridView.builder(
          padding: EdgeInsets.zero,
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
            final name = productsProvider.getTranslatedName(product, memberLang);

            // Get quantity from cart if product is already there
            final cartItem = cartProvider.items.firstWhereOrNull(
              (item) => item.productId == product.id,
            );
            final quantity = cartItem?.quantity ?? 0;

            return ProductCard(
              product: product,
              productName: name,
              locale: memberLang,
              quantity: quantity,
              onTap: () => cartProvider.addItem(
                product.id,
                name,
                product.priceCents,
                1,
                memberLang,
                iconName: product.iconName,
              ),
            );
          },
        );
      },
    );
  }
}
