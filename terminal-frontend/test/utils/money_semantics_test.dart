import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/l10n/app_localizations_de.dart';
import 'package:clubbar_terminal/l10n/app_localizations_en.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/utils/formatters.dart';

void main() {
  group('balanceColor', () {
    test('credit is green', () {
      expect(balanceColor(-1), hexToColor(AppColors.semanticSuccess));
      expect(balanceColor(-5000), hexToColor(AppColors.semanticSuccess));
    });

    test('settled and small open tabs are neutral', () {
      expect(balanceColor(0), hexToColor(AppColors.textPrimary));
      expect(balanceColor(1), hexToColor(AppColors.textPrimary));
      expect(
        balanceColor(AppMoney.warnAboveCents),
        hexToColor(AppColors.textPrimary),
      );
    });

    test('open tabs above the warn threshold are amber', () {
      expect(
        balanceColor(AppMoney.warnAboveCents + 1),
        hexToColor(AppColors.semanticWarning),
      );
    });
  });

  group('transactionAmountColor', () {
    test('a charge is neutral', () {
      expect(transactionAmountColor(0), hexToColor(AppColors.textPrimary));
      expect(transactionAmountColor(250), hexToColor(AppColors.textPrimary));
      expect(
        transactionAmountColor(AppMoney.warnAboveCents + 1),
        hexToColor(AppColors.textPrimary),
      );
    });

    test('a credit or refund is green', () {
      expect(
        transactionAmountColor(-250),
        hexToColor(AppColors.semanticSuccess),
      );
    });
  });

  group('formatBalance', () {
    test('labels an open tab and a credit distinctly (en)', () {
      final l10n = AppLocalizationsEn();

      expect(formatBalance(1480, l10n, 'en'), 'Open tab: €14.80');
      expect(formatBalance(0, l10n, 'en'), 'Open tab: €0.00');
      expect(formatBalance(-500, l10n, 'en'), 'Credit: €5.00');
    });

    test('labels an open tab and a credit distinctly (de)', () {
      final l10n = AppLocalizationsDe();

      expect(formatBalance(1480, l10n, 'de'), contains('Offener Betrag'));
      expect(formatBalance(-500, l10n, 'de'), contains('Guthaben'));
    });

    test('a credit is shown as a positive amount', () {
      expect(formatBalance(-500, AppLocalizationsEn(), 'en'), isNot(contains('-')));
    });
  });

  group('formatNewBalance', () {
    test('labels the projected balance by its sign', () {
      final l10n = AppLocalizationsEn();

      expect(formatNewBalance(1480, l10n, 'en'), 'New open tab: €14.80');
      expect(formatNewBalance(-500, l10n, 'en'), 'New credit: €5.00');
    });
  });
}
