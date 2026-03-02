import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'dart:convert';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/repository/products_repository.dart';
import 'package:clubbar_terminal/services/products_service.dart';

class MockProductsRepository extends Mock implements ProductsRepository {}

void main() {
  group('ProductsService', () {
    late MockProductsRepository mockRepo;
    late ProductsService service;

    setUp(() {
      mockRepo = MockProductsRepository();
      service = ProductsService(repository: mockRepo);
    });

    test('getActiveCategoriesWithProducts returns from repository', () async {
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

      when(() => mockRepo.getActiveCategoriesWithProducts())
          .thenAnswer((_) async => [(category, [product])]);

      final result = await service.getActiveCategoriesWithProducts();

      expect(result, hasLength(1));
      expect(result[0].$1, equals(category));
      expect(result[0].$2, equals([product]));
    });

    test('getProduct returns product by id', () async {
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

      when(() => mockRepo.getProduct('prod-1'))
          .thenAnswer((_) async => product);

      final result = await service.getProduct('prod-1');

      expect(result, equals(product));
    });

    test('getTranslatedName returns name in requested language', () async {
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

      final result = service.getTranslatedName(product, 'en');

      expect(result, equals('Beer'));
    });

    test('getTranslatedName falls back to German if language not available',
        () async {
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

      final result = service.getTranslatedName(product, 'fr');

      expect(result, equals('Bier'));
    });

    test('getTranslatedName returns empty string if no translations available',
        () async {
      final product = ProductsCacheData(
        id: 'prod-1',
        categoryId: 'cat-1',
        names: jsonEncode({}),
        descriptions: null,
        priceCents: 500,
        isActive: 1,
        requiresDispenser: 0,
        iconName: null,
        updatedAt: DateTime.now().toIso8601String(),
      );

      final result = service.getTranslatedName(product, 'en');

      expect(result, equals(''));
    });

    test('refreshProducts returns list from repository', () async {
      when(() => mockRepo.getActiveCategoriesWithProducts())
          .thenAnswer((_) async => []);

      final result = await service.refreshProducts();

      expect(result, equals([]));
    });
  });
}
