import 'package:flutter/material.dart';

/// Design tokens and theme constants
/// Mirrors admin-frontend design system for consistency

class AppColors {
  // Background colors
  static const String bgPrimary = '#0a1628';      // Deep navy
  static const String bgSecondary = '#0f1d32';    // Secondary bg
  static const String bgTertiary = '#11233a';     // Tertiary bg
  static const String bgCard = '#1a2744';         // Card bg
  static const String bgInput = '#0d1829';        // Input bg
  static const String bgHover = '#15213f';        // Hover state

  // Semantic colors
  static const String semanticPrimary = '#3b82f6';  // Blue - primary actions
  static const String semanticSuccess = '#22c55e';  // Green - success
  static const String semanticWarning = '#f97316';  // Orange - warnings
  static const String semanticDanger = '#ef4444';   // Red - errors
  static const String semanticInfo = '#0ea5e9';     // Cyan - info/prices

  // Text colors
  static const String textPrimary = '#f1f5f9';   // Primary text
  static const String textSecondary = '#94a3b8'; // Secondary text
  static const String textMuted = '#64748b';     // Muted text

  // Border colors
  static const String borderLight = '#334155';   // Light border
  static const String borderDark = '#1e293b';    // Dark border
  static const String borderFocus = '#3b82f6';   // Focus border
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

class AppFontSizes {
  static const double xs = 12.0;
  static const double sm = 13.0;
  static const double base = 14.0;
  static const double lg = 16.0;
  static const double xl = 18.0;
  static const double xxl = 20.0;
  static const double xxxl = 24.0;
}

class AppFontWeights {
  static const int normal = 400;
  static const int medium = 500;
  static const int semibold = 600;
  static const int bold = 700;
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

class AppShadows {
  static const List<BoxShadow> sm = [
    BoxShadow(
      color: Color.fromRGBO(0, 0, 0, 0.05),
      blurRadius: 1,
      spreadRadius: 0,
      offset: Offset(0, 1),
    ),
  ];

  static const List<BoxShadow> md = [
    BoxShadow(
      color: Color.fromRGBO(0, 0, 0, 0.1),
      blurRadius: 6,
      spreadRadius: -1,
      offset: Offset(0, 4),
    ),
  ];

  static const List<BoxShadow> lg = [
    BoxShadow(
      color: Color.fromRGBO(0, 0, 0, 0.1),
      blurRadius: 15,
      spreadRadius: -3,
      offset: Offset(0, 10),
    ),
  ];

  static const List<BoxShadow> xl = [
    BoxShadow(
      color: Color.fromRGBO(0, 0, 0, 0.1),
      blurRadius: 25,
      spreadRadius: -5,
      offset: Offset(0, 20),
    ),
  ];
}

// Avatar gradients (5 color schemes for member avatars)
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
}

// Convert hex string to Color
Color hexToColor(String hexString) {
  final buffer = StringBuffer();
  if (hexString.length == 7 && hexString.startsWith('#')) {
    buffer.write('ff');
  }
  buffer.write(hexString.replaceFirst('#', ''));
  return Color(int.parse(buffer.toString(), radix: 16));
}
