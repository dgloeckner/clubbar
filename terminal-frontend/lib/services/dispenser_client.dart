import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:uuid/uuid.dart';

/// The protocol version this terminal speaks (`dispenser-protocol.md`,
/// Design Principle 2).
///
/// It is a **handshake, not a feature flag**: the terminal requires exactly
/// this version and refuses anything else outright. There are no protocol-1
/// devices left in the field, so a different number means something was not
/// deployed — a terminal that adapts to it would be the bug the handshake
/// exists to catch (#948).
const int dispenserProtocolVersion = 2;

/// Exception thrown by DispenserClient
class DispenserException implements Exception {
  final String message;

  DispenserException(this.message);

  @override
  String toString() => 'DispenserException: $message';
}

/// Exception thrown when dispenser is busy (HTTP 409)
class DispenserBusyException extends DispenserException {
  DispenserBusyException() : super('Dispenser is busy');
}

/// Exception thrown when transaction not found (HTTP 404)
class DispenserNotFoundException extends DispenserException {
  DispenserNotFoundException() : super('Transaction not found');
}

/// The device refused the request because it has a fault (HTTP 409
/// `{"error": "fault", …}`).
///
/// Retrying does not help and must never be automatic: somebody has to clear
/// the jam, refill if it is empty, and pull the plug for five seconds
/// (`dispenser-protocol.md`, Design Principle 6).
class DispenserFaultException extends DispenserException {
  final DispenserFault fault;

  /// The Azkoyen code 1-7 behind a [DispenserFault.hopperError], `0` otherwise.
  final int faultCode;

  DispenserFaultException(this.fault, {this.faultCode = 0})
      : super('Dispenser fault: ${fault.wire} (code $faultCode)');
}

/// The device answered, but not in the protocol this terminal speaks.
///
/// Distinct from every network failure on purpose: a schema mismatch used to
/// be swallowed by `fromJson`'s hard casts and reported as *offline*, which
/// sent whoever was called out to look for a network problem that did not
/// exist (#948).
class DispenserProtocolException extends DispenserException {
  /// What the device claimed, when it claimed anything at all.
  final int? reportedProtocol;

  DispenserProtocolException(super.message, {this.reportedProtocol});
}

/// The device-level condition, independent of any transaction
/// (`dispenser-protocol.md`, Design Principle 6).
///
/// It outlives the transaction it broke and is cleared **by a power cycle and
/// by nothing else** — there is no `/reset` route and no button on the kiosk.
enum DispenserFault {
  none('none'),

  /// The 5 s jam watchdog: nothing came out. Something is wedged, or the
  /// hopper is empty — the device cannot tell the two apart, and neither
  /// should the copy the member reads.
  jam('jam'),

  /// The hopper reported a fault on its error line; `faultCode` (1-7) names it.
  hopperError('hopper_error');

  const DispenserFault(this.wire);

  /// The value as it travels in JSON.
  final String wire;

  static DispenserFault? fromWire(Object? value) {
    for (final fault in DispenserFault.values) {
      if (fault.wire == value) return fault;
    }
    return null;
  }
}

/// What the device itself is doing — `state` in protocol 2, which replaced
/// the overlapping `status` + `dispenser` pair of protocol 1.
enum DispenserDeviceState {
  idle('idle'),
  dispensing('dispensing'),
  fault('fault');

  const DispenserDeviceState(this.wire);

  final String wire;

  static DispenserDeviceState? fromWire(Object? value) {
    for (final state in DispenserDeviceState.values) {
      if (state.wire == value) return state;
    }
    return null;
  }
}

/// Why the dispenser cannot serve a token right now.
///
/// One reason, always nameable: the kiosk says *which* of these it is, and so
/// does the admin panel (#953). "Unavailable" with no reason is what this
/// enum exists to end.
enum DispenserUnavailableReason {
  /// Nothing answered — network, power, wrong address.
  offline,

  /// Something answered, in a protocol this terminal does not speak.
  protocolMismatch,

  /// `fault: jam` — wedged, or empty.
  jam,

  /// `fault: hopper_error` — the hopper's own verdict, with its code.
  hopperError,

  /// `state: fault` without a fault naming it. Not producible by a conforming
  /// device; kept so an unavailable dispenser is never rendered as available.
  unspecifiedFault,
}

