import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/models/cart_item.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/screens/shopping_cart_screen.dart';

class MockCartProvider extends Mock implements CartProvider {}

class MockMembersProvider extends Mock implements MembersProvider {}

class FakeMembersCacheData extends Fake implements MembersCacheData {}

class FakeBuildContext extends Fake implements BuildContext {}

void main() {
  setUpAll(() {
    registerFallbackValue(FakeMembersCacheData());
    registerFallbackValue(FakeBuildContext());
  });
  group('ShoppingCartScreen', () {
    late MockCartProvider mockCartProvider;
    late MockMembersProvider mockMembersProvider;

    setUp(() {
      mockCartProvider = MockCartProvider();
      mockMembersProvider = MockMembersProvider();

      // Default mock setup
      when(() => mockCartProvider.items).thenReturn([
        CartItem(
          productId: 'prod-1',
          productName: 'Bier',
          quantity: 2,
          priceCents: 550,
          language: 'de',
        ),
      ]);
      when(() => mockCartProvider.total).thenReturn(1100);
      when(() => mockCartProvider.itemCount).thenReturn(2);
      when(() => mockCartProvider.isLoading).thenReturn(false);
      when(() => mockCartProvider.lastError).thenReturn(null);
      when(() => mockCartProvider.removeItem(any())).thenReturn(null);
      when(() => mockCartProvider.updateQuantity(any(), any())).thenReturn(null);
      when(() => mockCartProvider.checkout(any(), any(), any()))
          .thenAnswer((_) async {});
      when(() => mockCartProvider.lastTransactionId).thenReturn(null);
      when(() => mockCartProvider.addListener(any())).thenReturn(null);
      when(() => mockCartProvider.removeListener(any())).thenReturn(null);

      // Members provider setup
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
      when(() => mockMembersProvider.selectedMember).thenReturn(testMember);
      when(() => mockMembersProvider.memberDeckel).thenReturn(0);
      when(() => mockMembersProvider.sessionId).thenReturn('test-session-id');
      when(() => mockMembersProvider.clearSelectedMember()).thenReturn(null);
      when(() => mockMembersProvider.refreshDeckel()).thenAnswer((_) async {});
      when(() => mockMembersProvider.addListener(any())).thenReturn(null);
      when(() => mockMembersProvider.removeListener(any())).thenReturn(null);
    });

    Widget buildTestWidget({Widget? child}) {
      return MaterialApp(
        locale: const Locale('de'), // Explicitly set to German
        localizationsDelegates: const [
          AppLocalizations.delegate,
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        supportedLocales: const [Locale('en'), Locale('de')],
        home: MultiProvider(
          providers: [
            ChangeNotifierProvider<CartProvider>.value(value: mockCartProvider),
            ChangeNotifierProvider<MembersProvider>.value(
              value: mockMembersProvider,
            ),
          ],
          child: child ?? const Scaffold(body: ShoppingCartScreen()),
        ),
      );
    }

    testWidgets('displays cart items', (WidgetTester tester) async {
      await tester.pumpWidget(buildTestWidget());

      expect(find.byType(ListView), findsOneWidget);
      expect(find.text('Bier'), findsOneWidget);
    });

    testWidgets('displays total price formatted correctly',
        (WidgetTester tester) async {
      await tester.pumpWidget(buildTestWidget());

      // Total is 1100 cents = 11,00 € in German format
      expect(find.textContaining('11,00'), findsWidgets);
      expect(find.text('Gesamt'), findsOneWidget); // German "Total"
    });

    testWidgets('has checkout button', (WidgetTester tester) async {
      await tester.pumpWidget(buildTestWidget());

      expect(find.text('Bezahlen'), findsOneWidget);
    });

    testWidgets('shows empty cart message when no items',
        (WidgetTester tester) async {
      when(() => mockCartProvider.items).thenReturn([]);
      when(() => mockCartProvider.itemCount).thenReturn(0);

      await tester.pumpWidget(buildTestWidget());

      expect(find.text('Dein Warenkorb ist leer'), findsOneWidget);
      expect(find.text('Bezahlen'), findsNothing);
    });

    testWidgets('calls checkout when button pressed', (WidgetTester tester) async {
      // Setup checkout to return a session ID
      when(() => mockCartProvider.lastSessionId).thenReturn('test-session-id');

      final router = GoRouter(
        initialLocation: '/',
        routes: [
          GoRoute(
            path: '/',
            builder: (context, state) => MultiProvider(
              providers: [
                ChangeNotifierProvider<CartProvider>.value(value: mockCartProvider),
                ChangeNotifierProvider<MembersProvider>.value(
                  value: mockMembersProvider,
                ),
              ],
              child: const Scaffold(
                body: ShoppingCartScreen(),
              ),
            ),
          ),
          GoRoute(
            path: '/confirmation/:transactionId',
            builder: (context, state) => const Scaffold(
              body: Center(child: Text('Confirmation')),
            ),
          ),
        ],
      );

      await tester.pumpWidget(
        MaterialApp.router(
          routerConfig: router,
          locale: const Locale('de'),
          localizationsDelegates: const [
            AppLocalizations.delegate,
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          supportedLocales: const [Locale('en'), Locale('de')],
        ),
      );

      await tester.tap(find.text('Bezahlen'));
      await tester.pumpAndSettle();

      // Verify checkout was called with the context and selected member
      verify(() => mockCartProvider.checkout(any(), any(), any())).called(1);
    });

    testWidgets('removes item when delete button tapped',
        (WidgetTester tester) async {
      await tester.pumpWidget(buildTestWidget());

      // Find the delete icon button
      await tester.tap(find.byIcon(Icons.delete_outline));
      await tester.pumpAndSettle();

      verify(() => mockCartProvider.removeItem('prod-1')).called(1);
    });

    testWidgets('displays price per unit', (WidgetTester tester) async {
      await tester.pumpWidget(buildTestWidget());

      // Price is 550 cents = 5,50 € in German format
      expect(find.textContaining('5,50'), findsWidgets);
      expect(find.textContaining('pro Stück'), findsWidgets); // German "each"
    });

    testWidgets('has plus and minus buttons for quantity',
        (WidgetTester tester) async {
      await tester.pumpWidget(buildTestWidget());

      // Check for plus and minus symbols
      expect(find.text('+'), findsOneWidget);
      expect(find.text('−'), findsOneWidget);
      // Check quantity is displayed
      expect(find.text('2'), findsOneWidget);
    });

    testWidgets('checkout button gives press feedback via InkWell',
        (WidgetTester tester) async {
      await tester.pumpWidget(buildTestWidget());

      final inkWell = tester.widget<InkWell>(
        find.descendant(
          of: find.byKey(const Key('checkout-button')),
          matching: find.byType(InkWell),
        ),
      );
      expect(inkWell.onTap, isNotNull);
    });

    group('checkout in flight', () {
      setUp(() {
        when(() => mockCartProvider.isLoading).thenReturn(true);
      });

      testWidgets('checkout button shows spinner and processing label',
          (WidgetTester tester) async {
        await tester.pumpWidget(buildTestWidget());

        expect(find.text('Wird verarbeitet…'), findsOneWidget);
        expect(find.text('Bezahlen'), findsNothing);
        expect(find.byType(CircularProgressIndicator), findsOneWidget);
        expect(find.byIcon(Icons.check), findsNothing);
      });

      testWidgets('checkout button is disabled', (WidgetTester tester) async {
        await tester.pumpWidget(buildTestWidget());

        final inkWell = tester.widget<InkWell>(
          find.descendant(
            of: find.byKey(const Key('checkout-button')),
            matching: find.byType(InkWell),
          ),
        );
        expect(inkWell.onTap, isNull);
      });

      testWidgets('tapping the checkout button does not start a checkout',
          (WidgetTester tester) async {
        await tester.pumpWidget(buildTestWidget());

        await tester.tap(find.text('Wird verarbeitet…'), warnIfMissed: false);
        await tester.pump();

        verifyNever(() => mockCartProvider.checkout(any(), any(), any()));
      });

      testWidgets('cart item controls are frozen', (WidgetTester tester) async {
        await tester.pumpWidget(buildTestWidget());

        await tester.tap(find.byIcon(Icons.delete_outline),
            warnIfMissed: false);
        await tester.tap(find.text('+'), warnIfMissed: false);
        await tester.pump();

        verifyNever(() => mockCartProvider.removeItem(any()));
        verifyNever(() => mockCartProvider.updateQuantity(any(), any()));
      });
    });
  });
}
