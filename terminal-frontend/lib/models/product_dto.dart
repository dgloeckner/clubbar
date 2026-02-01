import 'dart:convert';

class ProductDTO {
  final String id;
  final String categoryId;
  final Map<String, String> names;
  final Map<String, String>? descriptions;
  final int priceCents;
  final bool isActive;
  final String updatedAt;

  ProductDTO({
    required this.id,
    required this.categoryId,
    required this.names,
    this.descriptions,
    required this.priceCents,
    required this.isActive,
    required this.updatedAt,
  });

  factory ProductDTO.fromJson(Map<String, dynamic> json) {
    return ProductDTO(
      id: json['id'] as String,
      categoryId: json['category_id'] as String,
      names: Map<String, String>.from(jsonDecode(json['names'] as String) as Map),
      descriptions: json['descriptions'] != null
        ? Map<String, String>.from(jsonDecode(json['descriptions'] as String) as Map)
        : null,
      priceCents: json['price_cents'] as int,
      isActive: (json['is_active'] as int?) == 1,
      updatedAt: json['updated_at'] as String,
    );
  }

  Map<String, dynamic> toJson() => {
    'id': id,
    'category_id': categoryId,
    'names': jsonEncode(names),
    'descriptions': descriptions != null ? jsonEncode(descriptions) : null,
    'price_cents': priceCents,
    'is_active': isActive ? 1 : 0,
    'updated_at': updatedAt,
  };
}