/// Result of a dispense operation
class DispenseResult {
  final String txId;
  final String state; // "dispensing", "done", "error"
  final int quantity;
  final int dispensed;

  /// Whether the device vouches for [dispensed] being the real count.
  ///
  /// A dispenser that lost its state across a reset knows it dispensed
  /// *something* and not how much; it says so with `count_reliable: false`,
  /// and the terminal then keeps the tracking row for reconciliation instead
  /// of treating the dispense as settled (#946).
  ///
  /// **Required on the wire** since protocol 2 (#948): a response without it
  /// is a protocol error, not a default. There is no reading of the missing
  /// field that is safe — "we do not know how many fell" read as "we counted
  /// zero" is the one that bills nothing while the tray is full.
  ///
  /// A [DispenseResult] the *terminal* fabricates (a polling timeout carries
  /// the last state the device reported) states it too, from what the device
  /// last said.
  final bool countReliable;

  /// Why a transaction ended in `error`: the Azkoyen code 1-7 with its name,
  /// or `0` with `JAM_TIMEOUT`, `RESET` — or `NONE` when nothing went wrong.
  /// Both are required on the wire; a reader must not have to tell "no error"
  /// from "no field".
  final int errorCode;
  final String errorType;

  DispenseResult({
    required this.txId,
    required this.state,
    required this.quantity,
    required this.dispensed,
    required this.countReliable,
    this.errorCode = 0,
    this.errorType = 'NONE',
  });

  factory DispenseResult.fromJson(Map<String, dynamic> json) {
    return DispenseResult(
      txId: _requireString(json, 'tx_id'),
      state: _requireString(json, 'state'),
      quantity: _requireInt(json, 'quantity'),
      dispensed: _requireInt(json, 'dispensed'),
      countReliable: _requireBool(json, 'count_reliable'),
      errorCode: _requireInt(json, 'error_code'),
      errorType: _requireString(json, 'error_type'),
    );
  }
}

/// WiFi connection information
class WifiInfo {
  final int rssi; // signal strength in dBm
  final String ip;
  final String ssid;

  WifiInfo({
    required this.rssi,
    required this.ip,
    required this.ssid,
  });

  factory WifiInfo.fromJson(Map<String, dynamic> json) {
    return WifiInfo(
      rssi: _requireInt(json, 'rssi'),
      ip: _requireString(json, 'ip'),
      ssid: _requireString(json, 'ssid'),
    );
  }
}

/// One decoded hopper error out of `error_history`.
///
/// No `cleared` flag since protocol 2: an error raises a fault, a fault ends
/// with a power cycle, and the "self-healing" the flag described has no case
/// left to heal.
class DispenserError {
  final int code;
  final String type;
  final int timestamp; // seconds since boot

  DispenserError({
    required this.code,
    required this.type,
    required this.timestamp,
  });

  factory DispenserError.fromJson(Map<String, dynamic> json) {
    return DispenserError(
      code: _requireInt(json, 'code'),
      type: _requireString(json, 'type'),
      timestamp: _requireInt(json, 'timestamp'),
    );
  }
}

/// How this health report was obtained.
enum DispenserContact {
  /// A protocol-2 `/health` document the terminal parsed.
  reported,

  /// Nothing answered.
  unreachable,

  /// Something answered, and it was not this protocol.
  protocolMismatch,
}

/// Health status of the dispenser — protocol 2 only.
///
/// The protocol-1 reading (`status`, `dispenser`, the `error` block) is gone
/// rather than kept as a fallback: no device speaks it, and a terminal that
/// quietly accepted both would report a mismatch as a working machine.
class DispenserHealth {
  /// How the terminal came by this report.
  final DispenserContact contact;

  /// What the device claimed as its protocol version — `null` when it never
  /// answered or never said.
  final int? protocol;

  /// The device's own state, or `null` when it did not report one.
  final DispenserDeviceState? state;

  /// The device-level fault. `none` when the machine is fine; anything else
  /// means *a human has to go there*.
  final DispenserFault fault;

  /// The Azkoyen code behind a [DispenserFault.hopperError], `0` otherwise.
  final int faultCode;

  final int totalDispenses;
  final int successful;
  final int jams;
  final double successRate;

  final int? uptime; // seconds
  final String? firmware;
  final WifiInfo? wifi;
  final int? failures;
  final int? partial;
  final int? crashes;
  final int? requestedTokens;
  final int? dispensedTokens;

