import 'package:flutter/material.dart';
import 'package:ruderbar_terminal/l10n/app_localizations.dart';
import 'package:ruderbar_terminal/utils/design_tokens.dart';

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
      backgroundColor: const Color(0xff1a2744),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(AppBorderRadius.lg),
        side: const BorderSide(
          color: Color(0xff334155),
          width: 1,
        ),
      ),
      title: Row(
        children: [
          Icon(
            errorType == DispenserErrorType.busy
                ? Icons.hourglass_empty
                : Icons.wifi_off,
            color: const Color(0xfffbbf24),
            size: 28,
          ),
          const SizedBox(width: AppSpacing.md),
          Expanded(
            child: Text(
              title,
              style: const TextStyle(
                color: Color(0xfff1f5f9),
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
            style: const TextStyle(
              color: Color(0xff94a3b8),
              fontSize: AppFontSizes.lg,
            ),
          ),
          const SizedBox(height: AppSpacing.lg),
          Container(
            padding: const EdgeInsets.all(AppSpacing.md),
            decoration: BoxDecoration(
              color: const Color(0xff0f172a),
              borderRadius: BorderRadius.circular(AppBorderRadius.md),
              border: Border.all(
                color: const Color(0xff334155),
                width: 1,
              ),
            ),
            child: Row(
              children: [
                const Icon(
                  Icons.info_outline,
                  color: Color(0xff0ea5e9),
                  size: 20,
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: Text(
                    l10n.dispenserBuyWithoutTokensHint,
                    style: const TextStyle(
                      color: Color(0xff0ea5e9),
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
                color: Color(0xffef4444),
                width: 1,
              ),
            ),
          ),
          child: Text(
            l10n.dispenserCancelButton,
            style: const TextStyle(
              color: Color(0xffef4444),
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
            backgroundColor: const Color(0xff22c55e),
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
            style: const TextStyle(
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
