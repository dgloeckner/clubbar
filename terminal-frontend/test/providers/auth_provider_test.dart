import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/providers/auth_provider.dart';

void main() {
  group('AuthProvider', () {
    late AuthProvider provider;

    setUp(() {
      provider = AuthProvider();
    });

    test('isAuthenticated is false initially', () {
      expect(provider.isAuthenticated, isFalse);
    });

    test('token is null initially', () {
      expect(provider.token, isNull);
    });

    test('setToken stores token and updates isAuthenticated', () {
      provider.setToken('test-token-123');

      expect(provider.token, equals('test-token-123'));
      expect(provider.isAuthenticated, isTrue);
    });

    test('clearToken removes token and updates isAuthenticated', () {
      provider.setToken('test-token-123');
      provider.clearToken();

      expect(provider.token, isNull);
      expect(provider.isAuthenticated, isFalse);
    });

    test('lastError is null initially', () {
      expect(provider.lastError, isNull);
    });

    test('lastError tracks authentication errors', () {
      provider.setError('Invalid credentials');

      expect(provider.lastError, equals('Invalid credentials'));
    });

    test('clearError removes error', () {
      provider.setError('Invalid credentials');
      provider.clearError();

      expect(provider.lastError, isNull);
    });
  });
}
