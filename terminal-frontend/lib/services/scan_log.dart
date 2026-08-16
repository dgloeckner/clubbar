import 'dart:collection';

import 'package:flutter/foundation.dart';
import 'package:logger/logger.dart';

import 'package:clubbar_terminal/utils/app_logger.dart';

/// What the terminal made of one step on the way from a key event to a member.
///
/// The reader is a passive keyboard wedge, so a tap that goes wrong looks
/// exactly like a tap that never happened: no sound, no banner, nothing on
/// screen (issue #370). These are the outcomes that chain can produce, so that
/// "the terminal did not react" can be told apart from "the terminal reacted
/// and refused".
enum ScanEventKind {
  /// A burst of characters was terminated and handed on as a card UID. The
  /// only kind that means the capture stage did its job.
  uidCaptured,

  /// Buffered characters were thrown away because the next one took longer
  /// than a reader ever does. A silently lost tap.
  partialDiscarded,

  /// A terminator arrived with nothing buffered — the characters before it
  /// were never seen, or there were none.
  emptyTerminator,

  /// A keystroke was ignored because Ctrl/Alt/Meta was held. Reader output
  /// never carries a modifier; a *stuck* modifier silences the reader whole.
  modifierSuppressed,

  /// A non-modifier key carried no character, so it could not be part of a
  /// UID. A reader typing on the numeric keypad with NumLock off looks like
  /// this — and produces nothing at all.
  unprintableKey,

  /// A finished scan arrived before the app shell had subscribed to the
  /// reader. Only possible in the first frames after launch.
  captureNotReady,

  /// A scan was dropped because the previous one was still being resolved.
  droppedBusy,

  /// The scan was understood and refused by the session policy (ADR-0027).
  refused,

  /// The card resolved to no usable member: unknown, inactive, or without a
  /// valid SEPA mandate.
  rejected,

  /// A session started. The happy end of the chain.
  accepted;

  /// Whether this outcome is a tap the member made and nobody saw.
  ///
  /// These are logged at error level so they reach `error.log`, the only sink
  /// that survives on a kiosk started from a `.desktop` entry — its stdout
  /// goes nowhere.
  bool get isSilentLoss =>
      this == ScanEventKind.partialDiscarded ||
      this == ScanEventKind.captureNotReady ||
      this == ScanEventKind.droppedBusy;
}

/// One recorded outcome, with the repeats that followed it folded in.
@immutable
class ScanEvent {
  final DateTime at;
  final ScanEventKind kind;

  /// The UID involved, where the stage knows one.
  final String? uid;

  /// Free-form technical detail, e.g. how many characters were discarded or
  /// which key carried no character.
  final String? detail;

  /// How many times this outcome occurred in a row. Repeats are folded rather
  /// than appended so a single stuck key cannot push every earlier event out
  /// of the buffer.
  final int count;

  const ScanEvent({
    required this.at,
    required this.kind,
    this.uid,
    this.detail,
    this.count = 1,
  });

  ScanEvent _repeated(DateTime at) => ScanEvent(
        at: at,
        kind: kind,
        uid: uid,
        detail: detail,
        count: count + 1,
      );

  bool _sameOccurrenceAs(ScanEventKind kind, String? uid, String? detail) =>
      this.kind == kind && this.uid == uid && this.detail == detail;

  /// A single line for the status modal and the log, deliberately technical
  /// and untranslated: it exists to be read out to whoever is debugging the
  /// terminal, and the enum name is the thing to search the source for.
  String get summary {
    final parts = <String>[kind.name];
    if (uid != null) parts.add(uid!);
    if (detail != null) parts.add(detail!);
    if (count > 1) parts.add('x$count');
    return parts.join(' ');
  }

  @override
  String toString() => 'ScanEvent(${at.toIso8601String()} $summary)';
}

/// The terminal's memory of what its card reader has been doing (issue #370).
///
/// Every stage between a key event and a member writes here, because none of
/// them is observable otherwise: a tap that dies in the capture buffer makes no
/// sound, shows no banner and leaves no trace, which is precisely the
/// complaint. Two sinks, for two audiences:
///
/// - the last [capacity] events in memory, shown in the status modal, so staff
///   at the bar can say what the terminal saw for the tap that just failed;
/// - [AppLog], where a silently lost tap goes at error level and therefore
///   lands in `error.log` — on a kiosk launched from a `.desktop` entry that
///   file is the only log that outlives the session.
class ScanLog extends ChangeNotifier {
  /// How many events are kept. Enough to cover a member trying a few times
  /// before giving up and fetching staff.
  static const int capacity = 25;

  /// Repeats of the same outcome within this window are folded together.
  static const Duration _coalesceWindow = Duration(seconds: 30);

  /// Process-wide instance, like [AppLog]: the capture widget, the provider
  /// and the status modal all need the same one, and threading it through the
  /// widget tree would put a diagnostic in every constructor between them.
  static ScanLog instance = ScanLog();

  final DateTime Function() _now;
  final Queue<ScanEvent> _events = Queue<ScanEvent>();

  ScanLog({DateTime Function()? now}) : _now = now ?? DateTime.now;

  /// Newest first — the order staff read them in.
  List<ScanEvent> get events => _events.toList(growable: false);

  /// The most recent event, or null when nothing has been scanned yet.
  ScanEvent? get latest => _events.isEmpty ? null : _events.first;

  void record(ScanEventKind kind, {String? uid, String? detail}) {
    final at = _now();

    _log(kind, at, uid: uid, detail: detail);

    final newest = _events.isEmpty ? null : _events.first;
    if (newest != null &&
        newest._sameOccurrenceAs(kind, uid, detail) &&
        at.difference(newest.at).abs() <= _coalesceWindow) {
      _events.removeFirst();
      _events.addFirst(newest._repeated(at));
    } else {
      _events.addFirst(ScanEvent(at: at, kind: kind, uid: uid, detail: detail));
      while (_events.length > capacity) {
        _events.removeLast();
      }
    }

    notifyListeners();
  }

  void clear() {
    _events.clear();
    notifyListeners();
  }

  void _log(ScanEventKind kind, DateTime at, {String? uid, String? detail}) {
    final line = ScanEvent(at: at, kind: kind, uid: uid, detail: detail).summary;
    final level = kind.isSilentLoss
        ? Level.error
        : (kind == ScanEventKind.accepted || kind == ScanEventKind.uidCaptured
            ? Level.info
            : Level.warning);
    AppLog.instance.log(level, 'scan: $line');
  }
}
