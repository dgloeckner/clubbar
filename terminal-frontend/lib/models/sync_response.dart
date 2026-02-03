import 'member_dto.dart';
import 'category_dto.dart';
import 'product_dto.dart';

class MembersSyncResponse {
  final List<MemberDTO> members;
  final String? cursor; // For pagination

  MembersSyncResponse({
    required this.members,
    this.cursor,
  });

  factory MembersSyncResponse.fromJson(Map<String, dynamic> json) {
    return MembersSyncResponse(
      members: (json['members'] as List<dynamic>)
          .map((item) => MemberDTO.fromJson(item as Map<String, dynamic>))
          .toList(),
      cursor: json['cursor'] as String?,
    );
  }
}

class CategoriesSyncResponse {
  final List<CategoryDTO> categories;
  final String? cursor;

  CategoriesSyncResponse({
    required this.categories,
    this.cursor,
  });

  factory CategoriesSyncResponse.fromJson(Map<String, dynamic> json) {
    return CategoriesSyncResponse(
      categories: (json['categories'] as List<dynamic>)
          .map((item) => CategoryDTO.fromJson(item as Map<String, dynamic>))
          .toList(),
      cursor: json['cursor'] as String?,
    );
  }
}

class ProductsSyncResponse {
  final List<ProductDTO> products;
  final String? cursor;

  ProductsSyncResponse({
    required this.products,
    this.cursor,
  });

  factory ProductsSyncResponse.fromJson(Map<String, dynamic> json) {
    return ProductsSyncResponse(
      products: (json['products'] as List<dynamic>)
          .map((item) => ProductDTO.fromJson(item as Map<String, dynamic>))
          .toList(),
      cursor: json['cursor'] as String?,
    );
  }
}
