/// Centralized product icon registry
///
/// Maps database icon names (e.g., "PilsIcon", "CoffeeMugIcon") to emoji.
/// Used throughout the app for consistent icon display.
class ProductIcons {
  /// Get emoji for a product icon name
  ///
  /// Returns appropriate emoji based on icon name from database.
  /// Falls back to shopping cart icon if not found.
  /// Returns notepad icon if icon name is null (corrections, manual entries).
  static String getEmoji(String? iconName) {
    if (iconName == null) return '📝';

    final normalized = iconName.toLowerCase();
    return _iconMap[normalized] ?? '🛒';
  }

  /// Icon name to emoji mapping
  ///
  /// Keys are lowercase for case-insensitive matching.
  /// Database stores names like "PilsIcon", "CoffeeMugIcon", etc.
  static const Map<String, String> _iconMap = {
    // Beer varieties
    'pilsicon': '🍺',
    'pils': '🍺',
    'beeraficon': '🍺', // Non-alcoholic beer
    'beer': '🍺',
    'bembelicon': '🍺', // Frankfurt specialty apple cider
    'bembel': '🍺',

    // Beverages
    'coffeemugicon': '☕',
    'coffee': '☕',
    'apfelschorleicon': '🍎', // Apple spritzer
    'apfelschorle': '🍎',
    'watericon': '💧',
    'water': '💧',
    'sodaicon': '🥤',
    'soda': '🥤',
    'colaicon': '🥤',
    'cola': '🥤',

    // Food
    'pizzaicon': '🍕',
    'pizza': '🍕',
    'sandwichicon': '🥪',
    'sandwich': '🥪',
    'chipsicon': '🍟',
    'chips': '🍟',
    'snackicon': '🍿',
    'snack': '🍿',
  };
}
