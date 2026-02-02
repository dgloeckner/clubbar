import 'package:flutter/material.dart';
import 'package:ruderbar_terminal/utils/design_tokens.dart';

enum PriceFontSize { small, medium, large }

class PriceDisplay extends StatelessWidget {
  final int priceCents;
  final PriceFontSize fontSize;
  final bool fullWidth;

  const PriceDisplay({
    super.key,
    required this.priceCents,
    this.fontSize = PriceFontSize.medium,
    this.fullWidth = false,
  });

  double _getFontSize() {
    switch (fontSize) {
      case PriceFontSize.small:
        return AppFontSizes.base;
      case PriceFontSize.medium:
        return AppFontSizes.lg;
      case PriceFontSize.large:
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
