import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/repository/products_repository.dart';
import 'package:clubbar_terminal/services/products_service.dart';

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

void main() {
  late MockProductsRepository mockRepo;
  late ProductsService service;

  setUp(() {
    mockRepo = MockProductsRepository();
    service = ProductsService(repository: mockRepo);
  });

  group('ProductsService.sortForDisplay', () {
    test('orders products alphabetically by their German name', () {
      final unsorted = [
        _makeProduct('p1', {'de': 'Bier', 'en': 'Beer'}),
        _makeProduct('p2', {'de': 'Apfelsaft', 'en': 'Apple juice'}),
        _makeProduct('p3', {'de': 'Cola', 'en': 'Cola'}),
      ];

      final sorted = service.sortForDisplay(unsorted);

      expect(sorted.map((p) => p.id).toList(), ['p2', 'p1', 'p3']);
    });

    test('keeps tile positions identical whatever language the member reads',
        () {
      // Issue #33: sorting by the localized name shuffled the grid on every
      // language switch — 'Bier'/'Beer' sorts before 'Apfelsaft' in English
      // but after it in German, so regulars lost the position they had
      // memorized mid-order.
      final products = [
        _makeProduct('p1', {'de': 'Bier', 'en': 'Beer'}),
        _makeProduct('p2', {'de': 'Apfelsaft', 'en': 'Apple juice'}),
        _makeProduct('p3', {'de': 'Wasser', 'en': 'Water'}),
        _makeProduct('p4', {'de': 'Cola', 'en': 'Coke'}),
      ];

      final order = service.sortForDisplay(products).map((p) => p.id).toList();

      // Re-sorting a list the member sees in a different language must not
      // move anything: the order is a property of the product, not the UI
      // language.
      final reordered = products.reversed.toList();
      expect(
        service.sortForDisplay(reordered).map((p) => p.id).toList(),
        order,
      );
    });

    test('sorts case-insensitively', () {
      final mixedCase = [
        _makeProduct('x1', {'de': 'bier'}), // lowercase b
        _makeProduct('x2', {'de': 'Apfel'}), // uppercase A
        _makeProduct('x3', {'de': 'Cola'}), // uppercase C
      ];

      final sorted = service.sortForDisplay(mixedCase);

      expect(sorted.map((p) => p.id).toList(), ['x2', 'x1', 'x3']);
    });

    test('falls back to a stable order for products without a German name', () {
      // No German name means the sort key is empty for both — they must still
      // come out in the same order every time rather than swapping around.
      final noGerman = [
        _makeProduct('z2', {'en': 'Second'}),
        _makeProduct('z1', {'en': 'First'}),
      ];

      expect(
        service.sortForDisplay(noGerman).map((p) => p.id).toList(),
        ['z1', 'z2'],
      );
    });

    test('leaves the caller\'s list untouched', () {
      final products = [
        _makeProduct('p1', {'de': 'Bier'}),
        _makeProduct('p2', {'de': 'Apfelsaft'}),
      ];

      service.sortForDisplay(products);

      expect(products.map((p) => p.id).toList(), ['p1', 'p2']);
    });
  });
}
