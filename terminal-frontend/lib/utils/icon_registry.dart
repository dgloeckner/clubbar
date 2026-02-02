import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';

/// Get product icon as SVG widget from backend icon name enum value
/// Maps backend icon name (e.g., "PilsIcon") to SVG asset
Widget getProductIcon(
  String? iconName, {
  double size = 64,
}) {
  final iconPath = _getProductIconPath(iconName);

  return SvgPicture.asset(
    iconPath,
    width: size,
    height: size,
    placeholderBuilder: (BuildContext context) {
      return SizedBox(
        width: size,
        height: size,
        child: const Icon(Icons.shopping_bag_outlined),
      );
    },
  );
}

/// Get category icon as SVG widget from backend icon name enum value
/// Maps backend icon name (e.g., "CategoryIcon") to SVG asset
Widget getCategoryIcon(
  String? iconName, {
  double size = 40,
}) {
  final iconPath = _getCategoryIconPath(iconName);

  return SvgPicture.asset(
    iconPath,
    width: size,
    height: size,
    placeholderBuilder: (BuildContext context) {
      return SizedBox(
        width: size,
        height: size,
        child: const Icon(Icons.category),
      );
    },
  );
}

/// Map backend product icon enum name to SVG asset path
/// Backend enum values: PilsIcon, WeizenIcon, BeerAFIcon, RadlerIcon, LemonadeIcon,
/// AppleJuiceIcon, ApplerIcon, WaterLargeIcon, WaterSmallIcon, SaunaTokenIcon,
/// SaunaThermometerIcon, SaunaTimeIcon, SaunaCabinIcon
String _getProductIconPath(String? iconName) {
  switch (iconName) {
    case 'PilsIcon':
      return 'assets/icons/products/pils_icon.svg';
    case 'WeizenIcon':
      return 'assets/icons/products/weizen_icon.svg';
    case 'BeerAFIcon':
      return 'assets/icons/products/beerAF_icon.svg';
    case 'RadlerIcon':
      return 'assets/icons/products/radler_icon.svg';
    case 'LemonadeIcon':
      return 'assets/icons/products/lemonade_icon.svg';
    case 'AppleJuiceIcon':
      return 'assets/icons/products/apple_juice_icon.svg';
    case 'ApplerIcon':
      return 'assets/icons/products/appler_icon.svg';
    case 'WaterLargeIcon':
      return 'assets/icons/products/water_large_icon.svg';
    case 'WaterSmallIcon':
      return 'assets/icons/products/water_small_icon.svg';
    case 'SaunaTokenIcon':
      return 'assets/icons/products/sauna_token_icon.svg';
    case 'SaunaThermometerIcon':
      return 'assets/icons/products/sauna_thermometer_icon.svg';
    case 'SaunaTimeIcon':
      return 'assets/icons/products/sauna_time_icon.svg';
    case 'SaunaCabinIcon':
      return 'assets/icons/products/sauna_cabin_icon.svg';
    default:
      return 'assets/icons/products/pils_icon.svg'; // Fallback
  }
}

/// Map backend category icon enum name to SVG asset path
/// Backend enum values: CategoryIcon, CategoryTagsIcon, CategoryLayersIcon,
/// CategoryFolderIcon, CategoryListIcon, CategoryDrinksIcon, CategorySaunaIcon
String _getCategoryIconPath(String? iconName) {
  switch (iconName) {
    case 'CategoryIcon':
      return 'assets/icons/categories/category_icon.svg';
    case 'CategoryTagsIcon':
      return 'assets/icons/categories/category_tags_icon.svg';
    case 'CategoryLayersIcon':
      return 'assets/icons/categories/category_layers_icon.svg';
    case 'CategoryFolderIcon':
      return 'assets/icons/categories/category_folder_icon.svg';
    case 'CategoryListIcon':
      return 'assets/icons/categories/category_list_icon.svg';
    // Category-specific icons using product icon SVGs
    case 'CategoryDrinksIcon':
      return 'assets/icons/products/pils_icon.svg';
    case 'CategorySaunaIcon':
      return 'assets/icons/products/sauna_token_icon.svg';
    default:
      return 'assets/icons/categories/category_icon.svg'; // Fallback
  }
}
