/// What kind of thing a product icon depicts.
///
/// The Getränkewart already picks an icon for every product, and the icon
/// registry already knows which asset each name resolves to. This groups
/// those names into the handful of families a *reader* of the receipt cares
/// about, so a surface can say something about what was bought without the
/// data model growing a field for it (#929, move 2).
///
/// Deliberately coarse. It answers "is this a drink you say Prost to, food
/// you say Guten Appetit to, or a sauna session you wish someone well in" —
/// nothing finer, because nothing finer is wanted and a wrong guess must
/// cost nothing.
enum ProductIconFamily {
  /// Beer of every kind, alcohol-free included.
  beer,

  /// Wine, red and white.
  wine,

  /// Apfelwein and its cousins.
  cider,

  /// Anything eaten.
  food,

  /// The sauna's own products, session and accessories alike.
  sauna,

  /// Water, coffee, juice, soda — and every icon this build does not know.
  other,
}

/// The family [iconName] belongs to.
///
/// Accepts both the canonical `docs/icon-registry.md` names and the legacy
/// `PilsIcon` spellings, for the same reason `getProductIcon` does: a
/// deployment that still stores the old names must not silently drop into
/// [ProductIconFamily.other].
///
/// An unknown or absent name is [ProductIconFamily.other] — the neutral
/// answer, matching the neutral glyph the registry falls back to. This does
/// **not** log: an icon the terminal cannot draw is worth a warning, an icon
/// it cannot classify is not.
ProductIconFamily productIconFamily(String? iconName) {
  switch (iconName) {
    // === Beer ===
    case 'beer-pils':
    case 'beer-weizen':
    case 'beer-weizen-new':
    case 'beer-radler':
    case 'beer-alcohol-free':
    case 'PilsIcon':
    case 'WeizenIcon':
    case 'WeizenNewIcon':
    case 'RadlerIcon':
    case 'BeerAFIcon':
      return ProductIconFamily.beer;

    // === Wine ===
    case 'wine-red':
    case 'wine-white':
    case 'RotweinIcon':
    case 'WeissweinIcon':
      return ProductIconFamily.wine;

    // === Cider ===
    // The Apfelwein family only. `spritzer-apple` is Apfelschorle — a soft
    // drink that happens to share the fruit — and stays `other`.
    case 'cider-apfelwein':
    case 'cider-appler':
    case 'BembelIcon':
    case 'ApplerIcon':
      return ProductIconFamily.cider;

    // === Food ===
    case 'food-bratwurst':
    case 'food-hamburger':
    case 'food-fish-sandwich':
    case 'food-crisps':
    case 'food-fries':
    case 'food-bretzel':
    case 'food-crackers':
    case 'food-steak':
    case 'food-salad':
    case 'BratwurstIcon':
    case 'HamburgerIcon':
    case 'FishSandwichIcon':
    case 'CrispsIcon':
    case 'FriesIcon':
    case 'BretzelIcon':
    case 'CrackersIcon':
    case 'SteakIcon':
    case 'SaladIcon':
      return ProductIconFamily.food;

    // === Sauna ===
    case 'sauna-session':
    case 'sauna-cabin':
    case 'sauna-infusion':
    case 'sauna-towel':
    case 'sauna-token':
    case 'sauna-thermometer':
    case 'sauna-ice':
    case 'sauna-shower':
    case 'sauna-wellness':
    case 'sauna-whisk':
    case 'SaunaTimeIcon':
    case 'SaunaCabinIcon':
    case 'SaunaAufgussIcon':
    case 'SaunaTowelIcon':
    case 'SaunaTokenIcon':
    case 'SaunaThermometerIcon':
    case 'SaunaIceIcon':
    case 'SaunaShowerIcon':
    case 'SaunaWellnessIcon':
    case 'SaunaWhiskIcon':
      return ProductIconFamily.sauna;

    default:
      return ProductIconFamily.other;
  }
}
