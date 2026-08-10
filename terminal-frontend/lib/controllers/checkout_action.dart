import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/l10n/terminal_error_messages.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/widgets/error_modal.dart';

/// Run a checkout and route its outcome to the right surface.
///
/// Issue #34 made checkout reachable from two screens — the cart and the
/// product grid — so the flow lives here rather than on either of them. Both
/// entry points must handle a failure, a cancellation and the hop to the
/// confirmation screen identically; a second copy would drift.
///
/// Failures get a blocking [showErrorModal] with Dismiss + Retry, where Retry
/// re-enters this function — the member can recover without hunting for the
/// checkout button behind a snackbar that overlapped the total bar.
///
/// A member-initiated cancellation is not a failure: it stays in `lastError`
/// so the calling screen's inline `ErrorBanner` can quietly confirm the cart is
/// unchanged, instead of a modal nagging about a choice they just made. Every
/// screen that calls this must therefore render that banner.
Future<void> runCheckout(BuildContext context) async {
  final cartProvider = context.read<CartProvider>();
  final membersProvider = context.read<MembersProvider>();

  final selectedMember = membersProvider.selectedMember;
  if (selectedMember == null) {
    showErrorModal(
      context,
      TerminalErrorKey.noMemberSelected.message(AppLocalizations.of(context)!),
    );
    return;
  }
  final sessionId = membersProvider.sessionId ?? '';

  // ADR-0027 rule 7: suspend the inactivity timer while
  // checkout/dispensing is in flight.
  final session = context.read<SessionController>();
  session.beginCriticalOperation();
  try {
    await cartProvider.checkout(context, selectedMember, sessionId);
  } finally {
    session.endCriticalOperation();
  }

  final error = cartProvider.lastError;
  if (error != null) {
    if (error.key == TerminalErrorKey.checkoutCancelled) {
      // Left pending on purpose — the caller's banner renders it.
      return;
    }
    // Shown — drop it so the next failure signals afresh.
    cartProvider.clearError();
    if (!context.mounted) return;
    // The credit limit is a standing condition, not a hiccup: retrying
    // would refuse the exact same cart. The member's way out is the banner
    // above and the remove buttons, so the modal offers no Retry.
    final isRetryable = error.key != TerminalErrorKey.balanceLimitExceeded;
    showErrorModal(
      context,
      error.message(AppLocalizations.of(context)!),
      onRetry: isRetryable ? () => runCheckout(context) : null,
    );
    return;
  }

  // Recompute Deckel from database (now includes
  // the new unsynced transaction)
  await membersProvider.refreshDeckel();

  if (cartProvider.lastSessionId != null && context.mounted) {
    context.go('/confirmation/${cartProvider.lastSessionId}');
  }
}
