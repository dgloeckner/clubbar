import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/utils/formatters.dart';
import 'package:clubbar_terminal/utils/icon_registry.dart';
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
                      // ADR-0027: all session ends go through endSession()
                      context.read<SessionController>().endSession();
                      context.go('/idle');
                    },
                  ),
                ),
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

        final totalCents = cartProvider.items.fold<int>(
          0,
          (sum, item) => sum + (item.priceCents * item.quantity),
        );

        // Calculate new balance (Deckel + cart total)
        final currentDeckel = membersProvider.memberDeckel ?? 0;
        final newBalanceCents = currentDeckel + totalCents;

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
                    membersProvider.clearSelectedMember();
                    context.go('/idle');
                  },
                ),
              ),

            // Item list — frozen while a checkout is in flight, otherwise the
            // cart could be mutated after the transactions were computed.
            Expanded(
              child: IgnorePointer(
                ignoring: isCheckoutInFlight,
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
                                  l10n.cartEachPrice('€$unitPriceFormatted'),
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
                              '€$lineTotalFormatted',
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
                              l10n.cartNewBalance(formatPrice(newBalanceCents, locale)),
                              style: TextStyle(
                                color: Color(0xff22c55e),
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
                  _CheckoutButton(
                    isLoading: isCheckoutInFlight,
                    onPressed: () async {
                      final selectedMember = membersProvider.selectedMember;
                      final sessionId = membersProvider.sessionId ?? '';

                      if (selectedMember == null) {
                        ScaffoldMessenger.of(context).showSnackBar(
                          SnackBar(
                            content: Text(l10n.memberNotSelected),
                            backgroundColor: const Color(0xffef4444),
                          ),
                        );
                        return;
                      }

                      // ADR-0027 rule 7: suspend the inactivity timer while
                      // checkout/dispensing is in flight.
                      final session = context.read<SessionController>();
                      session.beginCriticalOperation();
                      try {
                        await cartProvider.checkout(
                            context, selectedMember, sessionId);
                      } finally {
                        session.endCriticalOperation();
                      }

                      if (cartProvider.lastError != null) {
                        if (context.mounted) {
                          ScaffoldMessenger.of(context).showSnackBar(
                            SnackBar(
                              content: Text(cartProvider.lastError!),
                              backgroundColor: const Color(0xffef4444),
                            ),
                          );
                        }
                        return;
                      }

                      // Recompute Deckel from database (now includes
                      // the new unsynced transaction)
                      await membersProvider.refreshDeckel();

                      if (cartProvider.lastSessionId != null && context.mounted) {
                        context.go('/confirmation/${cartProvider.lastSessionId}');
                      }
                    },
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

/// Checkout button with press feedback and an in-flight state.
///
/// While [isLoading] is true the button is non-interactive (no [onPressed] on
/// the [InkWell]) and shows a spinner plus a "processing" label, so a member
/// cannot tap it a second time during the async checkout.
class _CheckoutButton extends StatelessWidget {
  const _CheckoutButton({
    required this.isLoading,
    required this.onPressed,
  });

  final bool isLoading;
  final Future<void> Function() onPressed;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final borderRadius = BorderRadius.circular(AppBorderRadius.lg);

    return Material(
      // Stable selector for widget/integration tests
      key: const Key('checkout-button'),
      color: isLoading ? const Color(0xff166534) : const Color(0xff22c55e),
      borderRadius: borderRadius,
      child: InkWell(
        onTap: isLoading ? null : onPressed,
        borderRadius: borderRadius,
        child: SizedBox(
          height: 67,
          child: Center(
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                if (isLoading)
                  const SizedBox(
                    width: 24,
                    height: 24,
                    child: CircularProgressIndicator(
                      strokeWidth: 3,
                      valueColor: AlwaysStoppedAnimation<Color>(Colors.black),
                    ),
                  )
                else
                  const Icon(
                    Icons.check,
                    color: Colors.black,
                    size: 24,
                  ),
                const SizedBox(width: 8),
                Text(
                  isLoading ? l10n.checkoutProcessing : l10n.checkout,
                  style: TextStyle(
                    color: Colors.black,
                    fontSize: AppFontSizes.xl,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
