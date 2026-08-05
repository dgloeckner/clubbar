import 'package:logger/logger.dart';

/// Process-wide logger for code that has no constructor-injected one.
///
/// [ErrorSignal] uses it to record the raw exception behind a
/// [TerminalErrorKey], which is the only place that text may go. `main()` swaps
/// in the configured logger (console + error file) during startup; until then a
/// plain console logger applies, which is also what tests get.
class AppLog {
  static Logger _logger =
      Logger(printer: SimplePrinter(printTime: true, colors: false));

  static Logger get instance => _logger;

  static void configure(Logger logger) => _logger = logger;
}
