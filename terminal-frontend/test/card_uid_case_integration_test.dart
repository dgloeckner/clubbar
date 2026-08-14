// Acceptance test for issue #18: a member gets in regardless of the case their
// card UID happens to arrive in.
//
// The production input path is a USB keyboard-wedge reader, and different
// readers type the same card as `abcd` or `ABCD`. The lookup is an exact string
// match, so before normalization a valid member was rejected with "Unbekannter
// Chip" purely because of which reader — or which backend seeding — was in use.
//
// This drives the whole chain for real: the keyboard capture in the app shell,
// the real RfidProvider/SessionController/MembersProvider, and a real
// MembersRepository over an in-memory database. Only leaf services are mocked,
// so nothing about the case handling is faked.
import 'package:drift/native.dart';
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
import 'package:clubbar_terminal/generated/terminal.swagger.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/providers/products_provider.dart';
import 'package:clubbar_terminal/providers/rfid_provider.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/repository/members_repository.dart';
import 'package:clubbar_terminal/repository/transactions_repository.dart';
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/services/members_service.dart';
import 'package:clubbar_terminal/services/sound_service.dart';
import 'test_helpers.dart';

class MockMembersService extends Mock implements MembersService {}

class MockTransactionsRepository extends Mock
    implements TransactionsRepository {}

class MockCartProvider extends Mock implements CartProvider {}

class MockProductsProvider extends Mock implements ProductsProvider {}

class MockSyncProvider extends Mock implements SyncProvider {}

class MockSoundService extends Mock implements SoundService {}

class FakeMembersCacheData extends Fake implements MembersCacheData {}

/// The key a reader presses for each character of a UID.
///
/// Keyed by the lower-case character: the physical key is the same either way,
/// and the case the reader actually emits is passed separately (see
/// [scanCard]).
const _keysByCharacter = {
  'a': LogicalKeyboardKey.keyA,
  'b': LogicalKeyboardKey.keyB,
  'c': LogicalKeyboardKey.keyC,
  'd': LogicalKeyboardKey.keyD,
  '1': LogicalKeyboardKey.digit1,
  '2': LogicalKeyboardKey.digit2,
};

Member _syncedMember(String id, String? cardUid, String firstName) => Member(
      id: id,
      cardUid: cardUid,
      firstName: firstName,
      lastName: 'Member',
      preferredLanguage: 'de',
      isActive: true,
      isSepaValid: true,
      createdAt: DateTime.parse('2025-02-01T10:00:00Z'),
      updatedAt: DateTime.parse('2025-02-01T10:00:00Z'),
    );

void main() {
  setUpAll(() {
    registerFallbackValue(FakeMembersCacheData());
    registerFallbackValue(SoundEvent.scanSuccess);
  });

  late ClubBarDatabase db;
  late MembersRepository membersRepository;
  late MembersProvider membersProvider;
  late SessionController sessionController;
  late RfidProvider rfidProvider;
  late MockCartProvider cartProvider;
  late GoRouter router;

  setUp(() async {
    db = ClubBarDatabase.forTesting(NativeDatabase.memory());
    membersRepository = MembersRepository(db);

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
    when(() => cartProvider.addListener(any())).thenReturn(null);
    when(() => cartProvider.removeListener(any())).thenReturn(null);

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

  tearDown(() async {
    sessionController.dispose();
    rfidProvider.dispose();
    await db.close();
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
        .thenAnswer((_) async => 0);
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

  /// Tap a card the way a keyboard-wedge reader does: type [uid], then Enter.
  ///
  /// The character is passed explicitly, because that — not the physical key —
  /// is what a reader varies: the same key press delivers `a` from one reader
  /// and `A` from the next, which is the whole of issue #18.
  Future<void> scanCard(WidgetTester tester, String uid) async {
    for (final character in uid.split('')) {
      await tester.sendKeyEvent(
        _keysByCharacter[character.toLowerCase()]!,
        character: character,
      );
    }
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

  group('issue #18: a card works whatever case it is typed in', () {
    // The three acceptance cases, each against a UID the backend sent in the
    // opposite case to the one the reader types.
    for (final scanned in ['abcd12', 'ABCD12', 'aBcD12']) {
      testWidgets('a "$scanned" scan starts the session of the member stored '
          'as "ABCD12"', (tester) async {
        await membersRepository
            .upsertMembers([_syncedMember('member-a', 'ABCD12', 'Anna')]);
        await pumpApp(tester);

        await scanCard(tester, scanned);

        expect(membersProvider.selectedMember?.id, 'member-a');
        expect(location(), '/products');
        await logout(tester);
      });
    }

    testWidgets('a member synced with a lower-case UID is reachable at all',
        (tester) async {
      // The other half of the bug: the reader was canonical, the database was
      // not. Normalizing on write is what makes this scan resolve.
      await membersRepository
          .upsertMembers([_syncedMember('member-b', 'abcd12', 'Ben')]);
      await pumpApp(tester);

      await scanCard(tester, 'ABCD12');

      expect(membersProvider.selectedMember?.id, 'member-b');
      expect(location(), '/products');
      await logout(tester);
    });

    testWidgets('a genuinely unknown card is still rejected', (tester) async {
      // Normalization must not turn the lookup into a match-anything.
      await membersRepository
          .upsertMembers([_syncedMember('member-a', 'ABCD12', 'Anna')]);
      await pumpApp(tester);

      await scanCard(tester, 'abcd21');

      expect(membersProvider.selectedMember, isNull);
      expect(location(), '/idle');
    });
  });
}
