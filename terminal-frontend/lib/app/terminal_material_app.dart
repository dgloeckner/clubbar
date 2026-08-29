import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/app/terminal_theme.dart';
import 'package:clubbar_terminal/config/app_config.dart';
import 'package:clubbar_terminal/config/app_router.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/providers/locale_provider.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/services/display_power.dart';
import 'package:clubbar_terminal/utils/kiosk_touch.dart';
import 'package:clubbar_terminal/widgets/screen_blanker.dart';

/// The routed [MaterialApp] of the terminal.
///
/// Owns the [GoRouter] for the app's lifetime. It used to be built inside
/// [build], so switching the language mid-session — which notifies
/// [LocaleProvider] — handed [MaterialApp.router] a brand-new router starting
/// at `/idle`, dismounting the navigator and bouncing the member back to
/// `/products` with any open sheet gone (issue #33). Creating it once keeps
/// navigation state across locale changes; the locale itself still rebuilds
/// the widgets below.
class TerminalMaterialApp extends StatefulWidget {
  final ConfigService configService;
  final ScrollBehavior? scrollBehavior;

  const TerminalMaterialApp({
    super.key,
    required this.configService,
    this.scrollBehavior,
  });

  @override
  State<TerminalMaterialApp> createState() => _TerminalMaterialAppState();
}

class _TerminalMaterialAppState extends State<TerminalMaterialApp> {
  late final GoRouter _router;

  /// Null when the terminal only paints black rather than powering the panel
  /// down — see [ConfigService.screenBlankingPowersOutput].
  DisplayPower? _displayPower;

  @override
  void initState() {
    super.initState();
    _router = createAppRouter(
      membersProvider: context.read<MembersProvider>(),
      configService: widget.configService,
    );
    if (widget.configService.screenBlankingPowersOutput) {
      _displayPower = WlopmDisplayPower(
        output: widget.configService.screenBlankingOutput!,
      );
    }
  }

  @override
  void dispose() {
    _router.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final localeProvider = context.watch<LocaleProvider>();

    return MaterialApp.router(
      title: AppConfig.appName,
      scrollBehavior: widget.scrollBehavior,
      // Raise the touch slop for every route, dialog and sheet below. The
      // engine reports the platform's own slop through `gestureSettings`
      // (Linux desktop reports none, so recognizers fall back to the 18 px
      // `kTouchSlop`); overriding it here is what the drag recognizers of the
      // product grid and the cart list actually read.
      // ScreenBlanker sits outside the MediaQuery so it covers every route,
      // dialog and sheet the navigator below can put on screen — the blanked
      // terminal must be black whatever it was showing (#763).
      builder: (context, child) => ScreenBlanker(
        enabled: widget.configService.screenBlankingEnabled,
        timeout: widget.configService.screenBlankingTimeout,
        displayPower: _displayPower,
        child: MediaQuery(
          data: MediaQuery.of(context).copyWith(
            gestureSettings: const DeviceGestureSettings(
              touchSlop: kKioskTouchSlop,
            ),
          ),
          child: child ?? const SizedBox.shrink(),
        ),
      ),
      theme: buildTerminalTheme(),
      localizationsDelegates: const [
        AppLocalizations.delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      supportedLocales: LocaleProvider.supportedLocales,
      locale: localeProvider.locale,
      routerConfig: _router,
    );
  }
}
