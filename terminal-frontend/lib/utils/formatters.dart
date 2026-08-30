import 'package:intl/intl.dart';

import '../l10n/app_localizations.dart';

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

/// Self-explanatory balance label, e.g. "Open tab: €14.80" or "Credit: €5.00".
///
/// Positive cents mean the member owes money, negative mean credit (see
/// [AppMoney] in `design_tokens.dart`). The label carries the sign, so the
/// amount itself is always rendered positive. A settled account (#296) gets
/// its own wording rather than "Open tab: €0.00" — an amount owed of zero.
String formatBalance(int cents, AppLocalizations l10n, String locale) {
  if (cents == 0) return l10n.balanceSettled;
  return cents < 0
      ? l10n.balanceCredit(formatPrice(-cents, locale))
      : l10n.balanceOpenTab(formatPrice(cents, locale));
}

/// Same as [formatBalance] for a projected balance ("New open tab: …").
String formatNewBalance(int cents, AppLocalizations l10n, String locale) {
  return cents < 0
      ? l10n.newBalanceCredit(formatPrice(-cents, locale))
      : l10n.newBalanceOpenTab(formatPrice(cents, locale));
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

/// Locale-correct transaction timestamp: day/month order follows the
/// locale (e.g. "5. Mär." in German vs "Mar 5" in English), and the year
/// is shown only once the entry is more than 11 months old.
///
/// [now] defaults to the current time; pass it explicitly in tests to keep
/// the "how old is this" comparison independent of the wall clock.
String formatTransactionTimestamp(
  DateTime timestamp,
  String locale, {
  DateTime? now,
}) {
  final reference = now ?? DateTime.now();
  final intlLocale = locale == 'de' ? 'de_DE' : 'en_GB';

  var monthsOld =
      (reference.year - timestamp.year) * 12 + (reference.month - timestamp.month);
  if (reference.day < timestamp.day) monthsOld -= 1;

  final format = monthsOld > 11
      ? DateFormat.yMMMd(intlLocale).add_Hm()
      : DateFormat.MMMd(intlLocale).add_Hm();
  return format.format(timestamp);
}

/// SoC temperature with one decimal, in the reader's own notation:
/// "58,9" in German, "58.9" in English.
///
/// The unit is not appended here — it lives in the localized message, so a
/// translation can place it wherever that language puts it.
String formatTemperature(double celsius, String locale) {
  final format = NumberFormat.decimalPatternDigits(
    locale: locale == 'de' ? 'de_DE' : 'en_GB',
    decimalDigits: 1,
  );
  return format.format(celsius);
}
