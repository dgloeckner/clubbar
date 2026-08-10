import 'dart:async';

import 'package:fake_async/fake_async.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/services/rfid_reader_health_service.dart';
import 'package:clubbar_terminal/services/rfid_reader_probe.dart';

/// A probe whose answer the test sets, and which counts how often it was asked.
class FakeRfidReaderProbe implements RfidReaderProbe {
  bool? present;
  int calls = 0;

  /// When set, the probe hangs until it completes — for checking that a slow
  /// check does not stack up behind itself.
  Completer<void>? gate;

  FakeRfidReaderProbe({this.present});

  @override
  Future<bool?> isReaderPresent() async {
    calls++;
    if (gate != null) await gate!.future;
    return present;
  }
}

void main() {
  group('RfidReaderHealthService', () {
    late FakeRfidReaderProbe probe;

    setUp(() => probe = FakeRfidReaderProbe());

    RfidReaderHealthService serviceWith({
      Duration interval = const Duration(seconds: 5),
      DateTime Function()? now,
    }) =>
        RfidReaderHealthService(probe: probe, interval: interval, now: now);

    test('starts out unknown, before anything has been probed', () {
      expect(serviceWith().status, RfidReaderStatus.unknown);
      expect(serviceWith().isDisconnected, isFalse);
    });

    test('reports a present reader as connected', () async {
      probe.present = true;
      final service = serviceWith();

      await service.checkNow();

      expect(service.status, RfidReaderStatus.connected);
      expect(service.isDisconnected, isFalse);
    });

    test('reports an absent reader as disconnected', () async {
      probe.present = false;
      final service = serviceWith();

      await service.checkNow();

      expect(service.status, RfidReaderStatus.disconnected);
      expect(service.isDisconnected, isTrue);
    });

    test('stays unknown — never disconnected — when presence cannot be told',
        () async {
      probe.present = null;
      final service = serviceWith();

      await service.checkNow();

      // A terminal that cannot check must not accuse its own hardware.
      expect(service.status, RfidReaderStatus.unknown);
      expect(service.isDisconnected, isFalse);
    });

    test('notifies on every change and only on a change', () async {
      probe.present = true;
      final service = serviceWith();
      var notifications = 0;
      service.addListener(() => notifications++);

      await service.checkNow();
      expect(notifications, 1);

      // Same answer twice: nothing to tell the UI about.
      await service.checkNow();
      expect(notifications, 1);

      probe.present = false;
      await service.checkNow();
      expect(notifications, 2);
    });

    test('clears the disconnected state when the reader comes back', () async {
      probe.present = false;
      final service = serviceWith();
      await service.checkNow();
      expect(service.isDisconnected, isTrue);

      // Reconnect must recover without a restart (issue #35).
      probe.present = true;
      await service.checkNow();

      expect(service.status, RfidReaderStatus.connected);
      expect(service.isDisconnected, isFalse);
    });

    test('records when the reader was last seen', () async {
      var clock = DateTime.utc(2026, 8, 10, 20, 14);
      probe.present = true;
      final service = serviceWith(now: () => clock);

      await service.checkNow();
      expect(service.lastSeenAt, DateTime.utc(2026, 8, 10, 20, 14));

      // Once it is gone, the timestamp must keep pointing at the last sighting
      // — that is the part staff can act on.
      clock = DateTime.utc(2026, 8, 10, 20, 30);
      probe.present = false;
      await service.checkNow();

      expect(service.lastSeenAt, DateTime.utc(2026, 8, 10, 20, 14));
    });

    test('polls on the configured interval until stopped', () {
      fakeAsync((async) {
        probe.present = true;
        final service = serviceWith(interval: const Duration(seconds: 5));

        service.startMonitoring();
        async.flushMicrotasks();
        expect(probe.calls, 1, reason: 'checks immediately, not one poll late');

        async.elapse(const Duration(seconds: 11));
        expect(probe.calls, 3);

        service.stopMonitoring();
        async.elapse(const Duration(seconds: 20));
        expect(probe.calls, 3);
      });
    });

    test('does not stack checks behind a slow probe', () async {
      final gate = Completer<void>();
      probe
        ..present = true
        ..gate = gate;
      final service = serviceWith();

      final first = service.checkNow();
      final second = service.checkNow(); // while the first is still hanging
      expect(probe.calls, 1);

      gate.complete();
      await Future.wait([first, second]);

      expect(probe.calls, 1);
    });

    test('stops polling when disposed', () {
      fakeAsync((async) {
        probe.present = true;
        final service = serviceWith(interval: const Duration(seconds: 5));
        service.startMonitoring();
        async.flushMicrotasks();

        service.dispose();
        async.elapse(const Duration(seconds: 20));

        expect(probe.calls, 1);
      });
    });
  });
}
