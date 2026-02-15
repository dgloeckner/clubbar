import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:go_router/go_router.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/l10n/app_localizations.dart';
import 'package:ruderbar_terminal/providers/cart_provider.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/screens/checkout_confirmation_screen.dart';

class MockCartProvider extends Mock implements CartProvider {}
class MockMembersProvider extends Mock implements MembersProvider {}

void main() {
  group('CheckoutConfirmationScreen', () {
    late MockCartProvider mockCartProvider;
    late MockMembersProvider mockMembersProvider;

    setUp(() {
      mockCartProvider = MockCartProvider();
      mockMembersProvider = MockMembersProvider();

      // Setup cart provider mocks
      when(() => mockCartProvider.clearCart()).thenReturn(null);
      when(() => mockCartProvider.total).thenReturn(2500); // €25.00
      when(() => mockCartProvider.addListener(any())).thenReturn(null);
      when(() => mockCartProvider.removeListener(any())).thenReturn(null);

      // Setup members provider mocks
      when(() => mockMembersProvider.clearSelectedMember()).thenReturn(null);
      when(() => mockMembersProvider.selectedMember).thenReturn(null);
      when(() => mockMembersProvider.addListener(any())).thenReturn(null);
      when(() => mockMembersProvider.removeListener(any())).thenReturn(null);
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
              ],
              child: const CheckoutConfirmationScreen(
                transactionId: 'txn-12345',
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
              ],
              child: const CheckoutConfirmationScreen(
                transactionId: 'txn-12345',
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

      expect(find.textContaining('Weiterleitung in'), findsOneWidget);
    });

    testWidgets('displays transaction ID', (WidgetTester tester) async {
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
              child: const CheckoutConfirmationScreen(
                transactionId: 'txn-abc123',
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

      expect(find.text('txn-abc123'), findsOneWidget);
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
              ],
              child: const CheckoutConfirmationScreen(
                transactionId: 'txn-12345',
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

      // Initially shows 3 seconds
      expect(find.textContaining('Weiterleitung in 3'), findsOneWidget);

      // After 1 second, should show 2 seconds
      await tester.pump(const Duration(seconds: 1));
      expect(find.textContaining('Weiterleitung in 2'), findsOneWidget);

      // After another 1 second, should show 1 second
      await tester.pump(const Duration(seconds: 1));
      expect(find.textContaining('Weiterleitung in 1'), findsOneWidget);
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
              ],
              child: const CheckoutConfirmationScreen(
                transactionId: 'txn-12345',
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

      // Wait for auto-navigation
      await tester.pump(const Duration(seconds: 3));

      // Verify clearCart was called
      verify(() => mockCartProvider.clearCart()).called(greaterThanOrEqualTo(1));
      verify(() => mockMembersProvider.clearSelectedMember()).called(greaterThanOrEqualTo(1));
    });
  });
}
