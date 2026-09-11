import 'dart:math' as math;

/// Width of one word in *em* — pixels per pixel of font size — set in the
/// product name's own style. Measured once per distinct word (a `TextPainter`
/// in the app, arithmetic in the tests) so that the solver itself never
/// touches the text engine: a glyph run's width scales linearly with the font
/// size, so a word measured at one size is known at every size.
typedef WordWidth = double Function(String word);

/// One product's price pill, measured: the price's width in em in the price's
/// own style, and the volume's in the volume's, or `null` when the product has
/// no size. Like [WordWidth], measured once by the screen so the solver never
/// touches the text engine.
class PriceTag {
  const PriceTag({required this.priceEm, this.volumeEm});

  final double priceEm;
  final double? volumeEm;
}

/// The fixed geometry of a product tile — everything that is not the name.
///
/// One place for the numbers `ProductCard` draws with and the numbers
/// [ProductGridLayout] sizes with, so they cannot drift apart: the card's
/// padding, the gaps, the pinned line height and the icon's proportion to the
/// name all live here.
class ProductTileMetrics {
  const ProductTileMetrics({
    this.cardMargin = 4.0,
    this.padding = 12.0,
    this.gap = 8.0,
    this.lineHeight = 1.2,
    this.baseIconSize = 52.0,
    this.iconScale = 2.0,
    this.slack = 4.0,
    this.pricePillPadding = 4.0,
    this.pricePillBorder = 1.0,
    this.nameLines = 1,
    this.volumeTextScale = 0.6,
    this.priceScale = 0.9,
    this.pillOuterPadding = 12.0,
    this.pillInnerPadding = 8.0,
    this.pillDivider = 1.0,
  });

  /// `Card`'s default margin, on every side.
  final double cardMargin;

  /// Padding inside the card (`AppSpacing.md`).
  final double padding;

  /// Vertical gap under the icon and under the name (`AppSpacing.sm`).
  final double gap;

  /// Line height of name and price as a multiple of the font size.
  ///
  /// Pinned rather than left to the font: Roboto's natural line box is ~1.34
  /// em, and at a production scale that is 12 px per tile the grid could not
  /// spare. 1.2 is ordinary leading for a bold headline, and it makes the text
  /// block *exactly* `lineHeight * (nameLines * name + price)`.
  final double lineHeight;

  /// Icon edge when the name is at the floor — the 52 that #369 settled,
  /// which is what keeps two whole rows on the kiosk at the scale a
  /// production terminal runs. Deliberately *not* a multiple of the type
  /// scale: a club that raised `xxxl` to 31 raised the text, and 10 px more
  /// icon per tile would push the second row under the summary bar again.
  final double baseIconSize;

  /// How much the icon grows per pixel the name grows past the floor, so a
  /// category with room scales as one thing rather than as a bigger caption
  /// under the same small picture.
  final double iconScale;

  /// Headroom over the computed height.
  final double slack;

  /// Vertical padding inside the price pill, above and below the number.
  ///
  /// The pill is chrome the flat price text did not have, and it is 10 px of
  /// it. Unaccounted for, the card overflowed its own tile by exactly the 6 px
  /// the [slack] could not absorb — silently in production, and as a
  /// `RenderFlex overflowed` in tests.
  final double pricePillPadding;

  /// The price pill's 1 px border, top and bottom.
  final double pricePillBorder;

  /// Lines the name may take before it is ellipsised.
  ///
  /// **One**, since #878. The second line existed because a name carried its
  /// size — `Weizenbier (0,5l)`, `Apfelschorle (0,3l)` — and the suffix was
  /// what pushed it over. With the size in `volume_ml` and a badge of its own
  /// (ADR-0056) the grid's names are single words or short phrases, and the
  /// height the second line held goes to the price.
  final int nameLines;

  /// The volume's text size, as a multiple of the name's font size.
  ///
  /// The volume shares the price pill — `0,5 l │ 2,00 €`, one fact read as
  /// "this much, for this price". It was a 0.4 x chip in a reserved row of its
  /// own under the name, which put about 14 px of text on a panel read
  /// standing up, and cost every tile 0.67 x the name in height. Beside the
  /// price it has the width to be read, and still stays under the price.
  ///
  /// The price keeps its height across a row without a reserved row: the pill
  /// is one line tall whether or not it carries a volume, so a Sauna-Token's
  /// price sits level with its neighbours'.
  final double volumeTextScale;

