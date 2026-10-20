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

    /// When the reading was taken, which travels to the backend as
    /// `observed_at` (#953). The terminal's own clock: an ESP8266 has no wall
    /// clock and reports an uptime instead.
    group('lastCheckedAt', () {
      test('is null until the first poll answers', () {
        expect(service.lastCheckedAt, isNull);
      });

      test('is stamped in UTC on a reading', () async {
        when(() => client.getHealth()).thenAnswer((_) async => DispenserHealth(
              protocol: dispenserProtocolVersion,
              state: DispenserDeviceState.idle,
              totalDispenses: 0,
              successful: 0,
              jams: 0,
              successRate: 0,
            ));

        await service.checkNow();

        expect(service.lastCheckedAt, isNotNull);
        expect(service.lastCheckedAt!.isUtc, isTrue);
      });

      test('a failed poll is a reading too', () async {
        // "We looked at 20:14 and nothing was there" is a fact with a time on
        // it; without one, an outage would be dated to the last time the
        // machine answered.
        when(() => client.getHealth())
            .thenThrow(DispenserException('connection refused'));

        await service.checkNow();

        expect(service.currentHealth!.contact,
            equals(DispenserContact.unreachable));
        expect(service.lastCheckedAt, isNotNull);
      });

      test('a protocol mismatch is a reading too', () async {
        when(() => client.getHealth()).thenThrow(
            DispenserProtocolException('nope', reportedProtocol: 1));

        await service.checkNow();

        expect(service.lastCheckedAt, isNotNull);
      });
    });
  });
}
