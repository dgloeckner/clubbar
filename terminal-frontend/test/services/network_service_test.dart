import 'package:flutter_test/flutter_test.dart';
import 'package:ruderbar_terminal/services/network_service.dart';

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
}