  /// The price's font size as a multiple of the name's, above [priceFloor].
  ///
  /// The prototype makes the price the loudest thing on the tile, but it was
  /// drawn at one name size. The solver can set a name anywhere up to
  /// `productNameMax` (46.5 in the production config) while the price floor is
  /// a fixed `xxl` (27), so a flat "price is bigger" would be false at the top
  /// of the range and enormous at the bottom. Taking the larger of the floor
  /// and 0.9 x the name spends the height the dropped second name line freed on
  /// the price without adding a config key nobody asked for.
  final double priceScale;

  /// Horizontal padding at the pill's two rounded ends (`AppSpacing.md`).
  final double pillOuterPadding;

  /// Horizontal padding either side of the divider between volume and price.
  final double pillInnerPadding;

  /// The hairline between the volume and the price.
  final double pillDivider;

  /// Horizontal room the chrome takes out of a tile.
  double get horizontalInset => 2 * (cardMargin + padding);

  /// Vertical room that does not scale with the type.
  double get fixedHeight =>
      2 * cardMargin +
      2 * padding +
      2 * gap +
      slack +
      2 * (pricePillPadding + pricePillBorder);

  /// Icon edge for a name at [nameFontSize] when the floor is [floor].
  double iconSize(double nameFontSize, double floor) =>
      baseIconSize + iconScale * (nameFontSize - floor);

  /// Width the name may occupy inside a tile of [tileWidth].
  double innerWidth(double tileWidth) => tileWidth - horizontalInset;

  /// The price's size for a name at [nameFontSize], never below [priceFloor].
  ///
  /// See [priceScale]. This is the one place the relationship is stated, so the
  /// card draws the price at the size the grid sized the tile for.
  double priceFontSize(double nameFontSize, double priceFloor) =>
      math.max(priceFloor, priceScale * nameFontSize);

  /// The volume's text size for a name at [nameFontSize].
  double volumeFontSize(double nameFontSize) => volumeTextScale * nameFontSize;

  /// The name size at which the price leaves [priceFloor] behind.
  double _priceKnee(double priceFloor) => priceFloor / priceScale;

  /// Width of the pill's chrome — border, padding and, with a volume, the
  /// divider — which does not scale with the type.
  double pillChromeWidth({required bool withVolume}) =>
      2 * pricePillBorder +
      2 * pillOuterPadding +
      (withVolume ? 2 * pillInnerPadding + pillDivider : 0);

  /// Width of [tag]'s pill for a name at [nameFontSize].
  double pillWidth(PriceTag tag, double nameFontSize, double priceFloor) =>
      pillChromeWidth(withVolume: tag.volumeEm != null) +
      tag.priceEm * priceFontSize(nameFontSize, priceFloor) +
      (tag.volumeEm ?? 0) * volumeFontSize(nameFontSize);

  /// The largest name size at which [tag]'s pill fits a line [innerWidth]
  /// wide; negative when it fits at no size, infinite when at every size.
  ///
  /// The inverse of [pillWidth], which is piecewise linear in the name size
  /// for the same reason [tileHeight] is: the price sits flat on its floor
  /// below the knee.
  double maxNameForPill(PriceTag tag, double innerWidth, double priceFloor) {
    final room = innerWidth - pillChromeWidth(withVolume: tag.volumeEm != null);
    final volumeEm = tag.volumeEm ?? 0;

    // Above the knee, where the price grows with the name.
    final slope = priceScale * tag.priceEm + volumeTextScale * volumeEm;
    if (slope > 0) {
      final scaled = room / slope;
      if (scaled >= _priceKnee(priceFloor)) return scaled;
    }

    // Below it, where only the volume grows.
    final left = room - tag.priceEm * priceFloor;
    if (left < 0) return -1;
    if (volumeEm == 0) return double.infinity;
    return left / (volumeTextScale * volumeEm);
  }

  /// Height a tile needs for a name at [nameFontSize] over its price pill,
  /// when the name floor is [floor] and the price floor is [priceFloor].
  double tileHeight(double nameFontSize, double priceFloor, double floor) =>
      fixedHeight +
      iconSize(nameFontSize, floor) +
      lineHeight *
          (nameLines * nameFontSize + priceFontSize(nameFontSize, priceFloor));

