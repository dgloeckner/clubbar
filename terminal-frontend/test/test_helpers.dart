import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/l10n/terminal_error_messages.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/models/credit_limit.dart';
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/utils/card_uid.dart';

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
  // Screen blanking off by default (#763): a widget test must not have a
  // timer waiting to paint the whole app black underneath it.
  when(() => mock.screenBlankingEnabled).thenReturn(false);
  when(() => mock.screenBlankingTimeout)
      .thenReturn(const Duration(seconds: 300));
  when(() => mock.screenBlankingPowersOutput).thenReturn(false);
  when(() => mock.screenBlankingOutput).thenReturn(null);
  when(() => mock.seedTestData).thenReturn(false);
  when(() => mock.fontSizes).thenReturn(null);
  when(() => mock.displayName).thenReturn(ConfigService.defaultDisplayName);
  // The club's credit policy (ADR-0047). The shipped seed values, which are
  // what a terminal enforces before its first `/sync/config` poll — and what
  // every screen resolves a member's ceiling against.
  when(() => mock.creditLimitPolicy).thenReturn(CreditLimitPolicy.shipped);
  // How this terminal's reader spells a card UID. Hex is the stock profile and
  // what every reader shipped with a Club Bar terminal so far emits; a test
  // that cares about a decimal or byte-reversed reader overrides it.
  when(() => mock.rfidCardUidFormat).thenReturn(CardUidFormat.hex);
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

/// Load Roboto and the Material icon font from the Flutter SDK's cache, so a
/// test measures text the way the terminal renders it.
///
/// `flutter test` ships a placeholder font that draws every glyph one em
/// wide and one em tall. That is fine for "is the text there", and wrong for
/// any test about *how much room* text takes: at 21 px the credit-limit
/// banner's amounts line is ~1260 px wide in that font and ~700 in Roboto,
/// so a layout budget measured with it wraps a one-line banner onto three
/// and fails a layout the panel shows whole. Call from `setUpAll` in a file
/// whose assertions are about fit.
///
/// The fonts live under `bin/cache/artifacts/material_fonts`, a universal
/// artifact every `flutter` command fetches; the path is resolved from
/// `FLUTTER_ROOT`, which `flutter test` sets. Missing files fail loudly
/// rather than silently measuring with the wrong font.
Future<void> loadRealFonts() async {
  final root = Platform.environment['FLUTTER_ROOT'];
  if (root == null) {
    throw StateError('FLUTTER_ROOT is unset — run this through `flutter test`.');
  }
  final fonts = '$root/bin/cache/artifacts/material_fonts';

  Future<ByteData> read(String path) async {
    final file = File(path);
    if (!file.existsSync()) {
      throw StateError('$path is missing — the Flutter cache is incomplete.');
    }
    return ByteData.view(Uint8List.fromList(await file.readAsBytes()).buffer);
  }

  final roboto = FontLoader('Roboto')
    ..addFont(read('$fonts/Roboto-Regular.ttf'))
    ..addFont(read('$fonts/Roboto-Medium.ttf'))
    ..addFont(read('$fonts/Roboto-Bold.ttf'));
  await roboto.load();

  final icons = FontLoader('MaterialIcons')
    ..addFont(read('$fonts/MaterialIcons-Regular.otf'));
  await icons.load();
}
