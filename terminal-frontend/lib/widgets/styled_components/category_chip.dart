import 'package:flutter/material.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/utils/design_tokens.dart';
import 'package:ruderbar_terminal/utils/icon_registry.dart';

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
    final icon = getCategoryIcon(null); // iconName not in schema yet, use null for default
    final backgroundColor = selected
        ? const Color(0xff3b82f6)  // Blue when selected
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
          vertical: AppSpacing.md,
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
            Icon(
              icon,
              size: 32,
              color: textColor,
            ),
            const SizedBox(width: AppSpacing.sm),
            Text(
              categoryName,
              style: TextStyle(
                color: textColor,
                fontSize: AppFontSizes.base,
                fontWeight: selected ? FontWeight.w600 : FontWeight.w400,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
