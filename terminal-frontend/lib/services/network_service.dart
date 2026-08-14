import 'dart:async';
import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:logger/logger.dart';
import '../config/app_config.dart';
import '../generated/terminal.swagger.dart';
import 'token_interceptor.dart';

class NetworkService {
  String _baseUrl;
  final Logger _logger;
  final TokenInterceptor _tokenInterceptor;

  late Terminal _api;

  NetworkService({required String baseUrl, Logger? logger})
      : _baseUrl = baseUrl,
        _logger = logger ?? Logger(),
        _tokenInterceptor = TokenInterceptor() {
    _buildClient();
  }

  String get baseUrl => _baseUrl;

  /// Rebuild the Chopper Terminal service when the base URL changes.
  /// ChopperClient is immutable, so a new service is created on URL change.
  void _buildClient() {
    _api = Terminal.create(
      baseUrl: Uri.parse(_baseUrl),
      interceptors: [_tokenInterceptor],
    );
  }

  /// Update the base URL at runtime (rebuilds the ChopperClient).
  void setBaseUrl(String baseUrl) {
    _baseUrl = baseUrl;
    _buildClient();
  }

  /// Set authentication token.
  void setAuthToken(String? token) {
    _tokenInterceptor.token = token;
  }

  /// Get authentication token.
  String? getAuthToken() {
    return _tokenInterceptor.token;
  }

  /// Clear authentication token (for logout).
  void clearAuthToken() {
    _tokenInterceptor.token = null;
  }

  // ---------------------------------------------------------------------------
  // Health
  // ---------------------------------------------------------------------------

  /// Check if the backend is reachable via the health endpoint.
  /// Returns true on 2xx, false on any exception or non-2xx status.
  Future<bool> checkHealth() async {
    try {
      final response = await _api
          .healthGet()
          .timeout(AppConfig.healthCheckTimeout);
      return response.isSuccessful;
    } catch (_) {
      return false;
    }
  }

  /// Fetch the backend version from the health endpoint.
  ///
  /// The OAS spec does not include a `version` field in the health response, so
  /// this method is hand-written: it calls GET /health with a plain HTTP client
  /// and parses `version` from the raw JSON body.
  ///
  /// Returns the version string, or null on failure.
  Future<String?> fetchBackendVersion() async {
    try {
      final uri = Uri.parse('$_baseUrl${AppConfig.healthEndpoint}');
      final headers = <String, String>{'Content-Type': 'application/json'};
      final token = _tokenInterceptor.token;
      if (token != null) {
        headers['Authorization'] = 'Bearer $token';
      }
      final response = await http
          .get(uri, headers: headers)
          .timeout(AppConfig.healthCheckTimeout);
      if (response.statusCode >= 200 && response.statusCode < 300) {
        final data = jsonDecode(response.body) as Map<String, dynamic>;
        return data['version'] as String?;
      }
      return null;
    } catch (_) {
      return null;
    }
  }

  /// Fetch the deploying club's instance name from the health endpoint
  /// (ADR-0034).
  ///
  /// Like [fetchBackendVersion], `instance_name` predates the generated
  /// Chopper client, so this is hand-written: it calls GET /health with a
  /// plain HTTP client and parses `instance_name` from the raw JSON body.
  ///
  /// Returns the instance name, or null on failure (fail-soft: a terminal
  /// that cannot reach the backend simply keeps whatever name it already
  /// has, via ConfigService's displayName precedence).
  Future<String?> fetchInstanceName() async {
    try {
      final uri = Uri.parse('$_baseUrl${AppConfig.healthEndpoint}');
      final headers = <String, String>{'Content-Type': 'application/json'};
      final token = _tokenInterceptor.token;
      if (token != null) {
        headers['Authorization'] = 'Bearer $token';
      }
      final response = await http
          .get(uri, headers: headers)
          .timeout(AppConfig.healthCheckTimeout);
      if (response.statusCode >= 200 && response.statusCode < 300) {
        final data = jsonDecode(response.body) as Map<String, dynamic>;
        return data['instance_name'] as String?;
      }
      return null;
    } catch (_) {
      return null;
    }
  }

