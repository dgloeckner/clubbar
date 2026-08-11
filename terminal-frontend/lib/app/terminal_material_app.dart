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

  @override
  void initState() {
    super.initState();
    _router = createAppRouter(
      membersProvider: context.read<MembersProvider>(),
      configService: widget.configService,
    );
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
