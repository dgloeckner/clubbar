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
/// amount itself is always rendered positive.
String formatBalance(int cents, AppLocalizations l10n, String locale) {
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
