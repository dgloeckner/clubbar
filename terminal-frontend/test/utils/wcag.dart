import 'dart:math' as math;
import 'dart:ui';

import 'package:flutter_test/flutter_test.dart';

/// WCAG 2.1 contrast helpers, shared by the token-level checks in
/// `contrast_test.dart` and the widget-level ones that read colours back off a
/// rendered tree (see `widgets/clubbar_header_test.dart`).
///
/// One implementation on purpose: a second copy is a second thing to get
/// wrong, and the anchors in `contrast_test.dart` only vouch for this one.

/// WCAG AA floor for body text (1.4.3).
const double kTextContrastFloor = 4.5;

/// WCAG AA floor for meaningful non-text — icons, glyphs (1.4.11).
const double kGlyphContrastFloor = 3.0;

/// WCAG 2.1 relative luminance (sRGB).
double relativeLuminance(Color color) {
  double channel(double v) =>
      v <= 0.03928 ? v / 12.92 : math.pow((v + 0.055) / 1.055, 2.4).toDouble();
  return 0.2126 * channel(color.r) +
      0.7152 * channel(color.g) +
      0.0722 * channel(color.b);
}

/// WCAG 2.1 contrast ratio, in the range 1:1 to 21:1.
double contrastRatio(Color a, Color b) {
  final la = relativeLuminance(a);
  final lb = relativeLuminance(b);
  final lighter = math.max(la, lb);
  final darker = math.min(la, lb);
  return (lighter + 0.05) / (darker + 0.05);
}

/// Composite [fg] over an opaque [bg].
///
/// WCAG ratios are undefined for translucent colours, so anything faded — a
/// 15 % tint, a 70 % label — has to be flattened against what is behind it
/// before it can be measured.
Color compositeOver(Color fg, Color bg) {
  final a = fg.a;
  return Color.from(
    alpha: 1.0,
    red: fg.r * a + bg.r * (1 - a),
    green: fg.g * a + bg.g * (1 - a),
    blue: fg.b * a + bg.b * (1 - a),
  );
}

void expectTextContrast(Color fg, Color bg, {required String why}) {
  final ratio = contrastRatio(fg, bg);
  expect(ratio, greaterThanOrEqualTo(kTextContrastFloor),
      reason: '$why is ${ratio.toStringAsFixed(2)}:1, below the '
          '$kTextContrastFloor:1 WCAG AA needs for text');
}

void expectGlyphContrast(Color fg, Color bg, {required String why}) {
  final ratio = contrastRatio(fg, bg);
  expect(ratio, greaterThanOrEqualTo(kGlyphContrastFloor),
      reason: '$why is ${ratio.toStringAsFixed(2)}:1, below the '
          '$kGlyphContrastFloor:1 WCAG AA needs for non-text');
}
