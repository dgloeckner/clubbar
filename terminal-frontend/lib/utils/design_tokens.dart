import 'package:flutter/material.dart';

/// Design tokens and theme constants
/// Mirrors admin-frontend design system for consistency

class AppColors {
  // Background colors
  static const Color bgPrimary = Color(0xff0a1628); // Deep navy
  static const Color bgSecondary = Color(0xff0f1d32); // Secondary bg
  static const Color bgTertiary = Color(0xff11233a); // Tertiary bg
  static const Color bgCard = Color(0xff1a2744); // Card bg
  static const Color bgInput = Color(0xff0d1829); // Input bg
  static const Color bgHover = Color(0xff15213f); // Hover state

  /// Darkest surface in the palette — dispenser diagnostics code boxes.
  static const Color bgDeep = Color(0xff0f172a);

  // Semantic colors
  static const Color semanticPrimary = Color(0xff3b82f6); // Blue - primary actions
  static const Color semanticSuccess = Color(0xff22c55e); // Green - success
  static const Color semanticWarning = Color(0xfff97316); // Orange - warnings
  static const Color semanticDanger = Color(0xffef4444); // Red - errors
  static const Color semanticInfo = Color(0xff0ea5e9); // Cyan - info/prices

  /// The blue a *filled* control may use when it carries white text (#41).
  ///
  /// [semanticPrimary] is a fine accent — as a border, an icon or text on a
  /// dark background it clears AA comfortably. It is not a fine *fill*: white
  /// on `#3b82f6` is only 3.7:1, below the 4.5:1 AA needs for text. This is
  /// the same hue one step darker, where white reaches 5.2:1. Use it for
  /// button and chip backgrounds; keep [semanticPrimary] for everything else.
  static const Color semanticPrimaryStrong = Color(0xff2563eb);

  /// Lighter blue accent — active-state borders and highlights that want to
  /// read as "primary" without the strength of [semanticPrimaryStrong].
  static const Color semanticPrimaryLight = Color(0xff60a5fa);

  /// Red for text sitting on a *tinted red* surface, e.g. the offline badge
  /// (#41). [semanticDanger] on its own 15% tint is 3.9:1; this is 5.3:1.
  static const Color dangerOnTint = Color(0xfff87171);

  /// Stronger red fill — the failed-sales banner (#152).
  static const Color dangerStrong = Color(0xffb91c1c);

  /// Amber "needs attention" status — sync warnings, jam counters.
  static const Color semanticPending = Color(0xfff59e0b);

  /// Brighter amber for icons/badges that sit on a dark fill rather than as
  /// running text.
  static const Color semanticWarningLight = Color(0xfffbbf24);

  /// Brighter green "active/connected" accent — status pills and metrics
  /// that want more presence than [semanticSuccess].
  static const Color semanticSuccessLight = Color(0xff34d399);

  /// Indigo "idle" status text (dispenser machine-state badge).
  static const Color semanticIdle = Color(0xff818cf8);

  /// Teal secondary glow colour for the RFID detector button.
  static const Color accentTeal = Color(0xff14b8a6);

  // Text colors — every one of these clears WCAG AA (4.5:1) on every
  // background token above; `test/utils/contrast_test.dart` enforces it.
  static const Color textPrimary = Color(0xfff1f5f9); // Primary text
  static const Color textSecondary = Color(0xff94a3b8); // Secondary text

  /// Tertiary text — timestamps, version strings, hints.
  ///
  /// Was `#64748b`, which is 3.8:1 on [bgPrimary] and 3.1:1 on [bgCard] —
  /// below AA anywhere it was used (#41). Lightened so that the token is safe
  /// by construction rather than by reviewer vigilance; it is still visibly
  /// quieter than [textSecondary].
  static const Color textMuted = Color(0xff8494a8);

  /// Bright text for dark dialog surfaces — error headlines, metric values.
  static const Color textBright = Color(0xffe2e8f0);

