import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/l10n/terminal_error_messages.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/models/transaction_list_item.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/services/transaction_history_service.dart';
import 'package:clubbar_terminal/services/network_service.dart';
import 'package:clubbar_terminal/utils/formatters.dart';
import 'package:clubbar_terminal/utils/icon_registry.dart';
import 'package:clubbar_terminal/utils/app_logger.dart';
import 'package:clubbar_terminal/database/database.dart';

/// Show member details modal as a bottom sheet
void showMemberDetailsModal(BuildContext context) {
  showModalBottomSheet(
    context: context,
    isScrollControlled: true,
    backgroundColor: Colors.transparent,
    builder: (context) => const MemberDetailsModal(),
  );
}

class MemberDetailsModal extends StatefulWidget {
  const MemberDetailsModal({super.key});

  @override
  State<MemberDetailsModal> createState() => _MemberDetailsModalState();
}

class _MemberDetailsModalState extends State<MemberDetailsModal> {
  bool _isLoading = true;

  /// Which error to show, never *why* — the raw exception goes to the log.
  TerminalErrorKey? _errorKey;
  List<TransactionListItem> _transactions = [];
  bool _isOffline = false;

  // Independent scroll controller for the transaction list so that only the
  // list scrolls — the sheet header, member info, and language buttons stay fixed.
  final ScrollController _listScrollController = ScrollController();

  @override
  void initState() {
    super.initState();
    _loadTransactions();
  }

  @override
  void dispose() {
    _listScrollController.dispose();
    super.dispose();
  }

  Future<void> _loadTransactions() async {
    setState(() {
      _isLoading = true;
      _errorKey = null;
    });

    try {
      final membersProvider = context.read<MembersProvider>();
      final member = membersProvider.selectedMember;

      if (member == null) {
        setState(() {
          _errorKey = TerminalErrorKey.noMemberSelected;
          _isLoading = false;
        });
        return;
      }

      final service = TransactionHistoryService(
        networkService: context.read<NetworkService>(),
        database: context.read<ClubBarDatabase>(),
      );

      // Offline still yields the purchases this terminal recorded locally —
      // the same ones the displayed balance already accounts for (#32).
      final result = await service.fetchTransactionHistory(
        memberId: member.id,
        preferredLanguage: member.preferredLanguage,
      );

      if (!mounted) return;

      setState(() {
        _transactions = result.transactions;
        _isOffline = result.isOffline;
        _isLoading = false;
        _errorKey = null;
      });
    } catch (e, stackTrace) {
      logTerminalError(TerminalErrorKey.transactionHistoryFailed, e, stackTrace);
      setState(() {
        _errorKey = TerminalErrorKey.transactionHistoryFailed;
        _isLoading = false;
      });
    }
  }

