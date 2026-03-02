import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/config/app_router.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/providers/auth_provider.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/providers/products_provider.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/providers/rfid_provider.dart';

class MockAuthProvider extends Mock implements AuthProvider {}
class MockMembersProvider extends Mock implements MembersProvider {}
class MockProductsProvider extends Mock implements ProductsProvider {}
class MockCartProvider extends Mock implements CartProvider {}
class MockSyncProvider extends Mock implements SyncProvider {}
class MockRfidProvider extends Mock implements RfidProvider {}

void main() {
  group('App Navigation', () {
    late MockAuthProvider mockAuthProvider;
    late MockMembersProvider mockMembersProvider;
    late MockProductsProvider mockProductsProvider;
    late MockCartProvider mockCartProvider;
    late MockSyncProvider mockSyncProvider;
    late MockRfidProvider mockRfidProvider;

    setUp(() {
      mockAuthProvider = MockAuthProvider();
      mockMembersProvider = MockMembersProvider();
      mockProductsProvider = MockProductsProvider();
      mockCartProvider = MockCartProvider();
      mockSyncProvider = MockSyncProvider();
      mockRfidProvider = MockRfidProvider();

      // Setup default mock behaviors
      when(() => mockAuthProvider.isAuthenticated).thenReturn(true);
      when(() => mockAuthProvider.addListener(any())).thenReturn(null);
      when(() => mockAuthProvider.removeListener(any())).thenReturn(null);

      when(() => mockMembersProvider.selectedMember).thenReturn(null);
      when(() => mockMembersProvider.addListener(any())).thenReturn(null);
      when(() => mockMembersProvider.removeListener(any())).thenReturn(null);

      when(() => mockProductsProvider.categories).thenReturn([]);
      when(() => mockProductsProvider.products).thenReturn([]);
      when(() => mockProductsProvider.addListener(any())).thenReturn(null);
      when(() => mockProductsProvider.removeListener(any())).thenReturn(null);

      when(() => mockCartProvider.items).thenReturn([]);
      when(() => mockCartProvider.itemCount).thenReturn(0);
      when(() => mockCartProvider.addListener(any())).thenReturn(null);
      when(() => mockCartProvider.removeListener(any())).thenReturn(null);

      when(() => mockSyncProvider.isSyncing).thenReturn(false);
      when(() => mockSyncProvider.connectionStatus).thenReturn(ConnectionStatus.online);
      when(() => mockSyncProvider.addListener(any())).thenReturn(null);
      when(() => mockSyncProvider.removeListener(any())).thenReturn(null);

      when(() => mockRfidProvider.addListener(any())).thenReturn(null);
      when(() => mockRfidProvider.removeListener(any())).thenReturn(null);
      when(() => mockRfidProvider.isScanning).thenReturn(false);
    });

    testWidgets('app starts at idle screen', (WidgetTester tester) async {
      await tester.pumpWidget(
        MultiProvider(
          providers: [
            ChangeNotifierProvider<AuthProvider>.value(value: mockAuthProvider),
            ChangeNotifierProvider<MembersProvider>.value(value: mockMembersProvider),
            ChangeNotifierProvider<ProductsProvider>.value(value: mockProductsProvider),
            ChangeNotifierProvider<CartProvider>.value(value: mockCartProvider),
            ChangeNotifierProvider<SyncProvider>.value(value: mockSyncProvider),
            ChangeNotifierProvider<RfidProvider>.value(value: mockRfidProvider),
          ],
          child: Builder(
            builder: (context) => MaterialApp.router(
              routerConfig: createAppRouter(context),
              localizationsDelegates: const [
                AppLocalizations.delegate,
                GlobalMaterialLocalizations.delegate,
                GlobalWidgetsLocalizations.delegate,
                GlobalCupertinoLocalizations.delegate,
              ],
              supportedLocales: const [Locale('de'), Locale('en')],
              locale: const Locale('de'),
            ),
          ),
        ),
      );

      expect(find.text('Durstig?'), findsOneWidget);
    });
  });
}
