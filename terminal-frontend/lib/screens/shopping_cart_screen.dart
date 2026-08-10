import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/controllers/checkout_action.dart';
import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/l10n/terminal_error_messages.dart';
import 'package:clubbar_terminal/models/credit_limit.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/services/sound_service.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/utils/formatters.dart';
import 'package:clubbar_terminal/utils/icon_registry.dart';
import 'package:clubbar_terminal/widgets/checkout_button.dart';
import 'package:clubbar_terminal/widgets/credit_limit_banner.dart';
import 'package:clubbar_terminal/widgets/error_banner.dart';
import 'package:clubbar_terminal/widgets/loading_overlay.dart';
import 'package:clubbar_terminal/widgets/member_bar.dart';

class ShoppingCartScreen extends StatelessWidget {
  const ShoppingCartScreen({super.key});

  static const double _horizontalPadding = 16.0;
  static const double _verticalSpacing = 12.0;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;

    return Consumer2<CartProvider, MembersProvider>(
      builder: (context, cartProvider, membersProvider, child) {
        final selectedMember = membersProvider.selectedMember;
        final locale = selectedMember?.preferredLanguage ?? 'de';

        final totalCents = cartProvider.items.fold<int>(
          0,
          (sum, item) => sum + (item.priceCents * item.quantity),
        );
        final currentDeckel = membersProvider.memberDeckel ?? 0;

        // Credit limit (UC-T11 E3, UC-T12). Same verdict the service enforces
        // at checkout, computed here so the member sees the ceiling *before*
        // they tap Buy rather than being refused afterwards. Evaluated above
        // the empty-cart branch too: a member who is already over the limit
        // needs to know that with an empty cart, not after filling one.
        final limitCheck = CreditLimitCheck.evaluate(
          currentBalanceCents: currentDeckel,
          cartTotalCents: totalCents,
        );

        if (cartProvider.items.isEmpty) {
          return Column(
            children: [
              // Member bar with back button
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
                    showBackButton: true,
                    onBackPressed: () => context.go('/products'),
                    onLogoutPressed: () {
                      // ADR-0027: all session ends go through endSession(),
                      // which refuses to run mid-checkout (rule 7).
                      if (context.read<SessionController>().endSession()) {
                        context.go('/idle');
                      }
                    },
                  ),
                ),
              CreditLimitBanner(check: limitCheck, locale: locale),
              Expanded(
                child: Center(
                  child: Text(
                    l10n.cartEmpty,
                    style: TextStyle(
                      color: Color(0xff94a3b8),
                      fontSize: AppFontSizes.lg,
                    ),
                  ),
                ),
              ),
            ],
          );
        }

        // Balance preview after this cart (Deckel + cart total).
        final newBalanceCents = limitCheck.projectedBalanceCents;

        // While checkout runs the cart must not be edited or re-submitted
        final isCheckoutInFlight = cartProvider.isLoading;

        return Column(
          children: [
            // Member bar with back button
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
                  showBackButton: true,
                  onBackPressed: () => context.go('/products'),
                  onLogoutPressed: () {
                    // ADR-0027 rule 8: all session ends go through
                    // endSession() — it also refuses to run mid-checkout.
                    if (context.read<SessionController>().endSession()) {
                      context.go('/idle');
                    }
                  },
                ),
              ),

            // Quiet, dismissible acknowledgement of a cancelled checkout.
            // Genuine failures never reach here — they are cleared as soon as
            // their modal is shown.
            if (cartProvider.lastError != null)
              ErrorBanner(
                message: cartProvider.lastError!.message(l10n),
                onDismiss: cartProvider.clearError,
              ),

            // Standing notice while the tab is at or over the limit — renders
            // nothing below the warning band.
            CreditLimitBanner(check: limitCheck, locale: locale),

