/// Centralized product icon registry
///
/// Maps canonical icon names from docs/icon-registry.md to emoji.
/// This is the single source of truth for terminal icon display.
///
/// IMPORTANT: Icon names must match docs/icon-registry.md exactly.
/// All names are kebab-case (e.g., "beer-pils").
///
class ProductIcons {
  /// Get emoji for a product icon name
  ///
  /// Returns appropriate emoji based on canonical icon name from database.
  /// Falls back to shopping cart icon if not found.
  /// Returns notepad icon if icon name is null (corrections, manual entries).
  static String getEmoji(String? iconName) {
    if (iconName == null) return '📝';

    final normalized = iconName.toLowerCase();
    return _iconMap[normalized] ?? '🛒';
  }

  /// Icon name to emoji mapping
  ///
  /// Canonical names (from docs/icon-registry.md):
  ///   - Lowercase kebab-case (e.g., "beer-pils", "sauna-session")
  ///   - No "Icon" suffix
  ///
  ///   - CamelCase with "Icon" suffix (e.g., "PilsIcon", "CoffeeMugIcon")
  static const Map<String, String> _iconMap = {
    // === CANONICAL NAMES (from docs/icon-registry.md) ===

    // Beverages - Beer
    'beer-pils': '🍺',
    'beer-weizen': '🍺',
    'beer-radler': '🍺',
    'beer-alcohol-free': '🍺',

    // Beverages - Cider & Spritzers
    'cider-apfelwein': '🍺',
    'spritzer-apple': '🍎',

    // Beverages - Hot Drinks
    'coffee': '☕',

    // Beverages - Water & Soft Drinks
    'water': '💧',
    'water-large': '💧',
    'soda': '🥤',

    // Food
    'food-pizza': '🍕',
    'food-sandwich': '🥪',
    'snack-chips': '🍟',
    'snack': '🍿',
    'food-bratwurst': '🌭',
    'food-hamburger': '🍔',
    'food-fish-sandwich': '🐟',
    'food-crisps': '🥨',
    'food-fries': '🍟',
    'food-bretzel': '🥨',
    'food-crackers': '🍘',
    'food-steak': '🥩',
    'food-salad': '🥗',

    // Services - Sauna
    'sauna-session': '🧖',
    'sauna-cabin': '🏠',
    'sauna-infusion': '💨',
    'sauna-towel': '🧺',

    // Special
    'correction': '📝',
    'unknown': '🛒',
  };
}
