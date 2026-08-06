import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'dart:convert';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/providers/products_provider.dart';
import 'package:clubbar_terminal/services/products_service.dart';
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/services/dispenser_client.dart';
import 'package:clubbar_terminal/services/dispenser_health_service.dart';

class MockProductsService extends Mock implements ProductsService {}
class MockConfigService extends Mock implements ConfigService {}
class MockDispenserHealthService extends Mock implements DispenserHealthService {}

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

      expect(provider.lastErrorKey, equals(TerminalErrorKey.productsRefreshFailed));
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

  // Issue #31: a configured dispenser can still be jammed or unplugged. The
  // member must learn that at the grid, not at checkout, so availability is a
  // question the provider can answer per product.
  group('ProductsProvider - Dispenser Availability', () {
    late MockProductsService mockService;
    late MockConfigService mockConfig;
    late MockDispenserHealthService mockHealth;

    final tokenProduct = ProductsCacheData(
      id: 'prod-token',
      categoryId: 'cat-1',
      names: jsonEncode({'de': 'Sauna-Token', 'en': 'Sauna Token'}),
      descriptions: null,
      priceCents: 200,
      isActive: 1,
      requiresDispenser: 1,
      iconName: 'SaunaTokenIcon',
      updatedAt: '2025-02-01T10:00:00Z',
    );

    final beer = ProductsCacheData(
      id: 'prod-beer',
      categoryId: 'cat-1',
      names: jsonEncode({'de': 'Bier', 'en': 'Beer'}),
      descriptions: null,
      priceCents: 350,
      isActive: 1,
      requiresDispenser: 0,
      iconName: 'PilsIcon',
      updatedAt: '2025-02-01T10:00:00Z',
    );

    ProductsProvider buildProvider() => ProductsProvider(
          service: mockService,
          config: mockConfig,
          dispenserHealth: mockHealth,
        );

    setUp(() {
      mockService = MockProductsService();
      mockConfig = MockConfigService();
      mockHealth = MockDispenserHealthService();
      when(() => mockConfig.dispenserEnabled).thenReturn(true);
      when(() => mockHealth.addListener(any())).thenReturn(null);
      when(() => mockHealth.removeListener(any())).thenReturn(null);
      when(() => mockHealth.currentHealth).thenReturn(null);
    });

    test('dispenser product is available while the dispenser reports ok', () {
      when(() => mockHealth.currentHealth).thenReturn(
        DispenserHealth(
          status: 'ok',
          dispenser: 'idle',
          totalDispenses: 10,
          successful: 10,
          jams: 0,
          successRate: 1.0,
        ),
      );

      expect(buildProvider().isProductAvailable(tokenProduct), isTrue);
    });

    test('dispenser product is unavailable while the dispenser is offline', () {
      when(() => mockHealth.currentHealth)
          .thenReturn(DispenserHealth.offline());

      expect(buildProvider().isProductAvailable(tokenProduct), isFalse);
    });

    test('dispenser product is unavailable while the dispenser reports error',
        () {
      when(() => mockHealth.currentHealth).thenReturn(
        DispenserHealth(
          status: 'error',
          dispenser: 'error',
          totalDispenses: 10,
          successful: 8,
          jams: 2,
          successRate: 0.8,
        ),
      );

      expect(buildProvider().isProductAvailable(tokenProduct), isFalse);
    });

    test('non-dispenser product stays available while the dispenser is offline',
        () {
      when(() => mockHealth.currentHealth)
          .thenReturn(DispenserHealth.offline());

      expect(buildProvider().isProductAvailable(beer), isTrue);
    });

    test('dispenser product is available before the first health poll returns',
        () {
      when(() => mockHealth.currentHealth).thenReturn(null);

      expect(buildProvider().isProductAvailable(tokenProduct), isTrue);
    });

    test('everything is available when no dispenser health is monitored', () {
      final provider = ProductsProvider(
        service: mockService,
        config: mockConfig,
      );

      expect(provider.isProductAvailable(tokenProduct), isTrue);
      expect(provider.isProductAvailable(beer), isTrue);
    });

    test('a health change notifies listeners so the grid repaints', () {
      late void Function() healthListener;
      when(() => mockHealth.addListener(any())).thenAnswer((invocation) {
        healthListener = invocation.positionalArguments.first as void
            Function();
      });

      final provider = buildProvider();
      var notified = 0;
      provider.addListener(() => notified++);

      when(() => mockHealth.currentHealth)
          .thenReturn(DispenserHealth.offline());
      healthListener();

      expect(notified, equals(1));
    });
  });
}
