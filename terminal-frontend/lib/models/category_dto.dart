import 'dart:convert';

class CategoryDTO {
  final String id;
  final Map<String, String> names; // {"de": "...", "en": "..."}
  final int displayOrder;
  final bool isActive;
  final String updatedAt;

  CategoryDTO({
    required this.id,
    required this.names,
    required this.displayOrder,
    required this.isActive,
    required this.updatedAt,
  });

  factory CategoryDTO.fromJson(Map<String, dynamic> json) {
    return CategoryDTO(
      id: json['id'] as String,
      names: Map<String, String>.from(jsonDecode(json['names'] as String) as Map),
      displayOrder: json['display_order'] as int,
      isActive: (json['is_active'] as int?) == 1,
      updatedAt: json['updated_at'] as String,
    );
  }

  Map<String, dynamic> toJson() => {
    'id': id,
    'names': jsonEncode(names),
    'display_order': displayOrder,
    'is_active': isActive ? 1 : 0,
    'updated_at': updatedAt,
  };
}
