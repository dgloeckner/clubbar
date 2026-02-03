import 'package:flutter_test/flutter_test.dart';
import 'package:ruderbar_terminal/models/transaction_sync_response.dart';

void main() {
  group('TransactionSyncResponse', () {
    test('parses valid JSON with accepted transactions', () {
      final json = {
        'accepted_ids': ['uuid-1', 'uuid-2'],
        'rejected': {
          'count': 0,
          'errors': [],
        },
        'member_balances': {
          'member-1': 4500,
          'member-2': 1200,
        },
      };

      final response = TransactionSyncResponse.fromJson(json);

      expect(response.acceptedIds, ['uuid-1', 'uuid-2']);
      expect(response.rejected.count, 0);
      expect(response.rejected.errors, isEmpty);
      expect(response.memberBalances, {'member-1': 4500, 'member-2': 1200});
    });

    test('parses response with rejected transactions', () {
      final json = {
        'accepted_ids': ['uuid-1'],
        'rejected': {
          'count': 1,
          'errors': [
            {
              'transaction_id': 'uuid-2',
              'reason': 'member_not_found',
            },
          ],
        },
        'member_balances': {
          'member-1': 4500,
        },
      };

      final response = TransactionSyncResponse.fromJson(json);

      expect(response.acceptedIds, ['uuid-1']);
      expect(response.rejected.count, 1);
      expect(response.rejected.errors.length, 1);
      expect(response.rejected.errors[0].transactionId, 'uuid-2');
      expect(response.rejected.errors[0].reason, 'member_not_found');
    });

    test('parses response with empty accepted_ids', () {
      final json = {
        'accepted_ids': [],
        'rejected': {
          'count': 0,
          'errors': [],
        },
        'member_balances': {},
      };

      final response = TransactionSyncResponse.fromJson(json);

      expect(response.acceptedIds, isEmpty);
      expect(response.memberBalances, isEmpty);
    });

    test('parses response with empty member_balances', () {
      final json = {
        'accepted_ids': ['uuid-1'],
        'rejected': {
          'count': 0,
          'errors': [],
        },
        'member_balances': {},
      };

      final response = TransactionSyncResponse.fromJson(json);

      expect(response.acceptedIds, ['uuid-1']);
      expect(response.memberBalances, isEmpty);
    });

    test('parses response with multiple rejected errors', () {
      final json = {
        'accepted_ids': [],
        'rejected': {
          'count': 2,
          'errors': [
            {'transaction_id': 'uuid-1', 'reason': 'duplicate'},
            {'transaction_id': 'uuid-2', 'reason': 'product_not_found'},
          ],
        },
        'member_balances': {},
      };

      final response = TransactionSyncResponse.fromJson(json);

      expect(response.rejected.count, 2);
      expect(response.rejected.errors.length, 2);
      expect(response.rejected.errors[0].reason, 'duplicate');
      expect(response.rejected.errors[1].reason, 'product_not_found');
    });
  });

  group('TransactionSyncRejected', () {
    test('fromJson with empty errors', () {
      final json = {'count': 0, 'errors': []};
      final rejected = TransactionSyncRejected.fromJson(json);

      expect(rejected.count, 0);
      expect(rejected.errors, isEmpty);
    });
  });

  group('TransactionSyncError', () {
    test('fromJson parses correctly', () {
      final json = {
        'transaction_id': 'test-uuid',
        'reason': 'member_not_found',
      };

      final error = TransactionSyncError.fromJson(json);

      expect(error.transactionId, 'test-uuid');
      expect(error.reason, 'member_not_found');
    });
  });
}
