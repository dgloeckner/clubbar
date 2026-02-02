import 'package:flutter/material.dart';
import 'package:ruderbar_terminal/utils/design_tokens.dart';

class PriceDisplay extends StatelessWidget {
  final int priceCents;
  final FontSize fontSize;
  final bool fullWidth;

  enum FontSize { small, medium, large }

  const PriceDisplay({
    super.key,
    required this.priceCents,
    this.fontSize = FontSize.medium,
    this.fullWidth = false,
  });

  double _getFontSize() {
    switch (fontSize) {
      case FontSize.small:
        return AppFontSizes.base;
      case FontSize.medium:
        return AppFontSizes.lg;
      case FontSize.large:
        return AppFontSizes.xl;
    }
  }

  @override
  Widget build(BuildContext context) {
    final priceEuros = priceCents / 100.0;
    final text = '€${priceEuros.toStringAsFixed(2)}';

    return SizedBox(
      width: fullWidth ? double.infinity : null,
      child: Text(
        text,
        textAlign: fullWidth ? TextAlign.center : TextAlign.left,
        style: TextStyle(
          color: hexToColor(AppColors.semanticInfo),  // Cyan
          fontSize: _getFontSize(),
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }
}
