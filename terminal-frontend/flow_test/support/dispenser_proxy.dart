import 'dart:async';
import 'dart:io';

/// One request as the proxy saw it.
class ProxiedRequest {
  ProxiedRequest(this.method, this.path, this.startedAt);

  final String method;
  final String path;
  final DateTime startedAt;
  DateTime? finishedAt;

  bool get isPoll => method == 'GET' && path.startsWith('/dispense/');
  bool get isDispense => method == 'POST' && path == '/dispense';

  @override
  String toString() => '$method $path';
}

/// A reverse proxy in front of the mock, so the suite can *observe* the wire
/// and *break* it.
///
/// Two things it answers that no assertion on the database can:
///
/// * **How many requests the terminal had in flight at once.** An ESP8266 has a
///   handful of TCP slots; the dialog's `Timer.periodic` can stack polls on top
///   of each other when one is slow (finding 7, #946). [maxInFlight] is
///   asserted after every scenario.
/// * **What happens when a response is lost but the request arrived.** The
///   dispenser starts dispensing and the terminal sees a timeout
///   (dgloeckner/remote-token-dispenser#2). [dropNextDispenseResponse] does
///   exactly that: the POST reaches the mock, the answer never comes back.
class DispenserProxy {
  DispenserProxy._(this._server, this._target);

  final HttpServer _server;
  final Uri _target;
  final HttpClient _client = HttpClient();
  final List<ProxiedRequest> _requests = <ProxiedRequest>[];

  int _inFlight = 0;
  int _maxInFlight = 0;

  /// The next `POST /dispense` reaches the mock, but its response is thrown
  /// away and the connection closed.
  bool dropNextDispenseResponse = false;

  String get baseUrl => 'http://127.0.0.1:${_server.port}';

  /// The highest number of requests that were open at the same moment.
  int get maxInFlight => _maxInFlight;

  List<ProxiedRequest> get requests => List.unmodifiable(_requests);

  int get pollCount => _requests.where((r) => r.isPoll).length;

  int get dispenseCount => _requests.where((r) => r.isDispense).length;

  static Future<DispenserProxy> start(String targetBaseUrl) async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    final proxy = DispenserProxy._(server, Uri.parse(targetBaseUrl));
    unawaited(proxy._serve());
    return proxy;
  }

  Future<void> _serve() async {
    await for (final request in _server) {
      unawaited(_handle(request));
    }
  }

  Future<void> _handle(HttpRequest request) async {
    final record = ProxiedRequest(request.method, request.uri.path, DateTime.now());
    _requests.add(record);
    _inFlight++;
    if (_inFlight > _maxInFlight) _maxInFlight = _inFlight;

    try {
      final body = await _readBody(request);
      final drop = record.isDispense && dropNextDispenseResponse;
      if (drop) dropNextDispenseResponse = false;

      final upstream = await _client.openUrl(
          request.method, _target.replace(path: request.uri.path));
      request.headers.forEach((name, values) {
        if (name.toLowerCase() == 'host') return;
        for (final value in values) {
          upstream.headers.add(name, value);
        }
      });
      if (body.isNotEmpty) upstream.add(body);
      final response = await upstream.close();
      final responseBody = await _collect(response);

      if (drop) {
        // The request arrived and the dispenser is working on it; the answer
        // never reaches the terminal.
        final socket = await request.response.detachSocket(writeHeaders: false);
        socket.destroy();
        return;
      }

      request.response.statusCode = response.statusCode;
      response.headers.forEach((name, values) {
        if (name.toLowerCase() == 'content-length') return;
        if (name.toLowerCase() == 'transfer-encoding') return;
        for (final value in values) {
          request.response.headers.add(name, value);
        }
      });
      request.response.add(responseBody);
      await request.response.close();
    } on SocketException {
      // The mock is down or frozen. Behave like an unreachable dispenser.
      try {
        final socket = await request.response.detachSocket(writeHeaders: false);
        socket.destroy();
      } catch (_) {
        // Client already gone.
      }
    } finally {
      record.finishedAt = DateTime.now();
      _inFlight--;
    }
  }

  static Future<List<int>> _readBody(HttpRequest request) async {
    final bytes = <int>[];
    await for (final chunk in request) {
      bytes.addAll(chunk);
    }
    return bytes;
  }

  static Future<List<int>> _collect(HttpClientResponse response) async {
    final bytes = <int>[];
    await for (final chunk in response) {
      bytes.addAll(chunk);
    }
    return bytes;
  }

  Future<void> dispose() async {
    _client.close(force: true);
    await _server.close(force: true);
  }
}
