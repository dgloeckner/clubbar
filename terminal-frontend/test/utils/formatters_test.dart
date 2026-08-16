import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:clubbar_terminal/utils/formatters.dart';

void main() {
  setUpAll(() async {
    // Production loads this via GlobalMaterialLocalizations.delegate inside
    // MaterialApp; plain unit tests need it initialized explicitly.
    await initializeDateFormatting();
  });

  group('formatTransactionTimestamp', () {
    final now = DateTime(2026, 8, 16, 10, 0);

    test('German uses day-before-month order, no year within 11 months', () {
      final timestamp = DateTime(2026, 3, 5, 14, 3);
      final result = formatTransactionTimestamp(timestamp, 'de', now: now);

      expect(result, contains('5'));
      expect(result, contains('Mär'));
      expect(result, isNot(contains('2026')));
      expect(result, contains('14:03'));
      // Day must come before the month name in German order.
      expect(result.indexOf('5'), lessThan(result.indexOf('Mär')));
    });

    test('English (en_GB) uses day-before-month order, no year within 11 months', () {
      final timestamp = DateTime(2026, 3, 5, 14, 3);
      final result = formatTransactionTimestamp(timestamp, 'en', now: now);

      expect(result, contains('5'));
      expect(result, contains('Mar'));
      expect(result, isNot(contains('2026')));
      expect(result, contains('14:03'));
      // en_GB, like de_DE, is day-before-month ("5 Mar"), unlike en_US.
      expect(result.indexOf('5'), lessThan(result.indexOf('Mar')));
    });

    test('shows the year once the entry is more than 11 months old', () {
      final timestamp = DateTime(2025, 6, 1, 9, 30);
      final result = formatTransactionTimestamp(timestamp, 'de', now: now);

      expect(result, contains('2025'));
    });

    test('omits the year at exactly 11 months old', () {
      final timestamp = DateTime(2025, 9, 16, 9, 30);
      final result = formatTransactionTimestamp(timestamp, 'de', now: now);

      expect(result, isNot(contains('2025')));
    });

    test('shows the year once a full 12 months have elapsed', () {
      final timestamp = DateTime(2025, 8, 15, 9, 30);
      final result = formatTransactionTimestamp(timestamp, 'de', now: now);

      expect(result, contains('2025'));
    });

    test('always includes hour and minute', () {
      final timestamp = DateTime(2026, 8, 1, 7, 5);
      final result = formatTransactionTimestamp(timestamp, 'de', now: now);

      expect(result, contains('07:05'));
    });
  });
}
