// Issue #18: card UIDs are compared as exact strings, so the spelling they
// arrive in must never decide whether a member gets in.
//
// The spelling varies far beyond case. The same chip reaches the terminal as
// `001EB4CB`, `001eb4cb`, `00:1E:B4:CB`, `0x001EB4CB`, `1EB4CB` (leading zero
// byte dropped), `0002011339` (decimal) or `CBB41E00` (bytes reversed),
// depending purely on how the reader is configured. Replacing a broken reader
// with a differently configured one must not invalidate every member card.
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/utils/card_uid.dart';

void main() {
  // The worked example from the ADR, in every dialect a reader can print it.
  const canonical = '001EB4CB';

  group('normalizeCardUid — hex dialects (reader-independent)', () {
    test('upper-cases lower-case hex from a keyboard-wedge reader', () {
      expect(normalizeCardUid('001eb4cb'), canonical);
    });

    test('leaves an already canonical UID untouched', () {
      expect(normalizeCardUid(canonical), canonical);
    });

    test('collapses mixed case onto the same value', () {
      expect(normalizeCardUid('001Eb4Cb'), normalizeCardUid('001eB4cB'));
    });

    test('strips the whitespace a reader appends around the UID', () {
      expect(normalizeCardUid('  001eb4cb  '), canonical);
    });

    test('strips the separators a reader groups bytes with', () {
      expect(normalizeCardUid('00:1E:B4:CB'), canonical);
      expect(normalizeCardUid('00-1E-B4-CB'), canonical);
      expect(normalizeCardUid('00 1E B4 CB'), canonical);
      expect(normalizeCardUid('00.1e.b4.cb'), canonical);
    });

    test('strips the 0x a diagnostic tool prints', () {
      expect(normalizeCardUid('0x001EB4CB'), canonical);
      expect(normalizeCardUid('0X001eb4cb'), canonical);
    });

    test('restores leading zero bytes a reader dropped', () {
      // The same chip, printed without its leading zero byte.
      expect(normalizeCardUid('1EB4CB'), canonical);
      expect(normalizeCardUid('1eb4cb'), canonical);
    });

    test('restores a dropped leading nibble, which is half a byte', () {
      expect(normalizeCardUid('1EB4CBA'), '01EB4CBA');
    });

    test('does not pad a UID that is already at least four bytes', () {
      expect(normalizeCardUid('AABBCCDD01'), 'AABBCCDD01');
    });

    test('an empty scan stays empty, so callers can still reject it', () {
      expect(normalizeCardUid('   '), '');
    });

    test('an unreadable scan survives verbatim for the scan log', () {
      // Not a UID in any dialect. It must match nothing — which it does, since
      // no stored UID is spelled this way — while still being legible to
      // whoever reads the scan log to work out what the reader is doing.
      expect(normalizeCardUid(' hello '), 'HELLO');
    });
  });

  group('normalizeCardUid — decimal readers', () {
    test('reads a zero-padded decimal UID as the hex it stands for', () {
      // 0x001EB4CB == 2012363. A 125 kHz wedge pads to the ten digits a 32-bit
      // value needs.
      expect(
        normalizeCardUid('0002012363', format: CardUidFormat.decimal),
        canonical,
      );
    });

    test('reads the same value without its decimal padding', () {
      expect(
        normalizeCardUid('2012363', format: CardUidFormat.decimal),
        canonical,
      );
    });

    test('a decimal reader still prints hex for a UID containing A-F', () {
      // Not decodable as decimal, so it is read as what it plainly is.
      expect(
        normalizeCardUid('AABBCCDD', format: CardUidFormat.decimal),
        'AABBCCDD',
      );
    });

    test('a hex-configured terminal does not guess at decimal', () {
      // The whole reason the format is configured rather than sniffed:
      // 0002012363 is a valid 5-byte hex UID too, and only the reader knows.
      expect(normalizeCardUid('0002012363'), '0002012363');
    });

    test('a decimal UID wider than four bytes keeps its width', () {
      // 0x01A2B3C4D5 == 7024657621, an EM4100 five-byte id.
      expect(
        normalizeCardUid('7024657621', format: CardUidFormat.decimal),
        '01A2B3C4D5',
      );
    });
  });

  group('normalizeCardUid — byte order', () {
    test('a reversed hex reader lands on the same member', () {
      expect(
        normalizeCardUid('CBB41E00', format: CardUidFormat.hexReversed),
        canonical,
      );
    });

    test('a reversed reader that dropped a zero byte still lands there', () {
      // The zero byte a reversed reader drops is the one it would have sent
      // *last*, so the padding has to go on after the reversal. Padding first
      // would read `CBB41E` as `00CBB41E` and end up at `1EB4CB00` — a chip
      // nobody owns.
      expect(
        normalizeCardUid('CBB41E', format: CardUidFormat.hexReversed),
        canonical,
      );
    });

    test('a reversed decimal reader lands on the same member', () {
      // 0xCBB41E00 == 3417579008, printed by a reversed decimal wedge.
      expect(
        normalizeCardUid('3417579008', format: CardUidFormat.decimalReversed),
        canonical,
      );
    });

    test('a reversed decimal reader reads a chip with a trailing zero byte', () {
      // Chip CBB41E00 reaches such a reader as the value 0x001EB4CB == 2012363:
      // the width has to be restored before the reversal, or the zero byte
      // comes back on the wrong end as 00CBB41E.
      expect(
        normalizeCardUid('2012363', format: CardUidFormat.decimalReversed),
        'CBB41E00',
      );
    });

    test('reversing twice is the identity', () {
      final once = normalizeCardUid(canonical, format: CardUidFormat.hexReversed);
      expect(normalizeCardUid(once, format: CardUidFormat.hexReversed), canonical);
    });
  });

  group('CardUidFormat', () {
    test('parses the names accepted in config.json', () {
      expect(CardUidFormat.tryParse('hex'), CardUidFormat.hex);
      expect(CardUidFormat.tryParse('hex-reversed'), CardUidFormat.hexReversed);
      expect(CardUidFormat.tryParse('decimal'), CardUidFormat.decimal);
      expect(CardUidFormat.tryParse('decimal-reversed'),
          CardUidFormat.decimalReversed);
    });

    test('is forgiving about case and padding in the config value', () {
      expect(CardUidFormat.tryParse(' Hex-Reversed '),
          CardUidFormat.hexReversed);
    });

    test('an unknown name is null rather than a silent fallback', () {
      // A misspelled profile that quietly became `hex` would invalidate every
      // card on the terminal with no message anywhere — exactly the failure
      // this file exists to prevent.
      expect(CardUidFormat.tryParse('hexadecimal'), isNull);
      expect(CardUidFormat.tryParse(null), isNull);
    });

    test('names round-trip', () {
      for (final name in CardUidFormat.names) {
        expect(CardUidFormat.tryParse(name)!.name, name);
      }
    });
  });

  group('isCanonicalCardUid', () {
    test('accepts whole-byte uppercase hex from four to ten bytes', () {
      expect(isCanonicalCardUid('001EB4CB'), isTrue);
      expect(isCanonicalCardUid('AABBCCDDEEFF00112233'), isTrue);
    });

    test('rejects the spellings normalization exists to remove', () {
      expect(isCanonicalCardUid('001eb4cb'), isFalse);
      expect(isCanonicalCardUid('00:1E:B4:CB'), isFalse);
      expect(isCanonicalCardUid('1EB4CB'), isFalse); // three bytes
      expect(isCanonicalCardUid('01EB4CB'), isFalse); // half a byte
      expect(isCanonicalCardUid('AABBCCDDEEFF001122334'), isFalse); // > 10 bytes
    });
  });

  group('normalizeCardUidOrNull', () {
    test('a member without a card keeps no card', () {
      // An anonymized member has no UID; turning that into '' would make it
      // collide with an empty scan.
      expect(normalizeCardUidOrNull(null), isNull);
    });

    test('normalizes a present UID like normalizeCardUid', () {
      expect(normalizeCardUidOrNull(' 001eb4cb '), canonical);
    });
  });
}
