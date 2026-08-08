import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/repository/members_repository.dart';
import 'package:clubbar_terminal/repository/transactions_repository.dart';
import 'package:clubbar_terminal/services/network_service.dart';

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
  Future<(MembersCacheData?, TerminalErrorKey?)> lookupByRfid(
      String cardUid) async {
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
  ///
  /// The request always *names* the scanned member (#191). The backend reports
  /// balances only for members it was asked about, and the common case here is
  /// an empty batch: after a settlement collects the tab there is nothing left
  /// to upload and no next purchase to reveal the change, so a request that did
  /// not name the member left the pre-settlement Deckel on screen.
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
      final response =
          await network.syncTransactions(payload, memberIds: [memberId]);
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
    return _transactionsRepository.getEffectiveBalance(member);
  }

  /// Get all active members
  Future<List<MembersCacheData>> getAllMembers() async {
    return _repository.getAllActive();
  }

  /// Refresh members from repository
  Future<List<MembersCacheData>> refreshMembers() async {
    return _repository.getAllActive();
  }

  /// Find member by ID
  Future<MembersCacheData?> findMemberById(String memberId) async {
    return _repository.findById(memberId);
  }

  /// Update member's preferred language in local cache and backend (best-effort).
  /// Always updates local cache immediately; silently skips backend if offline.
  Future<void> updateLanguage(String memberId, String language) async {
    await _repository.updatePreferredLanguage(memberId, language);

    final network = _networkService;
    if (network != null) {
      try {
        await network.updateMemberLanguage(memberId, language);
      } catch (_) {
        // Offline or network error — local cache updated, backend will sync later
      }
    }
  }
}
