import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:go_router/go_router.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/repository/transactions_repository.dart';
import 'package:clubbar_terminal/screens/checkout_confirmation_screen.dart';

class MockCartProvider extends Mock implements CartProvider {}
class MockMembersProvider extends Mock implements MembersProvider {}
class MockSessionController extends Mock implements SessionController {}
class MockTransactionsRepository extends Mock implements TransactionsRepository {}

void main() {
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
      when(() => mockSessionController.endSession()).thenReturn(null);
      when(() => mockSessionController.addListener(any())).thenReturn(null);
      when(() => mockSessionController.removeListener(any())).thenReturn(null);

      // Setup cart provider mocks
      when(() => mockCartProvider.clearCart()).thenReturn(null);
      when(() => mockCartProvider.total).thenReturn(2500); // €25.00
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

    testWidgets('displays session ID', (WidgetTester tester) async {
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

      expect(find.text('sess-abc123'), findsOneWidget);
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

      // Initially shows 30 seconds
      expect(find.textContaining('Weiterleitung in 30'), findsOneWidget);

      // After 1 second, should show 29 seconds
      await tester.pump(const Duration(seconds: 1));
      expect(find.textContaining('Weiterleitung in 29'), findsOneWidget);

      // After another 1 second, should show 28 seconds
      await tester.pump(const Duration(seconds: 1));
      expect(find.textContaining('Weiterleitung in 28'), findsOneWidget);
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
