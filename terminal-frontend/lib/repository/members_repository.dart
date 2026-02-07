import 'package:drift/drift.dart' hide Column;
import '../database/database.dart';
import '../models/member_dto.dart';

class MembersRepository {
  final RuderbarDatabase _db;

  MembersRepository(this._db);

  /// Find member by card UID (fast lookup for terminal access)
  Future<(MembersCacheData?, String?)> findByCardUid(String cardUid) async {
    try {
      final member = await (_db.select(_db.membersCache)
            ..where((m) => m.cardUid.equals(cardUid)))
          .getSingleOrNull();

      if (member == null) {
        return (null, 'Unknown card');
      }

      if (member.isActive == 0) {
        return (null, 'Account inactive');
      }

      if (member.isSepaValid == 0) {
        return (null, 'SEPA mandate missing');
      }

      return (member, null);
    } catch (e) {
      return (null, 'Database error: $e');
    }
  }

  /// Upsert members from sync response (INSERT OR REPLACE)
  Future<void> upsertMembers(List<MemberDTO> members) async {
    for (final dto in members) {
      await _db.into(_db.membersCache).insertOnConflictUpdate(
        MembersCacheCompanion(
          id: Value(dto.id),
          cardUid: Value(dto.cardUid),
          firstName: Value(dto.firstName),
          lastName: Value(dto.lastName),
          preferredLanguage: Value(dto.preferredLanguage),
          isActive: Value(dto.isActive ? 1 : 0),
          isSepaValid: Value(dto.isSepaValid ? 1 : 0),
          updatedAt: Value(dto.updatedAt),
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

  /// Clear all member cache (for logout or reset)
  Future<void> clearCache() async {
    await _db.delete(_db.membersCache).go();
  }
}
