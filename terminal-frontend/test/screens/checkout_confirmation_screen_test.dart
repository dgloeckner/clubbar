import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:go_router/go_router.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/repository/transactions_repository.dart';
import 'package:clubbar_terminal/screens/checkout_confirmation_screen.dart';
import 'package:clubbar_terminal/services/members_service.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/widgets/styled_components/price_display.dart';
import 'package:clubbar_terminal/widgets/styled_components/secondary_button.dart';

class MockCartProvider extends Mock implements CartProvider {}
class MockMembersProvider extends Mock implements MembersProvider {}
class MockMembersService extends Mock implements MembersService {}
class FakeMembersCacheData extends Fake implements MembersCacheData {}
class MockSessionController extends Mock implements SessionController {}
class MockTransactionsRepository extends Mock implements TransactionsRepository {}

void main() {
  setUpAll(() => registerFallbackValue(FakeMembersCacheData()));

  group('CheckoutConfirmationScreen', () {
    late MockCartProvider mockCartProvider;
    late MockMembersProvider mockMembersProvider;
    late MockSessionController mockSessionController;
    late MockTransactionsRepository mockRepo;

    setUp(() {
      mockCartProvider = MockCartProvider();
      mockMembersProvider = MockMembersProvider();
      mockSessionController = MockSessionController();
      mockRepo = MockTransactionsRepository();

      // Session controller mocks (owns all session teardown, ADR-0027)
      when(() => mockSessionController.endSession()).thenReturn(true);
      when(() => mockSessionController.recordActivity()).thenReturn(null);
      when(() => mockSessionController.addListener(any())).thenReturn(null);
      when(() => mockSessionController.removeListener(any())).thenReturn(null);

      // Setup cart provider mocks
      when(() => mockCartProvider.clearCart()).thenReturn(null);
      // checkout() empties the cart before this screen mounts, so the billed
      // amount only survives on the provider.
      when(() => mockCartProvider.total).thenReturn(0);
      when(() => mockCartProvider.lastCheckoutTotalCents).thenReturn(2500);
      when(() => mockCartProvider.addListener(any())).thenReturn(null);
      when(() => mockCartProvider.removeListener(any())).thenReturn(null);

      // Setup members provider mocks
      when(() => mockMembersProvider.clearSelectedMember()).thenReturn(null);
      when(() => mockMembersProvider.selectedMember).thenReturn(null);
      when(() => mockMembersProvider.memberDeckel).thenReturn(0);
      when(() => mockMembersProvider.addListener(any())).thenReturn(null);
      when(() => mockMembersProvider.removeListener(any())).thenReturn(null);

      // Setup repository mocks — normal (non-partial) session by default
      when(() => mockRepo.getSessionTotal(any())).thenAnswer((_) async => 500);
      when(() => mockRepo.getSessionDispenserInfo(any())).thenAnswer((_) async => null);
    });

    testWidgets('keeps the receipt on the member it was issued to when the '
        'session is taken over (#26)', (WidgetTester tester) async {
      final anna = MembersCacheData(
        id: 'member-a',
        cardUid: '1',
        firstName: 'Anna',
        lastName: 'Member',
        preferredLanguage: 'de',
        isActive: 1,
        isSepaValid: 1,
        balanceCents: 0,
        updatedAt: '2025-02-01T10:00:00Z',
      );
      // A real provider: the point of the test is what happens when it
      // *notifies*, which a mocked addListener would swallow.
      final membersService = MockMembersService();
      when(() => membersService.getEffectiveBalance(any()))
          .thenAnswer((_) async => -1250);
      // Offline: the balance refresh finds nothing fresh, so the cached
      // member stands (#374).
      when(() => membersService.refreshBalance(any()))
          .thenAnswer((_) async => null);
      final membersProvider = MembersProvider(service: membersService);
      await membersProvider.setSelectedMember(anna);

      final router = GoRouter(
        initialLocation: '/',
        routes: [
          GoRoute(
            path: '/',
            builder: (context, state) => MultiProvider(
              providers: [
                ChangeNotifierProvider<CartProvider>.value(value: mockCartProvider),
                ChangeNotifierProvider<MembersProvider>.value(
                  value: membersProvider,
                ),
                ChangeNotifierProvider<SessionController>.value(
                  value: mockSessionController,
                ),
                Provider<TransactionsRepository>.value(value: mockRepo),
              ],
              child: const CheckoutConfirmationScreen(sessionId: 'sess-abc123'),
            ),
          ),
        ],
      );

      await tester.pumpWidget(
        MaterialApp.router(
          routerConfig: router,
          localizationsDelegates: const [
            AppLocalizations.delegate,
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          supportedLocales: const [Locale('de'), Locale('en')],
          locale: const Locale('de'),
        ),
      );
      await tester.pump();
      await tester.pump();
      expect(find.text('Anna Member'), findsOneWidget);
      expect(find.textContaining('Neues Guthaben: 12,50'), findsOneWidget);

      // A card tap on this screen ends Anna's session and starts the next
      // member's before the receipt fades out — the receipt must not repaint
      // with a cleared or foreign identity.
      membersProvider.clearSelectedMember();
      await tester.pump();

      expect(find.text('Anna Member'), findsOneWidget);
      expect(find.textContaining('Neues Guthaben: 12,50'), findsOneWidget);
    });

    testWidgets('displays success message', (WidgetTester tester) async {
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
                ChangeNotifierProvider<SessionController>.value(
                  value: mockSessionController,
                ),
                Provider<TransactionsRepository>.value(value: mockRepo),
              ],
              child: const CheckoutConfirmationScreen(
                sessionId: 'sess-abc123',
              ),
            ),
          ),
          GoRoute(
            path: '/idle',
            builder: (context, state) => const Scaffold(
              body: Center(child: Text('Idle')),
            ),
          ),
        ],
      );

      await tester.pumpWidget(
        MaterialApp.router(
          routerConfig: router,
          localizationsDelegates: const [
            AppLocalizations.delegate,
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          supportedLocales: const [Locale('de'), Locale('en')],
          locale: const Locale('de'),
        ),
      );

      // Let FutureBuilder resolve
      await tester.pump();
      await tester.pump();

      expect(find.text('Buchung erfolgreich!'), findsOneWidget);
      expect(find.byIcon(Icons.check_circle), findsOneWidget);
    });

    testWidgets('displays countdown message', (WidgetTester tester) async {
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
                ChangeNotifierProvider<SessionController>.value(
                  value: mockSessionController,
                ),
                Provider<TransactionsRepository>.value(value: mockRepo),
              ],
              child: const CheckoutConfirmationScreen(
                sessionId: 'sess-abc123',
              ),
            ),
          ),
          GoRoute(
            path: '/idle',
            builder: (context, state) => const Scaffold(
              body: Center(child: Text('Idle')),
            ),
          ),
        ],
      );

      await tester.pumpWidget(
        MaterialApp.router(
          routerConfig: router,
          localizationsDelegates: const [
            AppLocalizations.delegate,
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          supportedLocales: const [Locale('de'), Locale('en')],
          locale: const Locale('de'),
        ),
      );

      // Let FutureBuilder resolve
      await tester.pump();
      await tester.pump();

      expect(find.textContaining('Weiterleitung in'), findsOneWidget);
    });

    testWidgets('countdown decrements every second', (WidgetTester tester) async {
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
                ChangeNotifierProvider<SessionController>.value(
                  value: mockSessionController,
                ),
                Provider<TransactionsRepository>.value(value: mockRepo),
              ],
              child: const CheckoutConfirmationScreen(
                sessionId: 'sess-abc123',
              ),
            ),
          ),
          GoRoute(
            path: '/idle',
            builder: (context, state) => const Scaffold(
              body: Center(child: Text('Idle')),
            ),
          ),
        ],
      );

      await tester.pumpWidget(
        MaterialApp.router(
          routerConfig: router,
          localizationsDelegates: const [
            AppLocalizations.delegate,
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          supportedLocales: const [Locale('de'), Locale('en')],
          locale: const Locale('de'),
        ),
      );

      // Let FutureBuilder resolve
      await tester.pump();
      await tester.pump();
      // Trigger addPostFrameCallback for _startAutoNav
      await tester.pump();

      // Starts at the short dwell the queue can absorb (#25)
      expect(find.textContaining('Weiterleitung in 8'), findsOneWidget);

      await tester.pump(const Duration(seconds: 1));
      expect(find.textContaining('Weiterleitung in 7'), findsOneWidget);

      await tester.pump(const Duration(seconds: 1));
      expect(find.textContaining('Weiterleitung in 6'), findsOneWidget);
    });

    /// Pumps a normal (non-partial) receipt with `/idle` and `/products`
    /// destinations wired up, settled past the auto-nav post-frame callback.
    Future<void> pumpReceipt(WidgetTester tester) async {
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
                ChangeNotifierProvider<SessionController>.value(
                  value: mockSessionController,
                ),
                Provider<TransactionsRepository>.value(value: mockRepo),
              ],
              child: const CheckoutConfirmationScreen(sessionId: 'sess-abc123'),
            ),
          ),
          GoRoute(
            path: '/idle',
            builder: (context, state) => const Scaffold(
              body: Center(child: Text('Idle')),
            ),
          ),
          GoRoute(
            path: '/products',
            builder: (context, state) => const Scaffold(
              body: Center(child: Text('Products')),
            ),
          ),
        ],
      );

      await tester.pumpWidget(
        MaterialApp.router(
          routerConfig: router,
          localizationsDelegates: const [
            AppLocalizations.delegate,
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          supportedLocales: const [Locale('de'), Locale('en')],
          locale: const Locale('de'),
        ),
      );

      // Resolve the FutureBuilder, then run the auto-nav post-frame callback.
      await tester.pump();
      await tester.pump();
      await tester.pump();
    }

    testWidgets('never shows the raw session UUID (#25)',
        (WidgetTester tester) async {
      await pumpReceipt(tester);

      // A session reference is noise on a member-facing receipt.
      expect(find.text('sess-abc123'), findsNothing);
      // What the member actually came for is still there.
      expect(find.text('Buchung erfolgreich!'), findsOneWidget);
      expect(find.byType(PriceDisplay), findsOneWidget);
    });

    testWidgets('auto-returns to idle within 8 seconds (#25)',
        (WidgetTester tester) async {
      await pumpReceipt(tester);

      // Just short of the dwell: still on the receipt.
      await tester.pump(const Duration(seconds: 7));
      expect(find.text('Idle'), findsNothing);

      await tester.pump(const Duration(seconds: 1));
      await tester.pumpAndSettle();

      expect(find.text('Idle'), findsOneWidget);
      verify(() => mockSessionController.endSession()).called(1);
    });

    testWidgets('dismisses on a non-destructive "Fertig" button (#25)',
        (WidgetTester tester) async {
      await pumpReceipt(tester);

      // "Abmelden" on a success screen reads as "cancel my purchase".
      expect(find.text('Abmelden'), findsNothing);

      final done = find.widgetWithText(ElevatedButton, 'Fertig');
      expect(done, findsOneWidget);

      // Nothing red: red is this app's danger colour (semanticDanger).
      final style = tester.widget<ElevatedButton>(done).style!;
      final background = style.backgroundColor!.resolve({});
      expect(background, isNot(AppColors.semanticDanger));
      // The blue one step darker than semanticPrimary — a filled button
      // carrying white text needs it to clear WCAG AA (#41). Still blue, so
      // #25's point (nothing on this screen reads as "cancel") holds.
      expect(background, AppColors.semanticPrimaryStrong);

      await tester.tap(done);
      await tester.pumpAndSettle();

      verify(() => mockSessionController.endSession()).called(1);
      expect(find.text('Idle'), findsOneWidget);
    });

    testWidgets('stays put when the session refuses to end (ADR-0027 rule 7)',
        (WidgetTester tester) async {
      // A critical operation in flight makes endSession() refuse. Navigating
      // anyway lands on /idle with a member still selected, which the router
      // bounces straight back to /products — a session that was supposed to
      // be over, resumed.
      when(() => mockSessionController.endSession()).thenReturn(false);

      await pumpReceipt(tester);
      await tester.tap(find.widgetWithText(ElevatedButton, 'Fertig'));
      await tester.pumpAndSettle();

      expect(find.text('Idle'), findsNothing);
      expect(find.text('Buchung erfolgreich!'), findsOneWidget);
    });

    testWidgets('"Weiter einkaufen" resumes the session on /products (#25)',
        (WidgetTester tester) async {
      await pumpReceipt(tester);

      await tester.tap(find.text('Weiter einkaufen'));
      await tester.pumpAndSettle();

      expect(find.text('Products'), findsOneWidget);
      // The member keeps shopping — the session must survive.
      verifyNever(() => mockSessionController.endSession());
      verify(() => mockSessionController.recordActivity()).called(1);

      // The auto-return timer must not drag them off the product list.
      await tester.pump(const Duration(seconds: 30));
      expect(find.text('Products'), findsOneWidget);
      verifyNever(() => mockSessionController.endSession());
    });

    testWidgets(
        '"Weiter einkaufen" is an outlined button beside "Fertig", not '
        'muted text (#295)', (WidgetTester tester) async {
      await pumpReceipt(tester);

      // "Fertig" stays the only filled button — the outlined button beside
      // it must not read as equally (or more) weighted.
      expect(find.byType(ElevatedButton), findsOneWidget);

      final continueButton = find.byType(SecondaryButton);
      expect(continueButton, findsOneWidget);
      expect(
        find.descendant(
          of: continueButton,
          matching: find.text('Weiter einkaufen'),
        ),
        findsOneWidget,
      );

      // Tappable at kiosk-touch size, not a thin text link.
      final size = tester.getSize(continueButton);
      expect(size.height, greaterThanOrEqualTo(44));
    });

    /// Pumps the screen with a repository whose session lookup fails, so the
    /// receipt has to fall back (#16).
    Future<void> pumpFailingReceipt(WidgetTester tester) async {
      when(() => mockRepo.getSessionTotal(any()))
          .thenThrow(Exception('session gone'));

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
                ChangeNotifierProvider<SessionController>.value(
                  value: mockSessionController,
                ),
                Provider<TransactionsRepository>.value(value: mockRepo),
              ],
              child: const CheckoutConfirmationScreen(sessionId: 'sess-abc123'),
            ),
          ),
          GoRoute(
            path: '/idle',
            builder: (context, state) => const Scaffold(
              body: Center(child: Text('Idle')),
            ),
          ),
        ],
      );

      await tester.pumpWidget(
        MaterialApp.router(
          routerConfig: router,
          localizationsDelegates: const [
            AppLocalizations.delegate,
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          supportedLocales: const [Locale('de'), Locale('en')],
          locale: const Locale('de'),
        ),
      );

      // Let the FutureBuilder settle into its error state.
      await tester.pump();
      await tester.pump();
    }

    testWidgets('a failed session lookup still confirms the purchase (#16)',
        (WidgetTester tester) async {
      await pumpFailingReceipt(tester);

      // The purchase happened — say so, and explain what is missing.
      expect(find.text('Buchung erfolgreich!'), findsOneWidget);
      expect(find.byIcon(Icons.check_circle), findsOneWidget);
      expect(find.textContaining('Belegdaten'), findsOneWidget);
      // Never a bare spinner: that is indistinguishable from loading.
      expect(find.byType(CircularProgressIndicator), findsNothing);
    });

    testWidgets('the fallback receipt shows no raw session UUID either (#25)',
        (WidgetTester tester) async {
      await pumpFailingReceipt(tester);

      expect(find.text('sess-abc123'), findsNothing);
    });

    testWidgets('the fallback receipt falls back to the cart total (#16)',
        (WidgetTester tester) async {
      await pumpFailingReceipt(tester);

      // The cart is already empty here; the €25.00 comes from what checkout
      // recorded as billed.
      expect(find.textContaining('25,00'), findsOneWidget);
    });

    testWidgets('the fallback receipt omits an amount it does not have (#16)',
        (WidgetTester tester) async {
      when(() => mockCartProvider.lastCheckoutTotalCents).thenReturn(0);
      await pumpFailingReceipt(tester);

      // Still a receipt — just without a figure it cannot vouch for.
      expect(find.text('Buchung erfolgreich!'), findsOneWidget);
      expect(find.byType(PriceDisplay), findsNothing);
    });

    testWidgets('the fallback receipt never navigates on its own (#16)',
        (WidgetTester tester) async {
      await pumpFailingReceipt(tester);

      // Well past the 30s auto-nav the happy path uses.
      await tester.pump(const Duration(seconds: 60));

      expect(find.text('Idle'), findsNothing);
      expect(find.text('Buchung erfolgreich!'), findsOneWidget);
      verifyNever(() => mockSessionController.endSession());
    });

    testWidgets('the fallback receipt is dismissed by the user (#16)',
        (WidgetTester tester) async {
      await pumpFailingReceipt(tester);

      await tester.tap(find.text('Fertig'));
      await tester.pumpAndSettle();

      verify(() => mockSessionController.endSession()).called(1);
      expect(find.text('Idle'), findsOneWidget);
    });

    testWidgets(
        'the fallback receipt has no continue-shopping button (#16, #295)',
        (WidgetTester tester) async {
      await pumpFailingReceipt(tester);

      // Failing to read the receipt back is the wrong moment to invite more
      // spending — only "Fertig" is offered.
      expect(find.byType(SecondaryButton), findsNothing);
      expect(find.text('Weiter einkaufen'), findsNothing);
    });

    testWidgets('clears cart on navigation', (WidgetTester tester) async {
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
                ChangeNotifierProvider<SessionController>.value(
                  value: mockSessionController,
                ),
                Provider<TransactionsRepository>.value(value: mockRepo),
              ],
              child: const CheckoutConfirmationScreen(
                sessionId: 'sess-abc123',
              ),
            ),
          ),
          GoRoute(
            path: '/idle',
            builder: (context, state) => const Scaffold(
              body: Center(child: Text('Idle')),
            ),
          ),
        ],
      );

      await tester.pumpWidget(
        MaterialApp.router(
          routerConfig: router,
          localizationsDelegates: const [
            AppLocalizations.delegate,
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          supportedLocales: const [Locale('de'), Locale('en')],
          locale: const Locale('de'),
        ),
      );

      // Let FutureBuilder resolve
      await tester.pump();
      await tester.pump();
      // Trigger addPostFrameCallback for _startAutoNav
      await tester.pump();

      // Wait for auto-navigation (30 second countdown)
      await tester.pump(const Duration(seconds: 30));

      // Checkout completion ends the session (ADR-0027); endSession() owns
      // clearing the cart and the selected member.
      verify(() => mockSessionController.endSession())
          .called(greaterThanOrEqualTo(1));
    });
  });
}