  /// Fetch the backend's stable instance identity from the health endpoint
  /// (ADR-0035), used to detect a pairing mismatch — this backend now has a
  /// different, discontinuous history than the one this terminal last synced
  /// with (see #380).
  ///
  /// Like [fetchInstanceName], `instance_id` predates the generated Chopper
  /// client, so this is hand-written the same way.
  ///
  /// Returns the instance id, or null on failure or before instance_config
  /// exists — fail-soft, same as [fetchInstanceName]: a terminal that cannot
  /// reach the backend has nothing new to compare against, so it is not a
  /// mismatch.
  Future<String?> fetchInstanceId() async {
    try {
      final uri = Uri.parse('$_baseUrl${AppConfig.healthEndpoint}');
      final headers = <String, String>{'Content-Type': 'application/json'};
      final token = _tokenInterceptor.token;
      if (token != null) {
        headers['Authorization'] = 'Bearer $token';
      }
      final response = await http
          .get(uri, headers: headers)
          .timeout(AppConfig.healthCheckTimeout);
      if (response.statusCode >= 200 && response.statusCode < 300) {
        final data = jsonDecode(response.body) as Map<String, dynamic>;
        return data['instance_id'] as String?;
      }
      return null;
    } catch (_) {
      return null;
    }
  }

  /// Confirm a pairing mismatch is safe to trust (ADR-0035) — staff at the
  /// bar decided this backend's history is genuine despite reporting a
  /// different instance_id than the one this terminal last synced with.
  ///
  /// Unlike [fetchInstanceId]/[fetchInstanceName] this is deliberately NOT
  /// fail-soft: it is a one-off staff action, not a background poll, and
  /// swallowing a failure into null would let the terminal locally clear a
  /// mismatch the backend never actually recorded the acknowledgement for.
  /// Callers must catch [NetworkException] and keep the terminal blocked.
  ///
  /// Returns the instance_id to store as newly paired.
  Future<String?> acknowledgePairing() async {
    final uri = Uri.parse('$_baseUrl/terminal/pairing/ack');
    final headers = <String, String>{'Content-Type': 'application/json'};
    final token = _tokenInterceptor.token;
    if (token != null) {
      headers['Authorization'] = 'Bearer $token';
    }

    try {
      final response = await http
          .post(uri, headers: headers)
          .timeout(AppConfig.healthCheckTimeout);

      if (response.statusCode < 200 || response.statusCode >= 300) {
        throw NetworkException(
          'Acknowledge pairing failed: HTTP ${response.statusCode}',
          statusCode: response.statusCode,
          // This one call goes through `package:http` rather than Chopper, so
          // the failed body is the response body itself — there is no `error`
          // slot to read it out of.
          errorCode: backendErrorCode(response.body),
        );
      }

      final data = jsonDecode(response.body) as Map<String, dynamic>;
      return data['instance_id'] as String?;
    } on NetworkException {
      rethrow;
    } catch (e) {
      throw NetworkException('Acknowledge pairing failed: $e');
    }
  }

  // ---------------------------------------------------------------------------
  // Delta sync — members
  // ---------------------------------------------------------------------------

  /// Sync members endpoint.
  ///
  /// [since] — Unix timestamp for delta sync (only items modified after this
  /// time). Pass null or 0 for a full sync.
  ///
  /// Returns null if the server returns 304 Not Modified.
  Future<MemberDeltaResponse?> syncMembers({int? since}) async {
    try {
      final response = await _api.syncMembersGet(since: since);
      _logger.i('GET /sync/members -> HTTP ${response.statusCode}');

      if (response.statusCode == 304) {
        _logger.i('Members: 304 Not Modified');
        return null;
      }

      if (!response.isSuccessful) {
        throw NetworkException(
          'Sync members failed: HTTP ${response.statusCode}',
          statusCode: response.statusCode,
          errorCode: backendErrorCode(response.error),
        );
      }

      return response.body;
    } catch (e) {
      if (e is NetworkException) rethrow;
      throw NetworkException('Sync members failed: $e');
    }
  }

  // ---------------------------------------------------------------------------
  // Delta sync — categories
  // ---------------------------------------------------------------------------

