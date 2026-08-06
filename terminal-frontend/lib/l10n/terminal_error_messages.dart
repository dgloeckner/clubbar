import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';

/// Render-time mapping from error key to member-facing copy.
///
/// The switch is exhaustive on purpose: adding a [TerminalErrorKey] without
/// giving it copy is a compile error, not a raw key leaking onto the screen.
extension TerminalErrorKeyL10n on TerminalErrorKey {
  String message(AppLocalizations l10n) {
    switch (this) {
      case TerminalErrorKey.unknownCard:
        return l10n.rfidErrorUnknownCard;
      case TerminalErrorKey.accountInactive:
        return l10n.rfidErrorAccountInactive;
      case TerminalErrorKey.sepaMissing:
        return l10n.rfidErrorSepaMissing;
      case TerminalErrorKey.memberLookupFailed:
        return l10n.rfidErrorDatabaseError;
      case TerminalErrorKey.membersRefreshFailed:
        return l10n.errorMembersRefreshFailed;
      case TerminalErrorKey.noMemberSelected:
        return l10n.errorNoMemberSelected;
      case TerminalErrorKey.transactionHistoryFailed:
        return l10n.errorTransactionHistoryFailed;
      case TerminalErrorKey.statusLoadFailed:
        return l10n.errorStatusLoadFailed;
      case TerminalErrorKey.cartEmpty:
        return l10n.cartEmpty;
      case TerminalErrorKey.balanceLimitExceeded:
        return l10n.errorBalanceLimitExceeded;
      case TerminalErrorKey.checkoutFailed:
        return l10n.errorCheckoutFailed;
      case TerminalErrorKey.checkoutCancelled:
        return l10n.errorCheckoutCancelled;
      case TerminalErrorKey.transactionCreateFailed:
        return l10n.errorTransactionCreateFailed;
      case TerminalErrorKey.dispenserNotConfigured:
        return l10n.errorDispenserNotConfigured;
      case TerminalErrorKey.dispenserOperationFailed:
        return l10n.errorDispenserOperationFailed;
      case TerminalErrorKey.dispenserUnavailable:
        return l10n.errorDispenserUnavailable;
      case TerminalErrorKey.dispenserNoTokensDispensed:
        return l10n.errorDispenserNoTokensDispensed;
      case TerminalErrorKey.productsRefreshFailed:
        return l10n.errorProductsRefreshFailed;
      case TerminalErrorKey.backendUnreachable:
        return l10n.errorBackendUnreachable;
      case TerminalErrorKey.syncFailed:
        return l10n.errorSyncFailed;
      case TerminalErrorKey.transactionSyncFailed:
        return l10n.errorTransactionSyncFailed;
    }
  }
}

/// Convenience for the common "show the pending error, if any" case.
extension TerminalErrorL10n on TerminalError {
  String message(AppLocalizations l10n) => key.message(l10n);
}
