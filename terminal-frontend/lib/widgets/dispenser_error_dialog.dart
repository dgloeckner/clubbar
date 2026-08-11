import 'package:flutter/material.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';

/// Error type for dispenser error dialog
enum DispenserErrorType {
  busy,
  offline,
}

/// Dialog shown when dispenser is busy or offline during checkout
class DispenserErrorDialog extends StatelessWidget {
  final DispenserErrorType errorType;
  final VoidCallback onCancel;
  final VoidCallback onBuyWithoutTokens;

  const DispenserErrorDialog({
    super.key,
    required this.errorType,
    required this.onCancel,
    required this.onBuyWithoutTokens,
  });

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;

    final title = errorType == DispenserErrorType.busy
        ? l10n.dispenserBusyTitle
        : l10n.dispenserOfflineTitle;

    final message = errorType == DispenserErrorType.busy
        ? l10n.dispenserBusyMessage
        : l10n.dispenserOfflineMessage;

    return AlertDialog(
      backgroundColor: AppColors.bgCard,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(AppBorderRadius.lg),
        side: const BorderSide(
          color: AppColors.borderLight,
          width: 1,
        ),
      ),
      title: Row(
        children: [
          Icon(
            errorType == DispenserErrorType.busy
                ? Icons.hourglass_empty
                : Icons.wifi_off,
            color: AppColors.semanticWarningLight,
            size: 28,
          ),
          const SizedBox(width: AppSpacing.md),
          Expanded(
            child: Text(
              title,
              style: TextStyle(
                color: AppColors.textPrimary,
                fontSize: AppFontSizes.xl,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ],
      ),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            message,
            style: TextStyle(
              color: AppColors.textSecondary,
              fontSize: AppFontSizes.lg,
            ),
          ),
          const SizedBox(height: AppSpacing.lg),
          Container(
            padding: const EdgeInsets.all(AppSpacing.md),
            decoration: BoxDecoration(
              color: AppColors.bgDeep,
              borderRadius: BorderRadius.circular(AppBorderRadius.md),
              border: Border.all(
                color: AppColors.borderLight,
                width: 1,
              ),
            ),
            child: Row(
              children: [
                const Icon(
                  Icons.info_outline,
                  color: AppColors.semanticInfo,
                  size: 20,
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: Text(
                    l10n.dispenserBuyWithoutTokensHint,
                    style: TextStyle(
                      color: AppColors.semanticInfo,
                      fontSize: AppFontSizes.base,
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
      actions: [
        // Cancel button
        TextButton(
          onPressed: onCancel,
          style: TextButton.styleFrom(
            backgroundColor: const Color(0x807f1d1d),
            padding: const EdgeInsets.symmetric(
              horizontal: AppSpacing.lg,
              vertical: AppSpacing.md,
            ),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(AppBorderRadius.md),
              side: const BorderSide(
                color: AppColors.semanticDanger,
                width: 1,
              ),
            ),
          ),
          child: Text(
            l10n.dispenserCancelButton,
            style: TextStyle(
              color: AppColors.semanticDanger,
              fontSize: AppFontSizes.lg,
              fontWeight: FontWeight.w600,
            ),
          ),
        ),
        const SizedBox(width: AppSpacing.md),
        // Buy without tokens button
        TextButton(
          onPressed: onBuyWithoutTokens,
          style: TextButton.styleFrom(
            backgroundColor: AppColors.semanticSuccess,
            padding: const EdgeInsets.symmetric(
              horizontal: AppSpacing.lg,
              vertical: AppSpacing.md,
            ),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(AppBorderRadius.md),
            ),
          ),
          child: Text(
            l10n.dispenserBuyWithoutTokensButton,
            style: TextStyle(
              color: Colors.black,
              fontSize: AppFontSizes.lg,
              fontWeight: FontWeight.w600,
            ),
          ),
        ),
      ],
    );
  }

  /// Show the dialog and return the user's choice
  /// Returns true if user chose to buy without tokens, false if cancelled
  static Future<bool> show(
    BuildContext context,
    DispenserErrorType errorType,
  ) async {
    return await showDialog<bool>(
      context: context,
      barrierDismissible: false, // Force user to make a choice
      builder: (context) => DispenserErrorDialog(
        errorType: errorType,
        onCancel: () => Navigator.of(context).pop(false),
        onBuyWithoutTokens: () => Navigator.of(context).pop(true),
      ),
    ) ??
        false; // Default to cancel if dialog dismissed
  }
}
