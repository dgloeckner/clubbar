import 'dart:convert';

class ProductDTO {
  final String id;
  final String categoryId;
  final Map<String, String> names;
  final Map<String, String>? descriptions;
  final int priceCents;
  final bool isActive;
  final bool requiresDispenser;
  final String? iconName;
  final String updatedAt;
  final String? deletedAt; // Soft deletion timestamp

  ProductDTO({
    required this.id,
    required this.categoryId,
    required this.names,
    this.descriptions,
    required this.priceCents,
    required this.isActive,
    this.requiresDispenser = false,
    this.iconName,
    required this.updatedAt,
    this.deletedAt,
  });

  /// Returns true if product has been deleted
  bool get isDeleted => deletedAt != null;

  factory ProductDTO.fromJson(Map<String, dynamic> json) {
    // names may be a JSON string (from SQLite) or a Map (from API)
    final rawNames = json['names'];
    final names = rawNames is String
        ? Map<String, String>.from(jsonDecode(rawNames) as Map)
        : Map<String, String>.from(rawNames as Map);

    // descriptions may be a JSON string, a Map, a List (empty []), or null
    final rawDesc = json['descriptions'];
    Map<String, String>? descriptions;
    if (rawDesc is String) {
      descriptions = Map<String, String>.from(jsonDecode(rawDesc) as Map);
    } else if (rawDesc is Map && rawDesc.isNotEmpty) {
      descriptions = Map<String, String>.from(rawDesc);
    }

    // is_active may be bool (from API) or int (from SQLite)
    final rawActive = json['is_active'];
    final isActive = rawActive is bool ? rawActive : (rawActive as int?) == 1;

    // requires_dispenser may be bool (from API) or int (from SQLite), or missing (defaults to false)
    final rawRequiresDispenser = json['requires_dispenser'];
    final requiresDispenser = rawRequiresDispenser == null
        ? false
        : (rawRequiresDispenser is bool
            ? rawRequiresDispenser
            : (rawRequiresDispenser as int) == 1);

    return ProductDTO(
      id: json['id'] as String,
      categoryId: json['category_id'] as String,
      names: names,
      descriptions: descriptions,
      priceCents: json['price_cents'] as int,
      isActive: isActive,
      requiresDispenser: requiresDispenser,
      iconName: json['icon_name'] as String?,
      updatedAt: json['updated_at'] as String,
      deletedAt: json['deleted_at'] as String?,
    );
  }

  Map<String, dynamic> toJson() => {
    'id': id,
    'category_id': categoryId,
    'names': jsonEncode(names),
    'descriptions': descriptions != null ? jsonEncode(descriptions) : null,
    'price_cents': priceCents,
    'is_active': isActive ? 1 : 0,
    'requires_dispenser': requiresDispenser ? 1 : 0,
    'icon_name': iconName,
    'updated_at': updatedAt,
    'deleted_at': deletedAt,
  };
}
