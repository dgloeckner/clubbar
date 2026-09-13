import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/l10n/app_localizations_de.dart';
import 'package:clubbar_terminal/l10n/app_localizations_en.dart';
import 'package:clubbar_terminal/models/credit_limit.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/utils/formatters.dart';

void main() {
  group('balanceColor', () {
    // The club's shipped policy: a 10000 ceiling warned at 80%, so the band
    // falls at 8000. Named rather than inlined so the cases below read as
    // "at the band" / "just below it" rather than as arithmetic.
    const band = 8000;

    test('credit is green, whatever the band', () {
      expect(balanceColor(-1, warnAtCents: band), AppColors.semanticSuccess);
      expect(balanceColor(-5000, warnAtCents: band), AppColors.semanticSuccess);
      expect(balanceColor(-5000, warnAtCents: null), AppColors.semanticSuccess);
    });

    test('a settled account and an ordinary open tab are neutral', () {
      expect(balanceColor(0, warnAtCents: band), AppColors.textPrimary);
      expect(balanceColor(1, warnAtCents: band), AppColors.textPrimary);
      // The tab that started #926: €23.00 against a €80.00 band is an
      // ordinary evening, and reading it as a warning is what taught members
      // to ignore the colour.
      expect(balanceColor(2300, warnAtCents: band), AppColors.textPrimary);
      expect(balanceColor(band - 1, warnAtCents: band), AppColors.textPrimary);
    });

    test('the band itself is already amber', () {
      // `>=`, matching CreditLimitCheck.status: the colour and the banner
      // flip on the same cent, never one before the other.
      expect(balanceColor(band, warnAtCents: band), AppColors.semanticWarning);
      expect(
        balanceColor(band + 1, warnAtCents: band),
        AppColors.semanticWarning,
      );
    });

    test('past the ceiling stays amber rather than turning red', () {
      // Colour-by-state belongs to CreditLimitBanner and the checkout button
      // (ADR-0042 scope); the amount only ever says "in the band".
      expect(
        balanceColor(50000, warnAtCents: band),
        AppColors.semanticWarning,
      );
    });

    test('a member with no ceiling is never amber', () {
      // `null` is not "a band of zero" — it is "this member has no line to
      // approach" (ADR-0047 rule 2: NULL inherits, 0 is unlimited).
      expect(balanceColor(1, warnAtCents: null), AppColors.textPrimary);
      expect(balanceColor(50000, warnAtCents: null), AppColors.textPrimary);
      expect(balanceColor(1000000, warnAtCents: null), AppColors.textPrimary);
    });

    test('a settled account is neutral even against a zero band', () {
      // warn_threshold_percent may be as low as 1, so a small ceiling rounds
      // its band down to nothing. A zero balance rendered in warning colour
      // is bug #28, and a debt-free member has entered no band.
      expect(balanceColor(0, warnAtCents: 0), AppColors.textPrimary);
      expect(balanceColor(-500, warnAtCents: 0), AppColors.semanticSuccess);
      expect(balanceColor(1, warnAtCents: 0), AppColors.semanticWarning);
    });
  });

  group('transactionAmountColor', () {
    test('a charge is neutral', () {
      expect(transactionAmountColor(0), AppColors.textPrimary);
      expect(transactionAmountColor(250), AppColors.textPrimary);
      // Larger than any warning band a club would set, and still not amber:
      // a single booking is never coloured by size, only by sign.
      expect(transactionAmountColor(1000000), AppColors.textPrimary);
    });

    test('a credit or refund is green', () {
      expect(
        transactionAmountColor(-250),
        AppColors.semanticSuccess,
      );
    });
  });

  group('CreditLimitPolicy.warnAtCentsFor', () {
    const shipped = CreditLimitPolicy(
      defaultLimitCents: 10000,
      warnThresholdPercent: 80,
    );

    test('a member with no override inherits the club band', () {
      expect(shipped.warnAtCentsFor(null), 8000);
    });

    test('an override sets the ceiling; the band is still the club share', () {
      // ADR-0047 decision 4: an override sets one number, and it is not the
      // band. 80% of their own 5000.
      expect(shipped.warnAtCentsFor(5000), 4000);
    });

    test('an override of zero is unlimited, not inherit', () {
      // The distinction ADR-0047 rule 2 draws: 0 survives a change to the
      // club default rather than following it, and unlimited means no band.
      expect(shipped.warnAtCentsFor(0), isNull);
    });

    test('a club that caps nobody gives no band to inherit', () {
      const uncapped = CreditLimitPolicy(
        defaultLimitCents: 0,
        warnThresholdPercent: 80,
      );
      expect(uncapped.warnAtCentsFor(null), isNull);
      // …but a member singled out for a ceiling still has one.
      expect(uncapped.warnAtCentsFor(5000), 4000);
    });

    test('the boundary cent rounds down, as PHP intdiv does', () {
      // Both sides must round a band the same way or the dashboard lists a
      // member the terminal has not warned yet. 999 * 80 / 100 = 799.2.
      expect(shipped.warnAtCentsFor(999), 799);
    });
  });

  group('formatBalance', () {
    test('labels an open tab, a settled account and a credit distinctly (en)', () {
      final l10n = AppLocalizationsEn();

      expect(formatBalance(1480, l10n, 'en'), 'Open tab: €14.80');
      // A settled account (#296) gets its own wording — "Open tab: €0.00"
      // reads as an amount owed of zero, which is confusing at a glance.
      expect(formatBalance(0, l10n, 'en'), 'No open tab');
      expect(formatBalance(-500, l10n, 'en'), 'Credit: €5.00');
    });

    test('labels an open tab, a settled account and a credit distinctly (de)', () {
      final l10n = AppLocalizationsDe();

      expect(formatBalance(1480, l10n, 'de'), contains('Offener Betrag'));
      expect(formatBalance(0, l10n, 'de'), 'Nichts offen');
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

    // Unlike formatBalance (#296), a projected zero is still meaningful
    // information right after a storno/credit checkout — left unchanged.
    test('a projected zero balance still reads as a new open tab', () {
      expect(formatNewBalance(0, AppLocalizationsEn(), 'en'), 'New open tab: €0.00');
    });
  });
}
