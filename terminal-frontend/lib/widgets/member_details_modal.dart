import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/l10n/app_localizations.dart';
import 'package:ruderbar_terminal/models/transaction_list_item.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/services/transaction_history_service.dart';
import 'package:ruderbar_terminal/services/network_service.dart';
import 'package:ruderbar_terminal/config/app_config.dart';
import 'package:ruderbar_terminal/utils/formatters.dart';

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
  bool _hasError = false;
  String? _errorMessage;
  List<TransactionListItem> _transactions = [];
  bool _isOffline = false;

  @override
  void initState() {
    super.initState();
    _loadTransactions();
  }

  Future<void> _loadTransactions() async {
    setState(() {
      _isLoading = true;
      _hasError = false;
      _errorMessage = null;
    });

    try {
      final membersProvider = context.read<MembersProvider>();
      final member = membersProvider.selectedMember;

      if (member == null) {
        setState(() {
          _hasError = true;
          _errorMessage = 'No member selected';
          _isLoading = false;
        });
        return;
      }

      final networkService = context.read<NetworkService>();
      final authToken = networkService.getAuthToken();

      if (authToken == null) {
        setState(() {
          _hasError = true;
          _errorMessage = 'Not authenticated';
          _isLoading = false;
        });
        return;
      }

      // Check if online
      final isOnline = await networkService.checkHealth();

      List<TransactionListItem> transactions = [];

      if (isOnline) {
        // Fetch from backend
        final service = TransactionHistoryService(
          baseUrl: AppConfig.apiBaseUrl,
          authToken: authToken,
        );

        transactions = await service.fetchTransactionHistory(
          memberId: member.id,
          preferredLanguage: member.preferredLanguage ?? 'de',
        );
      }

      setState(() {
        _transactions = transactions;
        _isOffline = !isOnline;
        _isLoading = false;
        _hasError = false;
      });
    } catch (e) {
      setState(() {
        _hasError = true;
        _errorMessage = e.toString();
        _isLoading = false;
      });
    }
  }

  Future<void> _updateLanguage(String newLanguage) async {
    try {
      // TODO: Implement language update via backend API
      // PATCH /api/sync/members/{memberId}/language
      // Request body: {"preferred_language": "de" | "en"}

      debugPrint('Language update to $newLanguage requested (not yet implemented)');

      // Show a snackbar to inform user
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Language switching to $newLanguage (feature in progress)'),
            duration: const Duration(seconds: 2),
          ),
        );
      }
    } catch (e) {
      debugPrint('Failed to update language: $e');
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
    final balanceCents = member.balanceCents;
    final locale = member.preferredLanguage ?? 'de';

    return DraggableScrollableSheet(
      initialChildSize: 0.7,
      minChildSize: 0.5,
      maxChildSize: 0.9,
      builder: (context, scrollController) {
        return Container(
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
                      color: const Color(0xff475569).withOpacity(0.3),
                      width: 1,
                    ),
                  ),
                ),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      l10n.memberDetails,
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 20,
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
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 18,
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
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 18,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                        Text(
                          '${l10n.balance}: ${formatPrice(balanceCents, locale)}',
                          style: TextStyle(
                            color: _balanceColor(balanceCents),
                            fontSize: 15,
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
                      style: const TextStyle(
                        color: Color(0xffa1a1aa),
                        fontSize: 14,
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
                      style: const TextStyle(
                        color: Color(0xffa1a1aa),
                        fontSize: 14,
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

              // Transactions List or Error
              Expanded(
                child: _buildTransactionsList(scrollController, l10n, locale),
              ),
            ],
          ),
        );
      },
    );
  }

  Widget _buildTransactionsList(ScrollController scrollController, AppLocalizations l10n, String locale) {
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
              style: const TextStyle(
                color: Color(0xffa1a1aa),
                fontSize: 14,
              ),
            ),
          ],
        ),
      );
    }

    if (_hasError) {
      return Center(
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
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 16,
                  fontWeight: FontWeight.w600,
                ),
              ),
              if (_errorMessage != null) ...[
                const SizedBox(height: 8),
                Text(
                  _errorMessage!,
                  style: const TextStyle(
                    color: Color(0xffa1a1aa),
                    fontSize: 13,
                  ),
                  textAlign: TextAlign.center,
                ),
              ],
              const SizedBox(height: 16),
              ElevatedButton.icon(
                onPressed: _loadTransactions,
                icon: const Icon(Icons.refresh),
                label: Text(l10n.retry),
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xff3b82f6),
                  foregroundColor: Colors.white,
                ),
              ),
            ],
          ),
        ),
      );
    }

    if (_isOffline) {
      return Center(
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
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 16,
                  fontWeight: FontWeight.w600,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                l10n.transactionHistoryUnavailableOffline,
                style: const TextStyle(
                  color: Color(0xffa1a1aa),
                  fontSize: 13,
                ),
                textAlign: TextAlign.center,
              ),
            ],
          ),
        ),
      );
    }

    if (_transactions.isEmpty) {
      return Center(
        child: Text(
          l10n.noTransactions,
          style: const TextStyle(
            color: Color(0xffa1a1aa),
            fontSize: 14,
          ),
        ),
      );
    }

    return ListView.separated(
      controller: scrollController,
      padding: const EdgeInsets.symmetric(horizontal: 16),
      itemCount: _transactions.length,
      separatorBuilder: (context, index) => Divider(
        color: const Color(0xff475569).withOpacity(0.2),
        height: 1,
      ),
      itemBuilder: (context, index) {
        final transaction = _transactions[index];
        return _buildTransactionRow(transaction, locale);
      },
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
              child: Text(
                _getProductEmoji(transaction.productIcon),
                style: const TextStyle(fontSize: 20),
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
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 15,
                    fontWeight: FontWeight.w500,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  _formatTransactionTimestamp(transaction.timestamp, locale),
                  style: const TextStyle(
                    color: Color(0xffa1a1aa),
                    fontSize: 13,
                  ),
                ),
              ],
            ),
          ),
          // Amount
          Text(
            formatPrice(transaction.amountCents, locale),
            style: TextStyle(
              color: transaction.amountCents >= 0
                  ? const Color(0xffFF6B4A)
                  : const Color(0xff22c55e),
              fontSize: 16,
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
          color: isSelected
              ? const Color(0xff3b82f6)
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
            color: isSelected ? Colors.white : const Color(0xffa1a1aa),
            fontSize: 14,
            fontWeight: isSelected ? FontWeight.w600 : FontWeight.w500,
          ),
        ),
      ),
    );
  }

  Color _balanceColor(int balanceCents) {
    if (balanceCents > 0) {
      return const Color(0xff22c55e); // Green
    } else if (balanceCents < 0) {
      return const Color(0xfff97316); // Orange
    } else {
      return const Color(0xff94a3b8); // Gray
    }
  }

  String _getProductEmoji(String? iconName) {
    if (iconName == null) return '📝';

    final emojiMap = {
      'beer': '🍺',
      'coffee': '☕',
      'water': '💧',
      'soda': '🥤',
      'cola': '🥤',
      'pizza': '🍕',
      'sandwich': '🥪',
      'chips': '🍟',
      'snack': '🍿',
    };

    return emojiMap[iconName.toLowerCase()] ?? '🛒';
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
