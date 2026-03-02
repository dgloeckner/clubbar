import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/services/real_rfid_service.dart';

void main() {
  group('RealRfidService', () {
    late RealRfidService service;

    setUp(() {
      service = RealRfidService();
    });

    tearDown(() {
      service.dispose();
    });

    test('emitScan should emit card UID to stream', () async {
      // Listen for card scans
      final scans = <String>[];
      final subscription = service.cardScans.listen((cardUid) {
        scans.add(cardUid);
      });

      // Simulate RFID reader scanning a card
      service.emitScan('0003195661');

      // Wait for stream to process
      await Future.delayed(const Duration(milliseconds: 50));

      expect(scans, ['0003195661']);

      await subscription.cancel();
    });

    test('emitScan should trim and uppercase card UID', () async {
      final scans = <String>[];
      final subscription = service.cardScans.listen((cardUid) {
        scans.add(cardUid);
      });

      // Simulate scan with lowercase and whitespace
      service.emitScan('  abc123def  ');

      await Future.delayed(const Duration(milliseconds: 50));

      expect(scans, ['ABC123DEF']);

      await subscription.cancel();
    });

    test('emitScan should ignore empty strings', () async {
      final scans = <String>[];
      final subscription = service.cardScans.listen((cardUid) {
        scans.add(cardUid);
      });

      // Try to emit empty string
      service.emitScan('');
      service.emitScan('   ');

      await Future.delayed(const Duration(milliseconds: 50));

      expect(scans, isEmpty);

      await subscription.cancel();
    });

    test('cardScans should support multiple listeners', () async {
      final scans1 = <String>[];
      final scans2 = <String>[];

      final subscription1 = service.cardScans.listen((cardUid) {
        scans1.add(cardUid);
      });

      final subscription2 = service.cardScans.listen((cardUid) {
        scans2.add(cardUid);
      });

      service.emitScan('TEST123');

      await Future.delayed(const Duration(milliseconds: 50));

      expect(scans1, ['TEST123']);
      expect(scans2, ['TEST123']);

      await subscription1.cancel();
      await subscription2.cancel();
    });

    test('dispose should close the stream', () async {
      bool streamClosed = false;

      service.cardScans.listen(
        (_) {},
        onDone: () {
          streamClosed = true;
        },
      );

      service.dispose();

      await Future.delayed(const Duration(milliseconds: 50));

      expect(streamClosed, isTrue);
    });
  });
}
