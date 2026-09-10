import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:collection/collection.dart';
import 'dart:convert';
import 'package:clubbar_terminal/controllers/checkout_action.dart';
import 'package:clubbar_terminal/utils/age.dart';
import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/l10n/terminal_error_messages.dart';
import 'package:clubbar_terminal/models/credit_limit.dart';
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/providers/products_provider.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/services/sound_service.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/utils/product_grid_layout.dart';
import 'package:clubbar_terminal/widgets/cart_summary_bar.dart';
import 'package:clubbar_terminal/widgets/credit_limit_banner.dart';
import 'package:clubbar_terminal/widgets/error_banner.dart';
import 'package:clubbar_terminal/widgets/loading_overlay.dart';
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

  // Grid layout
  //
  // Issue #29: the tile is not a function of how many products the club
  // happens to sell *in the sense that mattered there*: a 25-product category
  // never squeezes its rows into the available height (it scrolls), and three
  // snacks never become screen-tall cards (the type has a ceiling and the tile
  // a width cap). Within those bounds the grid now does adapt — measured, not
  // guessed — and the reasons are in `plans/2026-09-10-terminal-product-card-
  // layout.md`:
  //
  //  * A word is never split. The tile used to be capped at 240 px because
  //    "Alkoholfreies" needs ~190 px at the shipped `xxxl`; at the scale a
  //    production terminal runs it needs ~226 in a 208 px line, and Flutter
  //    splits it mid-word with no hyphen. The solver measures every word of
  //    the category and chooses a column count and size at which every name
  //    wraps at spaces into two lines with every word whole.
  //  * One size per category: the largest at which *every* name fits.
  //  * The configured `xxxl` is the floor — it keeps its meaning as the size
  //    the club wants at minimum — and `AppFontSizes.productNameCeiling` the
  //    ceiling. A sparse category grows into the room it has; a full one stays
  //    at the floor and scrolls. Below the floor only to keep a word whole,
  //    after fewer columns have been tried, and never below the price size.
  //
  // The tile height is derived, not pinned (#41): the scale is a deployment
  // setting, so a constant is only right for the scale it was measured at. The
  // card and the solver share `ProductCard.metrics`, and the card pins the
  // line height of name and price, so the height is exact rather than
  // measured. The grid sizing tests in
  // `test/screens/product_selection_screen_test.dart` hold this at four
  // different scales.
  static const ProductGridLayout _gridLayout = ProductGridLayout(
    metrics: ProductCard.metrics,
    spacing: _gridSpacing,
    bottomPadding: AppSpacing.md,
  );

  /// Width of each word of a name in em, in the name's own style — measured
  /// once with a `TextPainter` and kept, since a glyph run's width scales
  /// linearly with the size. A category switch costs one layout per word the
  /// terminal has not seen yet, and nothing after that.
  final Map<String, double> _wordWidths = {};

  /// The ambient style the cache was measured under. `Text` merges the
  /// `DefaultTextStyle` — which is where the theme's font family comes from —
  /// into the card's own style, so the measurement has to start from the
  /// same base or it would measure a different font than the one drawn.
  TextStyle? _measuredBase;
  TextScaler _measuredScaler = TextScaler.noScaling;

  /// The size at which a word is measured. Large, so the em figure carries
  /// sub-pixel precision at the sizes the grid actually renders.
  static const double _measureFontSize = 100.0;

  WordWidth _wordWidthIn(BuildContext context) {
    final base = DefaultTextStyle.of(context).style;
    final scaler = MediaQuery.textScalerOf(context);
    if (base != _measuredBase || scaler != _measuredScaler) {
      _wordWidths.clear();
      _measuredBase = base;
      _measuredScaler = scaler;
    }
    final style = base.merge(TextStyle(
      fontSize: _measureFontSize,
      fontWeight: FontWeight.w700,
      height: ProductCard.textLineHeight,
    ));
    return (String word) => _wordWidths.putIfAbsent(word, () {
          final painter = TextPainter(
            text: TextSpan(text: word, style: style),
            textDirection: TextDirection.ltr,
            textScaler: scaler,
            maxLines: 1,
          )..layout();
          final em = painter.width / _measureFontSize;
          painter.dispose();
          return em;
        });
  }

  static const double _gridSpacing = 12.0;
  static const double _horizontalPadding = 16.0;

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

        final locale = selectedMember?.preferredLanguage ?? 'de';

        // Credit limit, checked as items go in rather than only in the cart
        // (UC-T12 "Add to cart | Warning shown, item still added"): the member
        // sees the ceiling while they can still choose, not after they have
        // picked a round for the table. Adding stays allowed — only checkout
        // is blocked, here and on the cart screen alike.
        final limitCheck =
            context.read<ConfigService>().creditLimitPolicy.evaluate(
                  memberLimitCents: selectedMember?.creditLimitCents,
                  currentBalanceCents: membersProvider.memberDeckel ?? 0,
                  cartTotalCents: cartProvider.total,
                );

        // While a checkout runs the cart must not be edited or re-submitted —
        // it can now be started from this screen too (#34).
        final isCheckoutInFlight = cartProvider.isLoading;

        // Body content only - MainLayout provides Scaffold and header
        return Column(
            children: [
              // Member bar
              if (selectedMember != null)
                Padding(
                  padding: const EdgeInsets.symmetric(
                    horizontal: _horizontalPadding,
                    vertical: AppSpacing.sm,
                  ),
                  child: MemberBar(
                    member: selectedMember,
                    deckelCents: membersProvider.memberDeckel,
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

              // Quiet, dismissible acknowledgement of a checkout cancelled
              // from this screen (#34). Genuine failures never reach here —
              // runCheckout clears them as soon as their modal is shown, so
              // what is left is the member's own choice, not an error to
              // apologise for.
              if (cartProvider.lastError != null)
                ErrorBanner(
                  message: cartProvider.lastError!.message(l10n),
                  onDismiss: cartProvider.clearError,
                ),

              // Standing notice while the tab is at or over the limit —
              // renders nothing below the warning band.
              CreditLimitBanner(check: limitCheck, locale: locale),

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
              const SizedBox(height: AppSpacing.sm),

              // Product grid — constant tile size, scrolls past the fold.
              //
              // Frozen while a checkout is in flight, for the same reason the
              // cart screen freezes its item list: the transactions have
              // already been computed from this cart, so a tile tapped now
              // would be paid for by nobody. The overlay makes the freeze
              // visible rather than letting the tiles swallow taps.
              Expanded(
                child: LoadingOverlay(
                  isLoading: isCheckoutInFlight,
                  child: Padding(
                    padding: const EdgeInsets.symmetric(
                      horizontal: _horizontalPadding,
                    ),
                    child: _buildProductGrid(
                      context,
                      categories[_selectedCategoryIndex],
                      productsProvider,
                      cartProvider,
                    ),
                  ),
                ),
              ),

              // Running total and checkout, right where the tapping happens
              // (#34). Absent on an empty cart: there is nothing to total and
              // nothing to pay for, and the grid gets the height back.
              if (cartProvider.items.isNotEmpty)
                CartSummaryBar(
                  totalCents: cartProvider.total,
                  newBalanceCents: limitCheck.projectedBalanceCents,
                  locale: locale,
                  isCheckoutInFlight: isCheckoutInFlight,
                  isBlockedByLimit: limitCheck.blocksCheckout,
                  // Watched rather than read: the block has to appear the
                  // moment a background sync cycle discovers the credential is
                  // gone, not on the next rebuild that happens to occur.
                  isBlockedByCredential:
                      context.watch<SyncProvider>().credentialExpired,
                  onCheckout: () => runCheckout(context),
                  onViewCart: () => context.go('/cart'),
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
    // Get member's preferred language (needed for display)
    final selectedMember = context.read<MembersProvider>().selectedMember;
    final memberLang = selectedMember?.preferredLanguage ?? 'de';
    // Issue #31: ask the provider what is sellable rather than filtering by
    // category here — the raw list still contains dispenser-gated products on
    // terminals that have no dispenser at all.
    //
    // Issue #33: the provider also decides the order. Sorting by the member's
    // language here reshuffled the grid on every language switch.
    final products = productsProvider.getVisibleProducts(category.id);

    if (products.isEmpty) {
      return Center(
        child: Text(
          l10n.noProductsInCategory,
          style: const TextStyle(color: AppColors.textSecondary),
        ),
      );
    }

    final names = [
      for (final product in products)
        productsProvider.getTranslatedName(product, memberLang),
    ];

    return LayoutBuilder(
      builder: (context, constraints) {
        final geometry = _gridLayout.solve(
          names: names,
          width: constraints.maxWidth,
          height: constraints.maxHeight,
          wordWidth: _wordWidthIn(context),
          floor: AppFontSizes.xxxl,
          ceiling: AppFontSizes.productNameCeiling,
          minimum: AppFontSizes.xxl,
          priceFontSize: AppFontSizes.xxl,
        );

        final grid = GridView.builder(
          // A flat `md`, not `CartSummaryBar.height` (#369). #293 reserved a
          // whole bar's worth of space here on the premise that the bar was
          // "sticky below the grid, not part of its scroll extent" — but the
          // bar is a plain Column sibling *after* the Expanded, so the grid's
          // viewport already ends where the bar begins and the two can never
          // overlap. The 93 px was dead scroll at the end of the list. What
          // #293 actually wanted — the last row visibly clearing the bar's
          // top border — is 12.
          padding: const EdgeInsets.only(bottom: AppSpacing.md),
          gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
            crossAxisCount: geometry.columns,
            // A pinned height, rather than an aspect ratio: the card's
            // contents are fixed-size at the chosen type, so the space they
            // need must not depend on how wide the row happens to be.
            mainAxisExtent: geometry.tileHeight,
            crossAxisSpacing: _gridSpacing,
            mainAxisSpacing: _gridSpacing,
          ),
          itemCount: products.length,
          itemBuilder: (context, index) => _buildTile(
            context,
            products[index],
            names[index],
            memberLang,
            geometry,
            productsProvider,
            cartProvider,
            selectedMember,
            l10n,
          ),
        );

        // The tile cap bit: two products on a wide screen are two tiles of
        // the capped width, centred, rather than two billboards.
        final usedWidth = geometry.usedWidth(_gridSpacing);
        if (usedWidth < constraints.maxWidth - 0.5) {
          return Align(
            alignment: Alignment.topCenter,
            child: SizedBox(width: usedWidth, child: grid),
          );
        }
        return grid;
      },
    );
  }

  Widget _buildTile(
    BuildContext context,
    ProductsCacheData product,
    String name,
    String memberLang,
    ProductGridGeometry geometry,
    ProductsProvider productsProvider,
    CartProvider cartProvider,
    MembersCacheData? selectedMember,
    AppLocalizations l10n,
  ) {
    // Get quantity from cart if product is already there
    final cartItem = cartProvider.items.firstWhereOrNull(
      (item) => item.productId == product.id,
    );
    final quantity = cartItem?.quantity ?? 0;

    // A configured but jammed or unplugged dispenser leaves the token on
    // the grid — greyed out with the reason — instead of letting the
    // member find out at checkout (issue #31).
    final available = productsProvider.isProductAvailable(product);

    // Jugendschutz (ADR-0045, UC-T12 E7). Same treatment, same reason: a
    // drink this member may not buy stays visible and greyed out with the
    // age on it, rather than vanishing from a grid they have used before or
    // refusing them only at checkout.
    //
    // This is a **courtesy**, not the control. `CartService` is the
    // authority (rule 1), and it is what a bypassed tile still runs into.
    // The note names the age the drink requires, never the member's own
    // (rule 6) — the screen is read by whoever is at the bar.
    final tooYoung = selectedMember != null &&
        !mayBuyAtAge(
            selectedMember.dateOfBirth, product.minAge, DateTime.now());
    final sellable = available && !tooYoung;

    return ProductCard(
      product: product,
      productName: name,
      locale: memberLang,
      nameFontSize: geometry.nameFontSize,
      iconSize: geometry.iconSize,
      quantity: quantity,
      enabled: sellable,
      unavailableNote: sellable
          ? null
          : tooYoung
              ? l10n.productAgeRestrictedNote(product.minAge!)
              : l10n.productUnavailableDispenserOffline,
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
          minAge: product.minAge,
        );
      },
    );
  }
}