  /// Default label colour for a neutral/disabled section title or icon.
  static const Color textDisabled = Color(0xff64748b);

  // Border colors
  static const Color borderLight = Color(0xff334155); // Light border
  static const Color borderDark = Color(0xff1e293b); // Dark border
  static const Color borderFocus = Color(0xff3b82f6); // Focus border

  /// Muted secondary border — inactive tabs, disabled dots, quiet dividers.
  static const Color borderMuted = Color(0xff475569);

  /// Border for a control that has to be *found*, not merely recognised once
  /// found.
  ///
  /// WCAG 1.4.11 asks 3:1 of the boundary of an interactive element against
  /// what is behind it. [borderMuted] is 2.0:1 on the member bar and
  /// [borderLight] is 1.5:1 — fine for a divider between things the eye is
  /// already resting on, not enough to advertise a button. The logout control
  /// sat inside both and members reported not finding it at all.
  ///
  /// 3.2:1 on the member bar. `test/utils/contrast_test.dart` holds the floor.
  static const Color borderStrong = Color(0xff64748b);

  /// Cart quantity "−" button fill (#41 — white glyph, not red-on-red).
  static const Color cartRemoveFill = Color(0xff7f1d1d);

  /// Cart quantity "+" button fill (#41 — white glyph, not green-on-green).
  static const Color cartAddFill = Color(0xff166534);
}

/// Money semantics for the terminal (see issue #28).
///
/// The rule below is binding for the admin frontend too — see ADR-0042
/// (`adr/0042-colour-semantics-for-monetary-values.md`), which records it once
/// for both apps. `admin-frontend/src/utils/transactions.ts` mirrors these two
/// functions, and its unit tests mirror `test/utils/money_semantics_test.dart`
/// case for case; change one side and the other has to follow.
///
/// Sign convention — the same everywhere, for balances and for single
/// transaction amounts:
///   * a **positive** amount means the member *owes* money (open tab / Deckel)
///   * a **negative** amount means *credit* in the member's favour
///
/// Colour rules, encoded once in [balanceColor] / [transactionAmountColor]:
///   * green is reserved for actual credit — it never shows debt
///   * a settled account or a small open tab stays neutral (primary text)
///   * an open tab above [AppMoney.warnAboveCents] turns amber
class AppMoney {
  /// Open tabs above this amount are shown in the warning colour.
  ///
  /// Deliberately *not* the credit limit: this is a reading cue ("that tab is
  /// getting substantial"), while `CreditLimitCheck` decides what the member
  /// may still buy. The limit's own warning band and hard stop are surfaced
  /// by `CreditLimitBanner` and the checkout button, not by this colour.
  static const int warnAboveCents = 2000; // €20.00
}

/// Colour for a *balance* (member bar, details modal, cart, confirmation).
Color balanceColor(int balanceCents) {
  if (balanceCents < 0) {
    return AppColors.semanticSuccess; // credit
  }
  if (balanceCents > AppMoney.warnAboveCents) {
    return AppColors.semanticWarning; // large open tab
  }
  return AppColors.textPrimary; // settled or small open tab
}

/// Colour for a single *transaction amount* in a booking history.
///
/// Same polarity as [balanceColor]: only money in the member's favour is
/// green. A charge is neutral — it is not an error, so it is not amber.
Color transactionAmountColor(int amountCents) {
  return amountCents < 0 ? AppColors.semanticSuccess : AppColors.textPrimary;
}

class AppSpacing {
  static const double xs = 4.0;
  static const double sm = 8.0;
  static const double md = 12.0;
  static const double lg = 16.0;
  static const double xl = 20.0;
  static const double xxl = 24.0;
  static const double xxxl = 32.0;
}

