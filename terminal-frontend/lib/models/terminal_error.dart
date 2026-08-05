import 'package:flutter/foundation.dart';

/// Stable identity of a member-facing error.
///
/// Services and providers emit these keys instead of English prose; the UI
/// maps a key to localized copy at render time (see
/// `lib/l10n/terminal_error_messages.dart`). Raw exception text belongs in the
/// log, never in front of a member.
enum TerminalErrorKey {
  // Card scan / member lookup
  unknownCard,
  accountInactive,
  sepaMissing,
  memberLookupFailed,
  membersRefreshFailed,

  // Cart / checkout
  cartEmpty,
  checkoutFailed,
  checkoutCancelled,
  transactionCreateFailed,

  // Token dispenser
  dispenserNotConfigured,
  dispenserOperationFailed,
  dispenserUnavailable,
  dispenserNoTokensDispensed,

  // Products
  productsRefreshFailed,

  // Sync / connectivity
  backendUnreachable,
  syncFailed,
  transactionSyncFailed,
}

/// A single occurrence of an error, as signalled to the UI.
///
/// [sequence] is what makes this an *event* rather than a state: it increments
/// on every emission, so two identical consecutive failures compare unequal and
/// a widget that renders on change will show the second one too. Without it, a
/// repeated failure (scan the same unknown card twice) would be swallowed by
/// value-equality checks.
@immutable
class TerminalError {
  final TerminalErrorKey key;
  final int sequence;

  const TerminalError({required this.key, required this.sequence});

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is TerminalError &&
          other.key == key &&
          other.sequence == sequence;

  @override
  int get hashCode => Object.hash(key, sequence);

  @override
  String toString() => 'TerminalError(${key.name}, #$sequence)';
}
