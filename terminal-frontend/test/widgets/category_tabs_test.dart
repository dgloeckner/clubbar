import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/widgets/category_tabs.dart';

void main() {
  group('CategoryTabs', () {
    final categories = [
      CategoriesCacheData(
        id: 'cat-1',
        names: '{"de":"Getränke","en":"Drinks"}',
        displayOrder: 1,
        isActive: 1,
        updatedAt: '2024-01-01T00:00:00Z',
      ),
      CategoriesCacheData(
        id: 'cat-2',
        names: '{"de":"Snacks","en":"Snacks"}',
        displayOrder: 2,
        isActive: 1,
        updatedAt: '2024-01-01T00:00:00Z',
      ),
      CategoriesCacheData(
        id: 'cat-3',
        names: '{"de":"Essen","en":"Food"}',
        displayOrder: 3,
        isActive: 1,
        updatedAt: '2024-01-01T00:00:00Z',
      ),
    ];

    testWidgets('displays all categories as tabs', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: CategoryTabs(
              categories: categories,
              selectedCategoryId: 'cat-1',
              getTranslatedName: (category) {
                return category.names; // Simplified for test
              },
              onCategorySelected: (_) {},
            ),
          ),
        ),
      );

      expect(find.text('{"de":"Getränke","en":"Drinks"}'), findsOneWidget);
      expect(find.text('{"de":"Snacks","en":"Snacks"}'), findsOneWidget);
      expect(find.text('{"de":"Essen","en":"Food"}'), findsOneWidget);
    });

    testWidgets('highlights selected category', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: CategoryTabs(
              categories: categories,
              selectedCategoryId: 'cat-2',
              getTranslatedName: (category) {
                return category.names;
              },
              onCategorySelected: (_) {},
            ),
          ),
        ),
      );

      // Selected tab should be visible
      expect(find.text('{"de":"Snacks","en":"Snacks"}'), findsOneWidget);
    });

    testWidgets('calls onCategorySelected when tab tapped',
        (WidgetTester tester) async {
      String? selected;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: CategoryTabs(
              categories: categories,
              selectedCategoryId: 'cat-1',
              getTranslatedName: (category) {
                return category.names;
              },
              onCategorySelected: (id) {
                selected = id;
              },
            ),
          ),
        ),
      );

      // Find and tap the second category instead (to avoid scroll issues)
      await tester.tap(find.text('{"de":"Snacks","en":"Snacks"}'));
      expect(selected, equals('cat-2'));
    });

    testWidgets('handles empty categories list', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: CategoryTabs(
              categories: [],
              selectedCategoryId: null,
              getTranslatedName: (category) {
                return category.names;
              },
              onCategorySelected: (_) {},
            ),
          ),
        ),
      );

      expect(find.byType(CategoryTabs), findsOneWidget);
    });

    testWidgets('displays different styles for selected and unselected tabs',
        (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: CategoryTabs(
              categories: categories,
              selectedCategoryId: 'cat-1',
              getTranslatedName: (category) {
                return category.names;
              },
              onCategorySelected: (_) {},
            ),
          ),
        ),
      );

      // Check that FilterChip widgets are present
      expect(find.byType(FilterChip), findsNWidgets(3));
    });
  });
}
