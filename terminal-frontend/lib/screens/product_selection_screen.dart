import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:collection/collection.dart';
import 'dart:convert';
import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/l10n/terminal_error_messages.dart';
import 'package:clubbar_terminal/models/credit_limit.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/providers/products_provider.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/services/sound_service.dart';
import 'package:clubbar_terminal/widgets/credit_limit_banner.dart';
import 'package:clubbar_terminal/widgets/error_banner.dart';
import 'package:clubbar_terminal/widgets/member_bar.dart';
import 'package:clubbar_terminal/widgets/styled_components/product_card.dart';
import 'package:clubbar_terminal/widgets/styled_components/category_chip.dart';

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
  //
  // Issue #29: the tile is a *constant*, not a function of how many products
  // the club happens to sell. The grid used to squeeze every row into the
  // available height, which made a 25-product category unreadable (and could
  // compute a negative tile height), while three snacks became screen-tall
  // cards. Now the column count follows the screen width and anything that
  // does not fit scrolls — touch-drag is enabled globally in main.dart.
  //
  // [_tileMaxWidth] is an upper bound: Flutter fits as many columns of at most
  // this width as the row allows, so tiles stay finger-sized on a 1920 px
  // screen instead of stretching. [_tileHeight] is what the card actually
  // needs — 72 px icon + two lines of name at `xl` + price at `xxl` + the
  // card's padding — with a little slack.
  static const double _tileMaxWidth = 240.0;
  static const double _tileHeight = 218.0;
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
                      // ADR-0027: all session ends go through endSession(),
                      // which refuses to run mid-checkout (rule 7).
                      if (context.read<SessionController>().endSession()) {
                        context.go('/idle');
                      }
                    },
                  ),
                ),

              // A stale product list is worth telling the member about, but
              // not worth blocking on — everything already cached still sells.
              if (productsProvider.lastError != null)
                ErrorBanner(
                  message: productsProvider.lastError!.message(l10n),
                  onDismiss: productsProvider.clearError,
                ),

              // Credit limit, checked as items go in rather than only in the
              // cart (UC-T12 "Add to cart | Warning shown, item still added"):
              // the member sees the ceiling while they can still choose, not
              // after they have picked a round for the table. Adding stays
              // allowed — only checkout is blocked.
              CreditLimitBanner(
                check: CreditLimitCheck.evaluate(
                  currentBalanceCents: membersProvider.memberDeckel ?? 0,
                  cartTotalCents: cartProvider.total,
                ),
                locale: selectedMember?.preferredLanguage ?? 'de',
              ),

              // Category tabs
              //
              // Issue #30: the bar used to be a bare Row of Expanded chips
              // sized for the two categories the first club happened to have.
              // Six categories — or one long German name — overflowed it.
              //
              // [IntrinsicWidth] inside a horizontal scroll view makes the row
              // as wide as its chips need, but never narrower than the screen:
              // up to three short chips still stretch edge to edge as before,
              // and anything wider scrolls instead of overflowing.
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: _horizontalPadding),
                child: LayoutBuilder(
                  builder: (context, constraints) {
                    return SingleChildScrollView(
                      key: const Key('category-bar'),
                      scrollDirection: Axis.horizontal,
                      child: ConstrainedBox(
                        constraints: BoxConstraints(minWidth: constraints.maxWidth),
                        child: IntrinsicWidth(
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
                                        context.read<SoundService>().play(SoundEvent.categorySwitch);
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
                      ),
                    );
                  },
                ),
              ),
              const SizedBox(height: _verticalSpacing),

              // Product grid — constant tile size, scrolls past the fold
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
    // Get member's preferred language (needed for sort and display)
    final memberLang = context.read<MembersProvider>().selectedMember?.preferredLanguage ?? 'de';
    final products = productsProvider.products
        .where((p) => p.categoryId == category.id)
        .toList()
      ..sort((a, b) {
        final nameA = productsProvider.getTranslatedName(a, memberLang).toLowerCase();
        final nameB = productsProvider.getTranslatedName(b, memberLang).toLowerCase();
        return nameA.compareTo(nameB);
      });

    if (products.isEmpty) {
      return Center(
        child: Text(
          l10n.noProductsInCategory,
          style: const TextStyle(color: Color(0xff94a3b8)),
        ),
      );
    }

    return GridView.builder(
      padding: EdgeInsets.zero,
      gridDelegate: const SliverGridDelegateWithMaxCrossAxisExtent(
        maxCrossAxisExtent: _tileMaxWidth,
        // A pinned height, rather than an aspect ratio: the card's contents
        // are fixed-size, so the space they need must not depend on how wide
        // the row happens to be.
        mainAxisExtent: _tileHeight,
        crossAxisSpacing: _gridSpacing,
        mainAxisSpacing: _gridSpacing,
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
          onDecrement: quantity > 0
            ? () => cartProvider.decreaseItem(product.id)
            : null,
          onTap: () {
            cartProvider.addItem(
              product.id,
              name,
              product.priceCents,
              1,
              memberLang,
              iconName: product.iconName,
              requiresDispenser: product.requiresDispenser == 1,
            );
          },
        );
      },
    );
  }
}