/// Type scale.
///
/// The defaults are sized for the deployment target — a 7″ touchscreen read
/// standing up, at arm's length, in bar lighting — not for a phone held at
/// 30 cm (#41). Every step is ~12% larger than the phone-ish scale the app
/// started with (base was 14). Deployments that need something else override
/// the steps from `config.json` via [applyConfig]; see `INSTALL.md`.
class AppFontSizes {
  static double xs = 13.0;
  static double sm = 14.0;
  static double base = 16.0;
  static double lg = 18.0;
  static double xl = 20.0;
  static double xxl = 22.0;
  static double xxxl = 26.0;

  /// The idle screen headline — the one display-size string in the app, kept
  /// as its own step (rather than derived from [xxxl]) so it tunes the same
  /// way every other step does (#303).
  static double display = 55.0;

  /// Apply font size overrides from config (e.g. from config.json `fontSizes` key).
  /// Only non-null values are applied; omitted keys keep their defaults.
  static void applyConfig(Map<String, dynamic>? fontSizes) {
    if (fontSizes == null) return;
    if (fontSizes['xs'] is num) xs = (fontSizes['xs'] as num).toDouble();
    if (fontSizes['sm'] is num) sm = (fontSizes['sm'] as num).toDouble();
    if (fontSizes['base'] is num) base = (fontSizes['base'] as num).toDouble();
    if (fontSizes['lg'] is num) lg = (fontSizes['lg'] as num).toDouble();
    if (fontSizes['xl'] is num) xl = (fontSizes['xl'] as num).toDouble();
    if (fontSizes['xxl'] is num) xxl = (fontSizes['xxl'] as num).toDouble();
    if (fontSizes['xxxl'] is num) xxxl = (fontSizes['xxxl'] as num).toDouble();
    if (fontSizes['display'] is num) {
      display = (fontSizes['display'] as num).toDouble();
    }
  }
}

class AppBorderRadius {
  static const double sm = 8.0;
  static const double md = 12.0;
  static const double lg = 16.0;
  static const double xl = 20.0;
  static const double full = 9999.0;
}

class AppAnimations {
  static const Duration fast = Duration(milliseconds: 100);
  static const Duration normal = Duration(milliseconds: 150);
  static const Duration slow = Duration(milliseconds: 200);
}

/// Avatar gradients (5 colour schemes for member avatars).
///
/// Picked by [avatarGradientFor] from a stable hash of the member id, so a
/// given member always gets the same gradient across the bar, the details
/// sheet and any future surface — instead of every avatar hard-coding the
/// same orange (#302).
class AppAvatarGradients {
  static const LinearGradient orange = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [Color(0xfff97316), Color(0xfffb923c)],
  );

  static const LinearGradient blue = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [Color(0xff3b82f6), Color(0xff8b5cf6)],
  );

  static const LinearGradient green = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [Color(0xff22c55e), Color(0xff10b981)],
  );

  static const LinearGradient pink = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [Color(0xffec4899), Color(0xfff472b6)],
  );

  static const LinearGradient gray = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [Color(0xff64748b), Color(0xff94a3b8)],
  );

  static const List<LinearGradient> _all = [orange, blue, green, pink, gray];

  /// Deterministically picks a gradient for [memberId].
  ///
  /// A stable hash rather than a random pick: the same member must render
  /// with the same gradient every time their avatar appears.
  static LinearGradient forId(String memberId) {
    return _all[memberId.hashCode.abs() % _all.length];
  }
}

/// Convenience alias for [AppAvatarGradients.forId].
LinearGradient avatarGradientFor(String memberId) =>
    AppAvatarGradients.forId(memberId);

// Convert hex string to Color. Still used where a hex value has no named
// token — e.g. one-off literals in tests.
Color hexToColor(String hexString) {
  final buffer = StringBuffer();
  if (hexString.length == 7 && hexString.startsWith('#')) {
    buffer.write('ff');
  }
  buffer.write(hexString.replaceFirst('#', ''));
  return Color(int.parse(buffer.toString(), radix: 16));
}
