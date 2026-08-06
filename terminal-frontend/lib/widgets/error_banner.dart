import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:flutter/material.dart';

/// Inline, dismissible error strip.
///
/// For errors the member can keep working around — a cancelled checkout, a
/// stale product list. Failures that need a decision belong in
/// `showErrorModal` instead.
///
/// [message] must already be localized: this widget renders copy, it never
/// resolves a `TerminalErrorKey` itself.
class ErrorBanner extends StatelessWidget {
  final String? message;
  final VoidCallback? onDismiss;

  const ErrorBanner({
    this.message,
    this.onDismiss,
    super.key,
  });

  @override
  Widget build(BuildContext context) {
    if (message == null || message!.isEmpty) {
      return const SizedBox.shrink();
    }

    final danger = hexToColor(AppColors.semanticDanger);

    return Container(
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
        // Danger at low alpha over the dark terminal background, so the strip
        // reads as urgent without the glare of a solid red block.
        color: danger.withValues(alpha: 0.15),
        border: Border.all(color: danger.withValues(alpha: 0.5)),
        borderRadius: BorderRadius.circular(AppBorderRadius.md),
      ),
      child: Row(
        children: [
          Icon(Icons.error, color: danger),
          const SizedBox(width: AppSpacing.md),
          Expanded(
            child: Text(
              message!,
              style: TextStyle(
                color: hexToColor(AppColors.textPrimary),
                fontSize: AppFontSizes.base,
              ),
            ),
          ),
          if (onDismiss != null)
            IconButton(
              icon: Icon(Icons.close, color: hexToColor(AppColors.textPrimary)),
              onPressed: onDismiss,
            ),
        ],
      ),
    );
  }
}