  /// Sync categories endpoint.
  ///
  /// [since] — Unix timestamp for delta sync. Pass null or 0 for a full sync.
  ///
  /// Returns null if the server returns 304 Not Modified.
  Future<CategoryDeltaResponse?> syncCategories({int? since}) async {
    try {
      final response = await _api.syncCategoriesGet(since: since);
      _logger.i('GET /sync/categories -> HTTP ${response.statusCode}');

      if (response.statusCode == 304) {
        _logger.i('Categories: 304 Not Modified');
        return null;
      }

      if (!response.isSuccessful) {
        throw NetworkException(
          'Sync categories failed: HTTP ${response.statusCode}',
          statusCode: response.statusCode,
          errorCode: backendErrorCode(response.error),
        );
      }

      return response.body;
    } catch (e) {
      if (e is NetworkException) rethrow;
      throw NetworkException('Sync categories failed: $e');
    }
  }

  // ---------------------------------------------------------------------------
  // Delta sync — products
  // ---------------------------------------------------------------------------

  /// Sync products endpoint.
  ///
  /// [since] — Unix timestamp for delta sync. Pass null or 0 for a full sync.
  ///
  /// Returns null if the server returns 304 Not Modified.
  Future<ProductDeltaResponse?> syncProducts({int? since}) async {
    try {
      final response = await _api.syncProductsGet(since: since);
      _logger.i('GET /sync/products -> HTTP ${response.statusCode}');

      if (response.statusCode == 304) {
        _logger.i('Products: 304 Not Modified');
        return null;
      }

      if (!response.isSuccessful) {
        throw NetworkException(
          'Sync products failed: HTTP ${response.statusCode}',
          statusCode: response.statusCode,
          errorCode: backendErrorCode(response.error),
        );
      }

      return response.body;
    } catch (e) {
      if (e is NetworkException) rethrow;
      throw NetworkException('Sync products failed: $e');
    }
  }

  // ---------------------------------------------------------------------------
  // Sync transactions (POST batch upload)
  // ---------------------------------------------------------------------------

  /// Sync transactions endpoint (POST batch upload).
  ///
  /// Accepts a list of raw transaction maps (as built by SyncService) and
  /// returns a [TransactionBatchResponse].
  ///
  /// **PHP compatibility**: PHP's `json_encode` returns `[]` for empty arrays
  /// and `{}` for objects. The `member_balances` field can arrive as either.
  /// The generated [TransactionBatchResponse.fromJson] cannot handle `[]`, so
  /// this method decodes the raw response body manually and applies the same
  /// defensive logic as the old `TransactionSyncResponse.fromJson`.
  ///
  /// [memberIds] names members whose balance should be reported back even
  /// though no uploaded transaction touches them (#191). It is what lets an
  /// empty batch still be a question: after a settlement there is nothing to
  /// upload, so without naming the member the response carries no balance and
  /// the terminal keeps showing the pre-settlement Deckel.
  Future<TransactionBatchResponse> syncTransactions(
    List<Map<String, dynamic>> transactions, {
    List<String> memberIds = const [],
  }) async {
    try {
      // Build the typed request body from the raw maps.
      final txList = transactions.map((t) {
        return Transaction(
          id: t['id'] as String,
          memberId: t['member_id'] as String,
          productId: t['product_id'] as String,
          amountCents: (t['amount_cents'] as num).toInt(),
          createdAt: DateTime.parse(t['created_at'] as String),
        );
      }).toList();

      final requestBody = TransactionBatchRequest(
        transactions: txList,
        memberIds: memberIds.isEmpty ? null : memberIds,
      );
      final response = await _api.syncTransactionsPost(body: requestBody);

      _logger.i('POST /sync/transactions -> HTTP ${response.statusCode}');

      if (!response.isSuccessful) {
        throw NetworkException(
          'Sync transactions failed: HTTP ${response.statusCode}',
          statusCode: response.statusCode,
          errorCode: backendErrorCode(response.error),
        );
      }

      // Decode raw body manually to apply PHP [] vs {} compatibility fix.
      // The generated fromJson will throw if member_balances is [] (empty array).
      final rawBody = response.bodyString;
      final json = jsonDecode(rawBody) as Map<String, dynamic>;

      // accepted_ids
      final rawAccepted = json['accepted_ids'] as List<dynamic>? ?? [];
      final acceptedIds = rawAccepted
          .where((id) => id != null)
          .map((id) => id.toString())
          .toList();

      // PHP json_encode returns [] for empty arrays and {} for objects.
      // Handle both Map and List (empty array) for member_balances.
      final balancesRaw = json['member_balances'];
      final Map<String, dynamic> memberBalances;
      if (balancesRaw is Map) {
        memberBalances = Map<String, dynamic>.from(balancesRaw);
      } else {
        memberBalances = {};
      }

      // PHP json_encode returns [] for empty arrays and {} for objects.
      // Handle both Map and List (empty array) for rejected.
      final rejectedRaw = json['rejected'];
      final TransactionBatchResponse$Rejected rejected;
      if (rejectedRaw is Map<String, dynamic>) {
        rejected = TransactionBatchResponse$Rejected.fromJson(rejectedRaw);
      } else {
        rejected = const TransactionBatchResponse$Rejected();
      }

      return TransactionBatchResponse(
        acceptedIds: acceptedIds,
        rejected: rejected,
        memberBalances: memberBalances,
      );
    } catch (e) {
      if (e is NetworkException) rethrow;
      throw NetworkException('Sync transactions failed: $e');
    }
  }

