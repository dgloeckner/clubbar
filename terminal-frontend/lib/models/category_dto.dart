import 'dart:convert';

class CategoryDTO {
  final String id;
  final Map<String, String> names; // {"de": "...", "en": "..."}
  final bool isActive;
  final String? iconName;
  final String updatedAt;
  final String? deletedAt; // Soft deletion timestamp

  CategoryDTO({
    required this.id,
    required this.names,
    required this.isActive,
    this.iconName,
    required this.updatedAt,
    this.deletedAt,
  });

  /// Returns true if category has been deleted
  bool get isDeleted => deletedAt != null;

  factory CategoryDTO.fromJson(Map<String, dynamic> json) {
    // names may be a JSON string (from SQLite) or a Map (from API)
    final rawNames = json['names'];
    final names = rawNames is String
        ? Map<String, String>.from(jsonDecode(rawNames) as Map)
        : Map<String, String>.from(rawNames as Map);

    // is_active may be bool (from API) or int (from SQLite)
    final rawActive = json['is_active'];
    final isActive = rawActive is bool ? rawActive : (rawActive as int?) == 1;

    return CategoryDTO(
      id: json['id'] as String,
      names: names,
      isActive: isActive,
      iconName: json['icon_name'] as String?,
      updatedAt: json['updated_at'] as String,
      deletedAt: json['deleted_at'] as String?,
    );
  }

  Map<String, dynamic> toJson() => {
    'id': id,
    'names': jsonEncode(names),
    'is_active': isActive ? 1 : 0,
    'icon_name': iconName,
    'updated_at': updatedAt,
    'deleted_at': deletedAt,
  };
}
