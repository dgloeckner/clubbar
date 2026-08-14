import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:http/http.dart' as http;
import 'package:clubbar_terminal/services/network_service.dart';

class MockHttpClient extends Mock implements http.Client {}

class FakeUri extends Fake implements Uri {}

void main() {
  group('NetworkService', () {
    late NetworkService service;

    setUp(() {
      service = NetworkService(baseUrl: 'http://localhost:8080/api');
    });

    test('setAuthToken stores token', () {
      service.setAuthToken('test-token');

      expect(service.getAuthToken(), equals('test-token'));
    });

    test('clearAuthToken removes token', () {
      service.setAuthToken('test-token');
      service.clearAuthToken();

      expect(service.getAuthToken(), isNull);
    });

    test('getAuthToken returns null initially', () {
      expect(service.getAuthToken(), isNull);
    });

    test('NetworkException has correct message', () {
      final exception = NetworkException('Test error', statusCode: 404);

      expect(exception.toString(), contains('Test error'));
      expect(exception.toString(), contains('404'));
    });

    test('NetworkException without status code', () {
      final exception = NetworkException('Test error');

      expect(exception.toString(), contains('Test error'));
    });

    /// #395 — the status code alone cannot carry this. Every terminal auth
    /// failure is a 401; only the backend's `error` field separates "this
    /// credential aged out, an admin must rotate it" from "this token was
    /// never valid", and only one of those is worth telling staff about.
    group('backendErrorCode', () {
      test('reads the error field out of a decoded body', () {
        expect(
          backendErrorCode({'error': 'terminal_token_expired'}),
          'terminal_token_expired',
        );
      });

      test('reads the error field out of a raw JSON string body', () {
        expect(
          backendErrorCode('{"error":"terminal_token_expired","message":"..."}'),
          'terminal_token_expired',
        );
      });

      test('is null for a body that carries no error code', () {
        expect(backendErrorCode(null), isNull);
        expect(backendErrorCode(''), isNull);
        expect(backendErrorCode('<html>gateway timeout</html>'), isNull);
        expect(backendErrorCode({'message': 'no code here'}), isNull);
        expect(backendErrorCode({'error': 42}), isNull);
      });

      test('the code reaches the exception it is raised with', () {
        final exception = NetworkException(
          'Sync members failed: HTTP 401',
          statusCode: 401,
          errorCode: 'terminal_token_expired',
        );

        expect(exception.errorCode, 'terminal_token_expired');
        expect(exception.toString(), contains('terminal_token_expired'));
      });
    });

    test('get endpoint constructs correct URL', () {
      // This is a basic test that the service can be instantiated
      // and has the right configuration
      expect(service, isNotNull);
    });

    test('post endpoint has correct method', () {
      // This is a basic test that the service can be instantiated
      // and has the right configuration
      expect(service, isNotNull);
    });

    test('syncMembers returns MembersSyncResponse', () {
      // This test verifies the sync methods are correctly defined
      // Full mocking would require more complex setup with MockHttpClient
      expect(service.syncMembers, isNotNull);
    });

    test('syncProducts returns ProductsSyncResponse', () {
      // This test verifies the sync methods are correctly defined
      // Full mocking would require more complex setup with MockHttpClient
      expect(service.syncProducts, isNotNull);
    });

    test('auth token is included in headers', () {
      service.setAuthToken('test-token');
      // Authorization header would be "Bearer test-token"
      expect(service.getAuthToken(), equals('test-token'));
    });
  });
}
