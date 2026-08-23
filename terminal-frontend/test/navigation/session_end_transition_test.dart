// Regression test for issue #644: logging out must hand the terminal over to
// the welcome screen cleanly.
//
// A `context.go` replaces the navigator's page rather than popping it, so the
// screen being left is covered: its own `animation` stays at 1 and only its
// `secondaryAnimation` moves. A transition that fades on `animation` alone
// therefore kept the product screen fully lit underneath the welcome screen —
// which is transparent — for the whole 300 ms, while
// `SessionController.endSession` (which clears the member, the cart and the
// price freeze before the route changes) had it rebuilding into a memberless,
// jumped-up version of itself. That read as the product screen coming back on
// top of the welcome screen.
//
// So this pins the two halves of the fix: the screen being left is off the
// glass on the first frame, and the screen being entered is still animating in.
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'dart:convert';
import 'package:clubbar_terminal/config/app_router.dart';
import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/providers/products_provider.dart';
import 'package:clubbar_terminal/providers/rfid_provider.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/screens/idle_waiting_screen.dart';
import 'package:clubbar_terminal/screens/product_selection_screen.dart';
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/services/members_service.dart';
import 'package:clubbar_terminal/widgets/member_bar.dart';
import '../test_helpers.dart';

class MockMembersService extends Mock implements MembersService {}

class MockCartProvider extends Mock implements CartProvider {}

class MockProductsProvider extends Mock implements ProductsProvider {}

class MockSyncProvider extends Mock implements SyncProvider {}

class MockRfidProvider extends Mock implements RfidProvider {}

class FakeMembersCacheData extends Fake implements MembersCacheData {}

final _member = MembersCacheData(
  id: 'member-a',
  cardUid: '1',
  firstName: 'Anna',
  lastName: 'Member',
  // English, so a fallback to the default locale mid-transition would be
  // visible in the labels of the screen being left.
  preferredLanguage: 'en',
  isActive: 1,
  isSepaValid: 1,
  balanceCents: 0,
  updatedAt: '2025-02-01T10:00:00Z',
);

