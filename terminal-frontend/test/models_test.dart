import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/models/member_dto.dart';
import 'package:clubbar_terminal/models/category_dto.dart';
import 'package:clubbar_terminal/models/product_dto.dart';

void main() {
  group('DTOs', () {
    test('MemberDTO parses JSON correctly', () {
      final json = {
        'id': '123',
        'card_uid': 'ABC123',
        'first_name': 'Max',
        'last_name': 'Mustermann',
        'preferred_language': 'de',
        'is_active': 1,
        'is_sepa_valid': 1,
        'updated_at': '2025-02-01T10:00:00Z',
      };

      final member = MemberDTO.fromJson(json);
      expect(member.id, equals('123'));
      expect(member.cardUid, equals('ABC123'));
      expect(member.firstName, equals('Max'));
      expect(member.isActive, isTrue);
    });

    test('CategoryDTO parses multilingual names', () {
      final json = {
        'id': '456',
        'names': '{"de":"Getränke","en":"Beverages"}',
        'is_active': 1,
        'updated_at': '2025-02-01T10:00:00Z',
      };

      final category = CategoryDTO.fromJson(json);
      expect(category.id, equals('456'));
      expect(category.names['de'], equals('Getränke'));
      expect(category.names['en'], equals('Beverages'));
    });

    test('ProductDTO parses price in cents', () {
      final json = {
        'id': '789',
        'category_id': '456',
        'names': '{"de":"Pils 0,5L","en":"Beer 0.5L"}',
        'price_cents': 350,
        'is_active': 1,
        'updated_at': '2025-02-01T10:00:00Z',
      };

      final product = ProductDTO.fromJson(json);
      expect(product.id, equals('789'));
      expect(product.priceCents, equals(350));
      expect(product.names['de'], equals('Pils 0,5L'));
    });
  });
}
