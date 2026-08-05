import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/models/scan_hint.dart';

/// Render-time mapping from scan hint key to member-facing copy.
///
/// Exhaustive on purpose, like [TerminalErrorKeyL10n]: adding a [ScanHintKey]
/// without copy is a compile error rather than a raw key on a kiosk screen.
extension ScanHintKeyL10n on ScanHintKey {
  String message(AppLocalizations l10n) {
    switch (this) {
      case ScanHintKey.alreadyLoggedIn:
        return l10n.scanHintAlreadyLoggedIn;
      case ScanHintKey.logOutFirst:
        return l10n.scanHintLogOutFirst;
      case ScanHintKey.transactionInProgress:
        return l10n.scanHintTransactionInProgress;
    }
  }
}

/// Convenience for the common "show the pending hint, if any" case.
extension ScanHintL10n on ScanHint {
  String message(AppLocalizations l10n) => key.message(l10n);
}
