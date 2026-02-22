import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/repository/members_repository.dart';
import 'package:ruderbar_terminal/repository/transactions_repository.dart';
import 'package:ruderbar_terminal/services/network_service.dart';

class MembersService {
  final MembersRepository _repository;
  final TransactionsRepository _transactionsRepository;
  final NetworkService? _networkService;

  MembersService({
    required MembersRepository repository,
    required TransactionsRepository transactionsRepository,
    NetworkService? networkService,
  })  : _repository = repository,
        _transactionsRepository = transactionsRepository,
        _networkService = networkService;

  /// Look up member by RFID card UID.
  /// On success, opportunistically syncs pending transactions for a fresh balance.
  Future<(MembersCacheData?, String?)> lookupByRfid(String cardUid) async {
    final (member, error) = await _repository.findByCardUid(cardUid);
    if (member == null) return (member, error);

    await _refreshBalance(member.id);

    // Re-read from DB so any updated balance is reflected
    final updated = await _repository.findById(member.id);
    return (updated ?? member, null);
  }

  /// Attempt to refresh member balance from backend via opportunistic sync.
  /// Sends any unsynced transactions and applies the returned memberBalances.
  /// Silently swallows all errors — offline is fine.
  Future<void> _refreshBalance(String memberId) async {
    final network = _networkService;
    if (network == null) return;
    try {
      final unsynced = await _transactionsRepository.getUnsyncedTransactions();
      final payload = unsynced
          .map((t) => {
                'id': t.id,
                'member_id': t.memberId,
                'product_id': t.productId,
                'amount_cents': t.amountCents,
                'transaction_type': t.transactionType,
                'notes': t.notes,
                'created_at': t.createdAt,
              })
          .toList();
      final response = await network.syncTransactions(payload);
      final freshBalance = response.memberBalances[memberId];
      if (freshBalance != null) {
        await _repository.updateMemberBalance(memberId, freshBalance);
      }
    } catch (_) {
      // Network unavailable or error — silent fallback to cached balance
    }
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
