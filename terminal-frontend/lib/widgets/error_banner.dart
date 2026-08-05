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
        color: const Color(0xff450a0a),
        border: Border.all(color: const Color(0xff7f1d1d)),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        children: [
          const Icon(Icons.error, color: Color(0xffef4444)),
          const SizedBox(width: AppSpacing.md),
          Expanded(
            child: Text(
              message!,
              style: TextStyle(
                color: const Color(0xfffecaca),
                fontSize: AppFontSizes.base,
              ),
            ),
          ),
          if (onDismiss != null)
            IconButton(
              icon: const Icon(Icons.close, color: Color(0xfffecaca)),
              onPressed: onDismiss,
            ),
        ],
      ),
    );
  }
}