  /// Tokens that left the hopper past the requested quantity (#5). They are
  /// billed, so they are counted.
  final int? overrunTokens;

  /// Falling edges on the coin line rejected as noise.
  final int? filteredPulses;

  final List<DispenserError>? errorHistory;

  DispenserHealth({
    this.contact = DispenserContact.reported,
    this.protocol,
    this.state,
    this.fault = DispenserFault.none,
    this.faultCode = 0,
    required this.totalDispenses,
    required this.successful,
    required this.jams,
    required this.successRate,
    this.uptime,
    this.firmware,
    this.wifi,
    this.failures,
    this.partial,
    this.crashes,
    this.requestedTokens,
    this.dispensedTokens,
    this.overrunTokens,
    this.filteredPulses,
    this.errorHistory,
  });

  /// Parses a protocol-2 `/health` document.
  ///
  /// Throws [DispenserProtocolException] for a wrong `protocol`, a missing
  /// required field or a value outside its enum. Strictness is the feature:
  /// the previous version hard-cast every field, so a schema change surfaced
  /// as a `TypeError` that the health service turned into *offline* (#948).
  factory DispenserHealth.fromJson(Map<String, dynamic> json) {
    final protocol = json['protocol'];
    if (protocol is! int) {
      throw DispenserProtocolException(
          'health document has no protocol version');
    }
    if (protocol != dispenserProtocolVersion) {
      throw DispenserProtocolException(
        'dispenser speaks protocol $protocol, this terminal speaks '
        '$dispenserProtocolVersion',
        reportedProtocol: protocol,
      );
    }

    final state = DispenserDeviceState.fromWire(json['state']);
    if (state == null) {
      throw DispenserProtocolException(
          'health document has no usable state: ${json['state']}',
          reportedProtocol: protocol);
    }

    final fault = DispenserFault.fromWire(json['fault']);
    if (fault == null) {
      throw DispenserProtocolException(
          'health document has no usable fault: ${json['fault']}',
          reportedProtocol: protocol);
    }

    final faultCode = json['fault_code'];
    if (faultCode is! int) {
      throw DispenserProtocolException('health document has no fault_code',
          reportedProtocol: protocol);
    }

    final metrics = json['metrics'];
    if (metrics is! Map<String, dynamic>) {
      throw DispenserProtocolException('health document has no metrics',
          reportedProtocol: protocol);
    }

    final totalDispenses = _requireInt(metrics, 'total_dispenses');
    final successful = _requireInt(metrics, 'successful');

    final successRate =
        totalDispenses > 0 ? (successful / totalDispenses) * 100 : 0.0;

    WifiInfo? wifi;
    final rawWifi = json['wifi'];
    if (rawWifi != null) {
      if (rawWifi is! Map<String, dynamic>) {
        throw DispenserProtocolException('wifi is not an object',
            reportedProtocol: protocol);
      }
      wifi = WifiInfo.fromJson(rawWifi);
    }

    List<DispenserError>? errorHistory;
    final rawHistory = json['error_history'];
    if (rawHistory != null) {
      if (rawHistory is! List) {
        throw DispenserProtocolException('error_history is not a list',
            reportedProtocol: protocol);
      }
      errorHistory = rawHistory.map((e) {
        if (e is! Map<String, dynamic>) {
          throw DispenserProtocolException('error_history entry is not an object',
              reportedProtocol: protocol);
        }
        return DispenserError.fromJson(e);
      }).toList();
    }

    return DispenserHealth(
      protocol: protocol,
      state: state,
      fault: fault,
      faultCode: faultCode,
      totalDispenses: totalDispenses,
      successful: successful,
      jams: _requireInt(metrics, 'jams'),
      successRate: successRate,
      uptime: _optionalInt(json, 'uptime'),
      firmware: json['firmware'] as String?,
      wifi: wifi,
      failures: _optionalInt(metrics, 'failures'),
      partial: _optionalInt(metrics, 'partial'),
      crashes: _optionalInt(metrics, 'crashes'),
      requestedTokens: _optionalInt(metrics, 'requested_tokens'),
      dispensedTokens: _optionalInt(metrics, 'dispensed_tokens'),
      overrunTokens: _optionalInt(metrics, 'overrun_tokens'),
      filteredPulses: _optionalInt(metrics, 'filtered_pulses'),
      errorHistory: errorHistory,
    );
  }

