import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/utils/age.dart';

/// The arithmetic the whole Jugendschutz gate rests on (epic #582 M6, ADR-0045).
///
/// It is pulled out as a pure function for one reason: **the birthday boundary
/// is where off-by-one errors live**, and it is far easier to state every case
/// against a fixed clock here than to reach them through a cart.
///
/// The terminal computes this itself, from its own clock, because it may not
/// have spoken to the server in weeks (decision 1). Nothing here reads a stored
/// age — an age in years is wrong from the member's next birthday onward.
void main() {
  group('hasReachedAge', () {
    // A fixed "today" so these cases mean the same thing next year.
    final today = DateTime(2026, 8, 20);

    test('the day before the birthday is still too young', () {
      expect(hasReachedAge(DateTime(2008, 8, 21), 18, today), isFalse);
    });

    /// The case an off-by-one gets wrong, and the one a member notices: they
    /// are 18 *on* their eighteenth birthday, not the day after.
    test('the birthday itself counts', () {
      expect(hasReachedAge(DateTime(2008, 8, 20), 18, today), isTrue);
    });

    test('the day after is obviously fine', () {
      expect(hasReachedAge(DateTime(2008, 8, 19), 18, today), isTrue);
    });

    test('a comfortably older member passes', () {
      expect(hasReachedAge(DateTime(1985, 6, 15), 18, today), isTrue);
    });

    test('a child fails both thresholds', () {
      final born2012 = DateTime(2012, 1, 1);
      expect(hasReachedAge(born2012, 16, today), isFalse);
      expect(hasReachedAge(born2012, 18, today), isFalse);
    });

    test('the two JuSchG thresholds are decided independently', () {
      // Seventeen: old enough for beer, not for spirits. The one member for
      // whom § 9's two limits actually differ.
      final seventeen = DateTime(2009, 3, 4);
      expect(hasReachedAge(seventeen, 16, today), isTrue);
      expect(hasReachedAge(seventeen, 18, today), isFalse);
    });

    /// A leap-day birth has no anniversary in three years out of four. German
    /// practice (§§ 187–188 BGB) puts the birthday at 1 March in those years,
    /// which is what `DateTime(2026, 2, 29)` normalizes to — so the rule is
    /// followed by the arithmetic rather than by a special case.
    group('a leap-day birth', () {
      final born = DateTime(2008, 2, 29);

      test('is not yet 18 on 28 February of a non-leap year', () {
        expect(hasReachedAge(born, 18, DateTime(2026, 2, 28)), isFalse);
      });

      test('turns 18 on 1 March of a non-leap year', () {
        expect(hasReachedAge(born, 18, DateTime(2026, 3, 1)), isTrue);
      });

      test('turns 18 on 29 February when the year has one', () {
        expect(hasReachedAge(born, 18, DateTime(2028, 2, 29)), isTrue);
      });
    });

    /// The time of day must not decide a legal question. A terminal open past
    /// midnight is the ordinary case for a bar.
    test('the clock time within the day is irrelevant', () {
      final born = DateTime(2008, 8, 20);
      expect(hasReachedAge(born, 18, DateTime(2026, 8, 20, 0, 0, 1)), isTrue);
      expect(hasReachedAge(born, 18, DateTime(2026, 8, 20, 23, 59, 59)), isTrue);
      expect(hasReachedAge(born, 18, DateTime(2026, 8, 19, 23, 59, 59)), isFalse);
    });

    test('a birth date in the future is never old enough', () {
      expect(hasReachedAge(DateTime(2027, 1, 1), 1, today), isFalse);
    });
  });

  group('parseCachedDateOfBirth', () {
    test('reads the wire format the sync stores', () {
      expect(parseCachedDateOfBirth('1985-06-15'), DateTime(1985, 6, 15));
    });

    test('reads a full timestamp, keeping only the day', () {
      expect(
        parseCachedDateOfBirth('1985-06-15T00:00:00.000Z'),
        DateTime(1985, 6, 15),
      );
    });

    /// Null is not "unknown" — it is an anonymized member (ADR-0045 rule 3),
    /// and the caller must refuse rather than fall open. Returning null here
    /// keeps that decision at the call site instead of inventing a date.
    test('gives nothing back for an absent or unusable value', () {
      expect(parseCachedDateOfBirth(null), isNull);
      expect(parseCachedDateOfBirth(''), isNull);
      expect(parseCachedDateOfBirth('not a date'), isNull);
    });
  });
}
