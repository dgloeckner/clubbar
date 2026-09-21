import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:http/http.dart' as http;
import 'dart:convert';
import 'dart:io';
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
    const signingKey = 'test-signing-key';

    /// The nonce the stubbed `GET /nonce` hands out, and how long it says it
    /// lives. A test that wants a second, different nonce changes these
    /// between calls.
    late String issuedNonce;
    late int issuedTtl;
    late int nonceRequests;

    /// What a GET that is *not* `/nonce` answers. Set per test instead of
    /// stubbing `http.Client.get` directly: every signed request is preceded
    /// by a nonce fetch through the same client, so the stub has to route by
    /// path rather than answer everything the same way.
    late http.Response Function(Uri uri) getHandler;

    setUp(() {
      mockHttpClient = MockHttpClient();
      issuedNonce = '0123456789abcdef0123456789abcdef';
      issuedTtl = 30;
      nonceRequests = 0;
      getHandler = (uri) => http.Response('no stub for $uri', 404);

      when(() => mockHttpClient.get(any(), headers: any(named: 'headers')))
          .thenAnswer((invocation) async {
        final uri = invocation.positionalArguments[0] as Uri;
        if (uri.path == '/nonce') {
          nonceRequests++;
          return http.Response(
            jsonEncode({'nonce': issuedNonce, 'ttl': issuedTtl}),
            200,
          );
        }
        return getHandler(uri);
      });

      client = DispenserClient(
        baseUrl: baseUrl,
        signingKey: signingKey,
        httpClient: mockHttpClient,
        timeoutMs: 3000,
      );
    });

    /// Answers every non-nonce GET with [response].
    void answersGet(http.Response response) {
      getHandler = (_) => response;
    }

    /// The `[uri, headers]` pairs of every GET that was not a nonce fetch.
    List<List<Object?>> capturedGets() {
      final captured = verify(() => mockHttpClient.get(
            captureAny(),
            headers: captureAny(named: 'headers'),
          )).captured;
      final pairs = <List<Object?>>[];
      for (var i = 0; i < captured.length; i += 2) {
        final uri = captured[i] as Uri;
        if (uri.path == '/nonce') continue;
        pairs.add([uri, captured[i + 1]]);
      }
      return pairs;
    }

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
        expect(headers['X-Nonce'], equals(issuedNonce));
        expect(
          headers['X-Signature'],
          equals(signDispenserRequest(
            key: signingKey,
            method: 'POST',
            path: '/dispense',
            // Over the body that was actually sent, byte for byte.
            body: body,
            nonce: issuedNonce,
          )),
        );

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

        answersGet(http.Response(
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

        final [uri as Uri, headers as Map<String, String>] =
            capturedGets().single;

        expect(uri.toString(), equals('$baseUrl/dispense/$txId'));
        expect(headers['X-Nonce'], equals(issuedNonce));
        expect(
          headers['X-Signature'],
          equals(signDispenserRequest(
            key: signingKey,
            method: 'GET',
            path: '/dispense/$txId',
            body: '',
            nonce: issuedNonce,
          )),
        );
      });

      test('parses status response correctly', () async {
        const txId = 'abc12345';

        answersGet(http.Response(
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
        answersGet(http.Response(
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
            'authenticated': true,
            'fault': fault,
            'fault_code': faultCode,
            'uptime': 84230,
            'firmware': '1.2.0',
            'reset_reason': 'Power on',
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
        answersGet(http.Response(
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
        expect(result.resetReason, equals('Power on'));
        expect(result.errorHistory!.single.type, equals('JAM_PERMANENT'));
      });

      test('a document without a reset reason is still a good document',
          () async {
        // Free-form text a reader compares against its previous value, not a
        // field any verdict rests on: a firmware that stops sending it must
        // not turn a working machine into a protocol mismatch (#953).
        answers(healthDocument()..remove('reset_reason'));

        final result = await client.getHealth();

        expect(result.resetReason, isNull);
        expect(result.isUnavailable, isFalse);
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
          'no authenticated': healthDocument()..remove('authenticated'),
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

        final [uri as Uri, headers as Map<String, String>] =
            capturedGets().single;

        expect(uri.toString(), equals('$baseUrl/health'));
        expect(
          headers['X-Signature'],
          equals(signDispenserRequest(
            key: signingKey,
            method: 'GET',
            path: '/health',
            body: '',
            nonce: issuedNonce,
          )),
        );
      });
    });

    group('request signing (#951)', () {
      /// The two worked examples from `dispenser-protocol.md`, with the
      /// signatures computed by an implementation that is not this one. They
      /// are the interoperability contract: the firmware
      /// (`request_signer.cpp`) and the Go mock (`signing.go`) build the very
      /// same string from the very same pieces, and a client that agrees with
      /// itself but not with them dispenses nothing.
      const exampleKey = 's3cr3t';
      const exampleNonce = '0123456789abcdef0123456789abcdef';

      test('the canonical string is METHOD, PATH, BODY, NONCE on single LFs',
          () {
        expect(
          dispenserCanonicalString(
            method: 'POST',
            path: '/dispense',
            body: '{"tx_id":"abc123","quantity":3}',
            nonce: exampleNonce,
          ),
          equals('POST\n/dispense\n{"tx_id":"abc123","quantity":3}\n'
              '$exampleNonce'),
        );

        // A GET signs the *empty* body — the empty line between two LFs.
        expect(
          dispenserCanonicalString(
            method: 'GET',
            path: '/dispense/abc123',
            body: '',
            nonce: exampleNonce,
          ),
          equals('GET\n/dispense/abc123\n\n$exampleNonce'),
        );
      });

      test('the signature matches the protocol document, in lower-case hex',
          () {
        final post = signDispenserRequest(
          key: exampleKey,
          method: 'POST',
          path: '/dispense',
          body: '{"tx_id":"abc123","quantity":3}',
          nonce: exampleNonce,
        );
        expect(
          post,
          equals(
              'e7c496b031252c2518538f95392be888fe2087574de7d66b676385ba6e7b0188'),
        );
        expect(post.length, equals(64));
        expect(post, equals(post.toLowerCase()),
            reason: 'upper-case hex is rejected by the device');

        expect(
          signDispenserRequest(
            key: exampleKey,
            method: 'GET',
            path: '/dispense/abc123',
            body: '',
            nonce: exampleNonce,
          ),
          equals(
              'aaa0c4869bf8d7313c4d5531a3f810b63c456c3be04ab0e930c6c0fb0cb0e008'),
        );
      });

      test('the key never travels on any request', () async {
        answersGet(http.Response(
          jsonEncode({
            'tx_id': 'abc12345',
            'state': 'done',
            'quantity': 1,
            'dispensed': 1,
            'count_reliable': true,
            'error_code': 0,
            'error_type': 'NONE',
          }),
          200,
        ));
        when(() => mockHttpClient.post(
              any(),
              headers: any(named: 'headers'),
              body: any(named: 'body'),
            )).thenAnswer((_) async => http.Response(
              jsonEncode({
                'tx_id': 'abc12345',
                'state': 'dispensing',
                'quantity': 1,
                'dispensed': 0,
                'count_reliable': true,
                'error_code': 0,
                'error_type': 'NONE',
              }),
              200,
            ));

        await client.dispenseTokens(txId: 'abc12345', quantity: 1);
        await client.getStatus('abc12345');

        final sent = <Map<String, String>>[
          ...verify(() => mockHttpClient.get(
                any(),
                headers: captureAny(named: 'headers'),
              )).captured.whereType<Map<String, String>>(),
          ...verify(() => mockHttpClient.post(
                any(),
                headers: captureAny(named: 'headers'),
                body: any(named: 'body'),
              )).captured.whereType<Map<String, String>>(),
        ];

        expect(sent, isNotEmpty);
        for (final headers in sent) {
          expect(headers.keys.map((k) => k.toLowerCase()),
              isNot(contains('x-api-key')),
              reason: 'protocol 1 is gone; there is no fallback header');
          expect(headers.values, isNot(contains(signingKey)),
              reason: 'the secret keys the HMAC and never leaves the process');
        }
      });

      test('one dispense costs one nonce, however many polls follow it',
          () async {
        when(() => mockHttpClient.post(
              any(),
              headers: any(named: 'headers'),
              body: any(named: 'body'),
            )).thenAnswer((_) async => http.Response(
              jsonEncode({
                'tx_id': 'abc12345',
                'state': 'dispensing',
                'quantity': 2,
                'dispensed': 0,
                'count_reliable': true,
                'error_code': 0,
                'error_type': 'NONE',
              }),
              200,
            ));
        answersGet(http.Response(
          jsonEncode({
            'tx_id': 'abc12345',
            'state': 'done',
            'quantity': 2,
            'dispensed': 2,
            'count_reliable': true,
            'error_code': 0,
            'error_type': 'NONE',
          }),
          200,
        ));

        await client.dispenseTokens(txId: 'abc12345', quantity: 2);
        // The POST spent the nonce, so the first poll fetches one. The polls
        // behind it are read-only and reuse it.
        await client.getStatus('abc12345');
        await client.getStatus('abc12345');
        await client.getStatus('abc12345');

        expect(nonceRequests, equals(2),
            reason: 'one for the POST, one for the polls behind it');
      });

      test('a nonce that has passed its stated TTL is replaced', () async {
        issuedTtl = 1; // shorter than the 5 s safety margin: usable once, now
        answersGet(http.Response(
          jsonEncode({
            'tx_id': 'abc12345',
            'state': 'done',
            'quantity': 1,
            'dispensed': 1,
            'count_reliable': true,
            'error_code': 0,
            'error_type': 'NONE',
          }),
          200,
        ));

        await client.getStatus('abc12345');
        await Future<void>.delayed(const Duration(milliseconds: 1100));
        await client.getStatus('abc12345');

        expect(nonceRequests, equals(2),
            reason: 'the TTL comes off the wire and is not assumed to be 30 s');
      });

      test('a 401 naming the nonce is retried exactly once, with a fresh one',
          () async {
        var posts = 0;
        when(() => mockHttpClient.post(
              any(),
              headers: any(named: 'headers'),
              body: any(named: 'body'),
            )).thenAnswer((_) async {
          posts++;
          if (posts == 1) {
            return http.Response(
              jsonEncode({'error': 'unauthorized', 'reason': 'nonce'}),
              401,
            );
          }
          return http.Response(
            jsonEncode({
              'tx_id': 'abc12345',
              'state': 'dispensing',
              'quantity': 1,
              'dispensed': 0,
              'count_reliable': true,
              'error_code': 0,
              'error_type': 'NONE',
            }),
            200,
          );
        });

        final result =
            await client.dispenseTokens(txId: 'abc12345', quantity: 1);

        expect(result.state, equals('dispensing'));
        expect(posts, equals(2), reason: 'retried once, not more');
        expect(nonceRequests, equals(2));
      });

      test('a 401 naming the signature stops, and says it is configuration',
          () async {
        var posts = 0;
        when(() => mockHttpClient.post(
              any(),
              headers: any(named: 'headers'),
              body: any(named: 'body'),
            )).thenAnswer((_) async {
          posts++;
          return http.Response(
            jsonEncode({'error': 'unauthorized', 'reason': 'signature'}),
            401,
          );
        });

        await expectLater(
          client.dispenseTokens(txId: 'abc12345', quantity: 1),
          throwsA(isA<DispenserSignatureException>()),
        );
        expect(posts, equals(1),
            reason: 'a retry with the same wrong key changes nothing');
      });

      test('a 401 the client cannot read is treated as the signature',
          () async {
        var posts = 0;
        when(() => mockHttpClient.post(
              any(),
              headers: any(named: 'headers'),
              body: any(named: 'body'),
            )).thenAnswer((_) async {
          posts++;
          return http.Response('<html>go away</html>', 401);
        });

        await expectLater(
          client.dispenseTokens(txId: 'abc12345', quantity: 1),
          throwsA(isA<DispenserSignatureException>()),
        );
        expect(posts, equals(1),
            reason: 'an unreadable body is not evidence that a retry helps');
      });

      test('the unauthenticated minimal document means our key is wrong',
          () async {
        // `/health` is the one endpoint that answers an unverified caller
        // with `200` instead of `401` — the four-field document, so a monitor
        // can still tell a live machine from a dead one. This terminal signs
        // every poll, so `authenticated: false` says the signature did not
        // verify, and reading it as a malformed document would report a
        // wrong key as a protocol mismatch.
        answersGet(http.Response(
          jsonEncode({
            'protocol': 2,
            'state': 'idle',
            'fault': 'none',
            'authenticated': false,
          }),
          200,
        ));

        await expectLater(
          client.getHealth(),
          throwsA(isA<DispenserSignatureException>()),
        );
      });

      test('a health poll the device refuses is a signature failure',
          () async {
        answersGet(http.Response(
          jsonEncode({'error': 'unauthorized', 'reason': 'signature'}),
          401,
        ));

        await expectLater(
          client.getHealth(),
          throwsA(isA<DispenserSignatureException>()),
        );
      });

      test('a nonce fetch that fails is a dispenser exception, not a crash',
          () async {
        when(() => mockHttpClient.get(any(), headers: any(named: 'headers')))
            .thenThrow(const SocketException('no route to host'));

        await expectLater(
          client.dispenseTokens(txId: 'abc12345', quantity: 1),
          throwsA(isA<DispenserException>()),
        );
        await expectLater(
          client.getStatus('abc12345'),
          throwsA(isA<DispenserException>()),
        );
        await expectLater(
          client.getHealth(),
          throwsA(isA<DispenserException>()),
        );
      });

      test('a nonce that is not 32 hex characters is a protocol error',
          () async {
        issuedNonce = 'NOT-HEX';

        await expectLater(
          client.getHealth(),
          throwsA(isA<DispenserProtocolException>()),
        );
      });
    });

    group('DispenserHealth.signingKeyRejected', () {
      test('is unavailable for its own reason, and never reads as offline',
          () {
        final health = DispenserHealth.signingKeyRejected();

        expect(health.isUnavailable, isTrue);
        expect(health.unavailableReason,
            equals(DispenserUnavailableReason.signingKeyRejected));
        expect(health.needsAttendance, isFalse,
            reason: 'nobody has to open the machine for a wrong key');
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
