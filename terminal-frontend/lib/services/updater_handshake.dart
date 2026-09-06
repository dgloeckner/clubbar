/// The two files through which the app and the Pi's updater talk to each other
/// (ADR-0054).
///
/// They are files rather than a socket or a D-Bus name because each side has to
/// work when the other is *dead*: the updater must be able to decide whether it
/// is safe to replace an app that has crash-looped, and the app must be able to
/// report a failed update that happened while it was not running. A file
/// written by one side and read by the other is the only channel that survives
/// both halves of that.
///
/// Both live beside `config.json` in the app's data directory. That directory
/// belongs to the kiosk user, which is also who the update timer runs as — so
/// neither side needs to own anything the other cannot write.
///
/// - [TerminalStatusFile] is the app → updater direction: the heartbeat and the
///   unsynced-transaction count.
/// - [UpdaterState] is the updater → app direction: the tag whose update failed
///   here, so that the app can report it and the club can find out.
library;

import 'dart:convert';
import 'dart:io';

/// The heartbeat, and the reason an update may be refused (ADR-0054).
///
/// Two facts, one file, written on every sync cycle:
///
/// 1. **`last_sync_at`** — when a sync round-trip last *succeeded*. This is the
///    watchdog's sole health criterion after an update. It cannot be a restart
///    count: the app's unit ships `StartLimitIntervalSec=0` on purpose, so a
///    kiosk always comes back up, and a terminal crash-looping forever never
///    trips a limit that is switched off. A stamp that moves covers the app
///    that crash-looped and the app that came up wedged with one test.
/// 2. **`unsynced_transactions`** — sales booked here that the backend has not
///    acknowledged. An update must never lose one, so a non-zero count is a
///    refusal: no update tonight, try again tomorrow.
///
/// Written on every cycle rather than only on success, and this matters for the
/// second fact: a terminal that has been offline all evening has a stale
/// `last_sync_at` *and* a growing pile of unsynced sales, and the updater has to
/// see the pile. A file refreshed only by successful syncs would report zero
/// unsynced transactions at exactly the moment there were most.
class TerminalStatusFile {
  /// Name of the file, in the app's data directory beside `config.json`.
  static const String fileName = 'status.json';

  final File file;

  TerminalStatusFile(this.file);

  factory TerminalStatusFile.inDirectory(String directory) =>
      TerminalStatusFile(File('$directory/$fileName'));

  /// Replace the status file with the current facts.
  ///
  /// Written to a temporary file and renamed over the target, because
  /// `rename()` within a directory is atomic: an updater reading this file
  /// while the app writes it sees the old contents or the new ones, never a
  /// half-written JSON document it would fail to parse and — reading a parse
  /// failure as "no heartbeat" — roll a perfectly healthy terminal back over.
  ///
  /// Never throws. A terminal whose disk is full or whose data directory has
  /// gone read-only must still sell drinks; the consequence of failing here is
  /// that the updater sees a stale heartbeat and declines to update, which is
  /// the safe direction.
  Future<void> write({
    required String appVersion,
    required DateTime? lastSyncAt,
    required int unsyncedTransactions,
  }) async {
    final payload = <String, dynamic>{
      'app_version': appVersion,
      'last_sync_at': lastSyncAt?.toUtc().toIso8601String(),
      'unsynced_transactions': unsyncedTransactions,
      'written_at': DateTime.now().toUtc().toIso8601String(),
    };

    final temp = File('${file.path}.tmp');
    try {
      await temp.parent.create(recursive: true);
      await temp.writeAsString(const JsonEncoder.withIndent('  ').convert(payload), flush: true);
      await temp.rename(file.path);
    } catch (_) {
      // Best effort, deliberately silent at this level: the caller logs, and a
      // failed heartbeat must never propagate into the sync cycle that produced
      // it.
      try {
        if (await temp.exists()) await temp.delete();
      } catch (_) {
        // Nothing further to do; a stray .tmp is harmless.
      }
    }
  }
}

/// What the updater left behind for the app to report (ADR-0054).
///
/// Exactly one fact: the tag whose update failed on this terminal. The updater
/// blacklists it and never retries it, and exact-match means that tag is also
/// the only version this terminal would consider next — so the terminal is
/// frozen on the last working version until a newer release moves its backend
/// forward. Nothing on the Pi says so, and nobody walks past a kiosk asking
/// what build it runs.
///
/// So the app reads this at startup and puts it on the wire in
/// `X-Terminal-Blocked-Version`, where the admin panel can show it. Read once
/// rather than watched: the updater writes it and *then* restarts the app, so
/// startup is the only moment at which it can have changed.
class UpdaterState {
  /// Name of the file, in the app's data directory beside `config.json`.
  static const String fileName = 'update-state.json';

  /// The tag whose update failed here, or null when nothing has.
  final String? blockedVersion;

  const UpdaterState({this.blockedVersion});

  static const UpdaterState none = UpdaterState();

  /// Read the updater's state, or [none] for anything that is not readable.
  ///
  /// A missing file is the ordinary case — most terminals never fail an update
  /// — and a malformed one is treated identically. Neither is worth a startup
  /// failure: the cost of getting this wrong is one missing header on an
  /// otherwise working till.
  static Future<UpdaterState> read(File file) async {
    try {
      if (!await file.exists()) return none;
      final decoded = jsonDecode(await file.readAsString());
      if (decoded is! Map<String, dynamic>) return none;
      final blocked = decoded['blocked_version'];
      if (blocked is! String || blocked.isEmpty) return none;
      return UpdaterState(blockedVersion: blocked);
    } catch (_) {
      return none;
    }
  }

  static Future<UpdaterState> readFromDirectory(String directory) =>
      read(File('$directory/$fileName'));
}