void main() {
  setUpAll(() {
    registerFallbackValue(FakeMembersCacheData());
  });

  late MembersProvider membersProvider;
  late SessionController sessionController;
  late GoRouter router;

  setUp(() {
    final membersService = MockMembersService();
    when(() => membersService.getEffectiveBalance(any()))
        .thenAnswer((_) async => 0);
    when(() => membersService.refreshBalance(any())).thenAnswer((_) async => null);

    membersProvider = MembersProvider(service: membersService);
    sessionController = SessionController(
      membersProvider: membersProvider,
      cartProvider: MockCartProvider(),
    );
  });

  tearDown(() => sessionController.dispose());

  /// Let pending futures, notifications and navigations land.
  ///
  /// `pumpAndSettle` is unusable here: the welcome screen's RFID button pulses
  /// forever, so the frame queue never drains.
  Future<void> flush(WidgetTester tester) async {
    for (var i = 0; i < 5; i++) {
      await tester.pump(const Duration(milliseconds: 100));
    }
  }

  Future<void> pumpApp(WidgetTester tester) async {
    final cartProvider = MockCartProvider();
    when(() => cartProvider.clearCart()).thenReturn(null);
    when(() => cartProvider.items).thenReturn([]);
    when(() => cartProvider.itemCount).thenReturn(0);
    when(() => cartProvider.total).thenReturn(0);
    when(() => cartProvider.isLoading).thenReturn(false);
    when(() => cartProvider.lastError).thenReturn(null);
    when(() => cartProvider.addListener(any())).thenReturn(null);
    when(() => cartProvider.removeListener(any())).thenReturn(null);

    // One category, so the product screen renders its member bar and grid
    // rather than the "no categories" placeholder.
    final productsProvider = MockProductsProvider();
    when(() => productsProvider.categories).thenReturn([
      CategoriesCacheData(
        id: 'cat-1',
        names: jsonEncode({'de': 'Bier', 'en': 'Beer'}),
        isActive: 1,
        iconName: null,
        updatedAt: '2025-02-01T10:00:00Z',
      ),
    ]);
    when(() => productsProvider.products).thenReturn([]);
    when(() => productsProvider.getVisibleProducts(any())).thenReturn([]);
    when(() => productsProvider.lastError).thenReturn(null);
    when(() => productsProvider.freezeForSession()).thenReturn(null);
    when(() => productsProvider.unfreezeForSession()).thenReturn(null);
    when(() => productsProvider.addListener(any())).thenReturn(null);
    when(() => productsProvider.removeListener(any())).thenReturn(null);

    final syncProvider = MockSyncProvider();
    when(() => syncProvider.isSyncing).thenReturn(false);
    when(() => syncProvider.pairingMismatch).thenReturn(false);
    when(() => syncProvider.credentialExpired).thenReturn(false);
    when(() => syncProvider.connectionStatus).thenReturn(ConnectionStatus.online);
    when(() => syncProvider.startBackgroundSync(
        intervalSeconds: any(named: 'intervalSeconds'))).thenReturn(null);
    when(() => syncProvider.addListener(any())).thenReturn(null);
    when(() => syncProvider.removeListener(any())).thenReturn(null);

    final rfidProvider = MockRfidProvider();
    when(() => rfidProvider.isScanning).thenReturn(false);
    when(() => rfidProvider.error).thenReturn(null);
    when(() => rfidProvider.hint).thenReturn(null);
    when(() => rfidProvider.addListener(any())).thenReturn(null);
    when(() => rfidProvider.removeListener(any())).thenReturn(null);

    await tester.pumpWidget(
      MultiProvider(
        providers: [
          ChangeNotifierProvider<MembersProvider>.value(value: membersProvider),
          ChangeNotifierProvider<SessionController>.value(
              value: sessionController),
          ChangeNotifierProvider<RfidProvider>.value(value: rfidProvider),
          ChangeNotifierProvider<CartProvider>.value(value: cartProvider),
          ChangeNotifierProvider<ProductsProvider>.value(value: productsProvider),
          ChangeNotifierProvider<SyncProvider>.value(value: syncProvider),
          Provider<ConfigService>.value(value: createMockConfigService()),
        ],
        child: Builder(
          builder: (context) {
            router = createAppRouter(
                membersProvider: context.read<MembersProvider>());
            return MaterialApp.router(
              routerConfig: router,
              localizationsDelegates: const [
                AppLocalizations.delegate,
                GlobalMaterialLocalizations.delegate,
                GlobalWidgetsLocalizations.delegate,
                GlobalCupertinoLocalizations.delegate,
              ],
              supportedLocales: const [Locale('de'), Locale('en')],
              locale: const Locale('de'),
            );
          },
        ),
      ),
    );
    await flush(tester);
  }

  /// How visible [screen] actually is right now: the product of every
  /// [FadeTransition] above it, which is what the route transition drives.
  /// Null when the screen is not mounted at all.
  double? paintedOpacityOf(WidgetTester tester, Type screen) {
    final screenFinder = find.byType(screen);
    if (screenFinder.evaluate().isEmpty) return null;
    final fades = tester.widgetList<FadeTransition>(
      find.ancestor(of: screenFinder, matching: find.byType(FadeTransition)),
    );
    return fades.fold<double>(1.0, (acc, fade) => acc * fade.opacity.value);
  }

  testWidgets('the product screen is off the glass before the welcome screen '
      'fades in (#644)', (tester) async {
    await pumpApp(tester);

    await sessionController.startSession(_member);
    await flush(tester);
    expect(find.byType(ProductSelectionScreen), findsOneWidget);
    expect(find.byType(MemberBar), findsOneWidget);

    await tester.tap(find.byKey(const Key('member-bar-logout')));
    await tester.pump(); // the frame the route change lands on
    await tester.pump(const Duration(milliseconds: 1));
    // The screen being left is gone — never seen half-transparent, and never
    // seen rebuilding without its member bar.
    expect(paintedOpacityOf(tester, ProductSelectionScreen), anyOf(isNull, 0.0),
        reason: 'the product screen must not still be painted during the '
            'hand-over to the welcome screen');

    // The screen being entered is on its way in, not snapped in: the fix is a
    // one-sided fade, not the removal of the animation.
    final welcomeOpacity = paintedOpacityOf(tester, IdleWaitingScreen);
    expect(welcomeOpacity, isNotNull);
    expect(welcomeOpacity, greaterThanOrEqualTo(0.0));
    expect(welcomeOpacity, lessThan(1.0),
        reason: 'the welcome screen should still be fading in');

    // ...and it gets all the way there.
    await flush(tester);
    expect(paintedOpacityOf(tester, IdleWaitingScreen), 1.0);
    expect(find.byType(ProductSelectionScreen), findsNothing);
  });
}
