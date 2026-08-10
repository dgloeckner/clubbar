import 'dart:ui';

import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:flutter_test/flutter_test.dart';

import 'wcag.dart';

/// Contrast floors for the terminal's colour system (#41).
///
/// The terminal is read standing up, at arm's length, on a 7″ panel, in bar
/// lighting. Colours that merely look fine on a laptop are not good enough, so
/// the thresholds here are the WCAG 2.1 AA ones and they are checked rather
/// than assumed:
///
///   * **4.5:1** for text — WCAG 1.4.3.
///   * **3:1** for interactive glyphs and other meaningful non-text — 1.4.11.
///
/// The token matrix below is the part that keeps this from rotting: a new
/// background token, or a text token nudged darker, fails here before it
/// reaches a member. The named pairs after it pin the specific regressions
/// #41 reported, so they cannot quietly come back.
void main() {
  group('WCAG contrast math', () {
    // Anchors from the WCAG 2.1 definition — if these drift the rest of the
    // file is measuring nothing.
    test('black on white is 21:1', () {
      expect(contrastRatio(_c('#000000'), _c('#ffffff')), closeTo(21.0, 0.01));
    });

    test('a colour against itself is 1:1', () {
      expect(contrastRatio(_c('#3b82f6'), _c('#3b82f6')), closeTo(1.0, 0.01));
    });

    test('the ratio is symmetric', () {
      expect(
        contrastRatio(_c('#0a1628'), _c('#f1f5f9')),
        closeTo(contrastRatio(_c('#f1f5f9'), _c('#0a1628')), 0.0001),
      );
    });

    // #767676 on white is the canonical "just passes AA" grey.
    test('#767676 on white just clears AA', () {
      final ratio = contrastRatio(_c('#767676'), _c('#ffffff'));
      expect(ratio, greaterThanOrEqualTo(4.5));
      expect(ratio, lessThan(4.6));
    });

    test('alpha compositing matches a hand-computed blend', () {
      // 50% white over black is #808080 (rounding aside).
      final blended = _over(_c('#ffffff').withValues(alpha: 0.5), _c('#000000'));
      expect(blended.r, closeTo(0.5, 0.01));
      expect(blended.g, closeTo(0.5, 0.01));
      expect(blended.b, closeTo(0.5, 0.01));
    });
  });

  group('text tokens clear AA on every background token', () {
    const backgrounds = <String, String>{
      'bgPrimary': AppColors.bgPrimary,
      'bgSecondary': AppColors.bgSecondary,
      'bgTertiary': AppColors.bgTertiary,
      'bgCard': AppColors.bgCard,
      'bgInput': AppColors.bgInput,
      'bgHover': AppColors.bgHover,
    };

    const textTokens = <String, String>{
      'textPrimary': AppColors.textPrimary,
      'textSecondary': AppColors.textSecondary,
      'textMuted': AppColors.textMuted,
    };

    for (final text in textTokens.entries) {
      for (final bg in backgrounds.entries) {
        test('${text.key} on ${bg.key}', () {
          _expectText(_c(text.value), _c(bg.value),
              why: '${text.key} on ${bg.key}');
        });
      }
    }
  });

  group('the failures #41 reported stay fixed', () {
    test('textMuted is legible, not decorative-only', () {
      // The old #64748b was 3.8:1 on bgPrimary and 3.1:1 on bgCard. The token
      // is now safe by construction, so a future call site cannot reintroduce
      // the bug simply by picking the "muted" name.
      _expectText(_c(AppColors.textMuted), _c(AppColors.bgCard),
          why: 'textMuted on bgCard');
    });

    test('textMuted is still visibly quieter than textSecondary', () {
      // Fixing contrast by collapsing the scale would be its own regression:
      // the hierarchy has to survive.
      final muted =
          contrastRatio(_c(AppColors.textMuted), _c(AppColors.bgPrimary));
      final secondary =
          contrastRatio(_c(AppColors.textSecondary), _c(AppColors.bgPrimary));
      expect(muted, lessThan(secondary),
          reason: 'textMuted should read quieter than textSecondary');
    });

    test('the header clock uses a token that clears AA on the header', () {
      _expectText(_c(AppColors.textSecondary), _c(AppColors.bgSecondary),
          why: 'header clock on the header bar');
    });

    test('dangerOnTint is legible where semanticDanger is not', () {
      // Red text on a red-tinted surface is the case the palette could not
      // serve: a header pill fills with its own colour and draws the label
      // over it. Both tints the header uses are checked, because #40's louder
      // 25 % fill is *redder*, so it lowers same-hue contrast rather than
      // raising it — the alerting state is the riskier one, not the safer.
      //
      // Whether a given pill actually renders these colours is a question
      // about the widget, and is asserted against the real tree in
      // `widgets/clubbar_header_test.dart`. This is only about the tokens.
      for (final alpha in [0.15, 0.25]) {
        final fill = _over(
          _c(AppColors.semanticDanger).withValues(alpha: alpha),
          _c(AppColors.bgSecondary),
        );
        _expectText(_c(AppColors.dangerOnTint), fill,
            why: 'dangerOnTint on a ${alpha * 100}% red fill');
        expect(
          contrastRatio(_c(AppColors.semanticDanger), fill),
          lessThan(kTextContrastFloor),
          reason: 'semanticDanger on its own ${alpha * 100}% tint is why '
              'dangerOnTint exists — if this ever passes, the token can go',
        );
      }
    });

    test('the cart quantity glyphs read as glyphs, not coloured squares', () {
      // 44×44 buttons whose '−'/'+' were 2.7:1 and 3.1:1 on their fills.
      const minusFill = '#7f1d1d';
      const plusFill = '#166534';
      _expectGlyph(_c('#ffffff'), _c(minusFill), why: 'cart − glyph');
      _expectGlyph(_c('#ffffff'), _c(plusFill), why: 'cart + glyph');

      // They are text nodes, so hold them to the text floor too — white
      // clears it on both fills with room to spare.
      _expectText(_c('#ffffff'), _c(minusFill), why: 'cart − glyph');
      _expectText(_c('#ffffff'), _c(plusFill), why: 'cart + glyph');
    });

    test('white text sits on semanticPrimaryStrong, never semanticPrimary',
        () {
      _expectText(_c('#ffffff'), _c(AppColors.semanticPrimaryStrong),
          why: 'white on the strong primary fill');
      // The reason the extra token exists. If plain semanticPrimary ever
      // clears AA with white, collapse the two.
      expect(
        contrastRatio(_c('#ffffff'), _c(AppColors.semanticPrimary)),
        lessThan(4.5),
        reason: 'semanticPrimary is an accent, not a fill for white text',
      );
    });

    test('semanticPrimaryStrong is still recognisably the primary blue', () {
      // Guards against "fix" by desaturating into a different colour.
      final strong = _c(AppColors.semanticPrimaryStrong);
      final primary = _c(AppColors.semanticPrimary);
      expect(strong.b, greaterThan(strong.r),
          reason: 'should still be blue-dominant');
      expect((strong.b - primary.b).abs(), lessThan(0.2),
          reason: 'should be the same hue one step darker');
    });

    test('accent colours used as text clear AA on the app background', () {
      // These carry meaning as text (prices, warnings, balances).
      const accents = <String, String>{
        'semanticSuccess': AppColors.semanticSuccess,
        'semanticWarning': AppColors.semanticWarning,
        'semanticInfo': AppColors.semanticInfo,
        'semanticDanger': AppColors.semanticDanger,
      };
      for (final entry in accents.entries) {
        _expectText(_c(entry.value), _c(AppColors.bgPrimary),
            why: '${entry.key} as text on bgPrimary');
      }
    });
  });

  group('the kiosk type scale', () {
    // Reset between tests: AppFontSizes is mutable static state that
    // applyConfig writes to, and a leaked override would make the floors below
    // pass for the wrong reason.
    setUp(_resetFontSizes);
    tearDown(_resetFontSizes);

    test('body text defaults to 16 for a 7-inch panel', () {
      expect(AppFontSizes.base, greaterThanOrEqualTo(16.0));
    });

    test('the scale increases monotonically', () {
      final steps = <double>[
        AppFontSizes.xs,
        AppFontSizes.sm,
        AppFontSizes.base,
        AppFontSizes.lg,
        AppFontSizes.xl,
        AppFontSizes.xxl,
        AppFontSizes.xxxl,
      ];
      for (var i = 1; i < steps.length; i++) {
        expect(steps[i], greaterThan(steps[i - 1]),
            reason: 'step $i (${steps[i]}) should exceed ${steps[i - 1]}');
      }
    });

    test('the smallest step stays readable at arm\'s length', () {
      expect(AppFontSizes.xs, greaterThanOrEqualTo(13.0));
    });

    test('a deployment can still override the defaults', () {
      // Raising the shipped default must not cost anyone the escape hatch —
      // a club on a larger panel may well want to go back down.
      AppFontSizes.applyConfig(const {'base': 14, 'xxxl': 30});
      expect(AppFontSizes.base, 14.0);
      expect(AppFontSizes.xxxl, 30.0);
      // Untouched keys keep the shipped default.
      expect(AppFontSizes.lg, 18.0);
    });
  });
}

// --- helpers ---------------------------------------------------------------

void _resetFontSizes() {
  AppFontSizes.xs = 13.0;
  AppFontSizes.sm = 14.0;
  AppFontSizes.base = 16.0;
  AppFontSizes.lg = 18.0;
  AppFontSizes.xl = 20.0;
  AppFontSizes.xxl = 22.0;
  AppFontSizes.xxxl = 26.0;
}

Color _c(String hex) => hexToColor(hex);

void _expectText(Color fg, Color bg, {required String why}) =>
    expectTextContrast(fg, bg, why: why);

void _expectGlyph(Color fg, Color bg, {required String why}) =>
    expectGlyphContrast(fg, bg, why: why);

Color _over(Color fg, Color bg) => compositeOver(fg, bg);
