import 'dart:io';

import 'package:logger/logger.dart';

/// Custom [LogOutput] that appends error-level events and above to a file,
/// flushing after every write.
///
/// The `logger` package's own [FileOutput] buffers on an [IOSink] and only
/// flushes in [destroy()]. On a kiosk terminal the process is routinely
/// stopped with SIGTERM/SIGKILL rather than a graceful shutdown — that
/// buffered output is lost, and the one file meant to survive a crash ends up
/// empty right when it matters. Opening the file in append mode and flushing
/// synchronously per line trades a bit of I/O throughput for every logged
/// error actually landing on disk.
class ErrorFileOutput extends LogOutput {
  final File file;

  ErrorFileOutput({required this.file});

  @override
  void output(OutputEvent event) {
    if (event.level < Level.error) return;
    final sink = file.openSync(mode: FileMode.writeOnlyAppend);
    try {
      for (final line in event.lines) {
        sink.writeStringSync('$line\n');
      }
      sink.flushSync();
    } finally {
      sink.closeSync();
    }
  }
}
