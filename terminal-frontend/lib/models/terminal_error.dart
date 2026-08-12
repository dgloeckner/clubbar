import 'package:flutter/foundation.dart';

import 'package:clubbar_terminal/utils/app_logger.dart';

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
  noMemberSelected,

  // Member details
  transactionHistoryFailed,

  // Terminal status
  statusLoadFailed,

  // Cart / checkout
  cartEmpty,
  balanceLimitExceeded,
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

/// Record the raw [cause] behind [key] — the only place that text may go.
///
/// Shared by [ErrorSignal.emitError] and by the widgets that catch their own
/// exceptions, so every terminal error reads the same way in `error.log`
/// regardless of which layer caught it.
void logTerminalError(
  TerminalErrorKey key,
  Object? cause, [
  StackTrace? stackTrace,
]) {
  if (cause == null) return;
  AppLog.instance.e(
    'Terminal error ${key.name}',
    error: cause,
    stackTrace: stackTrace,
  );
}

/// The HTTP status code behind [cause], or null when it isn't a network
/// failure (offline, timeout, parse error, ...).
///
/// Duck-typed on a `statusCode` getter rather than importing
/// `NetworkException` from `services/` — this is the one place the status
/// code is allowed to leave the log for the screen, so it stays a narrow,
/// dependency-free extraction rather than pulling the network layer into the
/// error model.
int? httpStatusCodeOf(Object? cause) {
  if (cause == null) return null;
  try {
    final dynamic d = cause;
    final code = d.statusCode;
    return code is int ? code : null;
  } catch (_) {
    return null;
  }
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

  /// HTTP status code behind this error, when it was a network failure.
  ///
  /// This is deliberately the only piece of the raw [Object] cause that
  /// reaches the UI — the full exception text stays in `error.log`.
  final int? httpStatusCode;

  const TerminalError({
    required this.key,
    required this.sequence,
    this.httpStatusCode,
  });

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
