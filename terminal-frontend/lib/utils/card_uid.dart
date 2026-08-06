/// Canonical form of an RFID card UID: trimmed and upper-cased.
///
/// Card lookup is an exact string match (`MembersRepository.findByCardUid`), so
/// before this existed the case a UID happened to arrive in decided whether a
/// valid member got in. A USB keyboard-wedge reader that types `abcd1234` was
/// rejected as "Unknown token" against a member stored as `ABCD1234`, and vice
/// versa — a per-hardware, per-seeding failure that looked random from the bar
/// (issue #18).
///
/// Every UID entering the terminal passes through here — both scan paths on the
/// way in and the members cache on the way to disk — so the comparison is only
/// ever made between canonical values.
///
/// Upper case rather than lower: card UIDs are hex, and upper-case hex is what
/// readers and the admin panel print.
String normalizeCardUid(String cardUid) => cardUid.trim().toUpperCase();

/// [normalizeCardUid] for the cache and API shapes where a member may carry no
/// card at all (an anonymized member keeps its booking history but loses its
/// UID). "No card" stays null rather than becoming an empty UID that a scan
/// could accidentally match.
String? normalizeCardUidOrNull(String? cardUid) =>
    cardUid == null ? null : normalizeCardUid(cardUid);
