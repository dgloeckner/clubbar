import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:uuid/uuid.dart';

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

/// Result of a dispense operation
class DispenseResult {
  final String txId;
  final String state; // "dispensing", "done", "error"
  final int quantity;
  final int dispensed;

  DispenseResult({
    required this.txId,
    required this.state,
    required this.quantity,
    required this.dispensed,
  });

  factory DispenseResult.fromJson(Map<String, dynamic> json) {
    return DispenseResult(
      txId: json['tx_id'] as String,
      state: json['state'] as String,
      quantity: json['quantity'] as int,
      dispensed: json['dispensed'] as int,
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
      rssi: json['rssi'] as int,
      ip: json['ip'] as String,
      ssid: json['ssid'] as String,
    );
  }
}

/// Dispenser error history entry
class DispenserError {
  final int code;
  final String type;
  final int timestamp; // milliseconds
  final bool cleared;

  DispenserError({
    required this.code,
    required this.type,
    required this.timestamp,
    required this.cleared,
  });

  factory DispenserError.fromJson(Map<String, dynamic> json) {
    return DispenserError(
      code: json['code'] as int,
      type: json['type'] as String,
      timestamp: json['timestamp'] as int,
      cleared: json['cleared'] as bool,
    );
  }
}

/// Health status of the dispenser
class DispenserHealth {
  final String status; // "ok", "degraded", "error"
  final String dispenser; // "idle", "dispensing", "error", "offline"
  final int totalDispenses;
  final int successful;
  final int jams;
  final double successRate;

  // New fields for dashboard
  final int? uptime; // seconds
  final String? firmware;
  final WifiInfo? wifi;
  final int? failures;
  final int? partial;
  final int? crashes;
  final int? requestedTokens;
  final int? dispensedTokens;
  final List<DispenserError>? errorHistory;

  DispenserHealth({
    required this.status,
    required this.dispenser,
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
    this.errorHistory,
  });

  factory DispenserHealth.fromJson(Map<String, dynamic> json) {
    final metrics = json['metrics'] as Map<String, dynamic>;
    final totalDispenses = metrics['total_dispenses'] as int;
    final successful = metrics['successful'] as int;

    // Calculate success rate, handle division by zero
    final successRate = totalDispenses > 0
        ? (successful / totalDispenses) * 100
        : 0.0;

    // Parse optional fields
    WifiInfo? wifi;
    if (json.containsKey('wifi') && json['wifi'] != null) {
      wifi = WifiInfo.fromJson(json['wifi'] as Map<String, dynamic>);
    }

    List<DispenserError>? errorHistory;
    if (json.containsKey('error_history') && json['error_history'] != null) {
      errorHistory = (json['error_history'] as List)
          .map((e) => DispenserError.fromJson(e as Map<String, dynamic>))
          .toList();
    }

    return DispenserHealth(
      status: json['status'] as String,
      dispenser: json['dispenser'] as String,
      totalDispenses: totalDispenses,
      successful: successful,
      jams: metrics['jams'] as int,
      successRate: successRate,
      uptime: json['uptime'] as int?,
      firmware: json['firmware'] as String?,
      wifi: wifi,
      failures: metrics['failures'] as int?,
      partial: metrics['partial'] as int?,
      crashes: metrics['crashes'] as int?,
      requestedTokens: metrics['requested_tokens'] as int?,
      dispensedTokens: metrics['dispensed_tokens'] as int?,
      errorHistory: errorHistory,
    );
  }

  /// Whether this health report means the dispenser cannot serve a token
  /// right now — unreachable, or reporting a fault it has not cleared.
  ///
  /// A busy dispenser ("dispensing") is *not* unavailable: it is mid-checkout
  /// for someone else and will be free again in seconds.
  bool get isUnavailable =>
      dispenser == 'offline' || dispenser == 'error' || status == 'error';

  /// Create offline/unreachable health state
  factory DispenserHealth.offline() {
    return DispenserHealth(
      status: 'error',
      dispenser: 'offline',
      totalDispenses: 0,
      successful: 0,
      jams: 0,
      successRate: 0.0,
      uptime: null,
      firmware: null,
      wifi: null,
      failures: null,
      partial: null,
      errorHistory: null,
    );
  }
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
        throw DispenserBusyException();
      }

      if (response.statusCode != 200) {
        throw DispenserException(
            'HTTP ${response.statusCode}: ${response.body}');
      }

      final json = jsonDecode(response.body) as Map<String, dynamic>;
      return DispenseResult.fromJson(json);
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

      final json = jsonDecode(response.body) as Map<String, dynamic>;
      return DispenseResult.fromJson(json);
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

      final json = jsonDecode(response.body) as Map<String, dynamic>;
      return DispenserHealth.fromJson(json);
    } on DispenserException {
      rethrow;
    } catch (e) {
      throw DispenserException('Request failed: $e');
    }
  }
}
