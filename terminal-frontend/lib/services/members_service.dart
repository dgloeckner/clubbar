import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/repository/members_repository.dart';
import 'package:ruderbar_terminal/repository/transactions_repository.dart';

class MembersService {
  final MembersRepository _repository;
  final TransactionsRepository _transactionsRepository;

  MembersService({
    required MembersRepository repository,
    required TransactionsRepository transactionsRepository,
  })  : _repository = repository,
        _transactionsRepository = transactionsRepository;

  /// Look up member by RFID card UID
  /// Returns tuple: (member, errorMessage)
  /// The repository already handles validation (active, SEPA signed)
  Future<(MembersCacheData?, String?)> lookupByRfid(String cardUid) async {
    return _repository.findByCardUid(cardUid);
  }

  /// Compute effective balance (Deckel) for a member:
  /// synced balance from backend + sum of unsynced local transactions
  Future<int> getEffectiveBalance(MembersCacheData member) async {
    final unsyncedAmount =
        await _transactionsRepository.getUnsyncedAmountForMember(member.id);
    return member.balanceCents + unsyncedAmount;
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
