import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:http/http.dart' as http;
import 'dart:convert';
import 'package:clubbar_terminal/services/dispenser_client.dart';

class MockHttpClient extends Mock implements http.Client {}

class FakeUri extends Fake implements Uri {}

void main() {
  setUpAll(() {
    registerFallbackValue(FakeUri());
  });

  group('DispenserClient', () {
    late MockHttpClient mockHttpClient;
    late DispenserClient client;
    const baseUrl = 'http://localhost:8081';
    const apiKey = 'test-api-key';

    setUp(() {
      mockHttpClient = MockHttpClient();
      client = DispenserClient(
        baseUrl: baseUrl,
        apiKey: apiKey,
        httpClient: mockHttpClient,
        timeoutMs: 3000,
      );
    });

    group('generateTxId', () {
      test('returns unique IDs on each call', () {
        final id1 = client.generateTxId();
        final id2 = client.generateTxId();
        final id3 = client.generateTxId();

        expect(id1, isNot(equals(id2)));
        expect(id2, isNot(equals(id3)));
        expect(id1, isNot(equals(id3)));
      });

      test('returns hex strings between 8-16 characters', () {
        for (var i = 0; i < 10; i++) {
          final id = client.generateTxId();
          expect(id.length, greaterThanOrEqualTo(8));
          expect(id.length, lessThanOrEqualTo(16));
          expect(RegExp(r'^[0-9a-f]+$').hasMatch(id), isTrue,
              reason: 'ID should only contain hex characters');
        }
      });
    });

    group('dispenseTokens', () {
      test('sends correct POST request with headers and body', () async {
        const txId = 'abc12345';
        const quantity = 3;

        when(() => mockHttpClient.post(
              any(),
              headers: any(named: 'headers'),
              body: any(named: 'body'),
            )).thenAnswer((_) async => http.Response(
              jsonEncode({
                'tx_id': txId,
                'state': 'dispensing',
                'quantity': quantity,
                'dispensed': 0,
              'count_reliable': true,
              'error_code': 0,
              'error_type': 'NONE',
              }),
              200,
            ));

        await client.dispenseTokens(txId: txId, quantity: quantity);

        final captured = verify(() => mockHttpClient.post(
              captureAny(),
              headers: captureAny(named: 'headers'),
              body: captureAny(named: 'body'),
            )).captured;

        final uri = captured[0] as Uri;
        final headers = captured[1] as Map<String, String>;
        final body = captured[2] as String;

        expect(uri.toString(), equals('$baseUrl/dispense'));
        expect(headers['Content-Type'], equals('application/json'));
        expect(headers['X-API-Key'], equals(apiKey));

        final bodyJson = jsonDecode(body) as Map<String, dynamic>;
        expect(bodyJson['tx_id'], equals(txId));
        expect(bodyJson['quantity'], equals(quantity));
      });

      test('parses 200 response correctly', () async {
        const txId = 'abc12345';
        const quantity = 3;

        when(() => mockHttpClient.post(
              any(),
              headers: any(named: 'headers'),
              body: any(named: 'body'),
            )).thenAnswer((_) async => http.Response(
              jsonEncode({
                'tx_id': txId,
                'state': 'dispensing',
                'quantity': quantity,
                'dispensed': 1,
              'count_reliable': true,
              'error_code': 0,
              'error_type': 'NONE',
              }),
              200,
            ));

        final result = await client.dispenseTokens(txId: txId, quantity: quantity);

        expect(result.txId, equals(txId));
        expect(result.state, equals('dispensing'));
        expect(result.quantity, equals(quantity));
        expect(result.dispensed, equals(1));
      });

      test('throws DispenserBusyException on 409 Conflict', () async {
        when(() => mockHttpClient.post(
              any(),
              headers: any(named: 'headers'),
              body: any(named: 'body'),
            )).thenAnswer((_) async => http.Response(
              jsonEncode({'error': 'Dispenser busy'}),
              409,
            ));

        expect(
          () => client.dispenseTokens(txId: 'test123', quantity: 2),
          throwsA(isA<DispenserBusyException>()),
        );
      });

      test('a 409 naming a fault is a fault, not "busy"', () async {
        // Two different answers used to arrive as the same exception: "wait,
        // somebody else is using it" and "somebody has to walk over" (#948).
        when(() => mockHttpClient.post(
              any(),
              headers: any(named: 'headers'),
              body: any(named: 'body'),
            )).thenAnswer((_) async => http.Response(
              jsonEncode({
                'error': 'fault',
                'fault': 'hopper_error',
                'fault_code': 3,
              }),
              409,
            ));

        await expectLater(
          client.dispenseTokens(txId: 'test123', quantity: 2),
          throwsA(isA<DispenserFaultException>()
              .having((e) => e.fault, 'fault', DispenserFault.hopperError)
              .having((e) => e.faultCode, 'faultCode', 3)),
        );
      });

      test('a response without count_reliable is a protocol error', () async {
        // Not a default, and above all not `false`: "we do not know how many
        // fell" read as "we counted zero" is the reading that bills nothing
        // while the tray is full (#948).
        when(() => mockHttpClient.post(
              any(),
              headers: any(named: 'headers'),
              body: any(named: 'body'),
            )).thenAnswer((_) async => http.Response(
              jsonEncode({
                'tx_id': 'abc12345',
                'state': 'dispensing',
                'quantity': 3,
                'dispensed': 0,
                'error_code': 0,
                'error_type': 'NONE',
              }),
              200,
            ));

        await expectLater(
          client.dispenseTokens(txId: 'abc12345', quantity: 3),
          throwsA(isA<DispenserProtocolException>()),
        );
      });

      test('throws DispenserException on other HTTP errors', () async {
        when(() => mockHttpClient.post(
              any(),
              headers: any(named: 'headers'),
              body: any(named: 'body'),
            )).thenAnswer((_) async => http.Response(
              jsonEncode({'error': 'Internal error'}),
              500,
            ));

        expect(
          () => client.dispenseTokens(txId: 'test123', quantity: 2),
          throwsA(isA<DispenserException>()
              .having((e) => e.message, 'message', contains('HTTP 500'))),
        );
      });

      test('throws DispenserException on timeout', () async {
        when(() => mockHttpClient.post(
              any(),
              headers: any(named: 'headers'),
              body: any(named: 'body'),
            )).thenAnswer(
          (_) => Future.delayed(
            const Duration(seconds: 10),
            () => http.Response('{}', 200),
          ),
        );

        expect(
          () => client.dispenseTokens(txId: 'test123', quantity: 2),
          throwsA(isA<DispenserException>()
              .having((e) => e.message, 'message', contains('Request failed'))),
        );
      });
    });

    group('getStatus', () {
      test('sends correct GET request with headers', () async {
        const txId = 'abc12345';

        when(() => mockHttpClient.get(
              any(),
              headers: any(named: 'headers'),
            )).thenAnswer((_) async => http.Response(
              jsonEncode({
                'tx_id': txId,
                'state': 'done',
                'quantity': 3,
                'dispensed': 3,
              'count_reliable': true,
              'error_code': 0,
              'error_type': 'NONE',
              }),
              200,
            ));

        await client.getStatus(txId);

        final captured = verify(() => mockHttpClient.get(
              captureAny(),
              headers: captureAny(named: 'headers'),
            )).captured;

        final uri = captured[0] as Uri;
        final headers = captured[1] as Map<String, String>;

        expect(uri.toString(), equals('$baseUrl/dispense/$txId'));
        expect(headers['X-API-Key'], equals(apiKey));
      });

      test('parses status response correctly', () async {
        const txId = 'abc12345';

        when(() => mockHttpClient.get(
              any(),
              headers: any(named: 'headers'),
            )).thenAnswer((_) async => http.Response(
              jsonEncode({
                'tx_id': txId,
                'state': 'done',
                'quantity': 3,
                'dispensed': 3,
              'count_reliable': true,
              'error_code': 0,
              'error_type': 'NONE',
              }),
              200,
            ));

        final result = await client.getStatus(txId);

        expect(result.txId, equals(txId));
        expect(result.state, equals('done'));
        expect(result.quantity, equals(3));
        expect(result.dispensed, equals(3));
      });

      test('throws DispenserNotFoundException on 404', () async {
        when(() => mockHttpClient.get(
              any(),
              headers: any(named: 'headers'),
            )).thenAnswer((_) async => http.Response(
              jsonEncode({'error': 'Transaction not found'}),
              404,
            ));

        expect(
          () => client.getStatus('unknown-id'),
          throwsA(isA<DispenserNotFoundException>()),
        );
      });
    });

    group('getHealth', () {
      /// A whole protocol-2 document, as `dispenser-protocol.md` prints it.
      Map<String, dynamic> healthDocument({
        int protocol = 2,
        String state = 'idle',
        String fault = 'none',
        int faultCode = 0,
      }) =>
          {
            'protocol': protocol,
            'state': state,
            'fault': fault,
            'fault_code': faultCode,
            'uptime': 84230,
            'firmware': '1.2.0',
            'wifi': {'rssi': -47, 'ip': '192.168.188.243', 'ssid': 'Ponyhof'},
            'metrics': {
              'total_dispenses': 150,
              'successful': 147,
              'jams': 3,
              'partial': 2,
              'crashes': 1,
              'failures': 3,
              'requested_tokens': 320,
              'dispensed_tokens': 318,
              'overrun_tokens': 4,
              'filtered_pulses': 11,
            },
            'error_history': [
              {'code': 3, 'type': 'JAM_PERMANENT', 'timestamp': 82150},
            ],
          };

      void answers(Object body, {int status = 200}) {
        when(() => mockHttpClient.get(
              any(),
              headers: any(named: 'headers'),
            )).thenAnswer((_) async => http.Response(
              body is String ? body : jsonEncode(body),
              status,
            ));
      }

      test('health parses the protocol 2 document', () async {
        answers(healthDocument());

        final result = await client.getHealth();

        expect(result.protocol, equals(2));
        expect(result.state, equals(DispenserDeviceState.idle));
        expect(result.fault, equals(DispenserFault.none));
        expect(result.faultCode, equals(0));
        expect(result.isUnavailable, isFalse);
        expect(result.unavailableReason, isNull);
        expect(result.totalDispenses, equals(150));
        expect(result.successful, equals(147));
        expect(result.jams, equals(3));
        expect(result.successRate, closeTo(98.0, 0.001));
        expect(result.overrunTokens, equals(4));
        expect(result.filteredPulses, equals(11));
        expect(result.wifi!.ssid, equals('Ponyhof'));
        expect(result.errorHistory!.single.type, equals('JAM_PERMANENT'));
      });

      test('a jam is unavailable and names its errand', () async {
        answers(healthDocument(state: 'fault', fault: 'jam'));

        final result = await client.getHealth();

        expect(result.isUnavailable, isTrue);
        expect(result.needsAttendance, isTrue);
        expect(result.unavailableReason,
            equals(DispenserUnavailableReason.jam));
      });

      test('a hopper error carries the Azkoyen code', () async {
        answers(healthDocument(
            state: 'fault', fault: 'hopper_error', faultCode: 5));

        final result = await client.getHealth();

        expect(result.unavailableReason,
            equals(DispenserUnavailableReason.hopperError));
        expect(result.faultCode, equals(5));
      });

      test('dispensing is busy, not unavailable', () async {
        answers(healthDocument(state: 'dispensing'));

        final result = await client.getHealth();

        expect(result.isUnavailable, isFalse);
      });

      test('a wrong protocol version is a mismatch, not offline', () async {
        answers(healthDocument(protocol: 1));

        await expectLater(
          client.getHealth(),
          throwsA(isA<DispenserProtocolException>()
              .having((e) => e.reportedProtocol, 'reportedProtocol', 1)),
        );
      });

      test('a malformed health document is a mismatch, not offline', () async {
        // Every one of these used to reach the caller as a `TypeError` out of
        // a hard cast, which the health service turned into `offline` — a
        // network fault that did not exist (#948).
        final broken = <String, Map<String, dynamic>>{
          'no protocol': healthDocument()..remove('protocol'),
          'no state': healthDocument()..remove('state'),
          'no fault': healthDocument()..remove('fault'),
          'no fault_code': healthDocument()..remove('fault_code'),
          'no metrics': healthDocument()..remove('metrics'),
          'unknown state': healthDocument(state: 'wobbling'),
          'unknown fault': healthDocument(fault: 'gremlins'),
        };

        for (final entry in broken.entries) {
          answers(entry.value);
          await expectLater(
            client.getHealth(),
            throwsA(isA<DispenserProtocolException>()),
            reason: '${entry.key} must be a protocol error',
          );
        }

        answers('<html>not json at all</html>');
        await expectLater(
          client.getHealth(),
          throwsA(isA<DispenserProtocolException>()),
        );
      });

      test('sends correct GET request to /health', () async {
        answers(healthDocument());

        await client.getHealth();

        final captured = verify(() => mockHttpClient.get(
              captureAny(),
              headers: captureAny(named: 'headers'),
            )).captured;

        final uri = captured[0] as Uri;
        final headers = captured[1] as Map<String, String>;

        expect(uri.toString(), equals('$baseUrl/health'));
        expect(headers['X-API-Key'], equals(apiKey));
      });
    });

    group('DispenserHealth.offline', () {
      test('is unavailable for the reason "offline"', () {
        final health = DispenserHealth.offline();

        expect(health.contact, equals(DispenserContact.unreachable));
        expect(health.isUnavailable, isTrue);
        expect(health.unavailableReason,
            equals(DispenserUnavailableReason.offline));
        expect(health.needsAttendance, isFalse,
            reason: 'nobody has to open the machine for a network problem');
        expect(health.totalDispenses, equals(0));
        expect(health.successRate, equals(0.0));
      });
    });

    group('DispenserHealth.protocolMismatch', () {
      test('is unavailable, and never reads as offline', () {
        final health = DispenserHealth.protocolMismatch(reportedProtocol: 1);

        expect(health.isUnavailable, isTrue);
        expect(health.unavailableReason,
            equals(DispenserUnavailableReason.protocolMismatch));
        expect(health.protocol, equals(1));
      });
    });
  });
}
