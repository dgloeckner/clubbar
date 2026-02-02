import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';

/// Get product icon as SVG widget
/// Returns SvgPicture with optional color filtering
Widget getProductIcon(
  String? productName, {
  double size = 64,
  Color? color,
}) {
  final iconPath = _getProductIconPath(productName);

  return SvgPicture.asset(
    iconPath,
    width: size,
    height: size,
    colorFilter: color != null
        ? ColorFilter.mode(color, BlendMode.srcIn)
        : null,
    placeholderBuilder: (BuildContext context) {
      return SizedBox(
        width: size,
        height: size,
        child: const Icon(Icons.shopping_bag_outlined),
      );
    },
  );
}

/// Get category icon as SVG widget
/// Returns SvgPicture with optional color filtering
Widget getCategoryIcon(
  String? categoryName, {
  double size = 40,
  Color? color,
}) {
  final iconPath = _getCategoryIconPath(categoryName);

  return SvgPicture.asset(
    iconPath,
    width: size,
    height: size,
    colorFilter: color != null
        ? ColorFilter.mode(color, BlendMode.srcIn)
        : null,
    placeholderBuilder: (BuildContext context) {
      return SizedBox(
        width: size,
        height: size,
        child: const Icon(Icons.category),
      );
    },
  );
}

/// Map product name to SVG asset path
String _getProductIconPath(String? productName) {
  switch (productName?.toLowerCase()) {
    case 'pils':
      return 'assets/icons/products/pils_icon.svg';
    case 'weizen':
      return 'assets/icons/products/weizen_icon.svg';
    case 'beeralf':
    case 'beer_af':
    case 'alcohol_free_beer':
      return 'assets/icons/products/beer_af_icon.svg';
    case 'radler':
      return 'assets/icons/products/radler_icon.svg';
    case 'lemonade':
      return 'assets/icons/products/lemonade_icon.svg';
    case 'applejuice':
    case 'apple_juice':
      return 'assets/icons/products/apple_juice_icon.svg';
    case 'appler':
    case 'apple_cider':
      return 'assets/icons/products/appler_icon.svg';
    case 'waterlarge':
    case 'water_large':
      return 'assets/icons/products/water_large_icon.svg';
    case 'watersmall':
    case 'water_small':
      return 'assets/icons/products/water_small_icon.svg';
    case 'saunatoken':
    case 'sauna_token':
      return 'assets/icons/products/sauna_token_icon.svg';
    case 'saunathermometer':
    case 'sauna_thermometer':
      return 'assets/icons/products/sauna_thermometer_icon.svg';
    case 'saunatime':
    case 'sauna_time':
      return 'assets/icons/products/sauna_time_icon.svg';
    case 'saunacabin':
    case 'sauna_cabin':
      return 'assets/icons/products/sauna_cabin_icon.svg';
    default:
      return 'assets/icons/products/pils_icon.svg'; // Safe fallback
  }
}

/// Map category name to SVG asset path
String _getCategoryIconPath(String? categoryName) {
  switch (categoryName?.toLowerCase()) {
    case 'tags':
    case 'category_tags':
      return 'assets/icons/categories/category_tags_icon.svg';
    case 'layers':
    case 'category_layers':
      return 'assets/icons/categories/category_layers_icon.svg';
    case 'folder':
    case 'category_folder':
      return 'assets/icons/categories/category_folder_icon.svg';
    case 'list':
    case 'category_list':
      return 'assets/icons/categories/category_list_icon.svg';
    default:
      return 'assets/icons/categories/category_icon.svg'; // Safe fallback
  }
}
