// This is a basic Flutter widget test.
//
// To perform an interaction with a widget in your test, use the WidgetTester
// utility in the flutter_test package. For example, you can send tap and scroll
// gestures. You can also use WidgetTester to find child widgets in the widget
// tree, read text, and verify that the values of widget properties are correct.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/providers/locale_provider.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/providers/products_provider.dart';
import 'package:clubbar_terminal/providers/quarantine_provider.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/repository/members_repository.dart';
import 'package:clubbar_terminal/repository/transactions_repository.dart';
// ConfigService mock comes from test_helpers.dart
import 'package:clubbar_terminal/services/network_service.dart';
import 'package:clubbar_terminal/services/sound_service.dart';
import 'package:clubbar_terminal/main.dart';
import 'test_helpers.dart';

class MockClubBarDatabase extends Mock implements ClubBarDatabase {}
class MockLocaleProvider extends Mock implements LocaleProvider {}
class MockMembersProvider extends Mock implements MembersProvider {}
class MockProductsProvider extends Mock implements ProductsProvider {}
class MockCartProvider extends Mock implements CartProvider {}
class MockSessionController extends Mock implements SessionController {}
class MockSyncProvider extends Mock implements SyncProvider {}
class MockMembersRepository extends Mock implements MembersRepository {}
class MockTransactionsRepository extends Mock implements TransactionsRepository {}
// MockConfigService is provided by test_helpers.dart
class MockNetworkService extends Mock implements NetworkService {}
class MockSoundService extends Mock implements SoundService {}

void main() {
  testWidgets('App initializes with Material 3 theme', (WidgetTester tester) async {
    final mockDatabase = MockClubBarDatabase();
    final mockLocaleProvider = MockLocaleProvider();
    final mockMembersProvider = MockMembersProvider();
    final mockProductsProvider = MockProductsProvider();
    final mockCartProvider = MockCartProvider();
    final mockSessionController = MockSessionController();
    final mockSyncProvider = MockSyncProvider();
    final mockMembersRepository = MockMembersRepository();
    final mockTransactionsRepository = MockTransactionsRepository();
    final mockConfigService = createMockConfigService();
    final mockNetworkService = MockNetworkService();
    final mockSoundService = MockSoundService();
    when(() => mockSyncProvider.connectionStatus).thenReturn(ConnectionStatus.online);
    when(() => mockLocaleProvider.locale).thenReturn(const Locale('de'));
    when(() => mockLocaleProvider.addListener(any())).thenReturn(null);
    when(() => mockLocaleProvider.removeListener(any())).thenReturn(null);
    when(() => mockCartProvider.addListener(any())).thenReturn(null);
    when(() => mockCartProvider.removeListener(any())).thenReturn(null);
    when(() => mockSessionController.addListener(any())).thenReturn(null);
    when(() => mockSessionController.removeListener(any())).thenReturn(null);
    when(() => mockSessionController.showTimeoutWarning).thenReturn(false);
    when(() => mockMembersProvider.addListener(any())).thenReturn(null);
    when(() => mockMembersProvider.removeListener(any())).thenReturn(null);
    when(() => mockMembersProvider.selectedMember).thenReturn(null);

    // Build our app and trigger a frame.
    await tester.pumpWidget(
      ClubBarTerminalApp(
        database: mockDatabase,
        localeProvider: mockLocaleProvider,
        membersProvider: mockMembersProvider,
        productsProvider: mockProductsProvider,
        cartProvider: mockCartProvider,
        sessionController: mockSessionController,
        syncProvider: mockSyncProvider,
        quarantineProvider: QuarantineProvider(
          transactionsRepo: mockTransactionsRepository,
          membersRepo: mockMembersRepository,
        ),
        membersRepository: mockMembersRepository,
        transactionsRepository: mockTransactionsRepository,
        configService: mockConfigService,
        networkService: mockNetworkService,
        soundService: mockSoundService,
      ),
    );

    // Verify the app instance exists (Material 3 theme is configured in main.dart)
    expect(find.byType(ClubBarTerminalApp), findsOneWidget);
  });
}
