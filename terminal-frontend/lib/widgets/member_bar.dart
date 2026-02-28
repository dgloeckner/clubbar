import 'package:ruderbar_terminal/utils/design_tokens.dart';
import 'package:flutter/material.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/l10n/app_localizations.dart';
import 'package:ruderbar_terminal/utils/formatters.dart';
import 'package:ruderbar_terminal/widgets/member_details_modal.dart';

class MemberBar extends StatelessWidget {
  final MembersCacheData member;
  final int itemCount;
  final int? deckelCents;
  final VoidCallback? onCartPressed;
  final VoidCallback? onBackPressed;
  final VoidCallback? onLogoutPressed;
  final bool showBackButton;

  const MemberBar({
    required this.member,
    required this.itemCount,
    this.deckelCents,
    this.onCartPressed,
    this.onBackPressed,
    this.onLogoutPressed,
    this.showBackButton = false,
    super.key,
  });

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final locale = member.preferredLanguage;
    final firstName = member.firstName ?? '';
    final lastName = member.lastName ?? '';
    final initials =
        '${firstName.isNotEmpty ? firstName[0] : '?'}${lastName.isNotEmpty ? lastName[0] : '?'}'
            .toUpperCase();

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      decoration: BoxDecoration(
        color: const Color(0xcc1e293b),
        border: Border.all(
          color: const Color(0x66475569),
          width: 1,
        ),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          // Member info on left
          Row(
            children: [
              // Avatar with initials (clickable)
              GestureDetector(
                onTap: () => showMemberDetailsModal(context),
                child: Container(
                  width: 43,
                  height: 43,
                  decoration: BoxDecoration(
                    color: const Color(0xffFF6B4A),
                    borderRadius: BorderRadius.circular(22),
                  ),
                  child: Center(
                    child: Text(
                      initials,
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: AppFontSizes.base,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 10),
              // Member name and balance (clickable — same action as avatar)
              GestureDetector(
                onTap: () => showMemberDetailsModal(context),
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '$firstName $lastName',
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: AppFontSizes.lg,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    Text(
                      '${l10n.balance}: ${formatPrice(deckelCents ?? member.balanceCents, locale)}',
                      style: TextStyle(
                        color: Color(0xffFF6B4A),
                        fontSize: AppFontSizes.lg,
                        fontWeight: FontWeight.w500,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          // Action buttons on right
          Row(
            children: [
              // Cart button or Back button
              if (showBackButton)
                _buildBackButton()
              else
                _buildCartButton(),
              const SizedBox(width: 8),
              // Logout button
              Material(
                color: Colors.transparent,
                child: InkWell(
                  onTap: onLogoutPressed,
                  borderRadius: BorderRadius.circular(14),
                  child: Container(
                    width: 46,
                    height: 46,
                    decoration: BoxDecoration(
                      color: const Color(0xff334155),
                      borderRadius: BorderRadius.circular(11),
                      border: Border.all(
                        color: const Color(0xff475569),
                        width: 1,
                      ),
                    ),
                    child: const Icon(
                      Icons.exit_to_app,
                      color: Color(0xff94a3b8),
                      size: 22,
                    ),
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildCartButton() {
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onCartPressed,
        borderRadius: BorderRadius.circular(14),
        child: Container(
          width: 58,
          height: 58,
          decoration: BoxDecoration(
            color: const Color(0xff3b82f6),
            borderRadius: BorderRadius.circular(14),
          ),
          child: Stack(
            children: [
              const Center(
                child: Icon(
                  Icons.shopping_cart_outlined,
                  color: Colors.white,
                  size: 28,
                ),
              ),
              // Badge with item count
              if (itemCount > 0)
                Positioned(
                  top: 2,
                  right: 2,
                  child: Container(
                    width: 20,
                    height: 20,
                    decoration: const BoxDecoration(
                      color: Color(0xffEF4444),
                      shape: BoxShape.circle,
                    ),
                    child: Center(
                      child: Text(
                        itemCount.toString(),
                        style: TextStyle(
                          color: Colors.white,
                          fontSize: AppFontSizes.xs,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildBackButton() {
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onBackPressed,
        borderRadius: BorderRadius.circular(12),
        child: Container(
          width: 58,
          height: 58,
          decoration: BoxDecoration(
            color: const Color(0x333b82f6),
            border: Border.all(
              color: const Color(0x663b82f6),
              width: 1,
            ),
            borderRadius: BorderRadius.circular(12),
          ),
          child: const Center(
            child: Opacity(
              opacity: 0.6,
              child: Icon(
                Icons.arrow_back,
                color: Colors.white,
                size: 31,
              ),
            ),
          ),
        ),
      ),
    );
  }
}
