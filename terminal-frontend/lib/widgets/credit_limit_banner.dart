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
///
/// Laid out as a [Wrap], not a [Column] or a [Row] (#369). The banner is a
/// fixed-height child of a screen whose only flexible child is the product
/// grid, so every pixel it takes is a pixel the grid loses — and it used to
/// take 91 of them for two lines that fit on one. A [Row] would be the obvious
/// fix and is the wrong one: the German blocked copy plus the three amounts is
/// ~1105 px of text against ~1214 px of usable width at 1280, so it fits at the
/// shipped type scale and overflows at 1024, or at 1280 once a club raises
/// `fontSizes` in config.json ([AppFontSizes.applyConfig]). [Wrap] takes the
/// one line when it fits and falls back to two when it does not, which is the
/// old height — never worse than before, 41 px better in the common case.
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
    // One joined string, not three widgets: the cart screen's tests read it
    // back with `textContaining`, and a member scanning it wants the three
    // figures in one eyeline anyway.
    final amounts = [
      l10n.creditLimitCurrent(formatPrice(check.currentBalanceCents, locale)),
      l10n.creditLimitCart(formatPrice(check.cartTotalCents, locale)),
      l10n.creditLimitMaximum(formatPrice(check.limitCents, locale)),
    ].join('   ·   ');

    return Container(
      key: const Key('credit-limit-banner'),
      width: double.infinity,
      margin: const EdgeInsets.symmetric(
        horizontal: AppSpacing.lg,
        vertical: AppSpacing.xs,
      ),
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.lg,
        vertical: AppSpacing.sm,
      ),
      decoration: BoxDecoration(
        color: accent.withValues(alpha: 0.15),
        border: Border.all(color: accent.withValues(alpha: 0.5)),
        borderRadius: BorderRadius.circular(AppBorderRadius.md),
      ),
      child: LayoutBuilder(
        builder: (context, constraints) {
          // Each run is capped at the banner's own width so a run that is too
          // wide soft-wraps inside itself rather than overflowing the Wrap.
          BoxConstraints cap() =>
              BoxConstraints(maxWidth: constraints.maxWidth);

          return Wrap(
            spacing: AppSpacing.md,
            runSpacing: AppSpacing.xs,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              ConstrainedBox(
                constraints: cap(),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(Icons.warning_amber_rounded, color: accent),
                    const SizedBox(width: AppSpacing.sm),
                    Flexible(
                      child: Text(
                        headline,
                        style: TextStyle(
                          color: AppColors.textPrimary,
                          fontSize: AppFontSizes.lg,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              ConstrainedBox(
                constraints: cap(),
                child: Text(
                  amounts,
                  style: TextStyle(
                    color: AppColors.textSecondary,
                    fontSize: AppFontSizes.base,
                  ),
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}
