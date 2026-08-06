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