  /// Why the dispenser cannot serve a token right now, or `null` when it can.
  ///
  /// Availability is `state != fault`; "needs a human" is `fault != none`,
  /// and the errand is named by [fault] and [faultCode]
  /// (`dispenser-protocol.md`, *Usage*). A busy dispenser (`dispensing`) is
  /// **not** unavailable: it is mid-checkout for someone else and will be
  /// free again in seconds.
  DispenserUnavailableReason? get unavailableReason {
    switch (contact) {
      case DispenserContact.unreachable:
        return DispenserUnavailableReason.offline;
      case DispenserContact.protocolMismatch:
        return DispenserUnavailableReason.protocolMismatch;
      case DispenserContact.reported:
        break;
    }

    switch (fault) {
      case DispenserFault.jam:
        return DispenserUnavailableReason.jam;
      case DispenserFault.hopperError:
        return DispenserUnavailableReason.hopperError;
      case DispenserFault.none:
        return state == DispenserDeviceState.fault
            ? DispenserUnavailableReason.unspecifiedFault
            : null;
    }
  }

  /// Whether this health report means the dispenser cannot serve a token
  /// right now.
  bool get isUnavailable => unavailableReason != null;

  /// Whether a human has to go to the machine — a jam or a hopper error.
  /// A mismatch and an outage are somebody's errand too, but not that one.
  bool get needsAttendance => fault != DispenserFault.none;

  /// Nothing answered.
  factory DispenserHealth.offline() {
    return DispenserHealth(
      contact: DispenserContact.unreachable,
      totalDispenses: 0,
      successful: 0,
      jams: 0,
      successRate: 0.0,
    );
  }

  /// Something answered, in a protocol this terminal does not speak — or in
  /// no protocol at all. [reportedProtocol] is what it claimed, when it
  /// claimed anything.
  factory DispenserHealth.protocolMismatch({int? reportedProtocol}) {
    return DispenserHealth(
      contact: DispenserContact.protocolMismatch,
      protocol: reportedProtocol,
      totalDispenses: 0,
      successful: 0,
      jams: 0,
      successRate: 0.0,
    );
  }
}

/// Reads a required field, or says which one the document is missing.
///
/// Every protocol-2 field the terminal reads goes through one of these: a
/// hard cast throws a `TypeError` that names a Dart type, and the layer above
/// cannot tell that apart from a socket that closed (#948).
String _requireString(Map<String, dynamic> json, String field) {
  final value = json[field];
  if (value is! String) {
    throw DispenserProtocolException('field "$field" is missing or not a text');
  }
  return value;
}

int _requireInt(Map<String, dynamic> json, String field) {
  final value = json[field];
  if (value is! int) {
    throw DispenserProtocolException(
        'field "$field" is missing or not a number');
  }
  return value;
}

bool _requireBool(Map<String, dynamic> json, String field) {
  final value = json[field];
  if (value is! bool) {
    throw DispenserProtocolException(
        'field "$field" is missing or not a yes/no');
  }
  return value;
}

int? _optionalInt(Map<String, dynamic> json, String field) {
  final value = json[field];
  return value is int ? value : null;
}

/// HTTP client for ESP8266 token dispenser API
class DispenserClient {
  final String baseUrl;
  final String apiKey;
  final http.Client _httpClient;
  final int timeoutMs;
  final Uuid _uuid = const Uuid();

  DispenserClient({
    required this.baseUrl,
    required this.apiKey,
    http.Client? httpClient,
    this.timeoutMs = 3000,
  }) : _httpClient = httpClient ?? http.Client();

  /// Generate unique transaction ID (16 hex characters)
  String generateTxId() {
    // Generate UUID v4, remove hyphens, take first 16 chars
    final uuid = _uuid.v4().replaceAll('-', '');
    return uuid.substring(0, 16);
  }

