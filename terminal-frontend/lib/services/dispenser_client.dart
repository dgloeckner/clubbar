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

/// Health status of the dispenser
class DispenserHealth {
  final String status; // "ok", "degraded", "error"
  final String dispenser; // "idle", "dispensing", "error", "offline"
  final int totalDispenses;
  final int successful;
  final int jams;
  final double successRate;

  DispenserHealth({
    required this.status,
    required this.dispenser,
    required this.totalDispenses,
    required this.successful,
    required this.jams,
    required this.successRate,
  });

  factory DispenserHealth.fromJson(Map<String, dynamic> json) {
    final metrics = json['metrics'] as Map<String, dynamic>;
    final totalDispenses = metrics['total_dispenses'] as int;
    final successful = metrics['successful'] as int;

    // Calculate success rate, handle division by zero
    final successRate = totalDispenses > 0
        ? (successful / totalDispenses) * 100
        : 0.0;

    return DispenserHealth(
      status: json['status'] as String,
      dispenser: json['dispenser'] as String,
      totalDispenses: totalDispenses,
      successful: successful,
      jams: metrics['jams'] as int,
      successRate: successRate,
    );
  }

  /// Create offline/unreachable health state
  factory DispenserHealth.offline() {
    return DispenserHealth(
      status: 'error',
      dispenser: 'offline',
      totalDispenses: 0,
      successful: 0,
      jams: 0,
      successRate: 0.0,
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

  /// Generate unique transaction ID (8-16 hex characters)
  String generateTxId() {
    // Generate UUID v4, remove hyphens, take first 16 chars
    final uuid = _uuid.v4().replaceAll('-', '');
    return uuid.substring(0, 16);
  }

  /// Start token dispense operation
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

  /// Poll dispense status
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

  /// Get dispenser health and metrics
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
    } catch (e) {
      throw DispenserException('Request failed: $e');
    }
  }
}
