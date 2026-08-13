import 'package:flutter_test/flutter_test.dart';
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
