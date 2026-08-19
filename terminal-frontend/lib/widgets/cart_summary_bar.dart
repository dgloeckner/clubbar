import 'package:flutter/material.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/utils/formatters.dart';
import 'package:clubbar_terminal/widgets/checkout_button.dart';
import 'package:clubbar_terminal/widgets/styled_components/secondary_button.dart';

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
    this.isBlockedByCredential = false,
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

  /// The terminal's own credential has expired, so nothing rung up here can be
  /// banked (#395). Stops checkout outright, ahead of the credit-limit block.
  final bool isBlockedByCredential;

  final Future<void> Function() onCheckout;

  final VoidCallback onViewCart;

  static const double _buttonHeight = 67.0;
  static const double _borderWidth = 1.0;

  /// Tightened from `AppSpacing.md` (#369) — the bar is the last of five fixed
  /// bands stacked above the product grid, and on a 1280x800 kiosk the grid was
  /// 19 px short of showing two whole rows of tiles. The buttons still set the
  /// bar's height, so this costs the controls nothing.
  static const double _verticalPadding = AppSpacing.sm;

  /// The bar's fixed content height, computed from the same tokens the bar
  /// itself renders with (button height, vertical padding, border) rather
  /// than measured and pinned separately — so a caller that needs to reserve
  /// space for the bar cannot drift out of sync with it.
  static const double height =
      _buttonHeight + 2 * _verticalPadding + 2 * _borderWidth;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;

    return Container(
      decoration: BoxDecoration(
        color: AppColors.bgCard,
        borderRadius: const BorderRadius.only(
          topLeft: Radius.circular(AppBorderRadius.lg),
          topRight: Radius.circular(AppBorderRadius.lg),
        ),
        border: Border.all(
          color: AppColors.borderLight,
          width: _borderWidth,
        ),
      ),
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.lg,
        vertical: _verticalPadding,
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
                          color: AppColors.textSecondary,
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
              isBlockedByCredential: isBlockedByCredential,
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

    return SecondaryButton(
      key: const Key('view-cart-button'),
      // Not the cart glyph: a list is what this button actually opens — the
      // order, itemised — so the icon says that instead.
      icon: Icons.list_alt,
      label: l10n.viewCart,
      height: CartSummaryBar._buttonHeight,
      onPressed: onPressed,
    );
  }
}
