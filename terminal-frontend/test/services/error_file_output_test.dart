import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:logger/logger.dart';
import 'package:clubbar_terminal/services/error_file_output.dart';

void main() {
  group('ErrorFileOutput', () {
    late Directory tempDir;
    late File logFile;
    late ErrorFileOutput errorOutput;

    setUp(() async {
      tempDir = Directory.systemTemp.createTempSync('error_output_test_');
      logFile = File('${tempDir.path}/error.log');
      errorOutput = ErrorFileOutput(file: logFile);
      await errorOutput.init();
    });

    tearDown(() async {
      await errorOutput.destroy();
      if (tempDir.existsSync()) {
        tempDir.deleteSync(recursive: true);
      }
    });

    OutputEvent makeEvent(Level level, String message) {
      final logEvent = LogEvent(level, message);
      return OutputEvent(logEvent, [message]);
    }

    test('writes error-level events to file', () async {
      errorOutput.output(makeEvent(Level.error, 'Something went wrong'));
      await errorOutput.destroy();

      final contents = logFile.readAsStringSync();
      expect(contents, contains('Something went wrong'));
    });

    test('writes fatal-level events to file', () async {
      errorOutput.output(makeEvent(Level.fatal, 'Fatal crash'));
      await errorOutput.destroy();

      final contents = logFile.readAsStringSync();
      expect(contents, contains('Fatal crash'));
    });

    test('does not write info-level events to file', () async {
      errorOutput.output(makeEvent(Level.info, 'Just info'));
      await errorOutput.destroy();

      if (logFile.existsSync()) {
        final contents = logFile.readAsStringSync();
        expect(contents, isNot(contains('Just info')));
      }
    });

    test('does not write warning-level events to file', () async {
      errorOutput.output(makeEvent(Level.warning, 'Just a warning'));
      await errorOutput.destroy();

      if (logFile.existsSync()) {
        final contents = logFile.readAsStringSync();
        expect(contents, isNot(contains('Just a warning')));
      }
    });

    test('does not write debug-level events to file', () async {
      errorOutput.output(makeEvent(Level.debug, 'Debug info'));
      await errorOutput.destroy();

      if (logFile.existsSync()) {
        final contents = logFile.readAsStringSync();
        expect(contents, isNot(contains('Debug info')));
      }
    });

    test('filters mixed events — only errors reach file', () async {
      errorOutput.output(makeEvent(Level.info, 'info message'));
      errorOutput.output(makeEvent(Level.warning, 'warning message'));
      errorOutput.output(makeEvent(Level.error, 'error message'));
      errorOutput.output(makeEvent(Level.debug, 'debug message'));
      errorOutput.output(makeEvent(Level.fatal, 'fatal message'));
      await errorOutput.destroy();

      final contents = logFile.readAsStringSync();
      expect(contents, contains('error message'));
      expect(contents, contains('fatal message'));
      expect(contents, isNot(contains('info message')));
      expect(contents, isNot(contains('warning message')));
      expect(contents, isNot(contains('debug message')));
    });
  });
}
