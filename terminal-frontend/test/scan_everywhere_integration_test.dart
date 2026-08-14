// Acceptance test for issue #26: a card tap is read — and answered — on every
// route, not only on the idle screen.
//
// Drives the real router, the real SessionController/MembersProvider/
// RfidProvider and the real keyboard-wedge capture; only leaf services are
// mocked. The per-route policy under test is ADR-0027 amendment 2.
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/config/app_router.dart';
import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/providers/products_provider.dart';
import 'package:clubbar_terminal/providers/rfid_provider.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/repository/members_repository.dart';
import 'package:clubbar_terminal/repository/transactions_repository.dart';
import 'package:clubbar_terminal/services/members_service.dart';
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/services/sound_service.dart';
import 'test_helpers.dart';

class MockMembersService extends Mock implements MembersService {}

class MockMembersRepository extends Mock implements MembersRepository {}

class MockTransactionsRepository extends Mock
    implements TransactionsRepository {}

class MockCartProvider extends Mock implements CartProvider {}

class MockProductsProvider extends Mock implements ProductsProvider {}

class MockSyncProvider extends Mock implements SyncProvider {}

class MockSoundService extends Mock implements SoundService {}

class FakeMembersCacheData extends Fake implements MembersCacheData {}

MembersCacheData _member(String id, String cardUid, String firstName) =>
    MembersCacheData(
      id: id,
      cardUid: cardUid,
      firstName: firstName,
      lastName: 'Member',
      preferredLanguage: 'de',
      isActive: 1,
      isSepaValid: 1,
      balanceCents: 0,
      updatedAt: '2025-02-01T10:00:00Z',
    );

