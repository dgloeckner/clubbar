import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/repository/members_repository.dart';
import 'package:clubbar_terminal/repository/transactions_repository.dart';
import 'package:clubbar_terminal/services/network_service.dart';

class MembersService {
  /// How long a card scan may wait for the backend to answer with a fresh
  /// balance before the cached one is used instead (#374).
  ///
  /// Short on purpose: this sits between the card touching the reader and the
  /// product grid appearing. An unreachable backend usually fails fast, but a
  /// network that accepts the connection and then goes quiet would otherwise
  /// hold the member at the idle screen for the full HTTP timeout.
  static const Duration balanceRefreshTimeout = Duration(seconds: 3);

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
  /// On success, refreshes the member's balance from the backend.
  Future<(MembersCacheData?, TerminalErrorKey?)> lookupByRfid(
      String cardUid) async {
    final (member, error) = await _repository.findByCardUid(cardUid);
    if (member == null) return (member, error);

    return (await refreshBalance(member.id) ?? member, null);
  }

  /// Ask the backend what [memberId] owes right now and store the answer.
  ///
  /// Returns the refreshed cache row, or null when the member is not in the
  /// cache at all. Every failure is swallowed: offline is a normal state for
  /// this terminal, and the cached balance is what it degrades to (ADR-0023).
  ///
  /// The request names the member in `member_ids` and uploads **nothing**
  /// (#191). Naming is what makes an empty batch a question, and it is the
  /// only way to learn about a change the terminal did not cause — a storno,
  /// a settlement, a sale on another terminal (#374).
  ///
  /// Uploading the pending queue from here would be wrong twice over: the
  /// returned balance would count rows this method does not mark as synced, so
  /// [TransactionsRepository.getEffectiveBalance] would add them a second time,
  /// and a queue over the 100-row batch limit would be refused whole, leaving
  /// the scan with no balance at all. Uploading belongs to `SyncService`.
  Future<MembersCacheData?> refreshBalance(String memberId) async {
    final network = _networkService;
    if (network == null) return _repository.findById(memberId);

    try {
      final response = await network
          .syncTransactions(const [], memberIds: [memberId])
          .timeout(balanceRefreshTimeout);

      // An unknown member is omitted from the map rather than reported as 0,
      // so an absent key must leave the cached balance alone (ADR-0023).
      final fresh = response.memberBalances[memberId];
      if (fresh is num) {
        await _repository.updateMemberBalance(memberId, fresh.toInt());
      }
    } catch (_) {
      // Network unavailable, slow or erroring — keep the cached balance.
    }

    return _repository.findById(memberId);
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