  /// The inverse of [tileHeight]: the name size a tile of [tileHeight] holds.
  ///
  /// [tileHeight] is piecewise linear in the name size, because the price stops
  /// at [priceFloor] below the knee, so the inverse has two branches. Height
  /// rises strictly with the name size, so exactly one of them is consistent
  /// with its own branch condition — try the steeper one first and fall back.
  double nameFontSizeFor(double tileHeight, double priceFloor, double floor) {
    final base = tileHeight - fixedHeight - baseIconSize + iconScale * floor;
    final common = iconScale + nameLines * lineHeight;

    // Above the knee, where the price is 0.9 x the name and grows with it.
    final scaled = base / (common + lineHeight * priceScale);
    if (scaled > _priceKnee(priceFloor)) return scaled;

    // At or below it, where the price sits flat on its floor.
    return (base - lineHeight * priceFloor) / common;
  }
}

/// What [ProductGridLayout.solve] decided for one category.
class ProductGridGeometry {
  const ProductGridGeometry({
    required this.columns,
    required this.tileWidth,
    required this.tileHeight,
    required this.nameFontSize,
    required this.priceFontSize,
    required this.volumeFontSize,
    required this.iconSize,
    required this.scrolls,
    required this.namesEllipsized,
    required this.wordsBroken,
    this.pillsOverflow = false,
  });

  final int columns;
  final double tileWidth;
  final double tileHeight;

  /// One size for every tile of the category.
  final double nameFontSize;

  /// The price's size, derived from the name's — the larger of the price floor
  /// and [ProductTileMetrics.priceScale] x the name. The card must draw at
  /// *this* number, because it is the one the tile's height was computed from.
  final double priceFontSize;

  /// The volume badge's text size, likewise derived from the name's.
  final double volumeFontSize;

  final double iconSize;

  /// Whether the rows exceed the viewport at this size.
  final bool scrolls;

  /// True when some *name* is wider than its line even at the minimum size,
  /// so the card's ellipsis is going to show.
  ///
  /// With one name line (#878) this is the ordinary fallback and it is a mild
  /// one: the member sees `Kaffeespezialitäte…` rather than a name broken in
  /// the middle of a word. It is separate from [wordsBroken] because the two
  /// are different failures with different remedies — this one is answered by
  /// a shorter name, that one by a wider tile.
  final bool namesEllipsized;

  /// True when a single *word* is wider than the line even at the minimum
  /// size, which is where Flutter splits it — silently, and with no hyphen.
  ///
  /// The bad one, and the reason the solver measures at all: `Alkoholfreies`
  /// broken as `Alkoholfreie` / `s` is what the screenshots in
  /// `docs/reviews/2026-09-10-product-card/` show.
  final bool wordsBroken;

  /// True when some price pill is wider than its tile even at the minimum
  /// size — a price floor too large for the tile, which no name size can
  /// answer. The card scales that pill down rather than clipping it.
  final bool pillsOverflow;

  /// Width the grid actually uses — less than the viewport when the tile cap
  /// bit, in which case the grid is centred.
  double usedWidth(double spacing) =>
      columns * tileWidth + (columns - 1) * spacing;
}

/// Sizes the product grid for the category on screen.
///
/// Three targets, in the order they are argued for in
/// `plans/2026-09-10-terminal-product-card-layout.md`:
///
/// 1. **A word is never split, and since #878 the name fits on one line.** A
///    name fits a tile when it wraps *at spaces* into at most
///    [ProductTileMetrics.nameLines] lines — now one — with no word wider than
///    the line. Flutter splits an over-long word silently and without a hyphen;
///    the only way to keep "Alkoholfreies" whole is to make sure it fits, and
///    the only way to know that is to measure it. The ellipsis survives only as
///    the fallback for a name still wider than the tile at [minimum], which is
///    what `wordsBroken` reports.
///
///    One line is affordable because the size has left the name (ADR-0056):
///    `Weizenbier (0,5l)` is now `Weizenbier` with a badge, and it was the
///    suffix that needed the second line.
/// 2. **One size per category.** The size is the largest at which *every* name
///    of the category fits, so tiles never argue with each other.
/// 3. **Large, adaptive, bounded.** Between [floor] — `productNameMin` in
///    config, the size the club wants at minimum — and [ceiling]
///    (`productNameMax`). The solver tries every column count the width allows and
///    keeps the one that yields the largest name: a sparse category gets
///    fewer, wider tiles that fill the screen; a full one stays at the floor
///    and scrolls, as issue #29 settled. It goes *below* the floor only to keep
///    a word whole, only after fewer columns have been tried, and never below
///    [minimum].
class ProductGridLayout {
  const ProductGridLayout({
    this.metrics = const ProductTileMetrics(),
    this.spacing = 12.0,
    this.bottomPadding = 12.0,
    this.minTileWidth = 160.0,
    this.maxTileWidth = 420.0,
  });

