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
        ? hexToColor(AppColors.semanticPrimaryStrong)
        : const Color(0xff0f1d32); // Secondary bg when unselected
    final textColor = selected
        ? Colors.white
        : const Color(0xff94a3b8); // Secondary text when unselected

    return GestureDetector(
      onTap: onSelected,
      child: AnimatedContainer(
        duration: AppAnimations.normal,
        padding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.lg,
          vertical: 14, // 20% taller than AppSpacing.md (12)
        ),
        decoration: BoxDecoration(
          color: backgroundColor,
          borderRadius: BorderRadius.circular(AppBorderRadius.full),
          border: Border.all(
            color: selected ? const Color(0xff3b82f6) : const Color(0xff334155),
            width: 1,
          ),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            getCategoryIcon(
              category.iconName,
              size: 38,
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
