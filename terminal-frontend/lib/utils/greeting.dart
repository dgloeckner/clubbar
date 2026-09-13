import 'package:clubbar_terminal/l10n/app_localizations.dart';

/// Which time-of-day greeting the terminal's own clock calls for.
///
/// The login burst used to say *Hi Jana!* at nine in the morning and at
/// eleven at night (#929, move 2). A club bar is open across both, and the
/// greeting is the one sentence the terminal says to a member before they
/// have done anything — it should sound like a person behind the counter.
enum TimeOfDayGreeting { morning, day, evening }

/// The greeting for [at].
///
/// The boundaries are the ordinary German ones and are the whole decision
/// here: *Guten Morgen* until eleven, *Hallo* through the afternoon, *Guten
/// Abend* from six in the evening — and through the small hours, because a
/// club bar at one in the morning is still that evening for everyone in it.
///
/// A pure function of a [DateTime] rather than a reader of the clock, so the
/// test can pin three hours of the day without pinning the machine's.
TimeOfDayGreeting greetingFor(DateTime at) {
  final hour = at.hour;
  if (hour >= 5 && hour < 11) return TimeOfDayGreeting.morning;
  if (hour >= 11 && hour < 18) return TimeOfDayGreeting.day;
  return TimeOfDayGreeting.evening;
}

/// The greeting for [at], in the reader's language.
String greetingText(AppLocalizations l10n, DateTime at) {
  switch (greetingFor(at)) {
    case TimeOfDayGreeting.morning:
      return l10n.greetingMorning;
    case TimeOfDayGreeting.day:
      return l10n.greetingDay;
    case TimeOfDayGreeting.evening:
      return l10n.greetingEvening;
  }
}
