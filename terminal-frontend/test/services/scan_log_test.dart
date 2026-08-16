// Issue #370: a tap that dies in the capture buffer is invisible — no sound,
// no banner, nothing on screen. The scan log is what makes it visible.
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/services/scan_log.dart';

void main() {
  late DateTime now;
  late ScanLog log;

  setUp(() {
    now = DateTime(2026, 8, 16, 20, 14, 3);
    log = ScanLog(now: () => now);
  });

  group('ScanLog', () {
    test('starts empty', () {
      expect(log.events, isEmpty);
      expect(log.latest, isNull);
    });

    test('records events newest first', () {
      log.record(ScanEventKind.uidCaptured, uid: 'AB12');
      now = now.add(const Duration(minutes: 1));
      log.record(ScanEventKind.rejected, uid: 'AB12', detail: 'unknownCard');

      expect(log.events.map((e) => e.kind),
          [ScanEventKind.rejected, ScanEventKind.uidCaptured]);
      expect(log.latest!.kind, ScanEventKind.rejected);
    });

    test('keeps the newest [capacity] events and drops the oldest', () {
      for (var i = 0; i < ScanLog.capacity + 5; i++) {
        now = now.add(const Duration(minutes: 1));
        log.record(ScanEventKind.uidCaptured, uid: 'UID$i');
      }

      expect(log.events, hasLength(ScanLog.capacity));
      expect(log.events.first.uid, 'UID${ScanLog.capacity + 4}');
      expect(log.events.last.uid, 'UID5');
    });

    // A keyboard attached to the kiosk, or a reader stuck mid-burst, would
    // otherwise push every earlier event out of the buffer within seconds —
    // exactly when the buffer is the only record of what went wrong.
    test('folds an immediate repeat of the same outcome into one entry', () {
      log.record(ScanEventKind.unprintableKey, detail: 'numpad4');
      now = now.add(const Duration(seconds: 1));
      log.record(ScanEventKind.unprintableKey, detail: 'numpad4');
      now = now.add(const Duration(seconds: 1));
      log.record(ScanEventKind.unprintableKey, detail: 'numpad4');

      expect(log.events, hasLength(1));
      expect(log.events.single.count, 3);
      expect(log.events.single.at, now, reason: 'the fold tracks the latest');
      expect(log.events.single.summary, contains('x3'));
    });

    test('a repeat after the coalesce window is its own entry', () {
      log.record(ScanEventKind.partialDiscarded, detail: '6 chars');
      now = now.add(const Duration(minutes: 5));
      log.record(ScanEventKind.partialDiscarded, detail: '6 chars');

      expect(log.events, hasLength(2));
      expect(log.events.every((e) => e.count == 1), isTrue);
    });

    test('a different outcome breaks the fold', () {
      log.record(ScanEventKind.unprintableKey, detail: 'numpad4');
      log.record(ScanEventKind.unprintableKey, detail: 'numpad5');

      expect(log.events, hasLength(2));
    });

    test('notifies listeners so an open status modal stays live', () {
      var notifications = 0;
      log.addListener(() => notifications++);

      log.record(ScanEventKind.uidCaptured, uid: 'AB12');
      log.record(ScanEventKind.uidCaptured, uid: 'AB12');
      log.clear();

      expect(notifications, 3);
      expect(log.events, isEmpty);
    });

    test('a summary names the kind, the UID and the detail', () {
      log.record(ScanEventKind.rejected, uid: 'AB12', detail: 'unknownCard');

      expect(log.latest!.summary, 'rejected AB12 unknownCard');
    });

    // The three that leave the member staring at an unchanged screen — they
    // are logged at error level so they reach error.log, the only sink that
    // outlives a kiosk session.
    test('the silently lost taps are marked as such', () {
      expect(
        ScanEventKind.values.where((k) => k.isSilentLoss).toSet(),
        {
          ScanEventKind.partialDiscarded,
          ScanEventKind.captureNotReady,
          ScanEventKind.droppedBusy,
        },
      );
    });
  });
}
