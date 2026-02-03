import 'dart:io';

import 'package:logger/logger.dart';

/// Custom [LogOutput] that wraps [FileOutput] and only forwards
/// error-level events and above to the file.
///
/// This allows [MultiOutput] to send all events to all outputs
/// while this class filters so only errors reach the log file.
class ErrorFileOutput extends LogOutput {
  final FileOutput _fileOutput;

  ErrorFileOutput({required File file})
      : _fileOutput = FileOutput(file: file);

  @override
  Future<void> init() async {
    await _fileOutput.init();
  }

  @override
  void output(OutputEvent event) {
    if (event.level >= Level.error) {
      _fileOutput.output(event);
    }
  }

  @override
  Future<void> destroy() async {
    await _fileOutput.destroy();
  }
}
