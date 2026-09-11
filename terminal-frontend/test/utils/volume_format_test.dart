import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:clubbar_terminal/utils/formatters.dart';

/// The Dart third of one formatting rule (ADR-0056, decision 4).
///
/// Every vector comes from `api/fixtures/volume-format.json`, which the PHP and
/// TypeScript suites read too. Nothing is hard-coded here on purpose: three
/// implementations checked against three private lists of examples agree only
/// by luck, and the first divergence would show up as a terminal badge saying
/// `0.5 l` beside a Deckelauszug saying `0,5 l`.
///
/// A new vector belongs in the fixture. Adding one here instead is what this
/// arrangement exists to prevent.
void main() {
  setUpAll(() async {
    // Production loads this via GlobalMaterialLocalizations.delegate inside
    // MaterialApp; plain unit tests need it initialized explicitly.
    await initializeDateFormatting();
  });

  /// The shared fixture, read from the checkout this package sits in.
  ///
  /// `flutter test` runs with the package root as its working directory, so the
  /// repository root is one level up.
  final fixtureFile = File('../api/fixtures/volume-format.json');
  final fixture =
      jsonDecode(fixtureFile.readAsStringSync()) as Map<String, dynamic>;
  final languages = (fixture['languages'] as List).cast<String>();
  final cases = (fixture['cases'] as List).cast<Map<String, dynamic>>();

  group('formatVolume against the shared vectors', () {
    test('the fixture is where it is supposed to be', () {
      expect(
        fixtureFile.existsSync(),
        isTrue,
        reason:
            'api/fixtures/volume-format.json is the single source of the volume '
            'formatting rule (ADR-0056) and all three language suites read it.',
      );
    });

    for (final testCase in cases) {
      for (final language in languages) {
        final millilitres = testCase['ml'] as int;
        final expected =
            (testCase['expected'] as Map<String, dynamic>)[language] as String;

        test('$millilitres ml in $language — ${testCase['why']}', () {
          expect(formatVolume(millilitres, language), expected);
        });
      }
    }

    test('the fixture covers the whole range it claims to', () {
      // A guard on the guard: a truncated fixture would make every case above
      // pass by having nothing to check.
      final millilitres = cases.map((c) => c['ml'] as int).toList();

      expect(millilitres.length, greaterThanOrEqualTo(15));
      expect(millilitres, contains(1));
      expect(millilitres, contains(1005));
      expect(millilitres, contains(10000));
      expect(languages, ['de', 'en']);
    });
  });

  group('formatVolume', () {
    test('separates the unit with a no-break space', () {
      // A plain space would let the badge wrap between the number and its unit,
      // leaving `0,5` on one line and `l` on the next.
      expect(formatVolume(500, 'de'), contains(' '));
      expect(formatVolume(500, 'de'), isNot(contains(' ')));
    });

    test('falls back to the decimal comma for an unknown language', () {
      // Called while building a tile: wrong punctuation is a smaller failure
      // than a card that does not render.
      expect(formatVolume(500, 'fr'), '0,5 l');
    });
  });

  group('formatVolumeOrEmpty', () {
    test('a product with no size formats to nothing at all', () {
      expect(formatVolumeOrEmpty(null, 'de'), '');
      expect(formatVolumeOrEmpty(500, 'de'), '0,5 l');
    });
  });

  group('formatProductLabel', () {
    test('puts the size after the name', () {
      expect(
        formatProductLabel('Weizenbier', 500, 'de'),
        'Weizenbier 0,5 l',
      );
      expect(
        formatProductLabel('Wheat beer', 500, 'en'),
        'Wheat beer 0.5 l',
      );
    });

    test('prints the name alone when there is no size', () {
      // No trailing space, no dash, nothing: a Sauna-Token is a Sauna-Token.
      expect(formatProductLabel('Sauna-Token', null, 'de'), 'Sauna-Token');
    });
  });
}
