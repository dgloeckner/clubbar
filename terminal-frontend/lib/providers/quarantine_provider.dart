import 'package:flutter/foundation.dart';
import 'package:clubbar_terminal/models/failed_sale.dart';
import 'package:clubbar_terminal/repository/members_repository.dart';
import 'package:clubbar_terminal/repository/transactions_repository.dart';

/// The terminal's quarantine: sales the backend permanently refused.
///
/// Under the sync ruling a permanent rejection always indicates a bug, so this
/// list should stay empty. When it does not, staff must see it — hence the
/// persistent warning driven by [hasFailedSales] (issue #152).
class QuarantineProvider extends ChangeNotifier {
  final TransactionsRepository _transactionsRepo;
  final MembersRepository _membersRepo;

  List<FailedSale> _failedSales = const [];
  bool _disposed = false;

  QuarantineProvider({
    required TransactionsRepository transactionsRepo,
    required MembersRepository membersRepo,
  })  : _transactionsRepo = transactionsRepo,
        _membersRepo = membersRepo;

  List<FailedSale> get failedSales => _failedSales;

  bool get hasFailedSales => _failedSales.isNotEmpty;

  /// Re-read the quarantine from local storage. Called after every sync cycle,
  /// which is the only thing that can add to it.
  Future<void> refresh() async {
    final rows = await _transactionsRepo.getQuarantinedTransactions();

    final sales = <FailedSale>[];
    for (final row in rows) {
      final member = await _membersRepo.findById(row.memberId);
      sales.add(FailedSale(
        transactionId: row.id,
        memberId: row.memberId,
        memberName: _displayName(member?.firstName, member?.lastName),
        amountCents: row.amountCents,
        // `created_at` is stored UTC, so parsing it yields a UTC DateTime and
        // formatting it prints UTC components. The transaction history
        // screen renders the same column with `.toLocal()`, so without this
        // the same sale read two different times on the same device (#365).
        occurredAt: DateTime.parse(row.createdAt).toLocal(),
        reason: row.quarantineReason,
      ));
    }

    _failedSales = sales;
    notifyListeners();
  }

  static String? _displayName(String? firstName, String? lastName) {
    final parts = [firstName, lastName]
        .where((p) => p != null && p.isNotEmpty)
        .cast<String>();
    return parts.isEmpty ? null : parts.join(' ');
  }

  @override
  void notifyListeners() {
    if (_disposed) return;
    super.notifyListeners();
  }

  @override
  void dispose() {
    _disposed = true;
    super.dispose();
  }
}
