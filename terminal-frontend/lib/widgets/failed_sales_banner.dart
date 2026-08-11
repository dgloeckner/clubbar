import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/models/failed_sale.dart';
import 'package:clubbar_terminal/providers/quarantine_provider.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/utils/formatters.dart';

/// Persistent warning shown while any sale sits in quarantine.
///
/// It cannot be dismissed: a quarantined sale is money the club will never
/// collect unless a human acts, and it stays that way until an admin clears it
/// from the backend (issue #152). Tapping it opens the list staff report from.
class FailedSalesBanner extends StatelessWidget {
  const FailedSalesBanner({super.key});

  @override
  Widget build(BuildContext context) {
    // Nullable lookup: screens and tests that never wire a quarantine simply
    // have nothing to warn about.
    final quarantine = context.watch<QuarantineProvider?>();
    if (quarantine == null || !quarantine.hasFailedSales) {
      return const SizedBox.shrink();
    }

    final l10n = AppLocalizations.of(context)!;
    final count = quarantine.failedSales.length;

    return Material(
      color: Colors.transparent,
      child: InkWell(
        key: const Key('failed-sales-banner'),
        onTap: () => showFailedSalesModal(context, quarantine.failedSales),
        child: Container(
          width: double.infinity,
          padding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.lg,
            vertical: AppSpacing.md,
          ),
          color: AppColors.dangerStrong,
          child: Row(
            children: [
              const Icon(Icons.warning_amber_rounded, color: Colors.white),
              const SizedBox(width: AppSpacing.md),
              Expanded(
                child: Text(
                  l10n.failedSalesWarning(count),
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: AppFontSizes.lg,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// The failed-sales screen: every quarantined sale with who bought, how much
/// and when — the three things an admin needs to book it by hand.
void showFailedSalesModal(BuildContext context, List<FailedSale> sales) {
  showDialog(
    context: context,
    builder: (context) => _FailedSalesDialog(sales: sales),
  );
}

class _FailedSalesDialog extends StatelessWidget {
  final List<FailedSale> sales;

  const _FailedSalesDialog({required this.sales});

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final locale = Localizations.localeOf(context).languageCode;

    return AlertDialog(
      key: const Key('failed-sales-dialog'),
      backgroundColor: AppColors.borderDark,
      title: Text(
        l10n.failedSalesTitle,
        style: const TextStyle(color: Colors.white),
      ),
      content: SizedBox(
        width: 520,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              l10n.failedSalesInstruction,
              style: const TextStyle(color: AppColors.textSecondary),
            ),
            const SizedBox(height: AppSpacing.lg),
            Flexible(
              child: ListView.separated(
                shrinkWrap: true,
                itemCount: sales.length,
                separatorBuilder: (_, _) =>
                    const Divider(color: AppColors.borderLight),
                itemBuilder: (context, index) {
                  final sale = sales[index];
                  return ListTile(
                    contentPadding: EdgeInsets.zero,
                    title: Text(
                      sale.memberName ?? l10n.failedSalesUnknownMember,
                      style: const TextStyle(color: Colors.white),
                    ),
                    subtitle: Text(
                      formatDateTime(sale.occurredAt, locale),
                      style: const TextStyle(color: AppColors.textSecondary),
                    ),
                    trailing: Text(
                      formatPrice(sale.amountCents, locale),
                      style: const TextStyle(color: Colors.white),
                    ),
                  );
                },
              ),
            ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: Text(l10n.dismiss),
        ),
      ],
    );
  }
}
