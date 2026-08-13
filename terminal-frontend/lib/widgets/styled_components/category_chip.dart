import 'package:flutter/material.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/utils/icon_registry.dart';

class CategoryChip extends StatelessWidget {
  final CategoriesCacheData category;
  final String categoryName;
  final bool selected;
  final VoidCallback onSelected;

  const CategoryChip({
    super.key,
    required this.category,
    required this.categoryName,
    required this.selected,
    required this.onSelected,
  });

  @override
  Widget build(BuildContext context) {
    // Strong blue when selected: white on #3b82f6 is 3.7:1 (#41).
    final backgroundColor = selected
        ? AppColors.semanticPrimaryStrong
        : AppColors.bgSecondary; // Secondary bg when unselected
    final textColor = selected
        ? Colors.white
        : AppColors.textSecondary; // Secondary text when unselected

    return GestureDetector(
      onTap: onSelected,
      child: AnimatedContainer(
        duration: AppAnimations.normal,
        // Vertical padding is `sm`, not the `14` this used to carry (#369):
        // the chip is a fixed-height band above the product grid, and at 38 px
        // of icon plus 28 px of padding it was costing 68 px for one word. The
        // 52 px that is left still clears the app's 44 px touch-target floor
        // (see kHeaderPillTouchTarget), and the icon still sets the height, so
        // the chip stays the same size whatever type scale a club configures.
        padding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.lg,
          vertical: AppSpacing.sm,
        ),
        decoration: BoxDecoration(
          color: backgroundColor,
          borderRadius: BorderRadius.circular(AppBorderRadius.full),
          border: Border.all(
            color: selected ? AppColors.semanticPrimary : AppColors.borderLight,
            width: 1,
          ),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            getCategoryIcon(
              category.iconName,
              size: 34,
            ),
            const SizedBox(width: AppSpacing.sm),
            // Issue #30: a long German category name ("Alkoholfreie Getränke")
            // used to push the row past its chip. The bar scrolls rather than
            // squeezing, so this is only the backstop for the case where the
            // chip is genuinely narrower than its label.
            Flexible(
              child: Text(
                categoryName,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  color: textColor,
                  fontSize: AppFontSizes.xl,
                  fontWeight: selected ? FontWeight.w600 : FontWeight.w400,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
