import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/services/mock_rfid_service.dart';

void main() {
  group('MockRfidService', () {
    late MockRfidService rfidService;

    setUp(() {
      rfidService = MockRfidService();
    });

    test('detectCard returns mock member', () async {
      final member = await rfidService.detectCard();

      expect(member, isNotNull);
      expect(member!.cardUid, equals('RF-4821'));
      expect(member.firstName, equals('Max'));
    });

    test('getAllMockMembers returns test data', () {
      final members = rfidService.getAllMockMembers();

      expect(members.length, equals(1));
      expect(members.first.firstName, equals('Max'));
      expect(members.first.isSepaValid, isTrue);
    });

    test('detectCard simulates delay', () async {
      final stopwatch = Stopwatch()..start();
      await rfidService.detectCard();
      stopwatch.stop();

      expect(stopwatch.elapsedMilliseconds, greaterThanOrEqualTo(800));
    });

    test('detectCard with override returns null for unknown card', () async {
      final member = await rfidService.detectCard(cardUidOverride: 'UNKNOWN');

      expect(member, isNull);
    });
  });
}
