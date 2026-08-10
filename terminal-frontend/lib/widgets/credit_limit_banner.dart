import 'package:flutter/material.dart';

import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/models/credit_limit.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/utils/formatters.dart';

/// Standing notice that the member's tab is at or over the credit limit
/// (UC-T11 E3, UC-T12).
///
/// Not dismissible on purpose: unlike [ErrorBanner] this is not an event that
/// happened once, it is a condition that holds until the cart shrinks. It
/// shows the three numbers the member needs to act on — what they owe, what
/// the cart adds, and where the ceiling is — because "limit reached" alone
/// gives them nothing to remove.
///
/// Renders nothing when [check] does not warn, so callers can place it
/// unconditionally.
class CreditLimitBanner extends StatelessWidget {
  const CreditLimitBanner({
    required this.check,
    required this.locale,
    super.key,
  });

  final CreditLimitCheck check;

  /// Member's language, for money formatting (`12,50 €` vs `€12.50`).
  final String locale;

  @override
  Widget build(BuildContext context) {
    if (!check.warnsMember) return const SizedBox.shrink();

    final l10n = AppLocalizations.of(context)!;
    // Amber for both states: over the limit is not an app error, it is the
    // member's tab doing what tabs do. Red is reserved for failures.
    final accent = AppColors.semanticWarning;
    final headline = check.blocksCheckout
        ? l10n.creditLimitReached
        : l10n.creditLimitApproaching;

    return Container(
      key: const Key('credit-limit-banner'),
      width: double.infinity,
      margin: const EdgeInsets.symmetric(
        horizontal: AppSpacing.lg,
        vertical: AppSpacing.sm,
      ),
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.lg,
        vertical: AppSpacing.md,
      ),
      decoration: BoxDecoration(
        color: accent.withValues(alpha: 0.15),
        border: Border.all(color: accent.withValues(alpha: 0.5)),
        borderRadius: BorderRadius.circular(AppBorderRadius.md),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.warning_amber_rounded, color: accent),
          const SizedBox(width: AppSpacing.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  headline,
                  style: TextStyle(
                    color: AppColors.textPrimary,
                    fontSize: AppFontSizes.lg,
                    fontWeight: FontWeight.w600,
                  ),
                ),
                const SizedBox(height: AppSpacing.xs),
                Text(
                  [
                    l10n.creditLimitCurrent(
                        formatPrice(check.currentBalanceCents, locale)),
                    l10n.creditLimitCart(
                        formatPrice(check.cartTotalCents, locale)),
                    l10n.creditLimitMaximum(
                        formatPrice(check.limitCents, locale)),
                  ].join('   ·   '),
                  style: TextStyle(
                    color: AppColors.textSecondary,
                    fontSize: AppFontSizes.base,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
