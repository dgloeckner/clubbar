import 'package:flutter/foundation.dart';

/// Stable identity of a member-facing *scan policy* outcome (ADR-0027
/// amendment 2).
///
/// A hint is not an error: nothing failed, the terminal simply refused or
/// ignored the tap because the session rules say so. Keeping the two channels
/// apart lets the UI say "please log out first" in a neutral tone while real
/// failures (unknown chip, database error) stay in [TerminalErrorKey].
enum ScanHintKey {
  /// The active member re-tapped their own card — nothing changed
  /// (ADR-0027 rule 4).
  alreadyLoggedIn,

  /// A foreign card was tapped during an active session; the session is
  /// protected (ADR-0027 rule 3).
  logOutFirst,

  /// A checkout or dispense is in flight, so every scan is refused and none is
  /// queued (ADR-0027 rule 7).
  transactionInProgress,
}

/// A single occurrence of a scan hint, as signalled to the UI.
///
/// [sequence] makes this an *event* rather than a state, for the same reason
/// [TerminalError.sequence] does: tapping a foreign card twice must show the
/// hint twice, and a value-equality check would swallow the second one.
@immutable
class ScanHint {
  final ScanHintKey key;
  final int sequence;

  const ScanHint({required this.key, required this.sequence});

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is ScanHint && other.key == key && other.sequence == sequence;

  @override
  int get hashCode => Object.hash(key, sequence);

  @override
  String toString() => 'ScanHint(${key.name}, #$sequence)';
}
