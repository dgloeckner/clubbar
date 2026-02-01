import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/repository/members_repository.dart';

class MembersService {
  final MembersRepository _repository;

  MembersService({required MembersRepository repository})
      : _repository = repository;

  /// Look up member by RFID card UID
  /// Returns tuple: (member, errorMessage)
  /// The repository already handles validation (active, SEPA signed)
  Future<(MembersCacheData?, String?)> lookupByRfid(String cardUid) async {
    return _repository.findByCardUid(cardUid);
  }

  /// Get all active members
  Future<List<MembersCacheData>> getAllMembers() async {
    return _repository.getAllActive();
  }

  /// Refresh members from repository
  Future<List<MembersCacheData>> refreshMembers() async {
    return _repository.getAllActive();
  }
}
