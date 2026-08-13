import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/utils/formatters.dart';
import 'package:clubbar_terminal/widgets/member_details_modal.dart';

class MemberBar extends StatelessWidget {
  final MembersCacheData member;
  final int itemCount;
  final int? deckelCents;
  final VoidCallback? onCartPressed;
  final VoidCallback? onBackPressed;
  final VoidCallback? onLogoutPressed;
  final bool showBackButton;

  /// Edge of the cart / back button. This is the tallest control in the row,
  /// so it alone sets the bar's height — 58 before #369, where the band above
  /// the product grid had to give six pixels back.
  static const double _actionButtonSize = 52.0;

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
    // ADR-0027 rule 7: a checkout/dispense in flight must not be interrupted,
    // so logout is refused for as long as it runs. Read here rather than per
    // screen so every surface showing the MemberBar is covered.
    //
    // The cart and back buttons are refused for the same window (#34): leaving
    // the screen that started the checkout unmounts the context it is waiting
    // on, and the member would be left on the other screen with an emptied
    // cart and no confirmation to show for their money.
    final navigationBlocked = context.select<SessionController, bool>(
      (session) => session.isCriticalOperationInFlight,
    );
    final locale = member.preferredLanguage;
    final firstName = member.firstName ?? '';
    final lastName = member.lastName ?? '';
    final initials =
        '${firstName.isNotEmpty ? firstName[0] : '?'}${lastName.isNotEmpty ? lastName[0] : '?'}'
            .toUpperCase();

    return Container(
      // Vertical padding is `sm`, not 12 (#369). The bar is a fixed-height
      // band above the product grid and both screens already wrap it in
      // vertical padding of their own, so this was 12 on top of 12 — 24 px of
      // gap the grid paid for.
      padding: const EdgeInsets.symmetric(
        horizontal: 16,
        vertical: AppSpacing.sm,
      ),
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
          // Member info on left — a single tappable cluster (#39): one
          // InkWell for avatar + name/balance + chevron, so the ripple and
          // the "tap for details" cue cover the whole area, not just part.
          Material(
            key: const Key('member-bar-details'),
            color: Colors.transparent,
            child: InkWell(
              onTap: () => showMemberDetailsModal(context),
              borderRadius: BorderRadius.circular(12),
              // No vertical padding around this cluster (#369): the
              // name/balance column is already two `lg` lines — ~48 px, over
              // the 44 px touch minimum on its own — so the 4 px that used to
              // wrap it only made the cluster the tallest thing in the row.
              child: Semantics(
                button: true,
                label: l10n.viewDetails,
                child: Row(
                  children: [
                    // Avatar with initials — gradient keyed off the member
                    // id so the same member always gets the same colours
                    // across every surface that shows their avatar (#302).
                    Container(
                      width: 43,
                      height: 43,
                      decoration: BoxDecoration(
                        gradient: avatarGradientFor(member.id),
                        borderRadius: BorderRadius.circular(AppBorderRadius.full),
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
                    const SizedBox(width: 10),
                    // Member name and balance
                    Column(
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
                          formatBalance(
                            deckelCents ?? member.balanceCents,
                            l10n,
                            locale,
                          ),
                          style: TextStyle(
                            color: balanceColor(
                              deckelCents ?? member.balanceCents,
                            ),
                            fontSize: AppFontSizes.lg,
                            fontWeight: FontWeight.w500,
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(width: 4),
                    Icon(
                      Icons.chevron_right,
                      color: AppColors.textMuted,
                      size: 22,
                    ),
                  ],
                ),
              ),
            ),
          ),
          // Action buttons on right
          Row(
            children: [
              // Cart button or Back button
              if (showBackButton)
                _buildBackButton(blocked: navigationBlocked)
              else
                _buildCartButton(blocked: navigationBlocked),
              const SizedBox(width: 8),
              // Logout button — disabled while a checkout/dispense runs
              Material(
                key: const Key('member-bar-logout'),
                color: Colors.transparent,
                child: InkWell(
                  onTap: navigationBlocked ? null : onLogoutPressed,
                  borderRadius: BorderRadius.circular(AppBorderRadius.lg),
                  child: Opacity(
                    opacity: navigationBlocked ? 0.4 : 1.0,
                    child: Container(
                      width: 46,
                      height: 46,
                      decoration: BoxDecoration(
                        color: AppColors.borderLight,
                        borderRadius: BorderRadius.circular(AppBorderRadius.md),
                        border: Border.all(
                          color: AppColors.borderMuted,
                          width: 1,
                        ),
                      ),
                      child: const Icon(
                        Icons.exit_to_app,
                        color: AppColors.textSecondary,
                        size: 22,
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

  Widget _buildCartButton({required bool blocked}) {
    return Material(
      key: const Key('member-bar-cart'),
      color: Colors.transparent,
      child: InkWell(
        onTap: blocked ? null : onCartPressed,
        borderRadius: BorderRadius.circular(AppBorderRadius.lg),
        child: Opacity(
          opacity: blocked ? 0.4 : 1.0,
          child: Container(
            width: _actionButtonSize,
            height: _actionButtonSize,
            decoration: BoxDecoration(
              color: AppColors.semanticPrimary,
              borderRadius: BorderRadius.circular(AppBorderRadius.lg),
            ),
            child: Stack(
              clipBehavior: Clip.none,
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
                    top: -4,
                    right: -4,
                    child: Container(
                      constraints:
                          const BoxConstraints(minWidth: 24, minHeight: 24),
                      padding: const EdgeInsets.symmetric(
                          horizontal: 5, vertical: 2),
                      decoration: BoxDecoration(
                        color: AppColors.semanticDanger,
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: Center(
                        child: Text(
                          itemCount.toString(),
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 13,
                            fontWeight: FontWeight.w700,
                            height: 1.2,
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
    );
  }

  Widget _buildBackButton({required bool blocked}) {
    return Material(
      key: const Key('member-bar-back'),
      color: Colors.transparent,
      child: InkWell(
        onTap: blocked ? null : onBackPressed,
        borderRadius: BorderRadius.circular(12),
        child: Opacity(
          opacity: blocked ? 0.4 : 1.0,
          child: Container(
            width: _actionButtonSize,
            height: _actionButtonSize,
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
      ),
    );
  }
}