  // ---------------------------------------------------------------------------
  // Transaction history (on-demand)
  // ---------------------------------------------------------------------------

  /// Fetch recent transaction history for a member.
  ///
  /// Returns null on 304 Not Modified.
  /// Throws [NetworkException] on non-2xx responses.
  Future<TransactionHistoryResponse?> getTransactionHistory(
    String memberId, {
    int limit = 50,
  }) async {
    try {
      final response = await _api.terminalTransactionsMemberIdGet(
        memberId: memberId,
        limit: limit,
      );

      _logger.i('GET /transactions/$memberId -> HTTP ${response.statusCode}');

      if (response.statusCode == 304) {
        return null;
      }

      if (!response.isSuccessful) {
        throw NetworkException(
          'Get transaction history failed: HTTP ${response.statusCode}',
          statusCode: response.statusCode,
          errorCode: backendErrorCode(response.error),
        );
      }

      return response.body;
    } catch (e) {
      if (e is NetworkException) rethrow;
      throw NetworkException('Get transaction history failed: $e');
    }
  }

  // ---------------------------------------------------------------------------
  // Member language update (PATCH /sync/members/{memberId}/language)
  // ---------------------------------------------------------------------------

  /// Update a member's preferred language on the backend.
  ///
  /// Calls `PATCH /api/sync/members/{memberId}/language` via the generated
  /// Chopper service. Throws [NetworkException] on non-2xx responses.
  Future<void> updateMemberLanguage(String memberId, String language) async {
    try {
      final body = SyncMembersMemberIdLanguagePatch$RequestBody(
        preferredLanguage: language,
      );
      final response = await _api.syncMembersMemberIdLanguagePatch(
        memberId: memberId,
        body: body,
      );
      _logger.i('PATCH /sync/members/$memberId/language -> HTTP ${response.statusCode}');

      if (!response.isSuccessful) {
        throw NetworkException(
          'Update member language failed: HTTP ${response.statusCode}',
          statusCode: response.statusCode,
          errorCode: backendErrorCode(response.error),
        );
      }
    } catch (e) {
      if (e is NetworkException) rethrow;
      throw NetworkException('Update member language failed: $e');
    }
  }
}

/// Network exception for API errors.
class NetworkException implements Exception {
  final String message;
  final int? statusCode;

  /// The backend's machine-readable `error` field, when the failing response
  /// carried one — e.g. `terminal_token_expired` (#106, #395).
  ///
  /// The status code alone cannot carry this: every terminal auth failure is a
  /// 401, and the terminal has to tell "this credential aged out, an admin must
  /// rotate it" apart from "this token was never valid", because only one of
  /// them is something staff at the bar can be told to wait out.
  final String? errorCode;

  NetworkException(this.message, {this.statusCode, this.errorCode});

  @override
  String toString() =>
      'NetworkException: $message'
      '${statusCode != null ? ' (HTTP $statusCode)' : ''}'
      '${errorCode != null ? ' [$errorCode]' : ''}';
}

/// The `error` field of a failed Chopper response, or null when the body is not
/// a JSON object with one.
///
/// Chopper hands the error body back as [Response.error], typed dynamic: a
/// decoded map when the converter got to it, the raw string when it did not.
/// Both shapes appear in practice, so both are read here rather than at every
/// call site.
String? backendErrorCode(Object? error) {
  Object? decoded = error;

  if (decoded is String) {
    if (decoded.isEmpty) return null;
    try {
      decoded = jsonDecode(decoded);
    } catch (_) {
      return null;
    }
  }

  if (decoded is Map && decoded['error'] is String) {
    return decoded['error'] as String;
  }

  return null;
}
