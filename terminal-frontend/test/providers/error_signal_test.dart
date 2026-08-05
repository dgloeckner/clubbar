import 'package:flutter/foundation.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/providers/error_signal.dart';

/// Minimal host for the mixin — exposes the protected members so the
/// signalling contract can be exercised directly.
class _TestEmitter extends ChangeNotifier with ErrorSignal {
  void fail(TerminalErrorKey key, {Object? cause, StackTrace? stackTrace}) {
    emitError(key, cause: cause, stackTrace: stackTrace);
    notifyListeners();
  }

  void succeed() {
    resetError();
    notifyListeners();
  }
}

void main() {
  group('ErrorSignal', () {
    late _TestEmitter emitter;

    setUp(() => emitter = _TestEmitter());

    test('starts with no error', () {
      expect(emitter.lastError, isNull);
      expect(emitter.lastErrorKey, isNull);
    });

    test('emitError exposes the key without prose', () {
      emitter.fail(TerminalErrorKey.cartEmpty);

      expect(emitter.lastErrorKey, equals(TerminalErrorKey.cartEmpty));
      expect(emitter.lastError!.sequence, equals(1));
    });

    // Acceptance criterion (#57): two identical consecutive errors must both
    // produce a distinct display event, so a repeated failure re-renders.
    test('two identical consecutive errors produce distinct display events',
        () {
      emitter.fail(TerminalErrorKey.backendUnreachable);
      final first = emitter.lastError;

      emitter.fail(TerminalErrorKey.backendUnreachable);
      final second = emitter.lastError;

      expect(first!.key, equals(second!.key));
      expect(second.sequence, greaterThan(first.sequence));
      expect(first, isNot(equals(second)));
    });

    test('every emission notifies listeners, even when the key repeats', () {
      var notifications = 0;
      emitter.addListener(() => notifications++);

      emitter.fail(TerminalErrorKey.syncFailed);
      emitter.fail(TerminalErrorKey.syncFailed);

      expect(notifications, equals(2));
    });

    test('clearError drops the error and notifies', () {
      var notifications = 0;
      emitter.fail(TerminalErrorKey.syncFailed);
      emitter.addListener(() => notifications++);

      emitter.clearError();

      expect(emitter.lastError, isNull);
      expect(notifications, equals(1));
    });

    test('resetError drops the error silently', () {
      var notifications = 0;
      emitter.fail(TerminalErrorKey.syncFailed);
      emitter.addListener(() => notifications++);

      emitter.succeed();

      expect(emitter.lastError, isNull);
      // one notification from succeed() itself, none from resetError
      expect(notifications, equals(1));
    });

    test('sequence keeps increasing across different keys', () {
      emitter.fail(TerminalErrorKey.cartEmpty);
      emitter.fail(TerminalErrorKey.syncFailed);
      emitter.fail(TerminalErrorKey.cartEmpty);

      expect(emitter.lastError!.sequence, equals(3));
    });

    test('clearing does not reset the sequence, so a repeat still differs', () {
      emitter.fail(TerminalErrorKey.cartEmpty);
      final before = emitter.lastError;
      emitter.clearError();
      emitter.fail(TerminalErrorKey.cartEmpty);

      expect(emitter.lastError, isNot(equals(before)));
    });

    test('emitError accepts a raw cause without surfacing it', () {
      emitter.fail(
        TerminalErrorKey.checkoutFailed,
        cause: Exception('SQLITE_BUSY: database is locked'),
        stackTrace: StackTrace.current,
      );

      expect(emitter.lastErrorKey, equals(TerminalErrorKey.checkoutFailed));
      expect(emitter.lastError.toString(), isNot(contains('SQLITE_BUSY')));
    });
  });
}
