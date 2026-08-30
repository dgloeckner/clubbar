// Screenshot harness for the status modal's System health section (#767).
//
// Not part of the test suite: it lives outside `test/` so `flutter test` never
// runs it, and it asserts nothing. Run it to regenerate the images:
//
//   flutter test tool/screenshots/status_health_screenshot_test.dart \
//     --update-goldens
//
// Goldens are the mechanism only because `--update-goldens` is the supported
// way to get a rendered frame onto disk.
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';

import 'package:clubbar_terminal/app/terminal_theme.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/services/system_health_probe.dart';
import 'package:clubbar_terminal/widgets/status_info_modal.dart';

class _MockSyncProvider extends Mock implements SyncProvider {}

class _MockConfigService extends Mock implements ConfigService {}

class _FixedProbe implements SystemHealthProbe {
  final SystemHealth health;
  const _FixedProbe(this.health);

  @override
  Future<SystemHealth> read() async => health;
}

/// The test renderer draws every glyph as a box unless real fonts are loaded.
Future<void> _loadFonts() async {
  final root = Platform.environment['FLUTTER_ROOT'];
  if (root == null) {
    throw StateError('FLUTTER_ROOT is unset — run this through `flutter test`.');
  }
  final fonts = '$root/bin/cache/artifacts/material_fonts';

  Future<ByteData> read(String path) async =>
      ByteData.view(Uint8List.fromList(await File(path).readAsBytes()).buffer);

  final roboto = FontLoader('Roboto')
    ..addFont(read('$fonts/Roboto-Regular.ttf'))
    ..addFont(read('$fonts/Roboto-Medium.ttf'))
    ..addFont(read('$fonts/Roboto-Bold.ttf'));
  await roboto.load();

  final icons = FontLoader('MaterialIcons')
    ..addFont(read('$fonts/MaterialIcons-Regular.otf'));
  await icons.load();

  // The modal renders URLs and the version badge in the generic `monospace`
  // family, which the test renderer has no face for.
  final mono = FontLoader('monospace')
    ..addFont(read('assets/fonts/JetBrainsMono-Medium.ttf'));
  await mono.load();
}

void main() {
  setUpAll(_loadFonts);

  Future<void> shoot(
    WidgetTester tester,
    String name,
    SystemHealth health,
  ) async {
    await tester.binding.setSurfaceSize(const Size(980, 620));
    tester.view.devicePixelRatio = 2.0;

    final sync = _MockSyncProvider();
    when(() => sync.addListener(any())).thenReturn(null);
    when(() => sync.removeListener(any())).thenReturn(null);
    when(() => sync.connectionStatus).thenReturn(ConnectionStatus.online);
    when(() => sync.lastSyncTime).thenReturn(DateTime(2026, 8, 30, 21, 14, 6));
    when(() => sync.lastSuccessfulTransactionSync)
        .thenReturn(DateTime(2026, 8, 30, 21, 13, 58));
    when(() => sync.retryCount).thenReturn(0);
    when(() => sync.lastError).thenReturn(null);
    when(() => sync.degradedSince).thenReturn(null);

    final config = _MockConfigService();
    when(() => config.apiUrl).thenReturn('https://bar.example.org/api');
    when(() => config.dispenserEnabled).thenReturn(false);
    when(() => config.dispenserBaseUrl).thenReturn(null);

    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('de'),
        theme: buildTerminalTheme().copyWith(
          textTheme: buildTerminalTheme().textTheme.apply(fontFamily: 'Roboto'),
        ),
        localizationsDelegates: const [
          AppLocalizations.delegate,
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        supportedLocales: const [Locale('de'), Locale('en')],
        home: MultiProvider(
          providers: [
            ChangeNotifierProvider<SyncProvider>.value(value: sync),
            Provider<ConfigService>.value(value: config),
          ],
          child: Builder(
            builder: (context) => Scaffold(
              backgroundColor: const Color(0xff0a1628),
              body: ElevatedButton(
                onPressed: () => showStatusInfoModal(
                  context,
                  systemHealthProbe: _FixedProbe(health),
                ),
                child: const Text('Open'),
              ),
            ),
          ),
        ),
      ),
    );

    await tester.tap(find.text('Open'));
    await tester.pumpAndSettle();

    await expectLater(find.byType(Dialog), matchesGoldenFile('out/$name.png'));

    // Close before the next scenario so the polling timer is disposed.
    await tester.tap(find.byIcon(Icons.close));
    await tester.pumpAndSettle();
  }

  testWidgets('normal', (t) => shoot(t, '01-normal',
      const SystemHealth(temperatureCelsius: 58.9, undervoltage: false)));

  testWidgets('warm', (t) => shoot(t, '02-warm',
      const SystemHealth(temperatureCelsius: 72.4, undervoltage: false)));

  testWidgets('throttling', (t) => shoot(t, '03-throttling',
      const SystemHealth(temperatureCelsius: 84.2, undervoltage: false)));

  testWidgets('undervoltage', (t) => shoot(t, '04-undervoltage',
      const SystemHealth(temperatureCelsius: 55.3, undervoltage: true)));

  testWidgets('both', (t) => shoot(t, '05-throttling-and-undervoltage',
      const SystemHealth(temperatureCelsius: 82.7, undervoltage: true)));
}
