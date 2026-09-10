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
  }) =>
      with_.solve(
        names: names,
        width: width,
        height: height,
        wordWidth: fakeWidth,
        floor: floor,
        ceiling: ceiling,
        minimum: minimum,
        priceFontSize: price,
      );

  /// Every name of the category fits its tile whole at the chosen size.
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

  group('ProductTileMetrics', () {
    test('matches the card the grid used to size by hand', () {
      // 8 + 24 + 8 + 8 + 4 of chrome, a 52 px icon at the shipped 26, and
      // the pinned 1.2 line height over two lines of name and one of price.
      expect(metrics.fixedHeight, 52);
      expect(metrics.iconSize(26, 26), 52);
      expect(metrics.horizontalInset, 32);
      expect(metrics.tileHeight(26, 22, 26),
          closeTo(52 + 52 + 1.2 * (52 + 22), 1e-9));
    });

    test('at the floor the icon is 52 whatever the scale (#369)', () {
      // A club that raised `xxxl` raised the text. The icon growing with it
      // is what would push the kiosk's second row under the summary bar.
      expect(metrics.iconSize(31, 31), 52);
      expect(metrics.tileHeight(31, 27, 31), closeTo(52 + 52 + 1.2 * (62 + 27), 1e-9));
    });

    test('the icon grows with the room a category has', () {
      expect(metrics.iconSize(39, 26), 78);
      expect(metrics.iconSize(23, 26), 46);
    });

    test('the height and its inverse agree', () {
      for (final floor in [26.0, 31.0]) {
        for (final size in [22.0, 26.0, 31.0, 39.0]) {
          expect(
              metrics.nameFontSizeFor(
                  metrics.tileHeight(size, 22, floor), 22, floor),
              closeTo(size, 1e-9),
              reason: 'size $size at floor $floor');
        }
      }
    });
  });

  group('ProductGridLayout', () {
    final drinks = [
      'Weizenbier (0,5l)',
      'Alkoholfreies Bier (0,5l)',
      'Pils (0,33l)',
      'Radler (0,5l)',
      'Apfelschorle',
      'Cola',
    ];

    test('one size for every tile, and every word whole', () {
      final g = solve(drinks);

      expectAllWhole(g, drinks);
      expect(g.wordsBroken, isFalse);
      expect(g.nameFontSize, greaterThanOrEqualTo(26));
    });

    test('a full category stays at the floor and scrolls (#29)', () {
      final names = List.generate(40, (i) => 'Alkoholfreies Bier $i');
      final g = solve(names);

      expect(g.nameFontSize, 26);
      expect(g.scrolls, isTrue);
      // Five columns of 240, as the kiosk has always had.
      expect(g.columns, 5);
      expect(g.tileWidth, closeTo(240, 1e-9));
      expectAllWhole(g, names);
    });

    test('a sparse category grows to the ceiling, and no further', () {
      final g = solve(['Cola', 'Bier', 'Wein']);

      expect(g.nameFontSize, 39);
      expect(g.scrolls, isFalse);
      expect(g.iconSize, 78);
      expect(g.tileHeight, closeTo(metrics.tileHeight(39, 22, 26), 1e-9));
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

    test('at the production scale the kiosk drops to four columns '
        'rather than splitting "Alkoholfreies"', () {
      final names = List.generate(40, (i) => 'Alkoholfreies Bier (0,5l)');
      final g = solve(names, floor: 31, ceiling: 46.5, minimum: 27);

      // Five columns leave 208 px for a 242 px word; four leave 271.
      expect(g.columns, 4);
      expect(g.nameFontSize, 31);
      expect(g.scrolls, isTrue);
      expect(g.wordsBroken, isFalse);
      expectAllWhole(g, names);
    });

    test('goes below the floor only when no column count keeps a word whole',
        () {
      final names = List.generate(40, (i) => 'Kaffeespezialitäten $i');
      // A 300 px screen: one column, 267 px inside the tile, and the word
      // needs 11.4 em.
      final g = solve(names, width: 300);

      expect(g.columns, 1);
      expect(g.nameFontSize, lessThan(26));
      expect(g.nameFontSize, greaterThanOrEqualTo(22));
      expect(g.wordsBroken, isFalse);
      expectAllWhole(g, names);
    });

    test('never below the minimum: a word wider than the screen is reported',
        () {
      final g = solve(['Donaudampfschifffahrtsgesellschaftskapitänsmütze'],
          width: 300);

      expect(g.nameFontSize, 22);
      expect(g.wordsBroken, isTrue);
    });

    test('the floor is the configured xxxl, whatever the category', () {
      final names = List.generate(40, (i) => 'Bier $i');
      for (final floor in [24.0, 26.0, 31.0]) {
        final g = solve(names, floor: floor, ceiling: floor * 1.5);
        expect(g.nameFontSize, floor, reason: 'floor $floor');
      }
    });

    test('the ceiling follows the scale a club set', () {
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
      final names = List.generate(12, (i) => 'Alkoholfreies Bier $i');
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
  });
}
