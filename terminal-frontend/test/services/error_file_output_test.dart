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

    test('writes reach disk without calling destroy() — survives a kill', () {
      // A kiosk terminal is routinely stopped with SIGTERM/SIGKILL rather
      // than a graceful shutdown, so destroy() may never run. The whole
      // point of this output is that error.log must not depend on it.
      errorOutput.output(makeEvent(Level.error, 'Crash before shutdown'));

      final contents = logFile.readAsStringSync();
      expect(contents, contains('Crash before shutdown'));
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

    // Issue #889 item 4: error.log had no size cap at all — a busy terminal
    // fills the disk over months.
    group('size cap', () {
      // Fixed-width (7 bytes: "lineNN\n") so the cut point can be reasoned
      // about exactly, and a partially-kept line is easy to spot.
      String numberedLines(int count) {
        final buffer = StringBuffer();
        for (var i = 0; i < count; i++) {
          buffer.write('line${i.toString().padLeft(2, '0')}\n');
        }
        return buffer.toString();
      }

      test('does not trim a file under the cap', () async {
        final capped =
            ErrorFileOutput(file: logFile, maxBytes: 1024 * 1024);
        capped.output(makeEvent(Level.error, 'small'));
        await capped.destroy();

        expect(logFile.readAsStringSync(), contains('small'));
      });

      test('trims the oldest half and keeps only whole lines', () async {
        final capped = ErrorFileOutput(file: logFile, maxBytes: 50);
        logFile.writeAsStringSync(numberedLines(20)); // 140 bytes

        capped.output(makeEvent(Level.error, 'the new line'));
        await capped.destroy();

        final lines =
            logFile.readAsLinesSync().where((l) => l.isNotEmpty).toList();

        expect(lines, isNot(contains('line00')),
            reason: 'oldest content must be dropped');
        expect(lines.last, 'the new line');
        expect(logFile.lengthSync(), lessThan(140),
            reason: 'the file must not just keep growing');
        for (final line in lines) {
          expect(
            line == 'the new line' || RegExp(r'^line\d\d$').hasMatch(line),
            isTrue,
            reason: 'no line may be a fragment of another: "$line"',
          );
        }
      });
    });
  });
}
