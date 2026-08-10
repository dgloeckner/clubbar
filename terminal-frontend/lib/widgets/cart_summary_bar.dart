import 'package:flutter/material.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/utils/formatters.dart';
import 'package:clubbar_terminal/widgets/checkout_button.dart';

/// Sticky bottom bar on the product grid: what the cart costs so far, and the
/// way to pay for it (issue #34).
///
/// The grid used to show an item *count* and nothing else, so the amount a
/// member was about to spend — the one number that matters on a tab — lived
/// one screen away, behind a mandatory interstitial that offered no editing
/// the tiles do not already have. The bar puts the running total where the
/// tapping happens and takes a 2-beer purchase from 4 taps to 3.
///
/// [onViewCart] keeps the cart screen one tap away for what the grid genuinely
/// cannot do: removing a line outright, and seeing the whole order at once.
class CartSummaryBar extends StatelessWidget {
  const CartSummaryBar({
    required this.totalCents,
    required this.newBalanceCents,
    required this.locale,
    required this.isCheckoutInFlight,
    required this.isBlockedByLimit,
    required this.onCheckout,
    required this.onViewCart,
    super.key = const Key('cart-summary-bar'),
  });

  /// What the cart costs right now.
  final int totalCents;

  /// The member's tab once this cart is booked (Deckel + [totalCents]).
  final int newBalanceCents;

  final String locale;

  /// While true the cart must not be edited or re-submitted; both buttons are
  /// inert and the checkout button says why.
  final bool isCheckoutInFlight;

  final bool isBlockedByLimit;

  final Future<void> Function() onCheckout;

  final VoidCallback onViewCart;

  static const double _buttonHeight = 67.0;
  static const double _borderWidth = 1.0;

  /// The bar's fixed content height, computed from the same tokens the bar
  /// itself renders with (button height, vertical padding, border) rather
  /// than measured and pinned separately — so a caller that needs to reserve
  /// space for the bar (the product grid's bottom padding, issue #293)
  /// cannot drift out of sync with it.
  static const double height =
      _buttonHeight + 2 * AppSpacing.md + 2 * _borderWidth;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;

    return Container(
      decoration: BoxDecoration(
        color: const Color(0xff1a2744),
        borderRadius: const BorderRadius.only(
          topLeft: Radius.circular(AppBorderRadius.lg),
          topRight: Radius.circular(AppBorderRadius.lg),
        ),
        border: Border.all(
          color: const Color(0xff334155),
          width: _borderWidth,
        ),
      ),
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.lg,
        vertical: AppSpacing.md,
      ),
      child: Row(
        children: [
          // Running total, in the same shape the cart screen uses: label,
          // projected tab, amount. A member who learns to read it there reads
          // it here without looking twice.
          Expanded(
            flex: 5,
            child: Row(
              children: [
                Expanded(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        l10n.cartTotal,
                        style: TextStyle(
                          color: const Color(0xff94a3b8),
                          fontSize: AppFontSizes.lg,
                          fontWeight: FontWeight.w500,
                        ),
                      ),
                      Text(
                        formatNewBalance(newBalanceCents, l10n, locale),
                        style: TextStyle(
                          color: balanceColor(newBalanceCents),
                          fontSize: AppFontSizes.base,
                          fontWeight: FontWeight.w500,
                        ),
                        overflow: TextOverflow.ellipsis,
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: AppSpacing.md),
                Text(
                  formatPrice(totalCents, locale),
                  key: const Key('cart-summary-total'),
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 40,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: AppSpacing.lg),

          // Secondary: review and edit. Deliberately quieter than checkout —
          // most purchases never need it.
          _ViewCartButton(
            onPressed: isCheckoutInFlight ? null : onViewCart,
          ),
          const SizedBox(width: AppSpacing.md),

          // Primary. Same control as on the cart screen, same three states.
          // Flexed rather than pinned to a width: the label is translated and
          // the terminal's font sizes are configurable, so a fixed box would
          // clip "Wird verarbeitet…" on someone's kiosk.
          Expanded(
            flex: 4,
            child: CheckoutButton(
              isLoading: isCheckoutInFlight,
              isBlockedByLimit: isBlockedByLimit,
              onPressed: onCheckout,
            ),
          ),
        ],
      ),
    );
  }
}

/// Outlined counterpart to [CheckoutButton] — same height, no fill, so the
/// green button keeps the eye.
class _ViewCartButton extends StatelessWidget {
  const _ViewCartButton({required this.onPressed});

  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final borderRadius = BorderRadius.circular(AppBorderRadius.lg);

    return Material(
      key: const Key('view-cart-button'),
      color: Colors.transparent,
      borderRadius: borderRadius,
      child: InkWell(
        onTap: onPressed,
        borderRadius: borderRadius,
        child: Opacity(
          opacity: onPressed == null ? 0.4 : 1.0,
          child: Container(
            height: CartSummaryBar._buttonHeight,
            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.lg),
            decoration: BoxDecoration(
              color: const Color(0x333b82f6),
              border: Border.all(
                color: const Color(0x663b82f6),
                width: 1,
              ),
              borderRadius: borderRadius,
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                // Not the cart glyph: the member bar above already carries
                // that one with the item-count badge, and two identical icons
                // on one screen read as two different destinations. A list is
                // what this button actually opens — the order, itemised.
                const Icon(
                  Icons.list_alt,
                  color: Colors.white,
                  size: 24,
                ),
                const SizedBox(width: 8),
                Text(
                  l10n.viewCart,
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: AppFontSizes.lg,
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
