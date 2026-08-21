/// Age arithmetic for the Jugendschutz gate (ADR-0045).
///
/// The terminal answers "is this member old enough" from **its own clock and a
/// raw birth date**, never from a number the server computed. An age in years
/// is wrong from the member's next birthday until the next sync, and a kiosk
/// may not reach the server for weeks — so the arithmetic lives here, on the
/// device, and runs at the moment of checkout.
library;

/// Whether someone born on [dateOfBirth] has reached [years] years by [asOf].
///
/// The birthday itself counts: a member is 18 *on* their eighteenth birthday,
/// not the day after. Only the calendar day matters — a bar open past midnight
/// must not get a different answer from the same day's clock.
///
/// A leap-day birth has no anniversary in three years out of four. German
/// practice (§§ 187–188 BGB) treats 1 March as the birthday in those years, and
/// that falls out of the arithmetic rather than needing a special case:
/// `DateTime(2026, 2, 29)` normalizes to 2026-03-01.
bool hasReachedAge(DateTime dateOfBirth, int years, DateTime asOf) {
  final today = DateTime(asOf.year, asOf.month, asOf.day);
  final anniversary = DateTime(
    dateOfBirth.year + years,
    dateOfBirth.month,
    dateOfBirth.day,
  );

  return !today.isBefore(anniversary);
}

/// Read a birth date as the members cache stores it, or null if it cannot be.
///
/// Null is **not** "unknown, allow anyway". An absent birth date means an
/// anonymized member (ADR-0045 rule 3), and an unreadable one means a cache
/// this code should not be guessing about. Both come back as null so the
/// decision stays at the call site, which refuses — rather than being papered
/// over here with an invented date.
DateTime? parseCachedDateOfBirth(String? raw) {
  if (raw == null || raw.isEmpty) return null;

  // The sync writes `YYYY-MM-DD`; a full timestamp is accepted because the
  // generated client models the field as a DateTime and older cache rows may
  // carry one.
  final parsed = DateTime.tryParse(raw);
  if (parsed == null) return null;

  return DateTime(parsed.year, parsed.month, parsed.day);
}

/// Whether a member whose cache holds [cachedDateOfBirth] may buy a product
/// requiring [minAge], as of [asOf].
///
/// The single definition of "old enough", shared by the two places that ask:
/// `CartService.requiredAgeBlocking` (the authority) and the product grid (the
/// courtesy that greys a tile out). They must not be able to disagree — a tile
/// that looks buyable and a checkout that refuses it is worse than either
/// behaviour alone.
///
/// - A null [minAge] is unrestricted: always true, whatever the birth date.
/// - A null or unreadable birth date with a limit present is **false**. That
///   is ADR-0045 rule 3: absent means anonymized, never "unknown, allow
///   anyway". There is no fail-open branch here to find.
bool mayBuyAtAge(String? cachedDateOfBirth, int? minAge, DateTime asOf) {
  if (minAge == null) return true;

  final dateOfBirth = parseCachedDateOfBirth(cachedDateOfBirth);
  if (dateOfBirth == null) return false;

  return hasReachedAge(dateOfBirth, minAge, asOf);
}
