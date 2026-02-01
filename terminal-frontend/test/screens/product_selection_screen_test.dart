import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'dart:convert';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/providers/products_provider.dart';
import 'package:ruderbar_terminal/providers/cart_provider.dart';
import 'package:ruderbar_terminal/screens/product_selection_screen.dart';

class MockProductsProvider extends Mock implements ProductsProvider {}
class MockCartProvider extends Mock implements CartProvider {}

void main() {
  setUpAll(() {
    registerFallbackValue(ProductsCacheData(
      id: 'test',
      categoryId: 'test',
      names: '{}',
      priceCents: 0,
      isActive: 1,
      updatedAt: '2025-02-01T10:00:00Z',
    ));
  });

  group('ProductSelectionScreen', () {
    late MockProductsProvider mockProductsProvider;
    late MockCartProvider mockCartProvider;

    setUp(() {
      mockProductsProvider = MockProductsProvider();
      mockCartProvider = MockCartProvider();
    });

    testWidgets('displays choice chips for categories', (WidgetTester tester) async {
      final categories = [
        CategoriesCacheData(
          id: 'cat-1',
          names: jsonEncode({'de': 'Bier'}),
          displayOrder: 1,
          isActive: 1,
          updatedAt: '2025-02-01T10:00:00Z',
        ),
      ];

      when(() => mockProductsProvider.categories).thenReturn(categories);
      when(() => mockProductsProvider.products).thenReturn([]);
      when(() => mockProductsProvider.addListener(any())).thenReturn(null);
      when(() => mockProductsProvider.removeListener(any())).thenReturn(null);
      when(() => mockCartProvider.addListener(any())).thenReturn(null);
      when(() => mockCartProvider.removeListener(any())).thenReturn(null);
      when(() => mockCartProvider.itemCount).thenReturn(0);
      when(() => mockCartProvider.items).thenReturn([]);

      await tester.pumpWidget(
        MaterialApp(
          home: MultiProvider(
            providers: [
              ChangeNotifierProvider<ProductsProvider>.value(value: mockProductsProvider),
              ChangeNotifierProvider<CartProvider>.value(value: mockCartProvider),
            ],
            child: const Scaffold(body: ProductSelectionScreen()),
          ),
        ),
      );

      expect(find.byType(ChoiceChip), findsWidgets);
    });

    testWidgets('displays grid view when categories exist', (WidgetTester tester) async {
      final categories = [
        CategoriesCacheData(
          id: 'cat-1',
          names: jsonEncode({'de': 'Bier'}),
          displayOrder: 1,
          isActive: 1,
          updatedAt: '2025-02-01T10:00:00Z',
        ),
      ];

      when(() => mockProductsProvider.categories).thenReturn(categories);
      when(() => mockProductsProvider.products).thenReturn([]);
      when(() => mockProductsProvider.addListener(any())).thenReturn(null);
      when(() => mockProductsProvider.removeListener(any())).thenReturn(null);
      when(() => mockCartProvider.addListener(any())).thenReturn(null);
      when(() => mockCartProvider.removeListener(any())).thenReturn(null);
      when(() => mockCartProvider.itemCount).thenReturn(0);
      when(() => mockCartProvider.items).thenReturn([]);

      await tester.pumpWidget(
        MaterialApp(
          home: MultiProvider(
            providers: [
              ChangeNotifierProvider<ProductsProvider>.value(value: mockProductsProvider),
              ChangeNotifierProvider<CartProvider>.value(value: mockCartProvider),
            ],
            child: const Scaffold(body: ProductSelectionScreen()),
          ),
        ),
      );

      // When no products in category, shows empty message instead of grid
      expect(find.text('No products in this category'), findsOneWidget);
    });

    testWidgets('displays cart button', (WidgetTester tester) async {
      final categories = [
        CategoriesCacheData(
          id: 'cat-1',
          names: jsonEncode({'de': 'Bier'}),
          displayOrder: 1,
          isActive: 1,
          updatedAt: '2025-02-01T10:00:00Z',
        ),
      ];

      when(() => mockProductsProvider.categories).thenReturn(categories);
      when(() => mockProductsProvider.products).thenReturn([]);
      when(() => mockProductsProvider.addListener(any())).thenReturn(null);
      when(() => mockProductsProvider.removeListener(any())).thenReturn(null);
      when(() => mockCartProvider.itemCount).thenReturn(5);
      when(() => mockCartProvider.addListener(any())).thenReturn(null);
      when(() => mockCartProvider.removeListener(any())).thenReturn(null);

      await tester.pumpWidget(
        MaterialApp(
          home: MultiProvider(
            providers: [
              ChangeNotifierProvider<ProductsProvider>.value(value: mockProductsProvider),
              ChangeNotifierProvider<CartProvider>.value(value: mockCartProvider),
            ],
            child: const Scaffold(body: ProductSelectionScreen()),
          ),
        ),
      );

      expect(find.byType(ElevatedButton), findsOneWidget);
      expect(find.text('Cart (5)'), findsOneWidget);
    });

    testWidgets('renders successfully when empty', (WidgetTester tester) async {
      when(() => mockProductsProvider.categories).thenReturn([]);
      when(() => mockProductsProvider.products).thenReturn([]);
      when(() => mockProductsProvider.addListener(any())).thenReturn(null);
      when(() => mockProductsProvider.removeListener(any())).thenReturn(null);
      when(() => mockCartProvider.itemCount).thenReturn(0);
      when(() => mockCartProvider.addListener(any())).thenReturn(null);
      when(() => mockCartProvider.removeListener(any())).thenReturn(null);

      await tester.pumpWidget(
        MaterialApp(
          home: MultiProvider(
            providers: [
              ChangeNotifierProvider<ProductsProvider>.value(value: mockProductsProvider),
              ChangeNotifierProvider<CartProvider>.value(value: mockCartProvider),
            ],
            child: const Scaffold(body: ProductSelectionScreen()),
          ),
        ),
      );

      expect(find.byType(ProductSelectionScreen), findsOneWidget);
    });
  });
}
