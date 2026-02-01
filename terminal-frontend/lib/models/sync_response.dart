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
      members: (json['data'] as List<dynamic>)
          .map((item) => MemberDTO.fromJson(item as Map<String, dynamic>))
          .toList(),
      cursor: json['cursor'] as String?,
    );
  }
}

class ProductsSyncResponse {
  final List<CategoryDTO> categories;
  final List<ProductDTO> products;
  final String? cursor;

  ProductsSyncResponse({
    required this.categories,
    required this.products,
    this.cursor,
  });

  factory ProductsSyncResponse.fromJson(Map<String, dynamic> json) {
    return ProductsSyncResponse(
      categories: (json['categories'] as List<dynamic>)
          .map((item) => CategoryDTO.fromJson(item as Map<String, dynamic>))
          .toList(),
      products: (json['products'] as List<dynamic>)
          .map((item) => ProductDTO.fromJson(item as Map<String, dynamic>))
          .toList(),
      cursor: json['cursor'] as String?,
    );
  }
}
