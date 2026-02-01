import 'package:flutter/material.dart';
import 'package:ruderbar_terminal/database/database.dart';

class CategoryTabs extends StatelessWidget {
  final List<CategoriesCacheData> categories;
  final String? selectedCategoryId;
  final String Function(CategoriesCacheData) getTranslatedName;
  final Function(String) onCategorySelected;

  const CategoryTabs({
    required this.categories,
    required this.selectedCategoryId,
    required this.getTranslatedName,
    required this.onCategorySelected,
    Key? key,
  }) : super(key: key);

  @override
  Widget build(BuildContext context) {
    if (categories.isEmpty) {
      return const SizedBox.shrink();
    }

    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 8.0),
        child: Row(
          children: categories.map((category) {
            final isSelected = category.id == selectedCategoryId;
            final translatedName = getTranslatedName(category);
            return Padding(
              padding: const EdgeInsets.symmetric(horizontal: 4.0),
              child: FilterChip(
                label: Text(translatedName),
                selected: isSelected,
                onSelected: (_) => onCategorySelected(category.id),
                backgroundColor: Colors.grey[200],
                selectedColor: Colors.blue,
                labelStyle: TextStyle(
                  color: isSelected ? Colors.white : Colors.black,
                  fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
                ),
              ),
            );
          }).toList(),
        ),
      ),
    );
  }
}
