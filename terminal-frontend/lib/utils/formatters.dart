import 'package:intl/intl.dart';

/// Currency formatter that respects locale.
/// German: 12,50 €
/// English: €12.50
String formatPrice(int cents, String locale) {
  final format = NumberFormat.currency(
    locale: locale == 'de' ? 'de_DE' : 'en_GB',
    symbol: '€',
    decimalDigits: 2,
  );
  return format.format(cents / 100.0);
}

/// Date formatter that respects locale.
String formatDate(DateTime date, String locale) {
  final format = DateFormat.yMd(locale == 'de' ? 'de_DE' : 'en_GB');
  return format.format(date);
}

/// DateTime formatter that respects locale.
String formatDateTime(DateTime date, String locale) {
  final format = DateFormat.yMd(locale == 'de' ? 'de_DE' : 'en_GB').add_Hm();
  return format.format(date);
}
