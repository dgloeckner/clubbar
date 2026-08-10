  import 'package:flutter/material.dart';
  import 'package:flutter_svg/flutter_svg.dart';

  import 'app_logger.dart';

  /// Get product icon as SVG widget from backend icon name enum value
  /// Maps backend icon name (e.g., "PilsIcon") to SVG asset
  Widget getProductIcon(
    String? iconName, {
    double size = 64,
  }) {
    final iconPath = _getProductIconPath(iconName);

    if (iconPath == null) {
      return SizedBox(
        width: size,
        height: size,
        child: Icon(Icons.shopping_bag_outlined, size: size),
      );
    }

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

    if (iconPath == null) {
      return SizedBox(
        width: size,
        height: size,
        child: Icon(Icons.category, size: size),
      );
    }

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

  /// Map backend product icon name to SVG asset path
  /// Supports canonical icon names from docs/icon-registry.md (e.g., "beer-pils", "coffee")
  /// and legacy names for backward compatibility (e.g., "PilsIcon", "CoffeeMugIcon")
  /// Returns null for unrecognized names so callers can fall back to a
  /// neutral placeholder instead of silently rendering a beer glass.
  String? _getProductIconPath(String? iconName) {
    switch (iconName) {
      // === CANONICAL NAMES (from docs/icon-registry.md) ===
      // Beverages - Beer
      case 'beer-pils':
        return 'assets/icons/products/pils_icon.svg';
      case 'beer-weizen':
        return 'assets/icons/products/weizen_icon.svg';
      case 'beer-radler':
        return 'assets/icons/products/radler_icon.svg';
      case 'beer-alcohol-free':
        return 'assets/icons/products/beerAF_icon.svg';
      case 'beer-weizen-new':
        return 'assets/icons/products/weizen_new_icon.svg';

      // Beverages - Cider & Spritzers
      case 'cider-apfelwein':
        return 'assets/icons/products/bembel_icon.svg';
      case 'cider-appler':
        return 'assets/icons/products/appler_icon.svg';
      case 'spritzer-apple':
        return 'assets/icons/products/apfelschorle_icon.svg';

      // Beverages - Hot Drinks
      case 'coffee':
        return 'assets/icons/products/coffee_mug_icon.svg';

      // Beverages - Water & Soft Drinks
      case 'water':
      case 'water-large':
        return 'assets/icons/products/water_large_icon.svg';
      case 'water-small':
        return 'assets/icons/products/water_small_icon.svg';
      case 'soda':
        return 'assets/icons/products/lemonade_icon.svg';
      case 'soda-lemonade':
        return 'assets/icons/products/lemonade_icon.svg';
      case 'soda-limonade':
        return 'assets/icons/products/limonade_icon.svg';

      // Beverages - Juices
      case 'juice-apple':
        return 'assets/icons/products/apple_juice_icon.svg';
      case 'juice-orange':
        return 'assets/icons/products/orangensaft_icon.svg';

      // Beverages - Wine
      case 'wine-red':
        return 'assets/icons/products/rotwein_icon.svg';
      case 'wine-white':
        return 'assets/icons/products/weisswein_icon.svg';

      // Food
      case 'food-bratwurst':
        return 'assets/icons/products/bratwurst_icon.svg';
      case 'food-hamburger':
        return 'assets/icons/products/hamburger_icon.svg';
      case 'food-fish-sandwich':
        return 'assets/icons/products/fish_sandwich_icon.svg';
      case 'food-crisps':
        return 'assets/icons/products/crisps_icon.svg';
      case 'food-fries':
        return 'assets/icons/products/fries_icon.svg';
      case 'food-bretzel':
        return 'assets/icons/products/bretzel_icon.svg';
      case 'food-crackers':
        return 'assets/icons/products/crackers_icon.svg';
      case 'food-steak':
        return 'assets/icons/products/steak_icon.svg';
      case 'food-salad':
        return 'assets/icons/products/salad_icon.svg';

      // Services - Sauna
      case 'sauna-session':
        return 'assets/icons/products/sauna_time_icon.svg';
      case 'sauna-cabin':
        return 'assets/icons/products/sauna_cabin_icon.svg';
      case 'sauna-infusion':
        return 'assets/icons/products/sauna_aufguss_icon.svg';
      case 'sauna-towel':
        return 'assets/icons/products/sauna_towel_icon.svg';
      case 'sauna-token':
        return 'assets/icons/products/sauna_token_icon.svg';
      case 'sauna-thermometer':
        return 'assets/icons/products/sauna_thermometer_icon.svg';
      case 'sauna-ice':
        return 'assets/icons/products/sauna_ice_icon.svg';
      case 'sauna-shower':
        return 'assets/icons/products/sauna_shower_icon.svg';
      case 'sauna-wellness':
        return 'assets/icons/products/sauna_wellness_icon.svg';
      case 'sauna-whisk':
        return 'assets/icons/products/sauna_whisk_icon.svg';

      // === LEGACY NAMES (for backward compatibility) ===
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
      case 'WeizenNewIcon':
        return 'assets/icons/products/weizen_new_icon.svg';
      case 'LimonadeIcon':
        return 'assets/icons/products/limonade_icon.svg';
      case 'ApfelschorleIcon':
        return 'assets/icons/products/apfelschorle_icon.svg';
      case 'OrangensaftIcon':
        return 'assets/icons/products/orangensaft_icon.svg';
      case 'BembelIcon':
        return 'assets/icons/products/bembel_icon.svg';
      case 'CoffeeMugIcon':
        return 'assets/icons/products/coffee_mug_icon.svg';
      case 'RotweinIcon':
        return 'assets/icons/products/rotwein_icon.svg';
      case 'WeissweinIcon':
        return 'assets/icons/products/weisswein_icon.svg';
      case 'SaunaAufgussIcon':
        return 'assets/icons/products/sauna_aufguss_icon.svg';
      case 'SaunaIceIcon':
        return 'assets/icons/products/sauna_ice_icon.svg';
      case 'SaunaShowerIcon':
        return 'assets/icons/products/sauna_shower_icon.svg';
      case 'SaunaTowelIcon':
        return 'assets/icons/products/sauna_towel_icon.svg';
      case 'SaunaWellnessIcon':
        return 'assets/icons/products/sauna_wellness_icon.svg';
      case 'SaunaWhiskIcon':
        return 'assets/icons/products/sauna_whisk_icon.svg';
      case 'BratwurstIcon':
        return 'assets/icons/products/bratwurst_icon.svg';
      case 'HamburgerIcon':
        return 'assets/icons/products/hamburger_icon.svg';
      case 'FishSandwichIcon':
        return 'assets/icons/products/fish_sandwich_icon.svg';
      case 'CrispsIcon':
        return 'assets/icons/products/crisps_icon.svg';
      case 'FriesIcon':
        return 'assets/icons/products/fries_icon.svg';
      case 'BretzelIcon':
        return 'assets/icons/products/bretzel_icon.svg';
      case 'CrackersIcon':
        return 'assets/icons/products/crackers_icon.svg';
      case 'SteakIcon':
        return 'assets/icons/products/steak_icon.svg';
      case 'SaladIcon':
        return 'assets/icons/products/salad_icon.svg';
      default:
        AppLog.instance.w('Unknown product icon name: $iconName');
        return null;
    }
  }

  /// Map backend category icon name to SVG asset path.
  ///
  /// Canonical `category-*` names (documented in docs/icon-registry.md)
  /// resolve to the dedicated category assets first (#299) — a category chip
  /// used to be able to wear only a product's icon, so e.g. Sauna wore a
  /// "sauna token" coin that read as *coin*, not *sauna*. Anything else falls
  /// through to the product icon set so existing product-icon-on-category
  /// deployments keep working.
  String? _getCategoryIconPath(String? iconName) {
    switch (iconName) {
      case 'category-folder':
        return 'assets/icons/categories/category_folder_icon.svg';
      case 'category-tags':
        return 'assets/icons/categories/category_tags_icon.svg';
      case 'category-layers':
        return 'assets/icons/categories/category_layers_icon.svg';
      case 'category-list':
        return 'assets/icons/categories/category_list_icon.svg';
      case 'category-generic':
        return 'assets/icons/categories/category_icon.svg';
      default:
        return _getProductIconPath(iconName);
    }
  }
