import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:ruderbar_terminal/l10n/app_localizations.dart';

/// Creates a MaterialApp with localization support for testing widgets
/// that require AppLocalizations
Widget createTestApp({
  required Widget child,
  Locale locale = const Locale('de'),
}) {
  return MaterialApp(
    locale: locale,
    localizationsDelegates: const [
      AppLocalizations.delegate,
      GlobalMaterialLocalizations.delegate,
      GlobalWidgetsLocalizations.delegate,
      GlobalCupertinoLocalizations.delegate,
    ],
    supportedLocales: const [
      Locale('de'),
      Locale('en'),
    ],
    home: child,
  );
}