  final ProductTileMetrics metrics;

  /// Gap between tiles, both axes.
  final double spacing;

  /// The grid's own bottom padding, inside the viewport.
  final double bottomPadding;

  /// Narrowest tile worth trying — bounds the column search from above.
  final double minTileWidth;

  /// Widest tile allowed. Two products on a 1920 px screen would otherwise
  /// become 940 px billboards around a 78 px icon.
  final double maxTileWidth;

  /// Sizes below this are compared as equal, so float noise cannot pick a
  /// column count.
  static const double _sizeResolution = 0.5;

  /// A pixel of safety between the measured words and the line they fill.
  static const double _fitMargin = 1.0;

  ProductGridGeometry solve({
    required List<String> names,
    required double width,
    required double height,
    required WordWidth wordWidth,
    required double floor,
    required double ceiling,
    required double minimum,
    required double priceFloor,
    List<PriceTag> tags = const [],
  }) {
    final count = names.length;
    final effectiveCeiling = math.max(ceiling, floor);
    final effectiveMinimum = math.min(minimum, floor);

    if (count == 0 || width <= 0) {
      return _geometry(
        columns: 1,
        tileWidth: math.min(maxTileWidth, math.max(width, 0)),
        nameFontSize: floor,
        priceFloor: priceFloor,
        floor: floor,
        scrolls: false,
        namesEllipsized: false,
        wordsBroken: false,
      );
    }

    final spaceEm = wordWidth(' ');
    final requiredEm = names
        .map((name) => _requiredEm(name, wordWidth, spaceEm))
        .fold<double>(0, math.max);
    // Measured separately from [requiredEm]: with one name line the whole name
    // not fitting means an ellipsis, while a single word not fitting means
    // Flutter breaks it mid-word. Only the second is the failure this solver
    // exists to prevent.
    final longestWordEm = names
        .map((name) => _longestWordEm(name, wordWidth))
        .fold<double>(0, math.max);

    final maxColumns = math.max(
      1,
      math.min(count, ((width + spacing) / (minTileWidth + spacing)).floor()),
    );

    _Candidate? best;
    for (var columns = 1; columns <= maxColumns; columns++) {
      final rawTileWidth = (width - (columns - 1) * spacing) / columns;
      final tileWidth = math.min(maxTileWidth, rawTileWidth);
      final rows = (count + columns - 1) ~/ columns;
      final availableTileHeight =
          (height - bottomPadding - (rows - 1) * spacing) / rows;

      final inner = metrics.innerWidth(tileWidth) - _fitMargin;
      final byWidthRaw =
          requiredEm <= 0 ? effectiveCeiling : inner / requiredEm;
      final namesEllipsized = byWidthRaw < effectiveMinimum;
      final wordsBroken = longestWordEm > 0 &&
          inner / longestWordEm < effectiveMinimum;
      // The pill is the tile's other wide line: `0,5 l │ 12,50 €` can be
      // wider than a short name, so it bounds the size just as the name does.
      final byPill = tags
          .map((tag) =>
              metrics.maxNameForPill(tag, inner, priceFloor))
          .fold<double>(double.infinity, math.min);
      final pillsOverflow = byPill < effectiveMinimum;
      final byWidth = math
          .min(byWidthRaw, byPill)
          .clamp(effectiveMinimum, effectiveCeiling)
          .toDouble();
      final byHeight =
          metrics.nameFontSizeFor(availableTileHeight, priceFloor, floor);

      final double size;
      final bool scrolls;
      if (byHeight >= floor) {
        size = math.min(byWidth, byHeight);
        scrolls = false;
      } else {
        size = math.min(byWidth, floor);
        scrolls = true;
      }

      final candidate = _Candidate(
        columns: columns,
        tileWidth: tileWidth,
        capped: rawTileWidth > maxTileWidth,
        emptySlots: rows * columns - count,
        size: size,
        scrolls: scrolls,
        namesEllipsized: namesEllipsized,
        wordsBroken: wordsBroken,
        pillsOverflow: pillsOverflow,
      );
      if (best == null || candidate.beats(best)) best = candidate;
    }

    final chosen = best!;
    return _geometry(
      columns: chosen.columns,
      tileWidth: chosen.tileWidth,
      nameFontSize: _snap(chosen.size),
      priceFloor: priceFloor,
      floor: floor,
      scrolls: chosen.scrolls,
      namesEllipsized: chosen.namesEllipsized,
      wordsBroken: chosen.wordsBroken,
      pillsOverflow: chosen.pillsOverflow,
    );
  }

