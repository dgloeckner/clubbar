import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/repository/products_repository.dart';
import 'package:ruderbar_terminal/services/products_service.dart';

class MockProductsRepository extends Mock implements ProductsRepository {}

/// Helper that builds a ProductsCacheData with only the fields relevant to
/// translation (names JSON) and minimum required fields for the constructor.
ProductsCacheData _makeProduct(String id, Map<String, String> names) {
  return ProductsCacheData(
    id: id,
    categoryId: 'cat-1',
    names: jsonEncode(names),
    descriptions: null,
    priceCents: 100,
    isActive: 1,
    requiresDispenser: 0,
    iconName: null,
    updatedAt: '2024-01-01T00:00:00Z',
  );
}

/// Applies the same sort comparator used in ProductSelectionScreen._buildProductGrid
List<ProductsCacheData> _sortProducts(
    List<ProductsCacheData> products, ProductsService service, String lang) {
  final sorted = List<ProductsCacheData>.from(products)
    ..sort((a, b) {
      final nameA = service.getTranslatedName(a, lang).toLowerCase();
      final nameB = service.getTranslatedName(b, lang).toLowerCase();
      return nameA.compareTo(nameB);
    });
  return sorted;
}

void main() {
  late MockProductsRepository mockRepo;
  late ProductsService service;

  setUp(() {
    mockRepo = MockProductsRepository();
    service = ProductsService(repository: mockRepo);
  });

  group('Product sorting (screen comparator via ProductsService.getTranslatedName)', () {
    // Three products whose German and English names produce different sort orders
    // to confirm that the correct language is used during comparison.
    final bier = _makeProduct('p1', {'de': 'Bier', 'en': 'Beer'});
    final apfelsaft = _makeProduct('p2', {'de': 'Apfelsaft', 'en': 'Apple juice'});
    final cola = _makeProduct('p3', {'de': 'Cola', 'en': 'Cola'});

    // Input list is intentionally unsorted.
    final unsorted = [bier, apfelsaft, cola];

    test('sorts alphabetically by German name (de)', () {
      final sorted = _sortProducts(unsorted, service, 'de');

      expect(
        sorted.map((p) => service.getTranslatedName(p, 'de')).toList(),
        ['Apfelsaft', 'Bier', 'Cola'],
      );
    });

    test('sorts alphabetically by English name (en)', () {
      final sorted = _sortProducts(unsorted, service, 'en');

      expect(
        sorted.map((p) => service.getTranslatedName(p, 'en')).toList(),
        ['Apple juice', 'Beer', 'Cola'],
      );
    });

    test('sort is case-insensitive (lowercase name does not sort before uppercase A)', () {
      // Without toLowerCase(), 'bier' (0x62) > 'Apfel' (0x41) > 'Cola' (0x43)
      // would give wrong order [Apfel, Cola, bier].
      // With toLowerCase() it must be [Apfel, bier, Cola].
      final mixedCase = [
        _makeProduct('x1', {'de': 'bier'}),   // lowercase b
        _makeProduct('x2', {'de': 'Apfel'}),  // uppercase A
        _makeProduct('x3', {'de': 'Cola'}),   // uppercase C
      ];

      final sorted = _sortProducts(mixedCase, service, 'de');

      expect(
        sorted.map((p) => service.getTranslatedName(p, 'de')).toList(),
        ['Apfel', 'bier', 'Cola'],
      );
    });

    test('falls back to German name when requested language is missing', () {
      // Products that only have German translations; sort by 'fr' should still
      // use the German fallback in getTranslatedName and produce correct order.
      final deOnly = [
        _makeProduct('y1', {'de': 'Weizen'}),
        _makeProduct('y2', {'de': 'Mineralwasser'}),
      ];

      final sorted = _sortProducts(deOnly, service, 'fr');

      expect(
        sorted.map((p) => service.getTranslatedName(p, 'fr')).toList(),
        ['Mineralwasser', 'Weizen'],
      );
    });
  });
}
