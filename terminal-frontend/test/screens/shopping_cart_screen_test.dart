import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
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
      when(() => mockCartProvider.isLoading).thenReturn(false);
      when(() => mockCartProvider.lastError).thenReturn(null);
      when(() => mockCartProvider.removeItem(any())).thenReturn(null);
      when(() => mockCartProvider.updateQuantity(any(), any())).thenReturn(null);
      when(() => mockCartProvider.checkout(any()))
          .thenAnswer((_) async => null);
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

      expect(find.text('€11.00'), findsWidgets);
      expect(find.text('Total:'), findsOneWidget);
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

      expect(find.text('Proceed to Checkout'), findsOneWidget);
    });

    testWidgets('shows empty cart message when no items',
        (WidgetTester tester) async {
      when(() => mockCartProvider.items).thenReturn([]);
      when(() => mockCartProvider.total).thenReturn(0);

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
      expect(find.text('Proceed to Checkout'), findsNothing);
    });

    testWidgets('calls checkout when button pressed', (WidgetTester tester) async {
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

      await tester.tap(find.text('Proceed to Checkout'));
      await tester.pumpAndSettle();

      verify(() => mockCartProvider.checkout(any())).called(1);
    });

    testWidgets('displays error banner when error present',
        (WidgetTester tester) async {
      when(() => mockCartProvider.lastError).thenReturn('Payment failed');

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

      expect(find.text('Payment failed'), findsOneWidget);
    });
  });
}