  ProductGridGeometry _geometry({
    required int columns,
    required double tileWidth,
    required double nameFontSize,
    required double priceFloor,
    required double floor,
    required bool scrolls,
    required bool namesEllipsized,
    required bool wordsBroken,
    bool pillsOverflow = false,
  }) =>
      ProductGridGeometry(
        columns: columns,
        tileWidth: tileWidth,
        tileHeight: metrics.tileHeight(nameFontSize, priceFloor, floor),
        nameFontSize: nameFontSize,
        priceFontSize: metrics.priceFontSize(nameFontSize, priceFloor),
        volumeFontSize: metrics.volumeFontSize(nameFontSize),
        iconSize: metrics.iconSize(nameFontSize, floor),
        scrolls: scrolls,
        namesEllipsized: namesEllipsized,
        wordsBroken: wordsBroken,
        pillsOverflow: pillsOverflow,
      );

  /// Rounds down to the resolution, so the size the card renders is the one
  /// the solver compared.
  static double _snap(double size) =>
      (size / _sizeResolution).floor() * _sizeResolution;

  /// The narrowest line, in em, on which [name] wraps at spaces into at most
  /// [ProductTileMetrics.nameLines] lines with every word whole.
  ///
  /// For two lines that is the best split point: the smaller of the wider
  /// halves over every place the name can break. Flutter's greedy wrapper
  /// takes at least as many words onto line one as that split does, so it
  /// never needs a third line at this width. A single word needs itself.
  double _requiredEm(String name, WordWidth wordWidth, double spaceEm) {
    final words = name
        .trim()
        .split(RegExp(r'\s+'))
        .where((word) => word.isNotEmpty)
        .toList();
    if (words.isEmpty) return 0;

    final widths = words.map(wordWidth).toList();
    if (widths.length == 1 || metrics.nameLines <= 1) {
      return _lineEm(widths, 0, widths.length, spaceEm);
    }

    var required = double.infinity;
    for (var split = 1; split < widths.length; split++) {
      final line = math.max(
        _lineEm(widths, 0, split, spaceEm),
        _lineEm(widths, split, widths.length, spaceEm),
      );
      required = math.min(required, line);
    }
    return required;
  }

  /// The widest single word of [name], in em — the one Flutter would break.
  double _longestWordEm(String name, WordWidth wordWidth) => name
      .trim()
      .split(RegExp(r'\s+'))
      .where((word) => word.isNotEmpty)
      .map(wordWidth)
      .fold<double>(0, math.max);

  static double _lineEm(List<double> widths, int from, int to, double spaceEm) {
    var em = 0.0;
    for (var i = from; i < to; i++) {
      em += widths[i];
    }
    return em + (to - from - 1) * spaceEm;
  }
}

class _Candidate {
  const _Candidate({
    required this.columns,
    required this.tileWidth,
    required this.capped,
    required this.emptySlots,
    required this.size,
    required this.scrolls,
    required this.namesEllipsized,
    required this.wordsBroken,
    required this.pillsOverflow,
  });

  final int columns;
  final double tileWidth;

  /// The tile would have been wider than the cap allows.
  final bool capped;

  /// Slots left over in the last row.
  final int emptySlots;

  final double size;
  final bool scrolls;
  final bool namesEllipsized;
  final bool wordsBroken;
  final bool pillsOverflow;

  int get _rank => (size / ProductGridLayout._sizeResolution).round();

  /// Larger type first; then the layout that shows every row.
  ///
  /// Among layouts that show every row at the same size: the one with the
  /// fewest empty slots in its last row (three tiles in a row over a 2 + 1
  /// orphan), then fewer columns — wide tiles that fill the screen rather
  /// than a half-empty one — unless the cap is what would make them wide,
  /// in which case more columns, since fewer would only stack capped tiles
  /// in a corner. Among layouts that scroll: more columns, so there are fewer
  /// rows to scroll past.
  bool beats(_Candidate other) {
    if (_rank != other._rank) return _rank > other._rank;
    if (scrolls != other.scrolls) return !scrolls;
    if (scrolls) return columns > other.columns;
    if (emptySlots != other.emptySlots) return emptySlots < other.emptySlots;
    if (capped || other.capped) return columns > other.columns;
    return columns < other.columns;
  }
}
