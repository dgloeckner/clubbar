import 'dart:math' as math;

/// Width of one word in *em* — pixels per pixel of font size — set in the
/// product name's own style. Measured once per distinct word (a `TextPainter`
/// in the app, arithmetic in the tests) so that the solver itself never
/// touches the text engine: a glyph run's width scales linearly with the font
/// size, so a word measured at one size is known at every size.
typedef WordWidth = double Function(String word);

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
    this.iconScale = 2.0,
    this.slack = 4.0,
    this.nameLines = 2,
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
  /// spare. 1.2 is ordinary leading for a bold two-line headline, and it makes
  /// the text block *exactly* `lineHeight * (nameLines * name + price)`.
  final double lineHeight;

  /// Icon edge as a multiple of the name size — 2.0 keeps the shipped 52 px
  /// icon at the shipped 26 px name and lets the tile scale as one thing.
  final double iconScale;

  /// Headroom over the computed height.
  final double slack;

  /// Lines the name may take before it is ellipsised.
  final int nameLines;

  /// Horizontal room the chrome takes out of a tile.
  double get horizontalInset => 2 * (cardMargin + padding);

  /// Vertical room that does not scale with the type.
  double get fixedHeight => 2 * cardMargin + 2 * padding + 2 * gap + slack;

  double iconSize(double nameFontSize) => iconScale * nameFontSize;

  /// Width the name may occupy inside a tile of [tileWidth].
  double innerWidth(double tileWidth) => tileWidth - horizontalInset;

  /// Height a tile needs for a name at [nameFontSize] over a price at
  /// [priceFontSize].
  double tileHeight(double nameFontSize, double priceFontSize) =>
      fixedHeight +
      iconSize(nameFontSize) +
      lineHeight * (nameLines * nameFontSize + priceFontSize);

  /// The inverse of [tileHeight]: the name size a tile of [tileHeight] holds.
  double nameFontSizeFor(double tileHeight, double priceFontSize) =>
      (tileHeight - fixedHeight - lineHeight * priceFontSize) /
      (iconScale + nameLines * lineHeight);
}

/// What [ProductGridLayout.solve] decided for one category.
class ProductGridGeometry {
  const ProductGridGeometry({
    required this.columns,
    required this.tileWidth,
    required this.tileHeight,
    required this.nameFontSize,
    required this.iconSize,
    required this.scrolls,
    required this.wordsBroken,
  });

  final int columns;
  final double tileWidth;
  final double tileHeight;

  /// One size for every tile of the category.
  final double nameFontSize;
  final double iconSize;

  /// Whether the rows exceed the viewport at this size.
  final bool scrolls;

  /// True when some word is wider than the line even at the minimum size, so
  /// the card's ellipsis (or a mid-word break) is going to show after all.
  final bool wordsBroken;

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
/// 1. **A word is never split.** A name fits a tile when it wraps *at spaces*
///    into at most [ProductTileMetrics.nameLines] lines with no word wider than
///    the line. Flutter splits an over-long word silently and without a
///    hyphen; the only way to keep "Alkoholfreies" whole is to make sure it
///    fits, and the only way to know that is to measure it.
/// 2. **One size per category.** The size is the largest at which *every* name
///    of the category fits, so tiles never argue with each other.
/// 3. **Large, adaptive, bounded.** Between [floor] — the configured `xxxl`,
///    which keeps its meaning as the size the club wants at minimum — and
///    [ceiling]. The solver tries every column count the width allows and
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
    required double priceFontSize,
  }) {
    final count = names.length;
    final effectiveCeiling = math.max(ceiling, floor);
    final effectiveMinimum = math.min(minimum, floor);

    if (count == 0 || width <= 0) {
      return _geometry(
        columns: 1,
        tileWidth: math.min(maxTileWidth, math.max(width, 0)),
        nameFontSize: floor,
        priceFontSize: priceFontSize,
        scrolls: false,
        wordsBroken: false,
      );
    }

    final spaceEm = wordWidth(' ');
    final requiredEm = names
        .map((name) => _requiredEm(name, wordWidth, spaceEm))
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
      final wordsBroken = byWidthRaw < effectiveMinimum;
      final byWidth =
          byWidthRaw.clamp(effectiveMinimum, effectiveCeiling).toDouble();
      final byHeight =
          metrics.nameFontSizeFor(availableTileHeight, priceFontSize);

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
        wordsBroken: wordsBroken,
      );
      if (best == null || candidate.beats(best)) best = candidate;
    }

    final chosen = best!;
    return _geometry(
      columns: chosen.columns,
      tileWidth: chosen.tileWidth,
      nameFontSize: _snap(chosen.size),
      priceFontSize: priceFontSize,
      scrolls: chosen.scrolls,
      wordsBroken: chosen.wordsBroken,
    );
  }

  ProductGridGeometry _geometry({
    required int columns,
    required double tileWidth,
    required double nameFontSize,
    required double priceFontSize,
    required bool scrolls,
    required bool wordsBroken,
  }) =>
      ProductGridGeometry(
        columns: columns,
        tileWidth: tileWidth,
        tileHeight: metrics.tileHeight(nameFontSize, priceFontSize),
        nameFontSize: nameFontSize,
        iconSize: metrics.iconSize(nameFontSize),
        scrolls: scrolls,
        wordsBroken: wordsBroken,
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
    required this.wordsBroken,
  });

  final int columns;
  final double tileWidth;

  /// The tile would have been wider than the cap allows.
  final bool capped;

  /// Slots left over in the last row.
  final int emptySlots;

  final double size;
  final bool scrolls;
  final bool wordsBroken;

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
