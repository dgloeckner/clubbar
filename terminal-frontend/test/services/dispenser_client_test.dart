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
      test('parses health metrics correctly', () async {
        when(() => mockHttpClient.get(
              any(),
              headers: any(named: 'headers'),
            )).thenAnswer((_) async => http.Response(
              jsonEncode({
                'status': 'ok',
                'dispenser': 'idle',
                'metrics': {
                  'total_dispenses': 150,
                  'successful': 147,
                  'jams': 3,
                }
              }),
              200,
            ));

        final result = await client.getHealth();

        expect(result.status, equals('ok'));
        expect(result.dispenser, equals('idle'));
        expect(result.totalDispenses, equals(150));
        expect(result.successful, equals(147));
        expect(result.jams, equals(3));
      });

      test('calculates successRate correctly', () async {
        when(() => mockHttpClient.get(
              any(),
              headers: any(named: 'headers'),
            )).thenAnswer((_) async => http.Response(
              jsonEncode({
                'status': 'ok',
                'dispenser': 'idle',
                'metrics': {
                  'total_dispenses': 200,
                  'successful': 190,
                  'jams': 10,
                }
              }),
              200,
            ));

        final result = await client.getHealth();

        // 190/200 * 100 = 95.0
        expect(result.successRate, equals(95.0));
      });

      test('handles zero dispenses correctly', () async {
        when(() => mockHttpClient.get(
              any(),
              headers: any(named: 'headers'),
            )).thenAnswer((_) async => http.Response(
              jsonEncode({
                'status': 'ok',
                'dispenser': 'idle',
                'metrics': {
                  'total_dispenses': 0,
                  'successful': 0,
                  'jams': 0,
                }
              }),
              200,
            ));

        final result = await client.getHealth();

        expect(result.successRate, equals(0.0));
      });

      test('sends correct GET request to /health', () async {
        when(() => mockHttpClient.get(
              any(),
              headers: any(named: 'headers'),
            )).thenAnswer((_) async => http.Response(
              jsonEncode({
                'status': 'ok',
                'dispenser': 'idle',
                'metrics': {
                  'total_dispenses': 0,
                  'successful': 0,
                  'jams': 0,
                }
              }),
              200,
            ));

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
      test('creates offline health state', () {
        final health = DispenserHealth.offline();

        expect(health.status, equals('error'));
        expect(health.dispenser, equals('offline'));
        expect(health.totalDispenses, equals(0));
        expect(health.successful, equals(0));
        expect(health.jams, equals(0));
        expect(health.successRate, equals(0.0));
      });
    });
  });
}
