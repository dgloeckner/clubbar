import 'package:flutter/material.dart';
import 'package:ruderbar_terminal/database/database.dart';

class MemberBar extends StatelessWidget {
  final MembersCacheData member;
  final int itemCount;
  final VoidCallback? onCartPressed;
  final VoidCallback? onLogoutPressed;

  const MemberBar({
    required this.member,
    required this.itemCount,
    this.onCartPressed,
    this.onLogoutPressed,
    Key? key,
  }) : super(key: key);

  @override
  Widget build(BuildContext context) {
    final firstName = member.firstName ?? '';
    final lastName = member.lastName ?? '';
    final initials =
        '${firstName.isNotEmpty ? firstName[0] : '?'}${lastName.isNotEmpty ? lastName[0] : '?'}'
            .toUpperCase();

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      decoration: BoxDecoration(
        color: const Color(0xff1e293b).withOpacity(0.8),
        border: Border.all(
          color: const Color(0xff475569).withOpacity(0.4),
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
              // Avatar with initials
              Container(
                width: 43,
                height: 43,
                decoration: BoxDecoration(
                  color: const Color(0xffFF6B4A),
                  borderRadius: BorderRadius.circular(22),
                ),
                child: Center(
                  child: Text(
                    initials,
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 14,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 10),
              // Member name and balance
              Column(
                mainAxisAlignment: MainAxisAlignment.center,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    '$firstName $lastName',
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 14,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  Text(
                    'Deckel: 0,00 €', // TODO: Get actual balance from member
                    style: const TextStyle(
                      color: Color(0xffFF6B4A),
                      fontSize: 12,
                      fontWeight: FontWeight.w500,
                    ),
                  ),
                ],
              ),
            ],
          ),
          // Action buttons on right
          Row(
            children: [
              // Cart button with badge
              Material(
                color: Colors.transparent,
                child: InkWell(
                  onTap: onCartPressed,
                  borderRadius: BorderRadius.circular(12),
                  child: Container(
                    width: 58,
                    height: 58,
                    decoration: BoxDecoration(
                      color: itemCount > 0
                          ? const Color(0xff3b82f6).withOpacity(0.2)
                          : const Color(0xff3b82f6).withOpacity(0.1),
                      border: Border.all(
                        color: itemCount > 0
                            ? const Color(0xff3b82f6).withOpacity(0.4)
                            : const Color(0xff3b82f6).withOpacity(0.2),
                        width: 1,
                      ),
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: Stack(
                      children: [
                        Center(
                          child: Opacity(
                            opacity: 0.6,
                            child: Icon(
                              Icons.shopping_cart_outlined,
                              color: Colors.white,
                              size: 31,
                            ),
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
                                  style: const TextStyle(
                                    color: Colors.white,
                                    fontSize: 12,
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
              ),
              const SizedBox(width: 8),
              // Logout button
              Material(
                color: Colors.transparent,
                child: InkWell(
                  onTap: onLogoutPressed,
                  borderRadius: BorderRadius.circular(12),
                  child: Container(
                    width: 58,
                    height: 58,
                    decoration: BoxDecoration(
                      color: const Color(0xffDC2626),
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
                        size: 31,
                      ),
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
}
