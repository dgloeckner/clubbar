import 'package:flutter/material.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/utils/formatters.dart';

class MemberInfoCard extends StatelessWidget {
  final MembersCacheData member;
  final int balanceCents;
  final String locale;

  const MemberInfoCard({
    super.key,
    required this.member,
    required this.balanceCents,
    required this.locale,
  });

  Color _getBalanceColor() {
    if (balanceCents > 0) {
      return const Color(0xff22c55e); // Green - positive balance
    } else if (balanceCents < 0) {
      return const Color(0xfff97316); // Orange - negative balance
    }
    return const Color(0xff94a3b8); // Secondary - zero balance
  }

  @override
  Widget build(BuildContext context) {
    final initials = '${member.firstName?[0] ?? '?'}${member.lastName?[0] ?? '?'}'
        .toUpperCase();

    return Container(
      padding: const EdgeInsets.all(AppSpacing.lg),
      decoration: BoxDecoration(
        color: const Color(0xff0f1d32), // Secondary bg
        borderRadius: BorderRadius.circular(AppBorderRadius.lg),
        border: Border.all(
          color: const Color(0xff334155),
          width: 1,
        ),
      ),
      child: Row(
        children: [
          // Orange gradient avatar
          Container(
            width: 48,
            height: 48,
            decoration: BoxDecoration(
              gradient: AppAvatarGradients.orange,
              borderRadius: BorderRadius.circular(AppBorderRadius.full),
            ),
            child: Center(
              child: Text(
                initials,
                style: TextStyle(
                  color: Colors.white,
                  fontSize: AppFontSizes.lg,
                  fontWeight: FontWeight.bold,
                ),
              ),
            ),
          ),
          const SizedBox(width: AppSpacing.lg),

          // Member info
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Name
                Text(
                  '${member.firstName ?? 'Unknown'} ${member.lastName ?? 'Member'}',
                  style: TextStyle(
                    color: Color(0xfff1f5f9),
                    fontSize: AppFontSizes.lg,
                    fontWeight: FontWeight.w600,
                  ),
                ),
                const SizedBox(height: AppSpacing.xs),

                // Balance/Deckel with color coding
                Text(
                  'Deckel: ${formatPrice(balanceCents, locale)}',
                  style: TextStyle(
                    color: _getBalanceColor(),
                    fontSize: AppFontSizes.base,
                    fontWeight: FontWeight.w500,
                  ),
                ),
                const SizedBox(height: AppSpacing.xs),

                // Language indicator (small, muted)
                Text(
                  'Language: ${member.preferredLanguage.toUpperCase()}',
                  style: TextStyle(
                    color: Color(0xff64748b),
                    fontSize: AppFontSizes.sm,
                    fontWeight: FontWeight.w400,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
