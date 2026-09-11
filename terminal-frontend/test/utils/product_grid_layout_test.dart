import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/utils/product_grid_layout.dart';

/// A stand-in for the text engine: every glyph 0.6 em wide, a space 0.3 em —
/// close to Roboto Bold's averages, and exact enough for the solver, which
/// only ever compares widths it was given.
double fakeWidth(String word) =>
    word == ' ' ? 0.3 : word.runes.length * 0.6;

/// Greedy word wrap, the way Flutter fills lines: how many lines [name]
/// takes on a line [innerWidth] wide at [size], and whether any word alone is
/// wider than the line (which is where Flutter would split it).
({int lines, bool wordBroken}) wrap(String name, double innerWidth, double size) {
  final words = name.split(RegExp(r'\s+')).where((w) => w.isNotEmpty);
  var lines = 1;
  var line = 0.0;
  var broken = false;
  for (final word in words) {
    final w = fakeWidth(word) * size;
    if (w > innerWidth) broken = true;
    if (line == 0) {
      line = w;
    } else if (line + fakeWidth(' ') * size + w <= innerWidth) {
      line += fakeWidth(' ') * size + w;
    } else {
      lines++;
      line = w;
    }
  }
  return (lines: lines, wordBroken: broken);
}

void main() {
  const layout = ProductGridLayout();
  const metrics = ProductTileMetrics();

  // The 1280x800 kiosk: 1248 wide inside the screen padding, and what is left
  // under header, member bar, category bar and summary bar.
  const kioskWidth = 1248.0;
  const kioskHeight = 550.0;

  ProductGridGeometry solve(
    List<String> names, {
    double width = kioskWidth,
    double height = kioskHeight,
    double floor = 26,
    double ceiling = 39,
    double minimum = 22,
    double price = 22,
    ProductGridLayout with_ = layout,
    List<PriceTag> tags = const [],
  }) =>
      with_.solve(
        names: names,
        width: width,
        height: height,
        wordWidth: fakeWidth,
        floor: floor,
        ceiling: ceiling,
        minimum: minimum,
        priceFloor: price,
        tags: tags,
      );

  /// Every name of the category fits its tile on one line at the chosen size,
  /// with every word whole.
  void expectAllWhole(ProductGridGeometry g, List<String> names) {
    final inner = metrics.innerWidth(g.tileWidth);
    for (final name in names) {
      final result = wrap(name, inner, g.nameFontSize);
      expect(result.wordBroken, isFalse,
          reason: '"$name" has a word wider than ${inner}px at ${g.nameFontSize}');
      expect(result.lines, lessThanOrEqualTo(metrics.nameLines),
          reason: '"$name" needs ${result.lines} lines at ${g.nameFontSize}');
    }
  }

  /// No word of the category is wider than its line — the failure the solver
  /// exists to prevent, and the one that is *not* fixed by a shorter name.
  void expectNoWordBroken(ProductGridGeometry g, List<String> names) {
    final inner = metrics.innerWidth(g.tileWidth);
    for (final name in names) {
      expect(wrap(name, inner, g.nameFontSize).wordBroken, isFalse,
          reason: '"$name" has a word wider than ${inner}px at ${g.nameFontSize}');
    }
  }

  group('ProductTileMetrics', () {
    test('matches the card the grid sizes by hand', () {
      // 8 + 24 + 8 + 8 + 4 of chrome plus the price pill's own 10 (padding
      // and border, top and bottom), a 52 px icon at the shipped 26, and the
      // pinned 1.2 line height over ONE line of name and one of price (#878).
      // No volume row: the size shares the price's pill.
      expect(metrics.fixedHeight, 62);
      expect(metrics.iconSize(26, 26), 52);
      expect(metrics.horizontalInset, 32);
      expect(metrics.tileHeight(26, 22, 26),
          closeTo(62 + 52 + 1.2 * (26 + 23.4), 1e-9));
    });

    test('the name takes one line, because the size has left it (#878)', () {
      expect(metrics.nameLines, 1);
    });

    test('the pill is its chrome plus the volume and the price', () {
      const withVolume = PriceTag(priceEm: 3, volumeEm: 2);
      const priceOnly = PriceTag(priceEm: 3);

      // Border 2, the rounded ends 24, and with a volume the divider and the
      // padding either side of it, 17.
      expect(metrics.pillChromeWidth(withVolume: false), 26);
      expect(metrics.pillChromeWidth(withVolume: true), 43);
      // At 26 the price sits on its 24 floor; the volume is 0.6 x 26.
      expect(metrics.pillWidth(priceOnly, 26, 24), closeTo(26 + 3 * 24, 1e-9));
      expect(metrics.pillWidth(withVolume, 26, 24),
          closeTo(43 + 3 * 24 + 2 * 0.6 * 26, 1e-9));
    });

    /// The volume is set in the pill, beside the price, and still under it:
    /// the member confirms the size once they have found the drink.
    test('the volume is quieter than the price at every size', () {
      for (final size in [20.0, 26.0, 31.0, 46.5]) {
        expect(metrics.volumeFontSize(size),
            lessThan(metrics.priceFontSize(size, 24)));
      }
    });

    /// [maxNameForPill] inverts [pillWidth] on both sides of the price knee,
    /// or the solver would pick a size whose pill does not fit.
    test('the pill bound and the pill width agree on both sides of the knee',
        () {
      const tag = PriceTag(priceEm: 3.5, volumeEm: 2.2);
      // 150 solves below the knee, where only the volume grows; the rest above.
      for (final inner in [150.0, 220.0, 300.0, 400.0]) {
        final size = metrics.maxNameForPill(tag, inner, 24);
        expect(metrics.pillWidth(tag, size, 24), closeTo(inner, 1e-9),
            reason: 'inner $inner');
      }
    });

    test('a pill without a volume is bounded only by its growing price', () {
      const tag = PriceTag(priceEm: 3);
      // Above the knee the price is 0.9 x the name: 26 + 2.7 n <= 120.
      expect(metrics.maxNameForPill(tag, 120, 24), closeTo(94 / 2.7, 1e-9));
      // A line of 100 holds the 98 px pill at the price floor, and the price
      // stops growing with the name below the knee — so the bound sits at the
      // name size where it would start to.
      expect(metrics.maxNameForPill(tag, 100, 24), closeTo(74 / 2.7, 1e-9));
      expect(metrics.maxNameForPill(tag, 60, 24), lessThan(0),
          reason: 'the price floor alone is wider than the line');
    });

    /// The recommendation the plan makes and #878 left open: the height the
    /// dropped second name line freed goes to the price, at the larger of the
    /// price floor and 0.9 x the name — so the price is loud without a new
    /// config key, and without being enormous at the bottom of the range or a
    /// fixed 27 at the top.
    test('the price is the larger of its floor and 0.9 x the name', () {
      expect(metrics.priceFontSize(26, 27), 27, reason: 'below the knee');
      expect(metrics.priceFontSize(30, 27), 27, reason: 'still below the knee');
      expect(metrics.priceFontSize(46.5, 27), closeTo(41.85, 1e-9));
      // At the production floor the price already matches the name closely,
      // which is the prototype's proportion.
      expect(metrics.priceFontSize(31, 27), closeTo(27.9, 1e-9));
    });

    test('at the floor the icon is 52 whatever the scale (#369)', () {
      // A club that raised `xxxl` raised the text. The icon growing with it
      // is what would push the kiosk's second row under the summary bar.
      expect(metrics.iconSize(31, 31), 52);
      expect(metrics.tileHeight(31, 27, 31),
          closeTo(62 + 52 + 1.2 * (31 + 27.9), 1e-9));
    });

    test('the icon grows with the room a category has', () {
      expect(metrics.iconSize(39, 26), 78);
      expect(metrics.iconSize(23, 26), 46);
    });

    /// The height is piecewise linear, because the price stops at its floor
    /// below the knee. Both branches have to invert, or the solver would size
    /// a tile it cannot fill.
    test('the height and its inverse agree on both sides of the price knee',
        () {
      for (final floor in [26.0, 31.0]) {
        for (final priceFloor in [22.0, 27.0]) {
          for (final size in [20.0, 22.0, 26.0, 31.0, 39.0, 46.5]) {
            expect(
                metrics.nameFontSizeFor(
                    metrics.tileHeight(size, priceFloor, floor),
                    priceFloor,
                    floor),
                closeTo(size, 1e-9),
                reason:
                    'size $size at floor $floor, price floor $priceFloor');
          }
        }
      }
    });
  });

  group('ProductGridLayout', () {
    // Post-ADR-0056 names: the size has left them and lives in `volume_ml`,
    // which is what makes one name line affordable. `Weizenbier (0,5l)` is now
    // `Weizenbier` with a badge underneath.
    final drinks = [
      'Weizenbier',
      'Apfelschorle',
      'Pils',
      'Radler',
      'Cola',
      'Kaffee',
    ];

    /// The same list as it was written before the volume column — kept so the
    /// tests can show what the suffix costs a one-line layout.
    final drinksWithSuffixes = [
      'Weizenbier (0,5l)',
      'Alkoholfreies Bier (0,5l)',
      'Pils (0,33l)',
      'Radler (0,5l)',
      'Apfelschorle',
      'Cola',
    ];

    test('one size for every tile, on one line, every word whole', () {
      final g = solve(drinks);

      expectAllWhole(g, drinks);
      expect(g.wordsBroken, isFalse);
      expect(g.namesEllipsized, isFalse);
      expect(g.nameFontSize, greaterThanOrEqualTo(26));
    });

    /// The point of ADR-0056, measured. The same six drinks, with and without
    /// the size in the name: taking the suffix out is what lets a one-line
    /// layout keep the same columns at a larger name.
    test('taking the size out of the name buys back name size', () {
      final withSuffix = solve(drinksWithSuffixes);
      final without = solve(drinks);

      expect(without.nameFontSize, greaterThan(withSuffix.nameFontSize));
      expect(without.columns, greaterThanOrEqualTo(withSuffix.columns));
      expectAllWhole(without, drinks);
    });

    test('a full category stays at the floor and scrolls (#29)', () {
      final names = List.generate(40, (i) => 'Weizenbier $i');
      final g = solve(names);

      expect(g.nameFontSize, 26);
      expect(g.scrolls, isTrue);
      // Five columns of 240, as the kiosk has always had. Six would leave
      // 165 px inside a tile for a name that needs 195 at the floor.
      expect(g.columns, 5);
      expect(g.tileWidth, closeTo(240, 1e-9));
      expectAllWhole(g, names);
    });

    /// A two-word name on one line needs a wider tile than the same name over
    /// two, so the solver spends columns on it. That is the trade #878 takes
    /// deliberately: fewer, larger tiles beat a name split mid-word, and a club
    /// whose names are this long shortens them (the *Alkoholfreie Getränke*
    /// category in the issue's third screenshot is exactly that move).
    test('a long two-word name costs columns rather than a mid-word split', () {
      final names = List.generate(40, (i) => 'Alkoholfreies Bier $i');
      final g = solve(names);

      expect(g.wordsBroken, isFalse);
      expect(g.columns, lessThan(5));
      expectAllWhole(g, names);
    });

    test('a sparse category grows to the ceiling, and no further', () {
      final g = solve(['Cola', 'Bier', 'Wein']);

      expect(g.nameFontSize, 39);
      expect(g.scrolls, isFalse);
      expect(g.iconSize, 78);
      expect(g.tileHeight, closeTo(metrics.tileHeight(39, 22, 26), 1e-9));
    });

    /// The price and badge sizes travel with the geometry rather than being
    /// recomputed by the card, so the card cannot draw at a size the tile was
    /// not solved for.
    test('the geometry carries the price and badge sizes the card draws at',
        () {
      final g = solve(drinks, floor: 31, ceiling: 46.5, minimum: 27, price: 27);

      expect(g.priceFontSize, metrics.priceFontSize(g.nameFontSize, 27));
      expect(g.volumeFontSize, metrics.volumeFontSize(g.nameFontSize));
      expect(g.priceFontSize, greaterThanOrEqualTo(27),
          reason: 'never below the price floor');
    });

    test('three products share one row rather than leaving an orphan', () {
      final g = solve(['Cola', 'Bier', 'Wein']);

      expect(g.columns, 3);
      expect(g.tileWidth, closeTo((kioskWidth - 2 * 12) / 3, 1e-9));
    });

    test('six short names fill two rows of wide tiles, not one half-empty row',
        () {
      final g = solve(['Cola', 'Bier', 'Wein', 'Sekt', 'Saft', 'Tee']);

      expect(g.columns, 3);
      expect(g.scrolls, isFalse);
      expect(g.nameFontSize, 39);
    });

    test('at the production scale "Alkoholfreies" is never split', () {
      // The failure in the first screenshot of
      // docs/reviews/2026-09-10-product-card/: Flutter breaking a word with no
      // hyphen. It stays prevented at the production font scale.
      final names = List.generate(40, (i) => 'Alkoholfreies Bier');
      final g = solve(names, floor: 31, ceiling: 46.5, minimum: 27);

      expect(g.wordsBroken, isFalse);
      expect(g.scrolls, isTrue);
      expectNoWordBroken(g, names);
    });

    test('goes below the floor only when no column count fits the name', () {
      final names = List.generate(40, (i) => 'Kaffeespezialitäten $i');
      // A 300 px screen: one column, 267 px inside the tile.
      final g = solve(names, width: 300);

      expect(g.columns, 1);
      expect(g.nameFontSize, lessThan(26));
      expect(g.nameFontSize, greaterThanOrEqualTo(22));
      // The word itself still fits — only the whole name does not, so the
      // fallback is an ellipsis rather than a break in the middle of a word.
      expect(g.wordsBroken, isFalse);
      expectNoWordBroken(g, names);
    });

    /// The two fallbacks are different failures with different remedies, and
    /// the solver reports them separately (#878): a name that will be
    /// ellipsised is answered by a shorter name, a word that will be split by a
    /// wider tile.
    test('an over-long name is ellipsised, not reported as a broken word', () {
      final g = solve(['Kaffeespezialitäten mit Sahne'], width: 300, minimum: 22);

      expect(g.namesEllipsized, isTrue);
      expect(g.wordsBroken, isFalse);
    });

    test('never below the minimum: a word wider than the screen is reported',
        () {
      final g = solve(['Donaudampfschifffahrtsgesellschaftskapitänsmütze'],
          width: 300);

      expect(g.nameFontSize, 22);
      expect(g.wordsBroken, isTrue);
      expect(g.namesEllipsized, isTrue);
    });

    test('the floor is the configured minimum, whatever the category', () {
      final names = List.generate(40, (i) => 'Bier $i');
      for (final floor in [24.0, 26.0, 31.0]) {
        final g = solve(names, floor: floor, ceiling: floor * 1.5);
        expect(g.nameFontSize, floor, reason: 'floor $floor');
      }
    });

    test('the ceiling is the configured maximum', () {
      final g = solve(['Cola', 'Bier'], floor: 31, ceiling: 46.5);

      expect(g.nameFontSize, 46.5);
    });

    test('a ceiling under the floor is lifted to it', () {
      final g = solve(['Cola', 'Bier'], floor: 31, ceiling: 20);

      expect(g.nameFontSize, 31);
    });

    test('tiles are capped and the grid centred rather than stretched', () {
      final g = solve(['Cola', 'Bier'], width: 1888);

      expect(g.tileWidth, 420);
      expect(g.columns, 2);
      expect(g.usedWidth(12), lessThan(1888));
    });

    test('a category too tall at the floor scrolls with the most columns', () {
      final names = List.generate(30, (i) => 'Weizenbier $i');
      final g = solve(names);

      expect(g.scrolls, isTrue);
      expect(g.nameFontSize, 26);
      expect(g.columns, 5);
    });

    test('a narrow screen still lays out, one column at worst', () {
      final g = solve(List.generate(9, (i) => 'Bier $i'), width: 200);

      expect(g.columns, 1);
      expect(g.tileWidth, 200);
    });

    test('no names yields a sane default rather than a division by zero', () {
      final g = solve([]);

      expect(g.columns, 1);
      expect(g.nameFontSize, 26);
      expect(g.scrolls, isFalse);
    });

    test('a blank name is ignored for the fit, not measured as a word', () {
      final g = solve(['   ', 'Cola']);

      expect(g.nameFontSize, 39);
    });

    test('the tile height is the height the card draws at that size', () {
      final g = solve(drinks, floor: 31, ceiling: 46.5, minimum: 27, price: 27);

      expect(g.tileHeight,
          closeTo(metrics.tileHeight(g.nameFontSize, 27, 31), 1e-9));
      expect(g.iconSize, metrics.iconSize(g.nameFontSize, 31));
    });

    /// `0,5 l │ 12,50 €` can be wider than a short name, so the pill bounds
    /// the size just as the name does — a sparse category of short names
    /// would otherwise grow until its pills overflowed the tile.
    test('a wide pill caps the size a short name would have allowed', () {
      final names = ['Cola', 'Bier', 'Wein'];
      final free = solve(names);
      const wide = PriceTag(priceEm: 8, volumeEm: 4);
      final capped = solve(names, tags: [wide, wide, wide]);

      expect(free.nameFontSize, 39);
      expect(capped.nameFontSize, lessThan(39));
      expect(capped.pillsOverflow, isFalse);
      expect(
          metrics.pillWidth(wide, capped.nameFontSize, 22),
          lessThanOrEqualTo(metrics.innerWidth(capped.tileWidth)),
          reason: 'the pill fits the tile at the size the grid chose');
    });

    test('a pill that fits at the chosen size changes nothing', () {
      final g = solve(drinks, tags: [
        for (final _ in drinks) const PriceTag(priceEm: 2.5, volumeEm: 1.8),
      ]);

      expect(g.nameFontSize, solve(drinks).nameFontSize);
    });

    test('a price floor wider than any tile is reported, not hidden', () {
      final g = solve(['Cola'],
          width: 200, tags: [const PriceTag(priceEm: 12, volumeEm: 3)]);

      expect(g.pillsOverflow, isTrue);
      expect(g.nameFontSize, 22, reason: 'never below the minimum');
    });
  });
}
