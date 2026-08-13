import 'package:drift/drift.dart' hide Column;
import '../database/database.dart';
import '../generated/terminal.swagger.dart';
import '../models/terminal_error.dart';
import '../utils/app_logger.dart';
import '../utils/card_uid.dart';

class MembersRepository {
  final ClubBarDatabase _db;

  MembersRepository(this._db);

  /// Find member by card UID (fast lookup for terminal access)
  /// Returns (member, errorKey); the key is localized at render time.
  ///
  /// Both sides of the comparison are canonicalized — the argument here and the
  /// stored value in [upsertMembers] — so a card is matched by its UID and not
  /// by the case the reader or the backend happened to use (issue #18).
  Future<(MembersCacheData?, TerminalErrorKey?)> findByCardUid(
      String cardUid) async {
    try {
      final normalizedUid = normalizeCardUid(cardUid);
      final member = await (_db.select(_db.membersCache)
            ..where((m) => m.cardUid.equals(normalizedUid)))
          .getSingleOrNull();

      if (member == null) {
        return (null, TerminalErrorKey.unknownCard);
      }

      if (member.isActive == 0) {
        return (null, TerminalErrorKey.accountInactive);
      }

      if (member.isSepaValid == 0) {
        return (null, TerminalErrorKey.sepaMissing);
      }

      return (member, null);
    } catch (e, stackTrace) {
      AppLog.instance.e('Member lookup by card UID failed',
          error: e, stackTrace: stackTrace);
      return (null, TerminalErrorKey.memberLookupFailed);
    }
  }

  /// Upsert members from sync response (INSERT OR REPLACE)
  ///
  /// Card UIDs are canonicalized on the way in, so the cache never holds a
  /// value a scan cannot match — whatever case the backend stores them in
  /// (issue #18).
  Future<void> upsertMembers(List<Member> members) async {
    for (final dto in members) {
      await _db.into(_db.membersCache).insertOnConflictUpdate(
        MembersCacheCompanion(
          id: Value(dto.id),
          cardUid: Value(normalizeCardUidOrNull(dto.cardUid)),
          firstName: Value(dto.firstName),
          lastName: Value(dto.lastName),
          preferredLanguage: Value(dto.preferredLanguage),
          isActive: Value(dto.isActive ? 1 : 0),
          isSepaValid: Value(dto.isSepaValid ? 1 : 0),
          updatedAt: Value(dto.updatedAt.toIso8601String()),
        ),
      );
    }
  }

  /// Get all active members (for testing/debugging)
  Future<List<MembersCacheData>> getAllActive() async {
    return (_db.select(_db.membersCache)
          ..where((m) => m.isActive.equals(1)))
        .get();
  }

  /// Find member by ID (for re-reading after balance update)
  Future<MembersCacheData?> findById(String memberId) async {
    return (_db.select(_db.membersCache)
          ..where((m) => m.id.equals(memberId)))
        .getSingleOrNull();
  }

  /// Ids of cached members whose last known tab is not zero.
  ///
  /// The set the periodic sync re-asks the backend about (#374). A cached zero
  /// cannot go stale in a way the member sees — it only grows again through a
  /// purchase, and a purchase brings its own balance back in the sync response
  /// — whereas a cached debt goes stale the moment an admin stornos or settles
  /// it, which is exactly the number the credit-limit banner shouts about.
  Future<List<String>> getMemberIdsWithOpenBalance() async {
    final rows = await (_db.select(_db.membersCache)
          ..where((m) => m.balanceCents.equals(0).not()))
        .get();
    return rows.map((m) => m.id).toList();
  }

  /// Update member balance (called during atomic sync completion)
  Future<void> updateMemberBalance(String memberId, int balanceCents) async {
    await (_db.update(_db.membersCache)
          ..where((m) => m.id.equals(memberId)))
        .write(MembersCacheCompanion(balanceCents: Value(balanceCents)));
  }

  /// Delete member by ID (for tombstone handling)
  Future<void> deleteById(String memberId) async {
    await (_db.delete(_db.membersCache)
          ..where((m) => m.id.equals(memberId)))
        .go();
  }

  /// Update member's preferred language in local cache
  Future<void> updatePreferredLanguage(String memberId, String language) async {
    await (_db.update(_db.membersCache)
          ..where((m) => m.id.equals(memberId)))
        .write(MembersCacheCompanion(preferredLanguage: Value(language)));
  }

  /// Clear all member cache (for logout or reset)
  Future<void> clearCache() async {
    await _db.delete(_db.membersCache).go();
  }
}
