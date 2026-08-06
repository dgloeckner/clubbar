// Issue #18: card UIDs are compared as exact strings, so the case they arrive
// in must never decide whether a member gets in.
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/utils/card_uid.dart';

void main() {
  group('normalizeCardUid', () {
    test('upper-cases lower-case hex from a keyboard-wedge reader', () {
      expect(normalizeCardUid('abcd1234'), 'ABCD1234');
    });

    test('leaves an already canonical UID untouched', () {
      expect(normalizeCardUid('ABCD1234'), 'ABCD1234');
    });

    test('collapses mixed case onto the same value', () {
      expect(normalizeCardUid('AbCd1234'), normalizeCardUid('aBcD1234'));
    });

    test('strips the whitespace a reader appends around the UID', () {
      expect(normalizeCardUid('  04:d2:3e:5a  '), '04:D2:3E:5A');
    });

    test('an empty scan stays empty, so callers can still reject it', () {
      expect(normalizeCardUid('   '), '');
    });

    test('keeps digit-only UIDs byte-identical', () {
      expect(normalizeCardUid('0003195661'), '0003195661');
    });
  });

  group('normalizeCardUidOrNull', () {
    test('a member without a card keeps no card', () {
      // An anonymized member has no UID; turning that into '' would make it
      // collide with an empty scan.
      expect(normalizeCardUidOrNull(null), isNull);
    });

    test('normalizes a present UID like normalizeCardUid', () {
      expect(normalizeCardUidOrNull(' abcd '), 'ABCD');
    });
  });
}
