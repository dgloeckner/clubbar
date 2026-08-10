import 'dart:async';

import 'package:flutter/foundation.dart';

import 'package:clubbar_terminal/services/rfid_reader_probe.dart';

/// What the terminal currently knows about the RFID reader.
enum RfidReaderStatus {
  /// Nothing is known — monitoring is off, or this platform cannot be asked.
  /// The UI must look exactly as it did before reader monitoring existed.
  unknown,

  /// The reader is plugged in; scans can be expected.
  connected,

  /// The reader is gone. Taps do nothing, so the terminal has to say so.
  disconnected,
}

/// Watches whether the RFID reader is still plugged in (issue #35).
///
/// The reader is a passive USB keyboard-wedge: it types a UID and nothing else,
/// so its absence is silent. Without this, an unplugged reader leaves the idle
/// screen pulsing "hold your token to the scanner" forever and recovery means a
/// member fetching staff who restart the terminal.
///
/// Polling — rather than waiting for an event — is what makes reconnect work
/// without a restart: the same check that notices the device vanish notices it
/// come back. [interval] is the whole of the detection latency, which is why it
/// defaults well inside the 15 s the issue asks for.
class RfidReaderHealthService extends ChangeNotifier {
  final RfidReaderProbe probe;
  final Duration interval;
  final DateTime Function() _now;

  Timer? _timer;
  bool _checkInFlight = false;
  RfidReaderStatus _status = RfidReaderStatus.unknown;
  DateTime? _lastSeenAt;

  RfidReaderHealthService({
    required this.probe,
    this.interval = const Duration(seconds: 5),
    DateTime Function()? now,
  }) : _now = now ?? DateTime.now;

  RfidReaderStatus get status => _status;

  /// True only for a reader known to be missing — never for [unknown], so a
  /// terminal that cannot check is never told its reader is broken.
  bool get isDisconnected => _status == RfidReaderStatus.disconnected;

  /// When the reader was last seen present, or null if it never was in this
  /// run. Shown to staff in the status modal: "gone since 20:14" is actionable,
  /// "gone" is not.
  DateTime? get lastSeenAt => _lastSeenAt;

  /// Check now, then keep checking every [interval].
  void startMonitoring() {
    stopMonitoring();
    _check();
    _timer = Timer.periodic(interval, (_) => _check());
  }

  void stopMonitoring() {
    _timer?.cancel();
    _timer = null;
  }

  /// Check on demand — e.g. when staff open the status modal to see whether
  /// the cable they just pushed in took effect.
  Future<void> checkNow() => _check();

  Future<void> _check() async {
    // A probe slower than the interval must not stack up checks behind itself.
    if (_checkInFlight) return;
    _checkInFlight = true;

    try {
      final present = await probe.isReaderPresent();
      final next = switch (present) {
        null => RfidReaderStatus.unknown,
        true => RfidReaderStatus.connected,
        false => RfidReaderStatus.disconnected,
      };

      if (next == RfidReaderStatus.connected) _lastSeenAt = _now();
      if (next == _status) return;

      _status = next;
      notifyListeners();
    } finally {
      _checkInFlight = false;
    }
  }

  @override
  void dispose() {
    stopMonitoring();
    super.dispose();
  }
}
