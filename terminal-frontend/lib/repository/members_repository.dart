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
            ..where((m) => m.cardUid.equals(normalizedUid) & m.deletedAt.isNull()))
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
  ///
  /// Tombstones travel this path too: a deleted member arrives in the same delta
  /// as any other change, and writing [Member.deletedAt] through is the whole of
  /// the deletion handling. The row is kept because `transactions_local`
  /// references it — see [MembersCache.deletedAt].
  ///
  /// A tombstoned member also gives up their card UID. `card_uid` is UNIQUE, so
  /// a row that keeps a released card would block the member it gets reassigned
  /// to; and a card with no member to resolve to is exactly what a scan should
  /// treat as unknown.
  ///
  /// That same UNIQUE index is why a chip handed on to another member has to
  /// release its previous holder here (issue #370). The upsert conflicts on the
  /// primary key only, so a delta that named the *new* holder before the old
  /// one's release raised `UNIQUE constraint failed: members_cache.card_uid`
  /// out of the first step of the sync cycle — and since the cursor only
  /// advances on success, every later cycle re-fetched the same delta and
  /// failed the same way. A terminal in that state quietly stops learning about
  /// anything: no new members, no product changes, and no newly registered chip
  /// for as long as it runs. The backend guarantees a UID sits at one member at
  /// a time, so mirroring that here — last writer takes the card — cannot lose
  /// state the next delta will not bring back.
  ///
  /// The batch is one transaction: half an applied delta with the cursor
  /// already advanced is not a state the terminal can tell from a complete one.
  Future<void> upsertMembers(List<Member> members) async {
    await _db.transaction(() async {
      for (final dto in members) {
        final isDeleted = dto.deletedAt != null;
        final cardUid =
            isDeleted ? null : normalizeCardUidOrNull(dto.cardUid);

        if (cardUid != null) await _releaseCardFromOtherMembers(cardUid, dto.id);

        await _db.into(_db.membersCache).insertOnConflictUpdate(
          MembersCacheCompanion(
            id: Value(dto.id),
            cardUid: Value(cardUid),
            firstName: Value(dto.firstName),
            lastName: Value(dto.lastName),
            // Stored as the bare day. The generated client models the field as
            // a DateTime because the spec says `format: date`; keeping only
            // `YYYY-MM-DD` in the cache means no timezone can move a birthday
            // across a day boundary on the device.
            dateOfBirth: Value(_dayOrNull(dto.dateOfBirth)),
            preferredLanguage: Value(dto.preferredLanguage),
            isActive: Value(dto.isActive ? 1 : 0),
            isSepaValid: Value(dto.isSepaValid ? 1 : 0),
            updatedAt: Value(dto.updatedAt.toIso8601String()),
            deletedAt: Value(dto.deletedAt?.toIso8601String()),
          ),
        );
      }
    });
  }

  /// `YYYY-MM-DD` for [value], or null when there is none.
  ///
  /// Null is preserved rather than defaulted: it means an anonymized member
  /// (ADR-0045 rule 3), which the checkout refuses — inventing a date here
  /// would turn an erased member into a buyer.
  static String? _dayOrNull(DateTime? value) {
    if (value == null) return null;

    return '${value.year.toString().padLeft(4, '0')}-'
        '${value.month.toString().padLeft(2, '0')}-'
        '${value.day.toString().padLeft(2, '0')}';
  }

  /// Take [cardUid] away from whoever else holds it locally, so [keepMemberId]
  /// can be given it. A no-op in the overwhelmingly common case where nobody
  /// else does.
  Future<void> _releaseCardFromOtherMembers(
      String cardUid, String keepMemberId) async {
    await (_db.update(_db.membersCache)
          ..where((m) => m.cardUid.equals(cardUid) & m.id.equals(keepMemberId).not()))
        .write(const MembersCacheCompanion(cardUid: Value(null)));
  }

  /// Get all active members (for testing/debugging)
  Future<List<MembersCacheData>> getAllActive() async {
    return (_db.select(_db.membersCache)
          ..where((m) => m.isActive.equals(1) & m.deletedAt.isNull()))
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
  /// Anonymized members are excluded: there is no tab to show for a member the
  /// terminal will never resolve a card to again.
  Future<List<String>> getMemberIdsWithOpenBalance() async {
    final rows = await (_db.select(_db.membersCache)
          ..where((m) => m.balanceCents.equals(0).not() & m.deletedAt.isNull()))
        .get();
    return rows.map((m) => m.id).toList();
  }

  /// Update member balance (called during atomic sync completion)
  Future<void> updateMemberBalance(String memberId, int balanceCents) async {
    await (_db.update(_db.membersCache)
          ..where((m) => m.id.equals(memberId)))
        .write(MembersCacheCompanion(balanceCents: Value(balanceCents)));
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
