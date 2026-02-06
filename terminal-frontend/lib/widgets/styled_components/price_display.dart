import 'package:flutter/material.dart';
import 'package:ruderbar_terminal/utils/design_tokens.dart';
import 'package:ruderbar_terminal/utils/formatters.dart';

enum PriceFontSize { small, medium, large }

class PriceDisplay extends StatelessWidget {
  final int priceCents;
  final String locale;
  final PriceFontSize fontSize;
  final bool fullWidth;

  const PriceDisplay({
    super.key,
    required this.priceCents,
    required this.locale,
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
    final text = formatPrice(priceCents, locale);

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
