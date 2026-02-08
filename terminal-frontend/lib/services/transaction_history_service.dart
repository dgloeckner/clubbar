import 'dart:async';
import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:logger/logger.dart';
import '../models/transaction_list_item.dart';

/// Service for fetching transaction history from backend
class TransactionHistoryService {
  final String baseUrl;
  final String authToken;
  final Logger _logger;

  TransactionHistoryService({
    required this.baseUrl,
    required this.authToken,
    Logger? logger,
  }) : _logger = logger ?? Logger();

  /// Fetch transaction history for a member
  ///
  /// Throws [TransactionFetchException] on network or API errors.
  /// Throws [TimeoutException] if request takes longer than 5 seconds.
  Future<List<TransactionListItem>> fetchTransactionHistory({
    required String memberId,
    required String preferredLanguage,
    int limit = 50,
  }) async {
    try {
      final uri = Uri.parse('$baseUrl/api/terminal/transactions/$memberId')
          .replace(queryParameters: {'limit': limit.toString()});

      _logger.d('Fetching transaction history: $uri');

      final response = await http
          .get(
            uri,
            headers: {
              'Authorization': 'Bearer $authToken',
              'Content-Type': 'application/json',
            },
          )
          .timeout(const Duration(seconds: 5));

      _logger.d('Transaction history response: HTTP ${response.statusCode}');

      if (response.statusCode == 200) {
        final json = jsonDecode(response.body) as Map<String, dynamic>;
        final transactionsJson = json['transactions'] as List<dynamic>;

        return transactionsJson
            .map((t) => TransactionListItem.fromBackendJson(
                  t as Map<String, dynamic>,
                  preferredLanguage,
                ))
            .toList();
      } else if (response.statusCode == 404) {
        throw MemberNotFoundException(memberId);
      } else {
        throw TransactionFetchException(
          'Failed to load transactions: HTTP ${response.statusCode}',
        );
      }
    } on TimeoutException {
      _logger.w('Transaction history request timed out');
      rethrow;
    } catch (e) {
      _logger.e('Error fetching transaction history', error: e);
      if (e is TransactionFetchException || e is MemberNotFoundException) {
        rethrow;
      }
      throw TransactionFetchException('Network error: $e');
    }
  }
}

/// Exception thrown when transaction fetch fails
class TransactionFetchException implements Exception {
  final String message;

  TransactionFetchException(this.message);

  @override
  String toString() => 'TransactionFetchException: $message';
}

/// Exception thrown when member is not found
class MemberNotFoundException implements Exception {
  final String memberId;

  MemberNotFoundException(this.memberId);

  @override
  String toString() => 'MemberNotFoundException: Member $memberId not found';
}
