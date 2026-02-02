import 'package:flutter/material.dart';

/// Type-safe mapping of icon names to Flutter Material icons
/// Mirrors admin-frontend IconRegistry pattern for consistency

/// Product icon names - map to Material icons
enum ProductIconName {
  pilsIcon,
  weizenIcon,
  beerAFIcon,
  radlerIcon,
  lemonadeIcon,
  appleJuiceIcon,
  applerIcon,
  waterLargeIcon,
  waterSmallIcon,
  saunaTokenIcon,
  saunaThermometerIcon,
  saunaTimeIcon,
  saunaCabinIcon,
}

/// Category icon names
enum CategoryIconName {
  categoryIcon,
  categoryTagsIcon,
  categoryLayersIcon,
  categoryFolderIcon,
  categoryListIcon,
}

/// Get product icon by name with fallback to package icon
/// @param iconName - Icon name from database (nullable)
/// @returns IconData for the icon
IconData getProductIcon(String? iconName) {
  if (iconName == null) return Icons.shopping_bag_outlined;

  switch (iconName) {
    // Beverages
    case 'PilsIcon':
      return Icons.local_bar;
    case 'WeizenIcon':
      return Icons.local_drink;
    case 'BeerAFIcon':
      return Icons.local_bar;
    case 'RadlerIcon':
      return Icons.local_drink;
    case 'LemonadeIcon':
      return Icons.local_drink;
    case 'AppleJuiceIcon':
      return Icons.local_drink;
    case 'ApplerIcon':
      return Icons.local_drink;

    // Liquids
    case 'WaterLargeIcon':
      return Icons.water;
    case 'WaterSmallIcon':
      return Icons.water;

    // Sauna
    case 'SaunaTokenIcon':
      return Icons.confirmation_number;
    case 'SaunaThermometerIcon':
      return Icons.thermostat;
    case 'SaunaTimeIcon':
      return Icons.schedule;
    case 'SaunaCabinIcon':
      return Icons.home;

    default:
      return Icons.shopping_bag_outlined;
  }
}

/// Get category icon by name with fallback to default category icon
/// @param iconName - Icon name from database (nullable)
/// @returns IconData for the icon
IconData getCategoryIcon(String? iconName) {
  if (iconName == null) return Icons.category;

  switch (iconName) {
    case 'CategoryIcon':
      return Icons.category;
    case 'CategoryTagsIcon':
      return Icons.local_offer;
    case 'CategoryLayersIcon':
      return Icons.layers;
    case 'CategoryFolderIcon':
      return Icons.folder;
    case 'CategoryListIcon':
      return Icons.list;
    default:
      return Icons.category;
  }
}
