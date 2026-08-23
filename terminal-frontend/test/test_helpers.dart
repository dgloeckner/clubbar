import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/l10n/terminal_error_messages.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/models/credit_limit.dart';
import 'package:clubbar_terminal/services/config_service.dart';

class MockConfigService extends Mock implements ConfigService {}

/// The copy a member actually sees for [key], in [locale].
///
/// Assert against this rather than a copied-out literal: error wording is
/// expected to keep improving, and a test that hardcodes it turns every
/// rewrite into a false failure.
Future<String> errorCopy(
  TerminalErrorKey key, {
  String locale = 'de',
}) async {
  final l10n = await AppLocalizations.delegate.load(Locale(locale));
  return key.message(l10n);
}

/// Creates a default MockConfigService with safe defaults for tests.
MockConfigService createMockConfigService() {
  final mock = MockConfigService();
  when(() => mock.isConfigured).thenReturn(true);
  when(() => mock.demoMode).thenReturn(true);
  when(() => mock.apiUrl).thenReturn('http://localhost:8080');
  when(() => mock.apiToken).thenReturn('test-token');
  when(() => mock.terminalId).thenReturn('test-terminal');
  when(() => mock.dispenserEnabled).thenReturn(false);
  when(() => mock.dispenserBaseUrl).thenReturn(null);
  when(() => mock.dispenserApiKey).thenReturn(null);
  when(() => mock.soundsEnabled).thenReturn(false);
  when(() => mock.fullscreen).thenReturn(false);
  when(() => mock.seedTestData).thenReturn(false);
  when(() => mock.fontSizes).thenReturn(null);
  when(() => mock.displayName).thenReturn(ConfigService.defaultDisplayName);
  // The club's credit policy (ADR-0047). The shipped seed values, which are
  // what a terminal enforces before its first `/sync/config` poll — and what
  // every screen resolves a member's ceiling against.
  when(() => mock.creditLimitPolicy).thenReturn(CreditLimitPolicy.shipped);
  return mock;
}

/// Creates a MaterialApp with localization support for testing widgets
/// that require AppLocalizations. Automatically provides a ConfigService.
Widget createTestApp({
  required Widget child,
  Locale locale = const Locale('de'),
  ConfigService? configService,
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
    home: Provider<ConfigService>.value(
      value: configService ?? createMockConfigService(),
      child: child,
    ),
  );
}
