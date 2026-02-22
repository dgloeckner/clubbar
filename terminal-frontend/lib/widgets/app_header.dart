import 'package:ruderbar_terminal/utils/design_tokens.dart';
import 'package:flutter/material.dart';
import 'package:ruderbar_terminal/providers/auth_provider.dart';
import 'package:ruderbar_terminal/providers/sync_provider.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';

class AppHeader extends AppBar {
  AppHeader({
    required String title,
    required AuthProvider authProvider,
    required SyncProvider syncProvider,
    MembersProvider? membersProvider,
    VoidCallback? onCartPressed,
    VoidCallback? onLogoutPressed,
    super.key,
  }) : super(
    backgroundColor: const Color(0xff0f1d32),
    elevation: 0,
    leadingWidth: 0,
    title: membersProvider?.selectedMember != null
        ? _buildMemberBar(membersProvider!, onCartPressed, onLogoutPressed)
        : Text(title),
  );

  static Widget _buildMemberBar(
    MembersProvider membersProvider,
    VoidCallback? onCartPressed,
    VoidCallback? onLogoutPressed,
  ) {
    final member = membersProvider.selectedMember!;
    final firstName = member.firstName ?? '';
    final lastName = member.lastName ?? '';
    final initials = '${firstName.isNotEmpty ? firstName[0] : '?'}${lastName.isNotEmpty ? lastName[0] : '?'}'.toUpperCase();

    return Row(
      children: [
        // Member avatar with initials
        Container(
          width: 56,
          height: 56,
          decoration: BoxDecoration(
            color: const Color(0xffFF6B4A), // Coral orange
            borderRadius: BorderRadius.circular(28),
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
        const SizedBox(width: 16),
        // Member name and balance
        Expanded(
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
                'Deckel: ${((membersProvider.memberDeckel ?? member.balanceCents) / 100.0).toStringAsFixed(2)} \u20ac',
                style: TextStyle(
                  color: Color(0xffFF6B4A), // Coral orange
                  fontSize: AppFontSizes.sm,
                  fontWeight: FontWeight.w500,
                ),
              ),
            ],
          ),
        ),
        // Cart button - slate blue rounded rectangle
        GestureDetector(
          onTap: onCartPressed,
          child: Container(
            width: 48,
            height: 48,
            decoration: BoxDecoration(
              color: const Color(0xff334155), // Slate blue
              borderRadius: BorderRadius.circular(12),
              border: Border.all(
                color: const Color(0xff475569),
                width: 1,
              ),
            ),
            child: const Opacity(
              opacity: 0.6,
              child: Icon(
                Icons.shopping_cart_outlined,
                color: Colors.white,
                size: 24,
              ),
            ),
          ),
        ),
        const SizedBox(width: 12),
        // Logout button - red rounded rectangle
        GestureDetector(
          onTap: onLogoutPressed,
          child: Container(
            width: 48,
            height: 48,
            decoration: BoxDecoration(
              color: const Color(0xffDC2626), // Red
              borderRadius: BorderRadius.circular(12),
              border: Border.all(
                color: const Color(0xffEF4444),
                width: 1,
              ),
            ),
            child: const Opacity(
              opacity: 0.6,
              child: Icon(
                Icons.logout,
                color: Colors.white,
                size: 24,
              ),
            ),
          ),
        ),
        const SizedBox(width: 16),
      ],
    );
  }
}
