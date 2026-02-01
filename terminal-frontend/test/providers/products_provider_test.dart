import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'dart:convert';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/providers/products_provider.dart';
import 'package:ruderbar_terminal/services/products_service.dart';

class MockProductsService extends Mock implements ProductsService {}

void main() {
  group('ProductsProvider', () {
    late MockProductsService mockService;
    late ProductsProvider provider;

    setUp(() {
      mockService = MockProductsService();
      provider = ProductsProvider(service: mockService);
    });

    test('initial state is empty', () {
      expect(provider.categories, isEmpty);
      expect(provider.products, isEmpty);
      expect(provider.isLoading, isFalse);
      expect(provider.isSyncing, isFalse);
      expect(provider.lastError, isNull);
    });

    test('refreshProducts updates categories and products', () async {
      final category = CategoriesCacheData(
        id: 'cat-1',
        names: jsonEncode({'de': 'Getränke', 'en': 'Drinks'}),
        displayOrder: 1,
        isActive: 1,
        updatedAt: DateTime.now().toIso8601String(),
      );
      final product = ProductsCacheData(
        id: 'prod-1',
        categoryId: 'cat-1',
        names: jsonEncode({'de': 'Bier', 'en': 'Beer'}),
        descriptions: null,
        priceCents: 500,
        isActive: 1,
        updatedAt: DateTime.now().toIso8601String(),
      );

      when(() => mockService.getActiveCategoriesWithProducts())
          .thenAnswer((_) async => [(category, [product])]);

      await provider.refreshProducts();

      expect(provider.categories, equals([category]));
      expect(provider.products, equals([product]));
      expect(provider.lastError, isNull);
    });

    test('refreshProducts sets isSyncing during operation', () async {
      when(() => mockService.getActiveCategoriesWithProducts())
          .thenAnswer((_) async {
        expect(provider.isSyncing, isTrue);
        return [];
      });

      await provider.refreshProducts();

      expect(provider.isSyncing, isFalse);
    });

    test('refreshProducts handles error', () async {
      when(() => mockService.getActiveCategoriesWithProducts())
          .thenThrow(Exception('Service error'));

      await provider.refreshProducts();

      expect(provider.lastError, contains('refresh'));
      expect(provider.isSyncing, isFalse);
    });

    test('getTranslatedName delegates to service', () {
      final product = ProductsCacheData(
        id: 'prod-1',
        categoryId: 'cat-1',
        names: jsonEncode({'de': 'Bier', 'en': 'Beer'}),
        descriptions: null,
        priceCents: 500,
        isActive: 1,
        updatedAt: DateTime.now().toIso8601String(),
      );

      when(() => mockService.getTranslatedName(product, 'en'))
          .thenReturn('Beer');

      final result = provider.getTranslatedName(product, 'en');

      expect(result, equals('Beer'));
      verify(() => mockService.getTranslatedName(product, 'en')).called(1);
    });

    test('clearCache empties products and categories', () async {
      final category = CategoriesCacheData(
        id: 'cat-1',
        names: jsonEncode({'de': 'Getränke'}),
        displayOrder: 1,
        isActive: 1,
        updatedAt: DateTime.now().toIso8601String(),
      );
      final product = ProductsCacheData(
        id: 'prod-1',
        categoryId: 'cat-1',
        names: jsonEncode({'de': 'Bier'}),
        descriptions: null,
        priceCents: 500,
        isActive: 1,
        updatedAt: DateTime.now().toIso8601String(),
      );

      when(() => mockService.getActiveCategoriesWithProducts())
          .thenAnswer((_) async => [(category, [product])]);

      await provider.refreshProducts();
      expect(provider.products, isNotEmpty);

      await provider.clearCache();

      expect(provider.categories, isEmpty);
      expect(provider.products, isEmpty);
    });
  });
}
