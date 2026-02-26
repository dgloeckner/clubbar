import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'dart:convert';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/providers/products_provider.dart';
import 'package:ruderbar_terminal/services/products_service.dart';
import 'package:ruderbar_terminal/services/config_service.dart';

class MockProductsService extends Mock implements ProductsService {}
class MockConfigService extends Mock implements ConfigService {}

void main() {
  group('ProductsProvider', () {
    late MockProductsService mockService;
    late MockConfigService mockConfig;
    late ProductsProvider provider;

    setUp(() {
      mockService = MockProductsService();
      mockConfig = MockConfigService();
      when(() => mockConfig.dispenserEnabled).thenReturn(false);
      provider = ProductsProvider(
        service: mockService,
        config: mockConfig,
      );
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
        requiresDispenser: 0,
        iconName: null,
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
        requiresDispenser: 0,
        iconName: null,
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
        requiresDispenser: 0,
        iconName: null,
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

  group('ProductsProvider - Dispenser Filtering', () {
    late MockProductsService mockService;
    late MockConfigService mockConfig;
    late ProductsProvider provider;

    setUp(() {
      mockService = MockProductsService();
      mockConfig = MockConfigService();
      provider = ProductsProvider(
        service: mockService,
        config: mockConfig,
      );
    });

    test('hides dispenser products when dispenser disabled', () async {
      // Given: dispenser is disabled
      when(() => mockConfig.dispenserEnabled).thenReturn(false);

      // Given: two products - one regular, one requiring dispenser
      final regularProduct = ProductsCacheData(
        id: 'prod-1',
        categoryId: 'cat-1',
        names: jsonEncode({'de': 'Bier', 'en': 'Beer'}),
        descriptions: null,
        priceCents: 350,
        isActive: 1,
        requiresDispenser: 0,
        iconName: 'PilsIcon',
        updatedAt: '2025-02-01T10:00:00Z',
      );

      final dispenserProduct = ProductsCacheData(
        id: 'prod-2',
        categoryId: 'cat-1',
        names: jsonEncode({'de': 'Sauna-Token', 'en': 'Sauna Token'}),
        descriptions: null,
        priceCents: 200,
        isActive: 1,
        requiresDispenser: 1,
        iconName: 'SaunaTokenIcon',
        updatedAt: '2025-02-01T10:00:00Z',
      );

      final category = CategoriesCacheData(
        id: 'cat-1',
        names: jsonEncode({'de': 'Getränke', 'en': 'Drinks'}),
        isActive: 1,
        updatedAt: '2025-02-01T10:00:00Z',
      );

      // Load products into provider
      when(() => mockService.getActiveCategoriesWithProducts())
          .thenAnswer((_) async => [(category, [regularProduct, dispenserProduct])]);

      await provider.refreshProducts();

      // When: getting visible products for the category
      final visibleProducts = provider.getVisibleProducts('cat-1');

      // Then: only the regular product should be visible
      expect(visibleProducts, hasLength(1));
      expect(visibleProducts.first.id, equals('prod-1'));
      expect(visibleProducts.first.requiresDispenser, equals(0));
    });

    test('shows dispenser products when dispenser enabled', () async {
      // Given: dispenser is enabled
      when(() => mockConfig.dispenserEnabled).thenReturn(true);

      // Given: two products - one regular, one requiring dispenser
      final regularProduct = ProductsCacheData(
        id: 'prod-1',
        categoryId: 'cat-1',
        names: jsonEncode({'de': 'Bier', 'en': 'Beer'}),
        descriptions: null,
        priceCents: 350,
        isActive: 1,
        requiresDispenser: 0,
        iconName: 'PilsIcon',
        updatedAt: '2025-02-01T10:00:00Z',
      );

      final dispenserProduct = ProductsCacheData(
        id: 'prod-2',
        categoryId: 'cat-1',
        names: jsonEncode({'de': 'Sauna-Token', 'en': 'Sauna Token'}),
        descriptions: null,
        priceCents: 200,
        isActive: 1,
        requiresDispenser: 1,
        iconName: 'SaunaTokenIcon',
        updatedAt: '2025-02-01T10:00:00Z',
      );

      final category = CategoriesCacheData(
        id: 'cat-1',
        names: jsonEncode({'de': 'Getränke', 'en': 'Drinks'}),
        isActive: 1,
        updatedAt: '2025-02-01T10:00:00Z',
      );

      // Load products into provider
      when(() => mockService.getActiveCategoriesWithProducts())
          .thenAnswer((_) async => [(category, [regularProduct, dispenserProduct])]);

      await provider.refreshProducts();

      // When: getting visible products for the category
      final visibleProducts = provider.getVisibleProducts('cat-1');

      // Then: both products should be visible
      expect(visibleProducts, hasLength(2));
      expect(visibleProducts.map((p) => p.id), containsAll(['prod-1', 'prod-2']));
    });

    test('filters out inactive products regardless of dispenser config', () async {
      // Given: dispenser is enabled
      when(() => mockConfig.dispenserEnabled).thenReturn(true);

      // Given: products with different active states
      final activeProduct = ProductsCacheData(
        id: 'prod-1',
        categoryId: 'cat-1',
        names: jsonEncode({'de': 'Bier', 'en': 'Beer'}),
        descriptions: null,
        priceCents: 350,
        isActive: 1,
        requiresDispenser: 0,
        iconName: 'PilsIcon',
        updatedAt: '2025-02-01T10:00:00Z',
      );

      final inactiveProduct = ProductsCacheData(
        id: 'prod-2',
        categoryId: 'cat-1',
        names: jsonEncode({'de': 'Old Product', 'en': 'Old Product'}),
        descriptions: null,
        priceCents: 200,
        isActive: 0,
        requiresDispenser: 0,
        iconName: null,
        updatedAt: '2025-02-01T10:00:00Z',
      );

      final category = CategoriesCacheData(
        id: 'cat-1',
        names: jsonEncode({'de': 'Getränke', 'en': 'Drinks'}),
        isActive: 1,
        updatedAt: '2025-02-01T10:00:00Z',
      );

      // Load products into provider
      when(() => mockService.getActiveCategoriesWithProducts())
          .thenAnswer((_) async => [(category, [activeProduct, inactiveProduct])]);

      await provider.refreshProducts();

      // When: getting visible products for the category
      final visibleProducts = provider.getVisibleProducts('cat-1');

      // Then: only active products should be visible
      expect(visibleProducts, hasLength(1));
      expect(visibleProducts.first.id, equals('prod-1'));
      expect(visibleProducts.first.isActive, equals(1));
    });
  });
}
