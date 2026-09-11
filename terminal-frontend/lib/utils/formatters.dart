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

/// Below this, litres stop reading as a size: at 100 ml the litre value gains a
/// non-zero first decimal, and below 5 ml two decimals of a litre round to zero
/// outright. See `api/fixtures/volume-format.json`.
const int _volumeMillilitreThreshold = 100;

/// A NO-BREAK SPACE, so a badge never wraps between its number and its unit.
const String _volumeUnitSeparator = '\u00A0';

/// A product's size, written the way the member's language writes it.
///
/// `500` becomes `0,5 l` for a German member and `0.5 l` for an English one,
/// from one language-neutral number: the size is data on the product rather
/// than part of its translated name (ADR-0056), which is what lets the card
/// draw a one-line name with the size in a badge beneath it.
///
/// The rule, and its vectors, live in `api/fixtures/volume-format.json`. PHP,
/// TypeScript and Dart each implement it and each language's suite reads that
/// file, so the three cannot drift apart unnoticed — a new vector belongs in
/// the fixture, never in one suite.
String formatVolume(int millilitres, String locale) {
  if (millilitres < _volumeMillilitreThreshold) {
    return '$millilitres${_volumeUnitSeparator}ml';
  }

  // Half away from zero, decided on integers before any division: `1005 / 1000`
  // is a double sitting just below 1.005, so leaving the rounding to
  // NumberFormat answers 1,00 where the backend answers 1,01. Rounding first
  // leaves the formatter nothing to disagree about — the value it receives
  // already has at most two decimals.
  final hundredths = (millilitres + 5) ~/ 10;

  // `en` is named and everything else falls back to German, which is the
  // opposite way round from [formatPrice] above. That is deliberate: the
  // fallback is part of the shared rule (`api/fixtures/volume-format.json`),
  // and the backend and the admin panel both fall back to the decimal comma. A
  // size in the wrong punctuation is a smaller failure than three surfaces
  // disagreeing about one number.
  final format = NumberFormat.decimalPattern(locale == 'en' ? 'en_GB' : 'de_DE')
    ..minimumFractionDigits = 0
    ..maximumFractionDigits = 2
    ..turnOffGrouping();

  return '${format.format(hundredths / 100)}${_volumeUnitSeparator}l';
}

/// The same, for a product that may have no size at all.
///
/// `null` means exactly that — a Sauna-Token, a Kaffee — and the caller draws
/// no badge. An empty string rather than a dash keeps that decision with the
/// caller, which is the one that knows whether it is building a line of text or
/// a widget.
String formatVolumeOrEmpty(int? millilitres, String locale) {
  return millilitres == null ? '' : formatVolume(millilitres, locale);
}

/// `Weizenbier` + `500` → `Weizenbier 0,5 l`; with no volume, the name alone.
///
/// The one place the "name, then size" order is decided on the terminal, so the
/// cart, the checkout confirmation, the failed-sales banner and the transaction
/// history all print a product the same way — and the same way the backend's
/// statements do (ADR-0056).
String formatProductLabel(String name, int? millilitres, String locale) {
  return millilitres == null ? name : '$name ${formatVolume(millilitres, locale)}';
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
