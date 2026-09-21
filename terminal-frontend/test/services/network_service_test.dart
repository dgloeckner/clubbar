import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/generated/terminal.enums.swagger.dart'
    as api_enums;
import 'package:clubbar_terminal/generated/terminal.swagger.dart';
import 'package:clubbar_terminal/services/network_service.dart';

void main() {
  group('NetworkService.checkHealth', () {
    test('returns false when no server is running', () async {
      // Use a port that is almost certainly not serving anything
      final service = NetworkService(baseUrl: 'http://localhost:19999/api');
      final result = await service.checkHealth();
      expect(result, isFalse);
    });

    test('returns false for invalid URL', () async {
      final service = NetworkService(baseUrl: 'http://invalid-host-that-does-not-exist.local/api');
      final result = await service.checkHealth();
      expect(result, isFalse);
    });
  });

  group('NetworkService.fetchInstanceName', () {
    test('returns null when no server is running', () async {
      final service = NetworkService(baseUrl: 'http://localhost:19999/api');
      final result = await service.fetchInstanceName();
      expect(result, isNull);
    });

    test('returns null for invalid URL', () async {
      final service = NetworkService(baseUrl: 'http://invalid-host-that-does-not-exist.local/api');
      final result = await service.fetchInstanceName();
      expect(result, isNull);
    });
  });

  // A single "no server running" case per method is deliberate, not partial
  // coverage: fetchInstanceId/acknowledgePairing route every failure — DNS,
  // refused connection, timeout — through the same generic catch, so a
  // second real-DNS-lookup case (as fetchInstanceName/checkHealth above
  // have) would exercise the identical code path while adding a slow,
  // real-network lookup. Two of those already made this suite measurably
  // flakier under load; this only proves the fast side.
  group('NetworkService.fetchInstanceId', () {
    test('returns null when no server is running', () async {
      final service = NetworkService(baseUrl: 'http://localhost:19999/api');
      final result = await service.fetchInstanceId();
      expect(result, isNull);
    });
  });

  /// The one place the status report's wire shape is asserted against a real
  /// socket (#953). Everything else about it is a model; this is what the
  /// backend actually receives — the route, the verb, the bearer token that
  /// names the terminal, and a body of counts and nothing else.
  group('NetworkService.reportTerminalStatus', () {
    late HttpServer server;
    late List<HttpRequest> received;
    late List<String> bodies;
    int status = 204;

    setUp(() async {
      received = [];
      bodies = [];
      status = 204;
      server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
      server.listen((request) async {
        received.add(request);
        bodies.add(await utf8.decoder.bind(request).join());
        request.response.statusCode = status;
        await request.response.close();
      });
    });

    tearDown(() => server.close(force: true));

    NetworkService serviceUnderTest() {
      final service =
          NetworkService(baseUrl: 'http://127.0.0.1:${server.port}/api');
      service.setAuthToken('terminal-token');
      return service;
    }

    const report = TerminalStatusReport(
      dispenser: DispenserStatus(
        configured: true,
        contact: api_enums.DispenserStatusContact.reported,
        state: api_enums.DispenserStatusState.idle,
        fault: api_enums.DispenserStatusFault.none,
        faultCode: 0,
        pendingReconciliations: 0,
        manualReconciliations: 0,
      ),
    );

    test('PUTs the report to the sync route, named by its bearer token',
        () async {
      await serviceUnderTest().reportTerminalStatus(report);

      expect(received, hasLength(1));
      expect(received.single.method, 'PUT');
      expect(received.single.uri.path, '/api/sync/terminal-status');
      expect(received.single.headers.value('authorization'),
          'Bearer terminal-token');

      final body = jsonDecode(bodies.single) as Map<String, dynamic>;
      expect(body.keys, ['dispenser']);
      final dispenser = body['dispenser'] as Map<String, dynamic>;
      expect(dispenser['configured'], isTrue);
      expect(dispenser['contact'], 'reported');
      expect(dispenser['state'], 'idle');
      expect(dispenser['fault'], 'none');
      // Nothing naming a person or a purchase reaches the wire.
      expect(bodies.single.contains('member'), isFalse);
      expect(bodies.single.contains('tx_id'), isFalse);
      // The verdict is the backend's to derive, never the terminal's to send:
      // a reason on the wire could contradict the state beside it.
      expect(dispenser.containsKey('available'), isFalse);
      expect(dispenser.containsKey('unavailable_reason'), isFalse);
      expect(dispenser.containsKey('state_since'), isFalse);
    });

    test('a non-204 answer is a transport failure, and says so', () async {
      // The route answers 204 for a stored report and a dropped one alike,
      // so anything else came from something other than the route.
      status = 503;

      await expectLater(
        serviceUnderTest().reportTerminalStatus(report),
        throwsA(isA<NetworkException>()
            .having((e) => e.statusCode, 'statusCode', 503)),
      );
    });

    test('throws rather than hanging when nothing is listening', () async {
      final port = server.port;
      await server.close(force: true);
      final service = NetworkService(baseUrl: 'http://127.0.0.1:$port/api');

      await expectLater(service.reportTerminalStatus(report),
          throwsA(isA<NetworkException>()));
    });
  });

  group('NetworkService.acknowledgePairing', () {
    // Unlike fetchInstanceId/fetchInstanceName this is NOT fail-soft: it is
    // a deliberate staff action (ADR-0035), and swallowing a failure into a
    // null would let the terminal locally clear a mismatch the backend
    // never actually recorded the acknowledgement for.
    test('throws NetworkException when no server is running', () async {
      final service = NetworkService(baseUrl: 'http://localhost:19999/api');
      expect(() => service.acknowledgePairing(), throwsA(isA<NetworkException>()));
    });
  });
}
