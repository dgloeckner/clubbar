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
    default:
      return 'assets/icons/products/pils_icon.svg'; // Fallback
  }
}

/// Map backend category icon name to SVG asset path.
/// Categories use the same universal product icon set.
String _getCategoryIconPath(String? iconName) {
  return _getProductIconPath(iconName);
}
