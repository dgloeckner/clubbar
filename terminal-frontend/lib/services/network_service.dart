import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:logger/logger.dart';
import '../config/app_config.dart';
import '../models/sync_response.dart';
import '../models/transaction_sync_response.dart';

/// Response wrapper that includes HTTP metadata
class HttpResponse<T> {
  final int statusCode;
  final T? data;
  final bool notModified;

  HttpResponse({
    required this.statusCode,
    this.data,
    this.notModified = false,
  });
}

class NetworkService {
  String _baseUrl;
  String? _authToken;
  final Logger _logger;

  NetworkService({required String baseUrl, Logger? logger})
      : _baseUrl = baseUrl,
        _logger = logger ?? Logger();

  String get baseUrl => _baseUrl;

  /// Update the base URL at runtime if needed
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

  /// Fetch the backend version from the health endpoint.
  /// Returns the version string or null on failure.
  Future<String?> fetchBackendVersion() async {
    try {
      final uri = Uri.parse('$_baseUrl${AppConfig.healthEndpoint}');
      final response = await http
          .get(uri, headers: _buildHeaders())
          .timeout(AppConfig.healthCheckTimeout);
      if (response.statusCode >= 200 && response.statusCode < 300) {
        final data = jsonDecode(response.body);
        return data['version'] as String?;
      }
      return null;
    } catch (_) {
      return null;
    }
  }

  /// GET request
  Future<dynamic> get(String endpoint) async {
    try {
      final uri = Uri.parse('$_baseUrl$endpoint');
      final response = await http.get(uri, headers: _buildHeaders());
      _logger.d('GET $endpoint -> HTTP ${response.statusCode}');
      return _handleResponse(response);
    } catch (e) {
      throw NetworkException('GET request failed: $e');
    }
  }

  /// GET request with full HTTP response info (for sync endpoints)
  Future<HttpResponse<dynamic>> getWithStatus(String endpoint, {Map<String, String>? extraHeaders}) async {
    try {
      final uri = Uri.parse('$_baseUrl$endpoint');
      final headers = _buildHeaders();
      if (extraHeaders != null) {
        headers.addAll(extraHeaders);
      }
      final response = await http.get(uri, headers: headers);
      _logger.i('GET $endpoint -> HTTP ${response.statusCode} (${response.contentLength ?? response.body.length} bytes)');

      // Handle 304 Not Modified
      if (response.statusCode == 304) {
        return HttpResponse(statusCode: 304, notModified: true);
      }

      return HttpResponse(
        statusCode: response.statusCode,
        data: _handleResponse(response),
      );
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

  /// Build endpoint URL with optional since parameter
  String _buildSyncEndpoint(String endpoint, {int? since}) {
    if (since != null && since > 0) {
      return '$endpoint?since=$since';
    }
    return endpoint;
  }

  /// Sync members endpoint
  /// [since] - Unix timestamp for delta sync (only items modified after this time)
  /// Returns null if server returns 304 Not Modified
  Future<MembersSyncResponse?> syncMembers({int? since, String? ifNoneMatch}) async {
    try {
      final headers = ifNoneMatch != null ? {'If-None-Match': ifNoneMatch} : null;
      final endpoint = _buildSyncEndpoint(AppConfig.syncEndpointMembers, since: since);
      final response = await getWithStatus(endpoint, extraHeaders: headers);

      if (response.notModified) {
        _logger.i('Members: 304 Not Modified');
        return null;
      }

      return MembersSyncResponse.fromJson(response.data);
    } catch (e) {
      throw NetworkException('Sync members failed: $e');
    }
  }

  /// Sync categories endpoint
  /// [since] - Unix timestamp for delta sync (only items modified after this time)
  /// Returns null if server returns 304 Not Modified
  Future<CategoriesSyncResponse?> syncCategories({int? since, String? ifNoneMatch}) async {
    try {
      final headers = ifNoneMatch != null ? {'If-None-Match': ifNoneMatch} : null;
      final endpoint = _buildSyncEndpoint(AppConfig.syncEndpointCategories, since: since);
      final response = await getWithStatus(endpoint, extraHeaders: headers);

      if (response.notModified) {
        _logger.i('Categories: 304 Not Modified');
        return null;
      }

      return CategoriesSyncResponse.fromJson(response.data);
    } catch (e) {
      throw NetworkException('Sync categories failed: $e');
    }
  }

  /// Sync products endpoint
  /// [since] - Unix timestamp for delta sync (only items modified after this time)
  /// Returns null if server returns 304 Not Modified
  Future<ProductsSyncResponse?> syncProducts({int? since, String? ifNoneMatch}) async {
    try {
      final headers = ifNoneMatch != null ? {'If-None-Match': ifNoneMatch} : null;
      final endpoint = _buildSyncEndpoint(AppConfig.syncEndpointProducts, since: since);
      final response = await getWithStatus(endpoint, extraHeaders: headers);

      if (response.notModified) {
        _logger.i('Products: 304 Not Modified');
        return null;
      }

      return ProductsSyncResponse.fromJson(response.data);
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