  Future<void> _updateLanguage(String newLanguage) async {
    try {
      await context.read<MembersProvider>().updateSelectedMemberLanguage(newLanguage);
      // Language switching is why the sheet gets opened; once it succeeds the
      // member's next goal is shopping, not the sheet (#294).
      if (mounted) Navigator.of(context).pop();
    } catch (e, stackTrace) {
      AppLog.instance.e(
        'Failed to update member language',
        error: e,
        stackTrace: stackTrace,
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final member = context.watch<MembersProvider>().selectedMember;

    if (member == null) {
      return Container();
    }

    final firstName = member.firstName ?? '';
    final lastName = member.lastName ?? '';
    final initials = '${firstName.isNotEmpty ? firstName[0] : '?'}${lastName.isNotEmpty ? lastName[0] : '?'}'.toUpperCase();
    final membersProvider = context.watch<MembersProvider>();
    final balanceCents = membersProvider.memberDeckel ?? member.balanceCents;
    final locale = member.preferredLanguage;

    final screenHeight = MediaQuery.of(context).size.height;

    return SizedBox(
      height: screenHeight * 0.75,
      child: Container(
        decoration: const BoxDecoration(
            color: Color(0xff1e293b),
            borderRadius: BorderRadius.only(
              topLeft: Radius.circular(16),
              topRight: Radius.circular(16),
            ),
          ),
          child: Column(
            children: [
              // Header
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  border: Border(
                    bottom: BorderSide(
                      color: const Color(0xff475569).withValues(alpha: 0.3),
                      width: 1,
                    ),
                  ),
                ),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      l10n.memberDetails,
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: AppFontSizes.xxl,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    IconButton(
                      onPressed: () => Navigator.of(context).pop(),
                      icon: const Icon(Icons.close, color: Colors.white),
                    ),
                  ],
                ),
              ),

              // Member Info Section
              Padding(
                padding: const EdgeInsets.all(16),
                child: Row(
                  children: [
                    // Avatar
                    Container(
                      width: 48,
                      height: 48,
                      decoration: BoxDecoration(
                        color: const Color(0xffFF6B4A),
                        borderRadius: BorderRadius.circular(24),
                      ),
                      child: Center(
                        child: Text(
                          initials,
                          style: TextStyle(
                            color: Colors.white,
                            fontSize: AppFontSizes.xl,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ),
                    ),
                    const SizedBox(width: 12),
                    // Name and balance
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          '$firstName $lastName',
                          style: TextStyle(
                            color: Colors.white,
                            fontSize: AppFontSizes.xl,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                        Text(
                          formatBalance(balanceCents, l10n, locale),
                          style: TextStyle(
                            color: balanceColor(balanceCents),
                            fontSize: AppFontSizes.base,
                            fontWeight: FontWeight.w500,
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),

              // Language Section
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      l10n.preferredLanguage,
                      style: TextStyle(
                        color: const Color(0xffa1a1aa),
                        fontSize: AppFontSizes.base,
                        fontWeight: FontWeight.w500,
                      ),
                    ),
                    const SizedBox(height: 8),
                    Row(
                      children: [
                        _languageButton('de', 'Deutsch', locale == 'de'),
                        const SizedBox(width: 8),
                        _languageButton('en', 'English', locale == 'en'),
                      ],
                    ),
                  ],
                ),
              ),

              const SizedBox(height: 16),

              // Transactions Section Header
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      l10n.recentTransactions,
                      style: TextStyle(
                        color: const Color(0xffa1a1aa),
                        fontSize: AppFontSizes.base,
                        fontWeight: FontWeight.w500,
                      ),
                    ),
                    if (_isLoading)
                      const SizedBox(
                        width: 16,
                        height: 16,
                        child: CircularProgressIndicator(
                          strokeWidth: 2,
                          valueColor: AlwaysStoppedAnimation(Color(0xff3b82f6)),
                        ),
                      ),
                  ],
                ),
              ),

              const SizedBox(height: 12),

              // Transactions List or Error — only this area scrolls
              Expanded(
                child: _buildTransactionsList(l10n, locale),
              ),
            ],
          ),
        ),
    );
  }

  Widget _buildTransactionsList(AppLocalizations l10n, String locale) {
    if (_isLoading) {
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const CircularProgressIndicator(
              valueColor: AlwaysStoppedAnimation(Color(0xff3b82f6)),
            ),
            const SizedBox(height: 12),
            Text(
              l10n.loadingTransactions,
              style: TextStyle(
                color: const Color(0xffa1a1aa),
                fontSize: AppFontSizes.base,
              ),
            ),
          ],
        ),
      );
    }

    if (_errorKey != null) {
      // Scrollable: actionable copy is longer than a bare "Database error",
      // and this block sits in whatever height the sheet has left over.
      return SingleChildScrollView(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              const Icon(
                Icons.error_outline,
                color: Color(0xffef4444),
                size: 48,
              ),
              const SizedBox(height: 12),
              Text(
                l10n.errorLoadingTransactions,
                style: TextStyle(
                  color: Colors.white,
                  fontSize: AppFontSizes.lg,
                  fontWeight: FontWeight.w600,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                _errorKey!.message(l10n),
                style: TextStyle(
                  color: const Color(0xffa1a1aa),
                  fontSize: AppFontSizes.sm,
                ),
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 16),
              // Strong blue/white/etc. now come from the app theme (#301) —
              // this used to duplicate elevatedButtonTheme's defaults exactly.
              ElevatedButton.icon(
                onPressed: _loadTransactions,
                icon: const Icon(Icons.refresh),
                label: Text(l10n.retry),
              ),
            ],
          ),
        ),
      );
    }

    // Offline with local purchases: show them. The balance above already
    // counts them, so hiding them would be a visible contradiction (#32).
    if (_isOffline && _transactions.isNotEmpty) {
      return Column(
        children: [
          _offlineBanner(l10n),
          const SizedBox(height: 8),
          Expanded(child: _transactionsListView(locale)),
        ],
      );
    }

    if (_isOffline) {
      // Scrollable, because the notice sits in a fixed-height slot and the
      // type scale is a deployment setting (#41): at the kiosk default this
      // column is 4 px taller than the space it is given. The minHeight keeps
      // the old centring when there *is* room — a bare scroll view would pin
      // the notice to the top — so all this changes is that "too tall"
      // degrades into a nudge rather than a striped overflow banner.
      return LayoutBuilder(
        builder: (context, constraints) => SingleChildScrollView(
          child: ConstrainedBox(
            constraints: BoxConstraints(minHeight: constraints.maxHeight),
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  const Icon(
                    Icons.cloud_off,
                    color: Color(0xff3b82f6),
                    size: 48,
                  ),
                  const SizedBox(height: 12),
                  Text(
                    l10n.offlineMode,
                    style: TextStyle(
                      color: Colors.white,
                      fontSize: AppFontSizes.lg,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    l10n.transactionHistoryUnavailableOffline,
                    style: TextStyle(
                      color: const Color(0xffa1a1aa),
                      fontSize: AppFontSizes.sm,
                    ),
                    textAlign: TextAlign.center,
                  ),
                ],
              ),
            ),
          ),
        ),
      );
    }

    if (_transactions.isEmpty) {
      return Center(
        child: Text(
          l10n.noTransactions,
          style: TextStyle(
            color: const Color(0xffa1a1aa),
            fontSize: AppFontSizes.base,
          ),
        ),
      );
    }

    return _transactionsListView(locale);
  }

  Widget _transactionsListView(String locale) {
    return ListView.separated(
      controller: _listScrollController,
      padding: const EdgeInsets.symmetric(horizontal: 16),
      itemCount: _transactions.length,
      separatorBuilder: (context, index) => Divider(
        color: const Color(0xff475569).withValues(alpha: 0.2),
        height: 1,
      ),
      itemBuilder: (context, index) {
        final transaction = _transactions[index];
        return _buildTransactionRow(transaction, locale);
      },
    );
  }

  /// Explains, above the list, that this is only what the terminal itself saw.
  Widget _offlineBanner(AppLocalizations l10n) {
    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 16),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: const Color(0xff3b82f6).withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        children: [
          const Icon(Icons.cloud_off, color: Color(0xff3b82f6), size: 20),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              l10n.offlineLocalTransactionsOnly,
              style: TextStyle(
                color: const Color(0xffa1a1aa),
                fontSize: AppFontSizes.sm,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildTransactionRow(TransactionListItem transaction, String locale) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 12),
      child: Row(
        children: [
          // Icon
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              color: const Color(0xff334155),
              borderRadius: BorderRadius.circular(20),
            ),
            child: Center(
              child: getProductIcon(
                transaction.productIcon,
                size: 27,
              ),
            ),
          ),
          const SizedBox(width: 12),
          // Details and timestamp
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  transaction.details,
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: AppFontSizes.base,
                    fontWeight: FontWeight.w500,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  _formatTransactionTimestamp(transaction.timestamp, locale),
                  style: TextStyle(
                    color: const Color(0xffa1a1aa),
                    fontSize: AppFontSizes.sm,
                  ),
                ),
              ],
            ),
          ),
          // Amount
          Text(
            formatPrice(transaction.amountCents, locale),
            style: TextStyle(
              color: transactionAmountColor(transaction.amountCents),
              fontSize: AppFontSizes.lg,
              fontWeight: FontWeight.w600,
            ),
          ),
          const SizedBox(width: 12),
          // Status badge
          _buildStatusBadge(transaction.syncStatus),
        ],
      ),
    );
  }

  Widget _buildStatusBadge(TransactionSyncStatus status) {
    IconData icon;
    Color color;

    switch (status) {
      case TransactionSyncStatus.unsynced:
        icon = Icons.sync_disabled;
        color = const Color(0xfff97316); // Orange
        break;
      case TransactionSyncStatus.open:
        icon = Icons.circle_outlined;
        color = const Color(0xff3b82f6); // Blue
        break;
      case TransactionSyncStatus.settled:
        icon = Icons.check_circle;
        color = const Color(0xff22c55e); // Green
        break;
    }

    return Icon(icon, color: color, size: 20);
  }

  Widget _languageButton(String code, String label, bool isSelected) {
    return InkWell(
      onTap: () => _updateLanguage(code),
      borderRadius: BorderRadius.circular(8),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
        decoration: BoxDecoration(
          // Strong blue: white on #3b82f6 is 3.7:1 (#41).
          color: isSelected
              ? hexToColor(AppColors.semanticPrimaryStrong)
              : const Color(0xff334155),
          borderRadius: BorderRadius.circular(8),
          border: Border.all(
            color: isSelected
                ? const Color(0xff60a5fa)
                : const Color(0xff475569),
            width: 1,
          ),
        ),
        child: Text(
          label,
          style: TextStyle(
            // #a1a1aa on #334155 was 4.0:1; this is a tappable language
            // label, so it needs to clear AA like any other text (#41).
            color: isSelected
                ? Colors.white
                : hexToColor(AppColors.textPrimary),
            fontSize: AppFontSizes.base,
            fontWeight: isSelected ? FontWeight.w600 : FontWeight.w500,
          ),
        ),
      ),
    );
  }

  String _formatTransactionTimestamp(DateTime timestamp, String locale) {
    final months = locale == 'de'
        ? ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez']
        : ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    final month = months[timestamp.month - 1];
    final day = timestamp.day;
    final hour = timestamp.hour.toString().padLeft(2, '0');
    final minute = timestamp.minute.toString().padLeft(2, '0');

    return '$month $day, $hour:$minute';
  }
}
