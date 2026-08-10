import 'package:flutter/material.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';

/// The app's established secondary-action style — transparent-blue fill,
/// blue border, white label — extracted from [cart_summary_bar]'s
/// `_ViewCartButton` for its second use on the checkout receipt (#295).
class SecondaryButton extends StatelessWidget {
  const SecondaryButton({
    required this.label,
    required this.onPressed,
    this.icon,
    this.height = 56.0,
    super.key,
  });

  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  final double height;

  @override
  Widget build(BuildContext context) {
    final borderRadius = BorderRadius.circular(AppBorderRadius.lg);

    return Material(
      color: Colors.transparent,
      borderRadius: borderRadius,
      child: InkWell(
        onTap: onPressed,
        borderRadius: borderRadius,
        child: Opacity(
          opacity: onPressed == null ? 0.4 : 1.0,
          child: Container(
            height: height,
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
                if (icon != null) ...[
                  Icon(icon, color: Colors.white, size: 24),
                  const SizedBox(width: 8),
                ],
                Text(
                  label,
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
