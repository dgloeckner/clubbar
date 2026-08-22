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
  final int? deckelCents;
  final VoidCallback? onBackPressed;
  final VoidCallback? onLogoutPressed;
  final bool showBackButton;

  /// Edge of the back button. This is the tallest control in the row, so it
  /// alone sets the bar's height — 58 before #369, where the band above the
  /// product grid had to give six pixels back.
  static const double _actionButtonSize = 52.0;

  const MemberBar({
    required this.member,
    this.deckelCents,
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
    // The back button is refused for the same window (#34): leaving the
    // screen that started the checkout unmounts the context it is waiting
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
        border: Border.all(color: const Color(0x66475569), width: 1),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          // Member info on left — a single tappable cluster (#39): one
          // InkWell for avatar + name/balance + chevron, so the ripple and
          // the "tap for details" cue cover the whole area, not just part.
          //
          // Flexible, so the row's fixed-width right-hand controls always get
          // their space and this cluster yields instead. It used to be rigid,
          // which held while the only control was a 52 px square and stopped
          // holding the moment the logout button got its label — but a long
          // enough member name would have overflowed it either way.
          Flexible(
            child: Material(
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
                          borderRadius: BorderRadius.circular(
                            AppBorderRadius.full,
                          ),
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
                      Flexible(
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              '$firstName $lastName',
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
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
          ),
          // Action buttons on right
          Row(
            children: [
              if (showBackButton) ...[
                _buildBackButton(l10n, blocked: navigationBlocked),
                const SizedBox(width: 8),
              ],
              // Logout button — disabled while a checkout/dispense runs.
              //
              // Labelled, not icon-only. Members reported not finding this
              // control at all, and the icon-only square was three problems
              // at once: `Icons.exit_to_app` is the arrow pointing *into* a
              // bracket and reads as "log in"; the chip's own boundary was
              // 1.5:1 (fill) and 2.0:1 (border) against the bar, under the
              // 3:1 WCAG 1.4.11 asks of a control's edge; and nothing said
              // what it did, in a row where the back button next to it is a
              // white glyph at 5.3:1 and wins the eye every time. The
              // `logout` string existed in both locales and was wired to
              // nothing.
              //
              // Height stays at _actionButtonSize: on screens without a back
              // button this is the only control in the row, and it alone must
              // keep setting the bar's height (#369 measured that band). Only
              // the width grows.
              Material(
                key: const Key('member-bar-logout'),
                color: Colors.transparent,
                child: InkWell(
                  onTap: navigationBlocked ? null : onLogoutPressed,
                  borderRadius: BorderRadius.circular(AppBorderRadius.md),
                  child: Opacity(
                    opacity: navigationBlocked ? 0.4 : 1.0,
                    // `button: true` but deliberately no `label:` — InkWell
                    // does not set the flag on its own, while the visible
                    // text already is the accessible name. Labelling it as
                    // well made a screen reader read "Abmelden Abmelden".
                    child: Semantics(
                      button: true,
                      child: Container(
                        height: _actionButtonSize,
                        padding: const EdgeInsets.symmetric(horizontal: 14),
                        decoration: BoxDecoration(
                          color: AppColors.borderLight,
                          borderRadius: BorderRadius.circular(
                            AppBorderRadius.md,
                          ),
                          border: Border.all(
                            color: AppColors.borderStrong,
                            width: 1,
                          ),
                        ),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            const Icon(
                              Icons.logout,
                              color: AppColors.textPrimary,
                              size: 24,
                            ),
                            const SizedBox(width: 8),
                            Text(
                              l10n.logout,
                              style: TextStyle(
                                color: AppColors.textPrimary,
                                fontSize: AppFontSizes.base,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ],
                        ),
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

  /// The back arrow, shown on the cart screen only (`showBackButton`).
  ///
  /// Stays icon-only where the logout button next to it got a label. That
  /// asymmetry is the point: logout is the way out of the session and has to
  /// be *found*, while a left arrow is one of the few glyphs that is read
  /// correctly without help — unlike `Icons.exit_to_app`, which members were
  /// reading as "log in". [AppLocalizations.backToProducts] is its accessible
  /// name, not a caption.
  Widget _buildBackButton(AppLocalizations l10n, {required bool blocked}) {
    return Material(
      key: const Key('member-bar-back'),
      color: Colors.transparent,
      child: InkWell(
        onTap: blocked ? null : onBackPressed,
        borderRadius: BorderRadius.circular(12),
        child: Opacity(
          opacity: blocked ? 0.4 : 1.0,
          child: Semantics(
            button: true,
            label: l10n.backToProducts,
            child: Container(
              width: _actionButtonSize,
              height: _actionButtonSize,
              decoration: BoxDecoration(
                color: const Color(0x333b82f6),
                // borderStrong, not the 40% blue this used: that measured
                // 1.8:1 against the bar, under the 3:1 WCAG 1.4.11 asks of a
                // control's edge — the same invisible boundary the logout
                // button had. Only the white glyph inside was carrying it.
                border: Border.all(color: AppColors.borderStrong, width: 1),
                borderRadius: BorderRadius.circular(12),
              ),
              child: const Center(
                child: Opacity(
                  opacity: 0.6,
                  child: Icon(Icons.arrow_back, color: Colors.white, size: 31),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