void main() {
  /// Resolved once so the assertions below track the ARB, not a copied literal.
  late String unknownCardCopy;

  setUpAll(() async {
    registerFallbackValue(FakeMembersCacheData());
    registerFallbackValue(SoundEvent.scanSuccess);
    unknownCardCopy = await errorCopy(TerminalErrorKey.unknownCard);
  });

  final memberA = _member('member-a', '1', 'Anna');
  final memberB = _member('member-b', '2', 'Ben');

  late MembersProvider membersProvider;
  late SessionController sessionController;
  late RfidProvider rfidProvider;
  late MockMembersRepository membersRepository;
  late MockCartProvider cartProvider;
  late GoRouter router;

  setUp(() {
    final membersService = MockMembersService();
    when(() => membersService.getEffectiveBalance(any()))
        .thenAnswer((_) async => 0);
    // Offline: the balance refresh finds nothing fresh, so the cached
    // member stands (#374).
    when(() => membersService.refreshBalance(any()))
        .thenAnswer((_) async => null);

    cartProvider = MockCartProvider();
    when(() => cartProvider.clearCart()).thenReturn(null);
    when(() => cartProvider.items).thenReturn([]);
    when(() => cartProvider.itemCount).thenReturn(0);
    when(() => cartProvider.lastCheckoutTotalCents).thenReturn(0);
    when(() => cartProvider.addListener(any())).thenReturn(null);
    when(() => cartProvider.removeListener(any())).thenReturn(null);

    membersRepository = MockMembersRepository();
    when(() => membersRepository.findByCardUid('1'))
        .thenAnswer((_) async => (memberA, null));
    when(() => membersRepository.findByCardUid('2'))
        .thenAnswer((_) async => (memberB, null));
    when(() => membersRepository.findByCardUid('9'))
        .thenAnswer((_) async => (null, TerminalErrorKey.unknownCard));

    final soundService = MockSoundService();
    when(() => soundService.play(any())).thenAnswer((_) async {});

    membersProvider = MembersProvider(service: membersService);
    sessionController = SessionController(
      membersProvider: membersProvider,
      cartProvider: cartProvider,
    );
    rfidProvider = RfidProvider(
      membersProvider,
      membersRepository,
      soundService,
      sessionController,
    );
  });

  tearDown(() {
    sessionController.dispose();
    rfidProvider.dispose();
  });

  /// Let pending futures, notifications and navigations land.
  ///
  /// `pumpAndSettle` is unusable here: the idle screen's RFID button pulses
  /// forever, so the frame queue never drains.
  Future<void> flush(WidgetTester tester) async {
    for (var i = 0; i < 5; i++) {
      await tester.pump(const Duration(milliseconds: 50));
    }
  }

  Future<void> pumpApp(WidgetTester tester) async {
    final productsProvider = MockProductsProvider();
    when(() => productsProvider.categories).thenReturn([]);
    when(() => productsProvider.products).thenReturn([]);
    when(() => productsProvider.addListener(any())).thenReturn(null);
    when(() => productsProvider.removeListener(any())).thenReturn(null);

    final syncProvider = MockSyncProvider();
    when(() => syncProvider.isSyncing).thenReturn(false);
    when(() => syncProvider.pairingMismatch).thenReturn(false);
    // #395: MainLayout renders CredentialExpiredBanner above every
    // screen, and an unstubbed non-nullable getter throws rather than
    // reading as false.
    when(() => syncProvider.credentialExpired).thenReturn(false);
    when(() => syncProvider.connectionStatus)
        .thenReturn(ConnectionStatus.online);
    when(() => syncProvider.startBackgroundSync(
        intervalSeconds: any(named: 'intervalSeconds'))).thenReturn(null);
    when(() => syncProvider.addListener(any())).thenReturn(null);
    when(() => syncProvider.removeListener(any())).thenReturn(null);

    final transactionsRepository = MockTransactionsRepository();
    when(() => transactionsRepository.getSessionTotal(any()))
        .thenAnswer((_) async => 350);
    when(() => transactionsRepository.getSessionDispenserInfo(any()))
        .thenAnswer((_) async => null);

    await tester.pumpWidget(
      MultiProvider(
        providers: [
          ChangeNotifierProvider<MembersProvider>.value(value: membersProvider),
          ChangeNotifierProvider<SessionController>.value(
              value: sessionController),
          ChangeNotifierProvider<RfidProvider>.value(value: rfidProvider),
          ChangeNotifierProvider<CartProvider>.value(value: cartProvider),
          ChangeNotifierProvider<ProductsProvider>.value(
              value: productsProvider),
          ChangeNotifierProvider<SyncProvider>.value(value: syncProvider),
          Provider<TransactionsRepository>.value(value: transactionsRepository),
          Provider<ConfigService>.value(value: createMockConfigService()),
        ],
        child: Builder(
          builder: (context) {
            router = createAppRouter(membersProvider: context.read<MembersProvider>());
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

  /// Tap a card the way the reader does it: type the UID, then Enter.
  Future<void> scanCard(WidgetTester tester, LogicalKeyboardKey digit) async {
    await tester.sendKeyEvent(digit);
    await tester.sendKeyEvent(LogicalKeyboardKey.enter);
    await flush(tester);
  }

  /// Leave no session behind: `testWidgets` fails a test that ends with a
  /// pending timer, and an active session holds the inactivity timer.
  Future<void> logout(WidgetTester tester) async {
    sessionController.endSession();
    await flush(tester);
  }

  String location() => router.routerDelegate.currentConfiguration.uri.path;

  group('issue #26: scanning works on every screen', () {
    testWidgets('a tap on the idle screen still starts a session',
        (tester) async {
      await pumpApp(tester);

      await scanCard(tester, LogicalKeyboardKey.digit1);

      expect(membersProvider.selectedMember?.id, 'member-a');
      expect(location(), '/products');
      await logout(tester);
    });

    testWidgets('rule 3: a foreign tap on the product screen is refused, and '
        'says so', (tester) async {
      await pumpApp(tester);
      await scanCard(tester, LogicalKeyboardKey.digit1);

      await scanCard(tester, LogicalKeyboardKey.digit2);

      expect(membersProvider.selectedMember?.id, 'member-a',
          reason: 'a foreign card never replaces an active session');
      expect(location(), '/products');
      expect(find.text('Bitte zuerst abmelden'), findsOneWidget);
      await logout(tester);
    });

    testWidgets('rule 4: the active member re-tapping is acknowledged, not '
        'ignored', (tester) async {
      await pumpApp(tester);
      await scanCard(tester, LogicalKeyboardKey.digit1);

      await scanCard(tester, LogicalKeyboardKey.digit1);

      expect(membersProvider.selectedMember?.id, 'member-a');
      expect(find.text('Du bist bereits angemeldet'), findsOneWidget);
      await logout(tester);
    });

    testWidgets('an unknown chip mid-session gets feedback instead of silence',
        (tester) async {
      await pumpApp(tester);
      await scanCard(tester, LogicalKeyboardKey.digit1);

      await scanCard(tester, LogicalKeyboardKey.digit9);

      expect(find.text(unknownCardCopy), findsOneWidget);
      expect(membersProvider.selectedMember?.id, 'member-a');
      await logout(tester);
    });

    testWidgets('rule 9: on the confirmation screen the next member\'s tap '
        'starts their session immediately', (tester) async {
      await pumpApp(tester);
      await scanCard(tester, LogicalKeyboardKey.digit1);
      router.go('/confirmation/session-1');
      await flush(tester);
      expect(location(), '/confirmation/session-1');

      await scanCard(tester, LogicalKeyboardKey.digit2);

      expect(membersProvider.selectedMember?.id, 'member-b');
      expect(location(), '/products');
      await logout(tester);
    });

    testWidgets('rule 9: the same member gets a fresh session — "one more '
        'round"', (tester) async {
      await pumpApp(tester);
      await scanCard(tester, LogicalKeyboardKey.digit1);
      router.go('/confirmation/session-1');
      await flush(tester);

      await scanCard(tester, LogicalKeyboardKey.digit1);

      expect(membersProvider.selectedMember?.id, 'member-a');
      expect(location(), '/products');
      // A takeover is a real session end followed by a real start, so the cart
      // is cleared — once at the first scan, once at the takeover.
      verify(() => cartProvider.clearCart()).called(greaterThanOrEqualTo(2));
      await logout(tester);
    });

    testWidgets('rule 9: an invalid chip leaves the receipt on screen',
        (tester) async {
      await pumpApp(tester);
      await scanCard(tester, LogicalKeyboardKey.digit1);
      router.go('/confirmation/session-1');
      await flush(tester);

      await scanCard(tester, LogicalKeyboardKey.digit9);

      expect(location(), '/confirmation/session-1');
      expect(membersProvider.selectedMember?.id, 'member-a');
      expect(find.text(unknownCardCopy), findsOneWidget);
      await logout(tester);
    });

    testWidgets('rule 7: every scan is refused while a checkout is in flight',
        (tester) async {
      await pumpApp(tester);
      await scanCard(tester, LogicalKeyboardKey.digit1);
      sessionController.beginCriticalOperation();
      await flush(tester);

      await scanCard(tester, LogicalKeyboardKey.digit2);

      expect(membersProvider.selectedMember?.id, 'member-a');
      expect(find.text('Bitte warten – Bezahlung läuft'), findsOneWidget);
      // Nothing is queued: the scan is gone, not replayed after the operation.
      sessionController.endCriticalOperation();
      await flush(tester);
      expect(membersProvider.selectedMember?.id, 'member-a');
      await logout(tester);
    });
  });
}