  /// Starts a token dispense operation.
  ///
  /// Sends a POST request to the dispenser to begin dispensing [quantity] tokens.
  /// The operation is tracked using the provided [txId].
  ///
  /// Returns a [DispenseResult] with the current state of the operation.
  ///
  /// Throws:
  /// - [DispenserBusyException] if the dispenser is already processing another request.
  /// - [DispenserException] for other HTTP errors or network failures.
  Future<DispenseResult> dispenseTokens({
    required String txId,
    required int quantity,
  }) async {
    final uri = Uri.parse('$baseUrl/dispense');
    final headers = {
      'Content-Type': 'application/json',
      'X-API-Key': apiKey,
    };
    final body = jsonEncode({
      'tx_id': txId,
      'quantity': quantity,
    });

    try {
      final response = await _httpClient
          .post(uri, headers: headers, body: body)
          .timeout(Duration(milliseconds: timeoutMs));

      if (response.statusCode == 409) {
        throw _conflict(response.body);
      }

      if (response.statusCode != 200) {
        throw DispenserException(
            'HTTP ${response.statusCode}: ${response.body}');
      }

      return DispenseResult.fromJson(_document(response.body));
    } on DispenserException {
      rethrow;
    } catch (e) {
      throw DispenserException('Request failed: $e');
    }
  }

  /// Polls the status of an ongoing dispense operation.
  ///
  /// Sends a GET request to retrieve the current state of the dispense
  /// operation identified by [txId].
  ///
  /// Returns a [DispenseResult] with the current state.
  ///
  /// Throws:
  /// - [DispenserNotFoundException] if the transaction ID is not found.
  /// - [DispenserException] for other HTTP errors or network failures.
  Future<DispenseResult> getStatus(String txId) async {
    final uri = Uri.parse('$baseUrl/dispense/$txId');
    final headers = {
      'X-API-Key': apiKey,
    };

    try {
      final response = await _httpClient
          .get(uri, headers: headers)
          .timeout(Duration(milliseconds: timeoutMs));

      if (response.statusCode == 404) {
        throw DispenserNotFoundException();
      }

      if (response.statusCode != 200) {
        throw DispenserException(
            'HTTP ${response.statusCode}: ${response.body}');
      }

      return DispenseResult.fromJson(_document(response.body));
    } on DispenserException {
      rethrow;
    } catch (e) {
      throw DispenserException('Request failed: $e');
    }
  }

  /// Retrieves the health status and metrics of the dispenser.
  ///
  /// Sends a GET request to the /health endpoint to check if the dispenser
  /// is operational and retrieve usage statistics.
  ///
  /// Returns a [DispenserHealth] with status and metrics.
  ///
  /// Throws:
  /// - [DispenserException] for HTTP errors or network failures.
  Future<DispenserHealth> getHealth() async {
    final uri = Uri.parse('$baseUrl/health');
    final headers = {
      'X-API-Key': apiKey,
    };

    try {
      final response = await _httpClient
          .get(uri, headers: headers)
          .timeout(Duration(milliseconds: timeoutMs));

      if (response.statusCode != 200) {
        throw DispenserException(
            'HTTP ${response.statusCode}: ${response.body}');
      }

      return DispenserHealth.fromJson(_document(response.body));
    } on DispenserException {
      rethrow;
    } catch (e) {
      throw DispenserException('Request failed: $e');
    }
  }

  /// A 409 is two different answers: another transaction is running ("busy",
  /// wait a moment) or the device has a fault ("fault", somebody has to walk
  /// over). Telling them apart is the whole point of #948 — they used to be
  /// the same exception.
  DispenserException _conflict(String body) {
    try {
      final json = jsonDecode(body);
      if (json is Map<String, dynamic> && json['error'] == 'fault') {
        final fault = DispenserFault.fromWire(json['fault']) ??
            DispenserFault.jam;
        final code = json['fault_code'];
        return DispenserFaultException(fault,
            faultCode: code is int ? code : 0);
      }
    } catch (_) {
      // An unparseable 409 is still a 409: the dispenser is busy, which is
      // the reading that costs nothing if it is wrong.
    }
    return DispenserBusyException();
  }

  /// The response body as a JSON object, or a protocol error naming it.
  ///
  /// A cast of `jsonDecode(...)` would throw a `TypeError` here, and the
  /// layer above cannot tell that apart from a dropped connection.
  Map<String, dynamic> _document(String body) {
    Object? json;
    try {
      json = jsonDecode(body);
    } catch (e) {
      throw DispenserProtocolException('response is not JSON');
    }
    if (json is! Map<String, dynamic>) {
      throw DispenserProtocolException('response is not a JSON object');
    }
    return json;
  }
}
