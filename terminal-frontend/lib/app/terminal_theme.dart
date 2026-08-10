import 'package:flutter/material.dart';

import 'package:clubbar_terminal/utils/design_tokens.dart';

/// The terminal's [ThemeData] (#301).
///
/// Before this, every visible control was hand-painted except where one
/// wasn't — a bare [ElevatedButton] (the partial-dispense confirm button)
/// rendered in default Material 3 seed colors that matched nothing else in
/// the app. Central component themes make a bare Material widget correct by
/// construction instead of relying on every call site remembering to style
/// itself.
///
/// A function rather than a top-level constant: [AppFontSizes] steps are
/// mutable (a deployment overrides them from `config.json`), so the theme
/// has to be rebuilt after that happens rather than captured once at import
/// time.
ThemeData buildTerminalTheme() {
  return ThemeData(
    useMaterial3: true,
    colorScheme: ColorScheme.fromSeed(
      seedColor: const Color(0xFF3B82F6),
      brightness: Brightness.dark,
    ),
    elevatedButtonTheme: ElevatedButtonThemeData(
      style: ElevatedButton.styleFrom(
        // Strong blue: white on #3b82f6 is 3.7:1, below AA (#41).
        backgroundColor: hexToColor(AppColors.semanticPrimaryStrong),
        foregroundColor: Colors.white,
        padding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.xl,
          vertical: AppSpacing.md,
        ),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(AppBorderRadius.md),
        ),
        textStyle: TextStyle(
          fontSize: AppFontSizes.lg,
          fontWeight: FontWeight.w600,
        ),
      ),
    ),
    textButtonTheme: TextButtonThemeData(
      style: TextButton.styleFrom(
        foregroundColor: hexToColor(AppColors.textSecondary),
        textStyle: TextStyle(fontSize: AppFontSizes.lg),
      ),
    ),
    bottomSheetTheme: BottomSheetThemeData(
      backgroundColor: hexToColor(AppColors.bgCard),
      modalBackgroundColor: hexToColor(AppColors.bgCard),
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.only(
          topLeft: Radius.circular(AppBorderRadius.lg),
          topRight: Radius.circular(AppBorderRadius.lg),
        ),
      ),
    ),
    dialogTheme: DialogThemeData(
      backgroundColor: hexToColor(AppColors.bgCard),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(AppBorderRadius.lg),
      ),
    ),
  );
}
