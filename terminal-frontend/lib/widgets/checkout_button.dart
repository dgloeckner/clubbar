import 'package:flutter/material.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';

/// Checkout button with press feedback, an in-flight state and a blocked
/// state.
///
/// While [isLoading] is true the button is non-interactive (no [onPressed] on
/// the [InkWell]) and shows a spinner plus a "processing" label, so a member
/// cannot tap it a second time during the async checkout.
///
/// While [isBlockedByLimit] is true it is greyed out and says so (UC-T12). A
/// tooltip would be the desktop answer; on a touch terminal nobody hovers, so
/// the reason goes in the label — and in the banner above the cart.
///
/// Shared by the cart screen and the product grid's [CartSummaryBar] (issue
/// #34): the same control must state the same three conditions wherever
/// checkout is offered.
class CheckoutButton extends StatelessWidget {
  const CheckoutButton({
    required this.isLoading,
    required this.isBlockedByLimit,
    required this.onPressed,
    super.key = const Key('checkout-button'),
  });

  final bool isLoading;
  final bool isBlockedByLimit;
  final Future<void> Function() onPressed;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final borderRadius = BorderRadius.circular(AppBorderRadius.lg);
    // Resolved once: background, foreground and label always describe the
    // same state, so they cannot drift apart as states are added.
    final (background, foreground, label) = switch (this) {
      _ when isBlockedByLimit => (
          AppColors.borderLight,
          AppColors.textSecondary,
          l10n.checkoutBlockedByLimit,
        ),
      _ when isLoading => (
          AppColors.cartAddFill,
          Colors.black,
          l10n.checkoutProcessing,
        ),
      _ => (AppColors.semanticSuccess, Colors.black, l10n.checkout),
    };

    return Material(
      color: background,
      borderRadius: borderRadius,
      child: InkWell(
        onTap: isLoading || isBlockedByLimit ? null : onPressed,
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
                  Icon(
                    isBlockedByLimit ? Icons.block : Icons.check,
                    color: foreground,
                    size: 24,
                  ),
                const SizedBox(width: 8),
                // Flexible, because this button is no longer always the full
                // width of the screen: on the product grid's summary bar it
                // shares a row. A clipped label would hide the very state the
                // button exists to state, so it ellipsises instead.
                Flexible(
                  child: Text(
                    label,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      color: foreground,
                      fontSize: AppFontSizes.xl,
                      fontWeight: FontWeight.w600,
                    ),
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
