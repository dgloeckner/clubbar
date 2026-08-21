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
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/providers/products_provider.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/services/sound_service.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
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
  // screen instead of stretching.
  static const double _tileMaxWidth = 240.0;

  // [_tileHeight] is what the card actually needs — 60 px icon + two lines of
  // name at `xl` + price at `xxl` + the card's padding — with a little slack.
  //
  // Computed, not a constant (#41): the type scale is a *deployment setting*
  // (`AppFontSizes.applyConfig`, `fontSizes` in config.json), so a pinned
  // height is only ever right for the scale it was measured against. It was
  // 218, measured when `xl` was 18 — raising the kiosk default to 20 made the
  // card 225 px and every tile overflowed by 7. A club dialling the scale up
  // further would have hit the same wall.
  //
  // [_tileTextLineHeight] is the font's line box as a multiple of its size,
  // measured from the rendered card; [_tileChrome] is everything that does not
  // scale with type (icon, gaps, padding, card border). See the grid sizing
  // tests in `test/screens/product_selection_screen_test.dart`, which pin the
  // relationship at three different scales.
  //
  // 143 -> 119 with #369, which took 24 px off ProductCard (icon 72 -> 60,
  // padding lg -> md, the gap under the icon lg -> md). Move this by exactly
  // what the card moved and no more: the constant carries ~7 px of *measured*
  // slack over the card's nominal height, and re-deriving it from first
  // principles would spend the slack that #41 put there.
  static const double _tileChrome = 119.0;
  static const double _tileTextLineHeight = 1.34;
  static const double _tileSlack = 4.0;

  static double get _tileHeight =>
      _tileChrome +
      _tileTextLineHeight * (2 * AppFontSizes.xl + AppFontSizes.xxl) +
      _tileSlack;
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
        final limitCheck = CreditLimitCheck.evaluate(
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

    return GridView.builder(
      // A flat `md`, not `CartSummaryBar.height` (#369). #293 reserved a whole
      // bar's worth of space here on the premise that the bar was "sticky
      // below the grid, not part of its scroll extent" — but the bar is a
      // plain Column sibling *after* the Expanded, so the grid's viewport
      // already ends where the bar begins and the two can never overlap. The
      // 93 px was dead scroll at the end of the list. What #293 actually
      // wanted — the last row visibly clearing the bar's top border — is 12.
      padding: const EdgeInsets.only(bottom: AppSpacing.md),
      gridDelegate: SliverGridDelegateWithMaxCrossAxisExtent(
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
      },
    );
  }
}
