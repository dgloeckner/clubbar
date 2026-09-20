import 'dart:convert';
import 'dart:io';

/// The Go mock from `dgloeckner/remote-token-dispenser`, run as a real process
/// on a real port.
///
/// This is the L3 layer of the epic's test plan (#944): the terminal talks HTTP
/// to a stand-in for the ESP8266 instead of to a hand-written `MockClient`. The
/// mock is a *separate repository*; CI checks it out at a pinned commit and
/// builds it, and `scripts/flow-test.sh` does the same locally. Nothing in this
/// repository builds Go by itself — the binary is handed in:
///
/// * `CLUBBAR_DISPENSER_MOCK` — path to an already built binary, or
/// * `CLUBBAR_DISPENSER_MOCK_SRC` — path to the `dispenser-mock/` source
///   directory, which is built once per test run into a temporary directory.
///
/// Neither set is a hard failure, not a skip: a flow suite that quietly passes
/// without ever reaching the mock is worth less than no suite at all.
class MockDispenser {
  MockDispenser._(this._binary, this.apiKey);

  final String _binary;
  final String apiKey;

  Process? _process;
  int? _port;
  final List<String> _log = <String>[];

  /// Scenario selection in the mock is by **quantity**, not by a flag
  /// (`dispenser-mock/scenarios.go`). These names exist so a test reads as the
  /// scenario it means rather than as a magic number.
  static const int qtySuccess = 3;
  static const int qtySuccessLong = 20;
  static const int qtyTimeoutPartial = 4; // 2 of 4, then 5 s stall, then error
  static const int qtyCrashAfterFirst = 5; // 1 token, connection hijacked
  static const int qtyPartialDispense = 6; // 4 of 6, then error
  static const int qtyLoadDelay = 7; // 2,5 s before the first token
  static const int qtySlowDispense = 15; // 500 ms per token

  /// Where the mock is listening. Only valid while it is running.
  String get baseUrl => 'http://127.0.0.1:$_port';

  /// The port it was started on — kept across a [kill] so [start] can come back
  /// on the same address, which is what "the dispenser was unplugged and
  /// plugged back in" means to the terminal.
  int get port => _port!;

  bool get isRunning => _process != null;

  /// Everything the mock wrote to stdout/stderr, for a failing test's message.
  List<String> get log => List.unmodifiable(_log);

  /// Resolves the binary (building it from source when asked to) and starts it.
  static Future<MockDispenser> start({String apiKey = 'flow-test-key'}) async {
    final mock = MockDispenser._(await _resolveBinary(), apiKey);
    await mock.launch();
    return mock;
  }

  static String? _builtBinary;

  static Future<String> _resolveBinary() async {
    final prebuilt = Platform.environment['CLUBBAR_DISPENSER_MOCK'];
    if (prebuilt != null && prebuilt.isNotEmpty) {
      if (!File(prebuilt).existsSync()) {
        throw StateError(
            'CLUBBAR_DISPENSER_MOCK points at $prebuilt, which does not exist.');
      }
      return prebuilt;
    }

    if (_builtBinary != null) return _builtBinary!;

    final src = Platform.environment['CLUBBAR_DISPENSER_MOCK_SRC'];
    if (src == null || src.isEmpty) {
      throw StateError(
        'The dispenser flow suite needs the Go mock from '
        'dgloeckner/remote-token-dispenser.\n'
        'Run it with scripts/flow-test.sh, or set CLUBBAR_DISPENSER_MOCK to a '
        'built binary, or CLUBBAR_DISPENSER_MOCK_SRC to the dispenser-mock/ '
        'source directory.',
      );
    }

    final out = '${Directory.systemTemp.createTempSync('dispenser-mock').path}'
        '/dispenser-mock';
    final build = await Process.run('go', ['build', '-o', out, '.'],
        workingDirectory: src);
    if (build.exitCode != 0) {
      throw StateError('go build in $src failed:\n${build.stderr}');
    }
    return _builtBinary = out;
  }

  /// Starts the process, on a fresh port the first time and on the previous one
  /// afterwards, and waits until `/health` answers.
  Future<void> launch() async {
    if (_process != null) return;

    _port ??= await _freePort();
    final process = await Process.start(_binary, [
      '--bind',
      '127.0.0.1:$_port',
      '--api-key',
      apiKey,
    ]);
    _process = process;
    process.stdout.transform(utf8.decoder).transform(const LineSplitter()).listen(_log.add);
    process.stderr.transform(utf8.decoder).transform(const LineSplitter()).listen(_log.add);

    await _waitForHealth();
  }

  /// Freezes the process without killing it: the socket stays open and every
  /// request hangs. This is the WLAN dropout of Cycle C step 2, compressed —
  /// the terminal cannot tell it apart from a dispenser that stopped answering.
  void pause() => _signal(ProcessSignal.sigstop);

  void resume() => _signal(ProcessSignal.sigcont);

  void _signal(ProcessSignal signal) {
    final process = _process;
    if (process == null) throw StateError('Mock is not running');
    process.kill(signal);
  }

  /// Takes the dispenser off the network entirely. The port is remembered, so
  /// [launch] brings it back at the same address — with no memory of what it
  /// dispensed, exactly like the ESP8266 at this protocol version.
  Future<void> kill() async {
    final process = _process;
    if (process == null) return;
    _process = null;
    // A paused process ignores SIGTERM until it is resumed.
    process.kill(ProcessSignal.sigcont);
    process.kill(ProcessSignal.sigkill);
    await process.exitCode;
  }

  Future<void> dispose() => kill();

  static Future<int> _freePort() async {
    final socket = await ServerSocket.bind(InternetAddress.loopbackIPv4, 0);
    final port = socket.port;
    await socket.close();
    return port;
  }

  Future<void> _waitForHealth() async {
    final client = HttpClient();
    final deadline = DateTime.now().add(const Duration(seconds: 10));
    try {
      while (DateTime.now().isBefore(deadline)) {
        try {
          final request = await client.getUrl(Uri.parse('$baseUrl/health'));
          final response = await request.close();
          await response.drain<void>();
          if (response.statusCode == 200) return;
        } on SocketException {
          // Not up yet.
        }
        await Future<void>.delayed(const Duration(milliseconds: 50));
      }
      throw StateError('Mock dispenser did not answer /health within 10 s.\n'
          '${_log.join('\n')}');
    } finally {
      client.close(force: true);
    }
  }
}
