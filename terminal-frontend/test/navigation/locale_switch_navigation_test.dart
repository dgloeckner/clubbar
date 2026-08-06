import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/app/terminal_material_app.dart';
import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/models/cart_item.dart';
import 'package:clubbar_terminal/providers/auth_provider.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/providers/locale_provider.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/providers/products_provider.dart';
import 'package:clubbar_terminal/providers/rfid_provider.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/services/sound_service.dart';
import 'package:clubbar_terminal/services/network_service.dart';
import 'package:clubbar_terminal/widgets/main_layout.dart';
import 'package:clubbar_terminal/widgets/member_details_modal.dart';
import '../test_helpers.dart';

class MockAuthProvider extends Mock implements AuthProvider {}

class MockMembersProvider extends Mock implements MembersProvider {}

class MockProductsProvider extends Mock implements ProductsProvider {}

class MockCartProvider extends Mock implements CartProvider {}

class MockSyncProvider extends Mock implements SyncProvider {}

class MockRfidProvider extends Mock implements RfidProvider {}

class MockSessionController extends Mock implements SessionController {}

class MockSoundService extends Mock implements SoundService {}

class MockNetworkService extends Mock implements NetworkService {}

class MockDatabase extends Mock implements ClubBarDatabase {}

void main() {
  late MockAuthProvider authProvider;
  late MockMembersProvider membersProvider;
  late MockProductsProvider productsProvider;
  late MockCartProvider cartProvider;
  late MockSyncProvider syncProvider;
  late MockRfidProvider rfidProvider;
  late MockSessionController sessionController;
  late MockSoundService soundService;

  final testMember = MembersCacheData(
    id: 'member-1',
    cardUid: 'card-123',
    firstName: 'John',
    lastName: 'Doe',
    preferredLanguage: 'de',
    isActive: 1,
    isSepaValid: 1,
    balanceCents: 0,
    updatedAt: '2025-02-01T10:00:00Z',
  );

  setUp(() {
    authProvider = MockAuthProvider();
    membersProvider = MockMembersProvider();
    productsProvider = MockProductsProvider();
    cartProvider = MockCartProvider();
    syncProvider = MockSyncProvider();
    rfidProvider = MockRfidProvider();
    sessionController = MockSessionController();
    soundService = MockSoundService();

    when(() => authProvider.isAuthenticated).thenReturn(true);
    when(() => authProvider.addListener(any())).thenReturn(null);
    when(() => authProvider.removeListener(any())).thenReturn(null);

    when(() => membersProvider.selectedMember).thenReturn(testMember);
    when(() => membersProvider.memberDeckel).thenReturn(0);
    when(() => membersProvider.sessionId).thenReturn('session-1');
    when(() => membersProvider.isLoading).thenReturn(false);
    when(() => membersProvider.lastError).thenReturn(null);
    when(() => membersProvider.addListener(any())).thenReturn(null);
    when(() => membersProvider.removeListener(any())).thenReturn(null);

    when(() => productsProvider.categories).thenReturn([]);
    when(() => productsProvider.products).thenReturn([]);
    when(() => productsProvider.lastError).thenReturn(null);
    when(() => productsProvider.addListener(any())).thenReturn(null);
    when(() => productsProvider.removeListener(any())).thenReturn(null);

    when(() => cartProvider.items).thenReturn([
      CartItem(
        productId: 'prod-1',
        productName: 'Bier',
        quantity: 2,
        priceCents: 550,
        language: 'de',
      ),
    ]);
    when(() => cartProvider.total).thenReturn(1100);
    when(() => cartProvider.itemCount).thenReturn(2);
    when(() => cartProvider.isLoading).thenReturn(false);
    when(() => cartProvider.lastError).thenReturn(null);
    when(() => cartProvider.lastTransactionId).thenReturn(null);
    when(() => cartProvider.addListener(any())).thenReturn(null);
    when(() => cartProvider.removeListener(any())).thenReturn(null);

    when(() => syncProvider.isSyncing).thenReturn(false);
    when(() => syncProvider.connectionStatus).thenReturn(ConnectionStatus.online);
    when(() => syncProvider.addListener(any())).thenReturn(null);
    when(() => syncProvider.removeListener(any())).thenReturn(null);

    when(() => rfidProvider.isScanning).thenReturn(false);
    when(() => rfidProvider.addListener(any())).thenReturn(null);
    when(() => rfidProvider.removeListener(any())).thenReturn(null);

    when(() => sessionController.showTimeoutWarning).thenReturn(false);
    when(() => sessionController.hasActiveSession).thenReturn(true);
    when(() => sessionController.isCriticalOperationInFlight).thenReturn(false);
    when(() => sessionController.addListener(any())).thenReturn(null);
    when(() => sessionController.removeListener(any())).thenReturn(null);
  });

  Widget buildApp(LocaleProvider localeProvider) {
    return MultiProvider(
      providers: [
        ChangeNotifierProvider<LocaleProvider>.value(value: localeProvider),
        ChangeNotifierProvider<AuthProvider>.value(value: authProvider),
        ChangeNotifierProvider<MembersProvider>.value(value: membersProvider),
        ChangeNotifierProvider<ProductsProvider>.value(value: productsProvider),
        ChangeNotifierProvider<CartProvider>.value(value: cartProvider),
        ChangeNotifierProvider<SyncProvider>.value(value: syncProvider),
        ChangeNotifierProvider<RfidProvider>.value(value: rfidProvider),
        ChangeNotifierProvider<SessionController>.value(value: sessionController),
        Provider<SoundService>.value(value: soundService),
        Provider<NetworkService>.value(value: MockNetworkService()),
        Provider<ClubBarDatabase>.value(value: MockDatabase()),
      ],
      child: TerminalMaterialApp(configService: createMockConfigService()),
    );
  }

  String currentLocation(WidgetTester tester) {
    final context = tester.element(find.byType(MainLayout));
    return GoRouter.of(context).routerDelegate.currentConfiguration.uri.path;
  }

  group('Language switch (issue #33)', () {
    testWidgets('keeps the member on the screen they were on', (tester) async {
      final localeProvider = LocaleProvider();

      await tester.pumpWidget(buildApp(localeProvider));
      await tester.pumpAndSettle();

      GoRouter.of(tester.element(find.byType(MainLayout))).go('/cart');
      await tester.pumpAndSettle();
      expect(currentLocation(tester), '/cart');

      localeProvider.setLocaleFromMember('en');
      await tester.pumpAndSettle();

      expect(currentLocation(tester), '/cart');
    });

    testWidgets('leaves an open member details sheet in place', (tester) async {
      final localeProvider = LocaleProvider();

      await tester.pumpWidget(buildApp(localeProvider));
      await tester.pumpAndSettle();

      showMemberDetailsModal(tester.element(find.byType(MainLayout)));
      await tester.pumpAndSettle();
      expect(find.byType(MemberDetailsModal), findsOneWidget);

      localeProvider.setLocaleFromMember('en');
      await tester.pumpAndSettle();

      expect(find.byType(MemberDetailsModal), findsOneWidget);
    });

    testWidgets('applies the new language to the UI', (tester) async {
      final localeProvider = LocaleProvider();

      await tester.pumpWidget(buildApp(localeProvider));
      await tester.pumpAndSettle();

      expect(Localizations.localeOf(tester.element(find.byType(MainLayout))),
          const Locale('de'));

      localeProvider.setLocaleFromMember('en');
      await tester.pumpAndSettle();

      expect(Localizations.localeOf(tester.element(find.byType(MainLayout))),
          const Locale('en'));
    });
  });
}
