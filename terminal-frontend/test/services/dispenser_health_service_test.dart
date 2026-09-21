import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';

import 'package:clubbar_terminal/services/dispenser_client.dart';
import 'package:clubbar_terminal/services/dispenser_health_service.dart';

class _MockDispenserClient extends Mock implements DispenserClient {}

void main() {
  group('DispenserHealthService', () {
    late _MockDispenserClient client;
    late DispenserHealthService service;

    setUp(() {
      client = _MockDispenserClient();
      service = DispenserHealthService(client: client);
    });

    tearDown(() => service.dispose());

    test('a network error is offline', () async {
      when(() => client.getHealth())
          .thenThrow(DispenserException('Request failed: SocketException'));

      await service.checkNow();

      expect(service.currentHealth!.unavailableReason,
          equals(DispenserUnavailableReason.offline));
    });

    test('a protocol mismatch is not retold as offline', () async {
      // The whole of finding 13: `fromJson` hard-cast every field, any
      // exception became `DispenserHealth.offline()`, and a terminal talking
      // to the wrong firmware reported a network problem that did not exist.
      when(() => client.getHealth()).thenThrow(DispenserProtocolException(
          'dispenser speaks protocol 1',
          reportedProtocol: 1));

      await service.checkNow();

      final health = service.currentHealth!;
      expect(health.unavailableReason,
          equals(DispenserUnavailableReason.protocolMismatch));
      expect(health.contact, equals(DispenserContact.protocolMismatch));
      expect(health.protocol, equals(1),
          reason: 'the claimed version is what the screen shows');
      expect(health.isUnavailable, isTrue,
          reason: 'a mismatch never degrades into "works, mostly"');
    });

    test('a health report is kept as the device sent it', () async {
      final reported = DispenserHealth(
        protocol: dispenserProtocolVersion,
        state: DispenserDeviceState.fault,
        fault: DispenserFault.jam,
        totalDispenses: 3,
        successful: 2,
        jams: 1,
        successRate: 66.6,
      );
      when(() => client.getHealth()).thenAnswer((_) async => reported);

      await service.checkNow();

      expect(service.currentHealth, same(reported));
      expect(service.currentHealth!.unavailableReason,
          equals(DispenserUnavailableReason.jam));
    });
  });
}