            // Item list — frozen while a checkout is in flight, otherwise the
            // cart could be mutated after the transactions were computed. The
            // overlay makes that freeze visible, rather than letting the
            // quantity and remove buttons silently swallow taps. It covers
            // only the list because the one control outside it, the checkout
            // button, states its own in-flight condition.
            Expanded(
              child: LoadingOverlay(
                isLoading: isCheckoutInFlight,
                child: ListView.builder(
                  padding: const EdgeInsets.all(AppSpacing.lg),
                  itemCount: cartProvider.items.length,
                  itemBuilder: (context, index) {
                    final item = cartProvider.items[index];
                    final unitPriceFormatted = formatPrice(item.priceCents, locale);
                    final lineTotalFormatted = formatPrice(item.priceCents * item.quantity, locale);

                    return Container(
                      margin: const EdgeInsets.only(bottom: AppSpacing.md),
                      padding: const EdgeInsets.symmetric(
                        horizontal: AppSpacing.lg,
                        vertical: AppSpacing.md,
                      ),
                      decoration: BoxDecoration(
                        color: const Color(0xff1a2744),
                        borderRadius: BorderRadius.circular(AppBorderRadius.lg),
                        border: Border.all(
                          color: const Color(0xff334155),
                          width: 1,
                        ),
                      ),
                      child: Row(
                        children: [
                          // Icon
                          getProductIcon(
                            item.iconName,
                            size: 48,
                          ),
                          const SizedBox(width: AppSpacing.md),

                          // Name and price per unit
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  item.productName,
                                  style: TextStyle(
                                    color: Color(0xfff1f5f9),
                                    fontSize: AppFontSizes.lg,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                                const SizedBox(height: 2),
                                Text(
                                  l10n.cartEachPrice(unitPriceFormatted),
                                  style: TextStyle(
                                    color: Color(0xff0ea5e9),
                                    fontSize: AppFontSizes.base,
                                    fontWeight: FontWeight.w500,
                                  ),
                                ),
                              ],
                            ),
                          ),

                          // Quantity controls
                          Row(
                            children: [
                              // Minus button
                              GestureDetector(
                                onTap: () {
                                  if (item.quantity > 1) {
                                    cartProvider.updateQuantity(
                                      item.productId,
                                      item.quantity - 1,
                                    );
                                  }
                                },
                                child: Container(
                                  width: 44,
                                  height: 44,
                                  decoration: BoxDecoration(
                                    color: const Color(0xff7f1d1d),
                                    borderRadius: BorderRadius.circular(8),
                                  ),
                                  child: const Center(
                                    child: Text(
                                      '−',
                                      style: TextStyle(
                                        color: Color(0xffef4444),
                                        fontSize: 28,
                                        fontWeight: FontWeight.bold,
                                      ),
                                    ),
                                  ),
                                ),
                              ),

                              // Quantity
                              SizedBox(
                                width: 48,
                                child: Text(
                                  '${item.quantity}',
                                  textAlign: TextAlign.center,
                                  style: TextStyle(
                                    color: Colors.white,
                                    fontSize: AppFontSizes.xl,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                              ),

                              // Plus button
                              GestureDetector(
                                onTap: () {
                                  cartProvider.updateQuantity(
                                    item.productId,
                                    item.quantity + 1,
                                  );
                                },
                                child: Container(
                                  width: 44,
                                  height: 44,
                                  decoration: BoxDecoration(
                                    color: const Color(0xff166534),
                                    borderRadius: BorderRadius.circular(8),
                                  ),
                                  child: const Center(
                                    child: Text(
                                      '+',
                                      style: TextStyle(
                                        color: Color(0xff22c55e),
                                        fontSize: 28,
                                        fontWeight: FontWeight.bold,
                                      ),
                                    ),
                                  ),
                                ),
                              ),
                            ],
                          ),

                          const SizedBox(width: AppSpacing.lg),

                          // Line total
                          SizedBox(
                            width: 90,
                            child: Text(
                              lineTotalFormatted,
                              textAlign: TextAlign.right,
                              style: TextStyle(
                                color: Colors.white,
                                fontSize: AppFontSizes.xl,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ),

                          const SizedBox(width: AppSpacing.md),

                          // Delete button
                          GestureDetector(
                            onTap: () => cartProvider.removeItem(item.productId),
                            child: Container(
                              width: 44,
                              height: 44,
                              decoration: BoxDecoration(
                                color: const Color(0x807f1d1d),
                                borderRadius: BorderRadius.circular(8),
                                border: Border.all(
                                  color: const Color(0xffef4444),
                                  width: 1,
                                ),
                              ),
                              child: const Center(
                                child: Icon(
                                  Icons.delete_outline,
                                  color: Color(0xffef4444),
                                  size: 28,
                                ),
                              ),
                            ),
                          ),
                        ],
                      ),
                    );
                  },
                ),
              ),
            ),

            // Total section (sticky bottom)
            Container(
              decoration: BoxDecoration(
                color: const Color(0xff1a2744),
                borderRadius: const BorderRadius.only(
                  topLeft: Radius.circular(AppBorderRadius.lg),
                  topRight: Radius.circular(AppBorderRadius.lg),
                ),
                border: Border.all(
                  color: const Color(0xff334155),
                  width: 1,
                ),
              ),
              padding: const EdgeInsets.all(AppSpacing.lg),
              child: Column(
                children: [
                  // Summe row
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              l10n.cartTotal,
                              style: TextStyle(
                                color: Color(0xff94a3b8),
                                fontSize: AppFontSizes.xxl,
                                fontWeight: FontWeight.w500,
                              ),
                            ),
                            const SizedBox(height: 2),
                            Text(
                              formatNewBalance(newBalanceCents, l10n, locale),
                              style: TextStyle(
                                color: balanceColor(newBalanceCents),
                                fontSize: AppFontSizes.xl,
                                fontWeight: FontWeight.w500,
                              ),
                              overflow: TextOverflow.ellipsis,
                            ),
                          ],
                        ),
                      ),
                      Text(
                        formatPrice(totalCents, locale),
                        style: TextStyle(
                          color: Colors.white,
                          fontSize: 48,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: AppSpacing.lg),

                  // Checkout button — disabled while a checkout is in flight so
                  // a double-tap cannot create duplicate transactions.
                  CheckoutButton(
                    isLoading: isCheckoutInFlight,
                    isBlockedByLimit: limitCheck.blocksCheckout,
                    onPressed: () => runCheckout(context),
                  ),
                ],
              ),
            ),
          ],
        );
      },
    );
  }
}
