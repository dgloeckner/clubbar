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
    }

    test('TerminalError delegates to its key', () async {
      final l10n = await AppLocalizations.delegate.load(const Locale('en'));
      const error =
          TerminalError(key: TerminalErrorKey.cartEmpty, sequence: 1);

      expect(error.message(l10n), equals(TerminalErrorKey.cartEmpty.message(l10n)));
    });
  });
}
