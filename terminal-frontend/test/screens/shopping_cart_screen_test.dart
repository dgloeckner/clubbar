import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/models/cart_item.dart';
import 'package:ruderbar_terminal/providers/cart_provider.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/screens/shopping_cart_screen.dart';

class MockCartProvider extends Mock implements CartProvider {}

class MockMembersProvider extends Mock implements MembersProvider {}

class FakeMembersCacheData extends Fake implements MembersCacheData {}

void main() {
  setUpAll(() {
    registerFallbackValue(FakeMembersCacheData());
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
      when(() => mockCartProvider.checkout(any()))
          .thenAnswer((_) async => null);
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
        updatedAt: '2025-02-01T10:00:00Z',
      );
      when(() => mockMembersProvider.selectedMember).thenReturn(testMember);
      when(() => mockMembersProvider.clearSelectedMember()).thenReturn(null);
      when(() => mockMembersProvider.addListener(any())).thenReturn(null);
      when(() => mockMembersProvider.removeListener(any())).thenReturn(null);
    });

    testWidgets('displays cart items', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: MultiProvider(
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
      );

      expect(find.byType(ListView), findsOneWidget);
      expect(find.text('Bier'), findsOneWidget);
    });

    testWidgets('displays total price formatted correctly',
        (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: MultiProvider(
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
      );

      // Total is 1100 cents = €11.00
      expect(find.text('€11.00'), findsWidgets);
      expect(find.text('Total'), findsOneWidget);
    });

    testWidgets('has checkout button', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: MultiProvider(
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
      );

      expect(find.text('Checkout'), findsOneWidget);
      expect(find.text('Cancel'), findsOneWidget);
    });

    testWidgets('shows empty cart message when no items',
        (WidgetTester tester) async {
      when(() => mockCartProvider.items).thenReturn([]);
      when(() => mockCartProvider.itemCount).thenReturn(0);

      await tester.pumpWidget(
        MaterialApp(
          home: MultiProvider(
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
      );

      expect(find.text('Your cart is empty'), findsOneWidget);
      expect(find.text('Checkout'), findsNothing);
      expect(find.text('Cancel'), findsNothing);
    });

    testWidgets('calls checkout when button pressed', (WidgetTester tester) async {
      // Setup checkout to return a transaction ID
      when(() => mockCartProvider.lastTransactionId).thenReturn('txn-123');

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

      await tester.pumpWidget(MaterialApp.router(routerConfig: router));

      await tester.tap(find.text('Checkout'));
      await tester.pumpAndSettle();

      // Verify checkout was called with the selected member
      verify(() => mockCartProvider.checkout(any())).called(1);
    });

    testWidgets('removes item when delete button tapped',
        (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: MultiProvider(
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
      );

      // Find the delete icon button
      await tester.tap(find.byIcon(Icons.delete_outline));
      await tester.pumpAndSettle();

      verify(() => mockCartProvider.removeItem('prod-1')).called(1);
    });

    testWidgets('displays price per unit', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: MultiProvider(
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
      );

      // Price is 550 cents = €5.50 per unit
      expect(find.text('€5.50 each'), findsOneWidget);
    });

    testWidgets('has plus and minus buttons for quantity',
        (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: MultiProvider(
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
      );

      // Check for plus and minus symbols
      expect(find.text('+'), findsOneWidget);
      expect(find.text('−'), findsOneWidget);
      // Check quantity is displayed
      expect(find.text('2'), findsOneWidget);
    });
  });
}
