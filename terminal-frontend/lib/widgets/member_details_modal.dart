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
import 'package:clubbar_terminal/utils/transaction_grouping.dart';
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
            // Was #1e293b — off-token surface next to bgCard #1a2744 (#302).
            color: AppColors.bgCard,
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
                      color: AppColors.borderMuted.withValues(alpha: 0.3),
                      width: 1,
                    ),
                  ),
                ),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      l10n.myPurchases,
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
                    // Avatar — gradient keyed off the member id so the same
                    // member always gets the same colours everywhere their
                    // avatar appears (#302).
                    Container(
                      width: 48,
                      height: 48,
                      decoration: BoxDecoration(
                        gradient: avatarGradientFor(member.id),
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

              // Transactions Section Header
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      l10n.recentTransactions,
                      style: TextStyle(
                        color: AppColors.textSecondary,
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
                          valueColor: AlwaysStoppedAnimation(AppColors.semanticPrimary),
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

              _languageFooter(l10n, locale),
            ],
          ),
        ),
    );
  }

  /// Language, under the list rather than over it.
  ///
  /// This used to sit between the member's name and their bookings, on the
  /// reading that "language switching is why the sheet gets opened" (#294).
  /// That was true while the sheet was reachable only by guessing, because
  /// the few who found it had been sent. Now the member bar names the
  /// bookings and sends people here for them, and a setting most members
  /// touch once was holding the top half of a sheet that is 75 % of the
  /// screen — the list, the reason for the visit, started below the fold.
  ///
  /// Still one tap and still without scrolling: the list takes the space
  /// between, and this stays pinned to the bottom edge. The label moves
  /// inline beside the buttons rather than stacked above them, which is the
  /// two lines the list gets back.
  Widget _languageFooter(AppLocalizations l10n, String locale) {
    return Container(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
      decoration: BoxDecoration(
        border: Border(
          top: BorderSide(
            color: AppColors.borderMuted.withValues(alpha: 0.3),
            width: 1,
          ),
        ),
      ),
      child: Row(
        children: [
          Expanded(
            child: Text(
              l10n.preferredLanguage,
              style: TextStyle(
                color: AppColors.textSecondary,
                fontSize: AppFontSizes.base,
                fontWeight: FontWeight.w500,
              ),
            ),
          ),
          _languageButton('de', 'Deutsch', locale == 'de'),
          const SizedBox(width: 8),
          _languageButton('en', 'English', locale == 'en'),
        ],
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
              valueColor: AlwaysStoppedAnimation(AppColors.semanticPrimary),
            ),
            const SizedBox(height: 12),
            Text(
              l10n.loadingTransactions,
              style: TextStyle(
                color: AppColors.textSecondary,
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
                color: AppColors.semanticDanger,
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
                  color: AppColors.textSecondary,
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
                    color: AppColors.semanticPrimary,
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
                      color: AppColors.textSecondary,
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
            color: AppColors.textSecondary,
            fontSize: AppFontSizes.base,
          ),
        ),
      );
    }

    return _transactionsListView(locale);
  }

  /// The list, as day sections: a heading per day and one line per product
  /// under it (see [groupTransactionsByDay]).
  ///
  /// Flattened into a single [ListView] rather than nested scrollables so the
  /// whole history keeps one scroll position and one physics — a list of lists
  /// on a kiosk gives a member two things to flick and no way to tell which one
  /// they just moved.
  Widget _transactionsListView(String locale) {
    final rows = <Widget>[];

    for (final day in groupTransactionsByDay(_transactions)) {
      rows.add(_dayHeading(day, locale));
      for (var i = 0; i < day.entries.length; i++) {
        if (i > 0) {
          rows.add(Divider(
            color: AppColors.borderMuted.withValues(alpha: 0.2),
            height: 1,
          ));
        }
        rows.add(_buildTransactionRow(day.entries[i], locale));
      }
    }

    return ListView.builder(
      controller: _listScrollController,
      padding: const EdgeInsets.symmetric(horizontal: 16),
      itemCount: rows.length,
      itemBuilder: (context, index) => rows[index],
    );
  }

  /// `Fr, 28.08.` on the left, the day's total on the right.
  ///
  /// The total is the reason a heading earns its vertical space rather than
  /// merely separating: "what did Friday cost me" was previously a sum the
  /// member had to do in their head, down a column that repeated the date on
  /// every line.
  Widget _dayHeading(TransactionDay day, String locale) {
    return Padding(
      padding: const EdgeInsets.only(top: 18, bottom: 6),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(
            day.heading(locale),
            style: TextStyle(
              color: AppColors.textSecondary,
              fontSize: AppFontSizes.sm,
              fontWeight: FontWeight.w700,
              letterSpacing: 0.6,
            ),
          ),
          Text(
            formatPrice(day.totalCents, locale),
            style: TextStyle(
              color: AppColors.textMuted,
              fontSize: AppFontSizes.sm,
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ),
    );
  }

  /// Explains, above the list, that this is only what the terminal itself saw.
  Widget _offlineBanner(AppLocalizations l10n) {
    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 16),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: AppColors.semanticPrimary.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        children: [
          const Icon(Icons.cloud_off, color: AppColors.semanticPrimary, size: 20),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              l10n.offlineLocalTransactionsOnly,
              style: TextStyle(
                color: AppColors.textSecondary,
                fontSize: AppFontSizes.sm,
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// One product line: `4 x Helles` with what one costs beneath it, and the
  /// line's total on the right.
  ///
  /// The date that used to sit under every name has moved into the day heading,
  /// and the count that used to be four repeated rows is now a multiplier — so
  /// the space the timestamp occupied is spent on the unit price instead, which
  /// is the number a member checks against the shelf.
  Widget _buildTransactionRow(TransactionGroup group, String locale) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 12),
      child: Row(
        children: [
          // Icon
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              color: AppColors.borderLight,
              borderRadius: BorderRadius.circular(20),
            ),
            child: Center(
              child: getProductIcon(
                group.productIcon,
                size: 27,
              ),
            ),
          ),
          const SizedBox(width: 12),
          // Name, with its count, and what one of them cost
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    // A count only when there is one to state. A leading "1 x"
                    // on every single line is the noise this screen was
                    // redesigned to remove.
                    if (group.isMultiple) ...[
                      Text(
                        '${group.count}',
                        style: TextStyle(
                          color: AppColors.brandBeerGold,
                          fontSize: AppFontSizes.base,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      Text(
                        ' x ',
                        style: TextStyle(
                          color: AppColors.textMuted,
                          fontSize: AppFontSizes.sm,
                          fontWeight: FontWeight.w500,
                        ),
                      ),
                    ],
                    Flexible(
                      child: Text(
                        group.details,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          color: Colors.white,
                          fontSize: AppFontSizes.base,
                          fontWeight: FontWeight.w500,
                        ),
                      ),
                    ),
                  ],
                ),
                // Only where it says something the total does not: on a line of
                // one, the unit price *is* the total already on the right.
                if (group.isMultiple) ...[
                  const SizedBox(height: 2),
                  Text(
                    formatPrice(group.unitCents, locale),
                    style: TextStyle(
                      color: AppColors.textSecondary,
                      fontSize: AppFontSizes.sm,
                    ),
                  ),
                ],
              ],
            ),
          ),
          // Amount — the line's total, which is what the day heading sums
          Text(
            formatPrice(group.totalCents, locale),
            style: TextStyle(
              color: transactionAmountColor(group.totalCents),
              fontSize: AppFontSizes.lg,
              fontWeight: FontWeight.w600,
            ),
          ),
          const SizedBox(width: 12),
          // Status badge
          _buildStatusBadge(group.syncStatus),
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
        color = AppColors.semanticWarning; // Orange
        break;
      case TransactionSyncStatus.open:
        icon = Icons.circle_outlined;
        color = AppColors.semanticPrimary; // Blue
        break;
      case TransactionSyncStatus.settled:
        icon = Icons.check_circle;
        color = AppColors.semanticSuccess; // Green
        break;
    }

    return Icon(icon, color: color, size: 20);
  }

  Widget _languageButton(String code, String label, bool isSelected) {
    return InkWell(
      // A successful switch pops the sheet, which is right when the member
      // asked for a different language and wrong when they tapped the one
      // already in force: that wrote the value it already had and closed the
      // bookings they were part-way through reading. Selecting the current
      // language is now the no-op it looks like.
      onTap: isSelected ? null : () => _updateLanguage(code),
      borderRadius: BorderRadius.circular(8),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
        decoration: BoxDecoration(
          // Strong blue: white on #3b82f6 is 3.7:1 (#41).
          color: isSelected
              ? AppColors.semanticPrimaryStrong
              : AppColors.borderLight,
          borderRadius: BorderRadius.circular(8),
          border: Border.all(
            color: isSelected
                ? AppColors.semanticPrimaryLight
                : AppColors.borderMuted,
            width: 1,
          ),
        ),
        child: Text(
          label,
          style: TextStyle(
            // #a1a1aa on #334155 was 4.0:1; this is a tappable language
            // label, so it needs to clear AA like any other text (#41).
            color: isSelected ? Colors.white : AppColors.textPrimary,
            fontSize: AppFontSizes.base,
            fontWeight: isSelected ? FontWeight.w600 : FontWeight.w500,
          ),
        ),
      ),
    );
  }
}
