import 'package:flutter/foundation.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';

/// Error signalling for providers: emit a [TerminalErrorKey], never prose.
///
/// Mix into a [ChangeNotifier] to get a `lastError` that behaves as an *event*
/// stream rather than a value — see [TerminalError.sequence] for why identical
/// consecutive failures must still compare unequal.
///
/// [emitError] and [resetError] deliberately do not notify, because they are
/// called mid-operation where the caller already notifies once at the end
/// (typically in a `finally`). Use [clearError] from the UI once an error has
/// been displayed.
mixin ErrorSignal on ChangeNotifier {
  int _errorSequence = 0;
  TerminalError? _lastError;

  /// The most recent error occurrence, or null if none is pending display.
  TerminalError? get lastError => _lastError;

  /// Convenience for callers that only care which error, not which occurrence.
  TerminalErrorKey? get lastErrorKey => _lastError?.key;

  /// Record an error occurrence and log [cause] for diagnosis.
  ///
  /// [cause] is the raw exception. It goes to the log and nowhere else — the
  /// member sees only the localized copy for [key].
  @protected
  void emitError(
    TerminalErrorKey key, {
    Object? cause,
    StackTrace? stackTrace,
  }) {
    _lastError = TerminalError(
      key: key,
      sequence: ++_errorSequence,
      httpStatusCode: httpStatusCodeOf(cause),
    );
    logTerminalError(key, cause, stackTrace);
  }

  /// Drop the pending error without notifying — for success paths that are
  /// about to notify anyway.
  @protected
  void resetError() {
    _lastError = null;
  }

  /// Drop the pending error and notify. Call after the error has been shown.
  void clearError() {
    _lastError = null;
    notifyListeners();
  }
}
