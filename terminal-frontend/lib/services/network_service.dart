import 'dart:convert';
import 'package:http/http.dart' as http;
import '../config/app_config.dart';
import '../models/sync_response.dart';
import '../models/transaction_sync_response.dart';

class NetworkService {
  String _baseUrl;
  String? _authToken;

  NetworkService({String baseUrl = AppConfig.apiBaseUrl}) : _baseUrl = baseUrl;

  /// Update the base URL (e.g. after setup)
  void setBaseUrl(String baseUrl) {
    _baseUrl = baseUrl;
  }

  /// Set authentication token
  void setAuthToken(String? token) {
    _authToken = token;
  }

  /// Get authentication token
  String? getAuthToken() {
    return _authToken;
  }

  /// Build HTTP headers with auth token if available
  Map<String, String> _buildHeaders() {
    final headers = <String, String>{
      'Content-Type': 'application/json',
    };

    if (_authToken != null) {
      headers['Authorization'] = 'Bearer $_authToken';
    }

    return headers;
  }

  /// Check if the backend is reachable via the health endpoint.
  /// Returns true on 2xx, false on any exception or non-2xx status.
  Future<bool> checkHealth() async {
    try {
      final uri = Uri.parse('$_baseUrl${AppConfig.healthEndpoint}');
      final response = await http
          .get(uri, headers: _buildHeaders())
          .timeout(AppConfig.healthCheckTimeout);
      return response.statusCode >= 200 && response.statusCode < 300;
    } catch (_) {
      return false;
    }
  }

  /// GET request
  Future<dynamic> get(String endpoint) async {
    try {
      final uri = Uri.parse('$_baseUrl$endpoint');
      final response = await http.get(uri, headers: _buildHeaders());
      return _handleResponse(response);
    } catch (e) {
      throw NetworkException('GET request failed: $e');
    }
  }

  /// POST request
  Future<dynamic> post(String endpoint, Map<String, dynamic> body) async {
    try {
      final uri = Uri.parse('$_baseUrl$endpoint');
      final response = await http.post(
        uri,
        headers: _buildHeaders(),
        body: jsonEncode(body),
      );
      return _handleResponse(response);
    } catch (e) {
      throw NetworkException('POST request failed: $e');
    }
  }

  /// PUT request
  Future<dynamic> put(String endpoint, Map<String, dynamic> body) async {
    try {
      final uri = Uri.parse('$_baseUrl$endpoint');
      final response = await http.put(
        uri,
        headers: _buildHeaders(),
        body: jsonEncode(body),
      );
      return _handleResponse(response);
    } catch (e) {
      throw NetworkException('PUT request failed: $e');
    }
  }

  /// PATCH request
  Future<dynamic> patch(String endpoint, Map<String, dynamic> body) async {
    try {
      final uri = Uri.parse('$_baseUrl$endpoint');
      final response = await http.patch(
        uri,
        headers: _buildHeaders(),
        body: jsonEncode(body),
      );
      return _handleResponse(response);
    } catch (e) {
      throw NetworkException('PATCH request failed: $e');
    }
  }

  /// DELETE request
  Future<dynamic> delete(String endpoint) async {
    try {
      final uri = Uri.parse('$_baseUrl$endpoint');
      final response = await http.delete(uri, headers: _buildHeaders());
      return _handleResponse(response);
    } catch (e) {
      throw NetworkException('DELETE request failed: $e');
    }
  }

  /// Handle HTTP response - throw on non-2xx status codes
  dynamic _handleResponse(http.Response response) {
    try {
      final decoded = jsonDecode(response.body);

      // Status code 2xx is success
      if (response.statusCode >= 200 && response.statusCode < 300) {
        return decoded;
      }

      // Status code 4xx or 5xx is error — include full response body for debugging
      final errorMessage = decoded['message'] ?? 'HTTP ${response.statusCode}';
      throw NetworkException(
        '$errorMessage | Body: ${response.body}',
        statusCode: response.statusCode,
      );
    } catch (e) {
      if (e is NetworkException) {
        rethrow;
      }
      throw NetworkException('Failed to parse response: $e');
    }
  }

  /// Sync members endpoint
  Future<MembersSyncResponse> syncMembers() async {
    try {
      final response = await get(AppConfig.syncEndpointMembers);
      return MembersSyncResponse.fromJson(response);
    } catch (e) {
      throw NetworkException('Sync members failed: $e');
    }
  }

  /// Sync categories endpoint
  Future<CategoriesSyncResponse> syncCategories() async {
    try {
      final response = await get(AppConfig.syncEndpointCategories);
      return CategoriesSyncResponse.fromJson(response);
    } catch (e) {
      throw NetworkException('Sync categories failed: $e');
    }
  }

  /// Sync products endpoint
  Future<ProductsSyncResponse> syncProducts() async {
    try {
      final response = await get(AppConfig.syncEndpointProducts);
      return ProductsSyncResponse.fromJson(response);
    } catch (e) {
      throw NetworkException('Sync products failed: $e');
    }
  }

  /// Sync transactions endpoint (POST batch upload)
  Future<TransactionSyncResponse> syncTransactions(
      List<Map<String, dynamic>> transactions) async {
    try {
      final response = await post(
        AppConfig.syncEndpointTransactions,
        {'transactions': transactions},
      );
      return TransactionSyncResponse.fromJson(response);
    } catch (e) {
      throw NetworkException('Sync transactions failed: $e');
    }
  }

  /// Clear auth token (for logout)
  void clearAuthToken() {
    _authToken = null;
  }
}

/// Network exception for API errors
class NetworkException implements Exception {
  final String message;
  final int? statusCode;

  NetworkException(this.message, {this.statusCode});

  @override
  String toString() => 'NetworkException: $message ${statusCode != null ? '(HTTP $statusCode)' : ''}';
}
