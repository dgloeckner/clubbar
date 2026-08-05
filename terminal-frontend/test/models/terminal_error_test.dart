import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';

void main() {
  group('TerminalError', () {
    test('errors with the same key but different sequence are not equal', () {
      const first =
          TerminalError(key: TerminalErrorKey.cartEmpty, sequence: 1);
      const second =
          TerminalError(key: TerminalErrorKey.cartEmpty, sequence: 2);

      expect(first, isNot(equals(second)));
    });

    test('errors with the same key and sequence are equal', () {
      const first =
          TerminalError(key: TerminalErrorKey.cartEmpty, sequence: 1);
      const second =
          TerminalError(key: TerminalErrorKey.cartEmpty, sequence: 1);

      expect(first, equals(second));
      expect(first.hashCode, equals(second.hashCode));
    });

    test('errors with different keys are not equal', () {
      const first =
          TerminalError(key: TerminalErrorKey.cartEmpty, sequence: 1);
      const second =
          TerminalError(key: TerminalErrorKey.syncFailed, sequence: 1);

      expect(first, isNot(equals(second)));
    });

    test('toString exposes the key and sequence, never prose', () {
      const error =
          TerminalError(key: TerminalErrorKey.backendUnreachable, sequence: 7);

      expect(error.toString(), contains('backendUnreachable'));
      expect(error.toString(), contains('7'));
    });
  });
}
