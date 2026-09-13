import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/utils/greeting.dart';

/// Issue #929, move 2: the login burst used to say "Hi Jana!" at nine in the
/// morning and at eleven at night.
void main() {
  group('greetingFor', () {
    test('three hours of the day', () {
      expect(greetingFor(DateTime(2026, 9, 13, 9)), TimeOfDayGreeting.morning);
      expect(greetingFor(DateTime(2026, 9, 13, 14)), TimeOfDayGreeting.day);
      expect(greetingFor(DateTime(2026, 9, 13, 23)), TimeOfDayGreeting.evening);
    });

    test('the boundaries, named', () {
      expect(greetingFor(DateTime(2026, 9, 13, 5)), TimeOfDayGreeting.morning);
      expect(
          greetingFor(DateTime(2026, 9, 13, 10, 59)),
          TimeOfDayGreeting.morning);
      expect(greetingFor(DateTime(2026, 9, 13, 11)), TimeOfDayGreeting.day);
      expect(
          greetingFor(DateTime(2026, 9, 13, 17, 59)), TimeOfDayGreeting.day);
      expect(greetingFor(DateTime(2026, 9, 13, 18)), TimeOfDayGreeting.evening);
    });

    test('the small hours belong to the evening they follow', () {
      // A club bar at one in the morning is still that evening for everyone
      // standing in it.
      expect(greetingFor(DateTime(2026, 9, 14, 1)), TimeOfDayGreeting.evening);
      expect(greetingFor(DateTime(2026, 9, 14, 4, 59)),
          TimeOfDayGreeting.evening);
    });
  });

  group('greetingText', () {
    /// Resolves the localizations for [locale] without pumping a screen.
    Future<AppLocalizations> l10nFor(WidgetTester tester, String locale) async {
      late AppLocalizations l10n;
      await tester.pumpWidget(MaterialApp(
        localizationsDelegates: const [
          AppLocalizations.delegate,
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        supportedLocales: const [Locale('de'), Locale('en')],
        locale: Locale(locale),
        home: Builder(builder: (context) {
          l10n = AppLocalizations.of(context)!;
          return const SizedBox.shrink();
        }),
      ));
      return l10n;
    }

    testWidgets('German, at three hours of the day', (tester) async {
      final l10n = await l10nFor(tester, 'de');

      expect(greetingText(l10n, DateTime(2026, 9, 13, 9)), 'Guten Morgen');
      expect(greetingText(l10n, DateTime(2026, 9, 13, 14)), 'Hallo');
      expect(greetingText(l10n, DateTime(2026, 9, 13, 23)), 'Guten Abend');

      // …and the sentence the burst actually says.
      expect(
        l10n.loginWelcome(
          greetingText(l10n, DateTime(2026, 9, 13, 23)),
          'Jana',
        ),
        'Guten Abend, Jana!',
      );
      expect(
        l10n.loginWelcomeNoName(greetingText(l10n, DateTime(2026, 9, 13, 9))),
        'Guten Morgen!',
      );
    });

    testWidgets('English, at three hours of the day', (tester) async {
      final l10n = await l10nFor(tester, 'en');

      expect(greetingText(l10n, DateTime(2026, 9, 13, 9)), 'Good morning');
      expect(greetingText(l10n, DateTime(2026, 9, 13, 14)), 'Hello');
      expect(greetingText(l10n, DateTime(2026, 9, 13, 23)), 'Good evening');
      expect(
        l10n.loginWelcome(
          greetingText(l10n, DateTime(2026, 9, 13, 9)),
          'Jana',
        ),
        'Good morning, Jana!',
      );
    });
  });
}
