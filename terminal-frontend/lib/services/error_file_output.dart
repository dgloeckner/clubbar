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

  /// Size at which the file gets trimmed back (issue #889 item 4).
  ///
  /// Nothing rotated or capped this file before: a kiosk that runs for
  /// months turns every silently-lost tap into a line here forever. 5 MiB is
  /// generous for a text log and small enough that a Raspberry Pi's SD card
  /// never notices it.
  final int maxBytes;

  ErrorFileOutput({required this.file, this.maxBytes = 5 * 1024 * 1024});

  @override
  void output(OutputEvent event) {
    if (event.level < Level.error) return;
    _capSize();
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

  /// Once the file has grown past [maxBytes], drop its oldest half.
  ///
  /// Trimmed rather than rotated to a `.1` file: a second unbounded file
  /// would just move the same problem sideways. The cut lands on the next
  /// line boundary after the halfway point, so what remains is always whole
  /// lines, never a truncated one at the top.
  void _capSize() {
    if (!file.existsSync()) return;
    final length = file.lengthSync();
    if (length <= maxBytes) return;

    final raf = file.openSync(mode: FileMode.read);
    try {
      final keepFrom = length - (maxBytes ~/ 2);
      raf.setPositionSync(keepFrom);
      final tail = raf.readSync(length - keepFrom);
      final newlineIndex = tail.indexOf(0x0A);
      final trimmed =
          newlineIndex == -1 ? tail : tail.sublist(newlineIndex + 1);
      file.writeAsBytesSync(trimmed, mode: FileMode.write);
    } finally {
      raf.closeSync();
    }
  }
}
