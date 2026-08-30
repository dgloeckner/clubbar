import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/l10n/terminal_error_messages.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';

void main() {
  group('TerminalErrorKey localization', () {
    for (final locale in ['de', 'en']) {
      test('every key has non-empty copy in $locale', () async {
        final l10n = await AppLocalizations.delegate.load(Locale(locale));

        for (final key in TerminalErrorKey.values) {
          final message = key.message(l10n);
          expect(message, isNotEmpty, reason: '${key.name} has no $locale copy');
          expect(
            message,
            isNot(equals(key.name)),
            reason: '${key.name} falls back to the raw key in $locale',
          );
        }
      });

      test('copy is distinct enough to be useful in $locale', () async {
        final l10n = await AppLocalizations.delegate.load(Locale(locale));
        final messages =
            TerminalErrorKey.values.map((k) => k.message(l10n)).toSet();

        // A few keys intentionally share copy is fine, but a wholesale
        // collapse would mean the mapping is broken.
        expect(messages.length, greaterThan(TerminalErrorKey.values.length - 3));
      });

      test('copy carries no exception-shaped placeholder in $locale', () async {
        final l10n = await AppLocalizations.delegate.load(Locale(locale));

        // Error copy takes no arguments by construction — `message()` returns
        // a plain getter. This pins the *content* side of the same rule: no
        // key smuggles engineer vocabulary in front of a member.
        const leaks = [
          'Exception',
          'HTTP',
          'null',
          'SQL',
          'SEPA',
          'Datenbank',
          'Database',
          '{',
        ];

        for (final key in TerminalErrorKey.values) {
          final message = key.message(l10n);
          for (final leak in leaks) {
            expect(
              message,
              isNot(contains(leak)),
              reason: '${key.name} exposes "$leak" in $locale: $message',
            );
          }
        }
      });

      test('copy tells the member what to do in $locale', () async {
        final l10n = await AppLocalizations.delegate.load(Locale(locale));

        // Every error that is not purely informational should name a next
        // step — try again, see the staff, report it to an admin.
        const informational = {
          TerminalErrorKey.cartEmpty,
          TerminalErrorKey.checkoutCancelled,
          TerminalErrorKey.backendUnreachable,
          TerminalErrorKey.syncFailed,
        };
        const cues = [
          'try again',
          'bar staff',
          'to an admin',
          'erneut versuchen',
          'Bar-Team',
          'einem Admin melden',
          'noch einmal versuchen',
          'Scanner',
          'scanner',
          // "…you can still buy everything else" — a workaround is a next
          // step too.
          'you can still',
          'kannst du trotzdem',
          // Jugendschutz (ADR-0045): the one refusal nobody at the bar can
          // lift, so "see the bar staff" would be a false promise. Choosing a
          // different drink is the only true next step.
          'choose something else',
          'wähle etwas anderes',
        ];

        for (final key in TerminalErrorKey.values) {
          if (informational.contains(key)) continue;
          final message = key.message(l10n);
          expect(
            cues.any(message.contains),
            isTrue,
            reason: '${key.name} offers no next step in $locale: $message',
          );
        }
      });
    }

    test('TerminalError delegates to its key', () async {
      final l10n = await AppLocalizations.delegate.load(const Locale('en'));
      const error =
          TerminalError(key: TerminalErrorKey.cartEmpty, sequence: 1);

      expect(error.message(l10n), equals(TerminalErrorKey.cartEmpty.message(l10n)));
    });
  });
}
