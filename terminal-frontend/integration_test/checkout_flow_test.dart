import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';
import 'package:provider/provider.dart';

import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/models/cart_item.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/providers/rfid_provider.dart';
import 'package:clubbar_terminal/repository/transactions_repository.dart';
import 'package:clubbar_terminal/screens/checkout_confirmation_screen.dart';
import 'package:clubbar_terminal/screens/idle_waiting_screen.dart';
import 'package:clubbar_terminal/screens/product_selection_screen.dart';
import 'package:clubbar_terminal/screens/shopping_cart_screen.dart';
import 'package:clubbar_terminal/services/cart_service.dart';
import 'package:clubbar_terminal/widgets/styled_components/product_card.dart';

import 'test_helpers.dart';

/// A [CartService] that holds [createTransaction] open for [delay], modelling
/// the slow DB / dispenser window in which a member can tap "Bezahlen" again.
class SlowCartService extends CartService {
  SlowCartService({
    required super.database,
    required super.repository,
    required this.delay,
  });

  final Duration delay;

  /// How often the app asked to persist the cart. Exactly one call per
  /// completed checkout is expected, no matter how often the button is tapped.
  int createTransactionCalls = 0;

  @override
  Future<(String?, TerminalErrorKey?)> createTransaction(
    MembersCacheData member,
    List<CartItem> items, {
    required String sessionId,
  }) async {
    createTransactionCalls++;
    await Future<void>.delayed(delay);
    return super.createTransaction(member, items, sessionId: sessionId);
  }
}

/// Pump multiple frames to allow async rebuilds, navigation, and fade
/// transitions to complete.  We cannot use [pumpAndSettle] because
/// [ClubBarHeader] runs a periodic 1-second Timer that prevents settling.
Future<void> pumpFrames(WidgetTester tester, {int count = 15}) async {
  for (int i = 0; i < count; i++) {
    await tester.pump(const Duration(milliseconds: 100));
  }
}

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  group('Checkout flow', () {
    late ClubBarDatabase db;

    setUp(() async {
      db = await createTestDatabase();
    });

    tearDown(() async {
      await db.close();
    });

    testWidgets('App launches and shows idle scan screen', (tester) async {
      final app = await buildTestApp(db);
      await tester.pumpWidget(app);
      await tester.pump(const Duration(milliseconds: 500));

      // The idle screen should be displayed (initial route is /idle)
      expect(find.byType(IdleWaitingScreen), findsOneWidget);
    });

    testWidgets('Member identification via RFID navigates to product selection',
        (tester) async {
      final app = await buildTestApp(db);
      await tester.pumpWidget(app);
      await tester.pump(const Duration(milliseconds: 500));

      // Verify we start on the idle screen
      expect(find.byType(IdleWaitingScreen), findsOneWidget);

      // Simulate RFID card scan by calling RfidProvider.handleCardScan
      // with the test member's card UID (seeded as 'test-card-001').
      // startListening sets _context so handleCardScan can navigate.
      final context = tester.element(find.byType(IdleWaitingScreen));
      final rfidProvider = context.read<RfidProvider>();
      rfidProvider.startListening(context);
      await rfidProvider.handleCardScan('test-card-001');
      await pumpFrames(tester);

      // After successful scan, the router should redirect to /products
      // because MembersProvider.selectedMember is now non-null
      expect(find.byType(ProductSelectionScreen), findsOneWidget);

      // Verify the member name appears in the member bar
      // MemberBar displays "$firstName $lastName" as a single Text widget
      expect(find.text('Test Member'), findsOneWidget);

      rfidProvider.stopListening();
    });

    testWidgets('Product selection adds item to cart with correct price',
        (tester) async {
      final app = await buildTestApp(db);
      await tester.pumpWidget(app);
      await tester.pump(const Duration(milliseconds: 500));

      // First, identify a member to reach the product selection screen
      final idleContext = tester.element(find.byType(IdleWaitingScreen));
      final rfidProvider = idleContext.read<RfidProvider>();
      rfidProvider.startListening(idleContext);
      await rfidProvider.handleCardScan('test-card-001');
      await pumpFrames(tester);

      // We should now be on the product selection screen
      expect(find.byType(ProductSelectionScreen), findsOneWidget);

      // Verify products are displayed as ProductCard widgets
      // Test data has 2 products: "Pils 0,5l" (350 cents) and "Wasser 0,33l" (150 cents)
      // Member preferred language is 'de', so German names are shown
      expect(find.byType(ProductCard), findsNWidgets(2));
      expect(find.text('Pils 0,5l'), findsOneWidget);
      expect(find.text('Wasser 0,33l'), findsOneWidget);

      // Verify prices are displayed (German format: "3,50 \u20AC" and "1,50 \u20AC")
      expect(find.textContaining('3,50'), findsOneWidget);
      expect(find.textContaining('1,50'), findsOneWidget);

      // Tap on the "Pils 0,5l" product card to add it to cart
      await tester.tap(find.text('Pils 0,5l'));
      await pumpFrames(tester, count: 5);

      // After tapping, the product card should show a quantity badge "1x"
      expect(find.text('1x'), findsOneWidget);

      // Verify the cart provider reflects the added item
      final productContext = tester.element(find.byType(ProductSelectionScreen));
      final cartProvider = productContext.read<CartProvider>();
      expect(cartProvider.itemCount, equals(1));
      expect(cartProvider.total, equals(350));

      // Tap the same product again to increase quantity
      await tester.tap(find.text('Pils 0,5l'));
      await pumpFrames(tester, count: 5);

      // Quantity badge should now show "2x"
      expect(find.text('2x'), findsOneWidget);
      expect(cartProvider.itemCount, equals(2));
      expect(cartProvider.total, equals(700));

      rfidProvider.stopListening();
    });
  });

  group('Checkout double-tap (UC-T11)', () {
    late ClubBarDatabase db;
    late SlowCartService cartService;

    setUp(() async {
      db = await createTestDatabase();
      cartService = SlowCartService(
        database: db,
        repository: TransactionsRepository(db),
        delay: const Duration(seconds: 2),
      );
    });

    tearDown(() async {
      await db.close();
    });

    /// Reads every persisted transaction straight from the terminal database.
    Future<List<TransactionsLocalData>> persistedTransactions() =>
        db.select(db.transactionsLocal).get();

    /// Drives the app from the idle screen to a cart holding one "Pils 0,5l".
    Future<RfidProvider> fillCartAndOpenCartScreen(WidgetTester tester) async {
      final app = await buildTestApp(db, cartService: cartService);
      await tester.pumpWidget(app);
      await tester.pump(const Duration(milliseconds: 500));

      expect(find.byType(IdleWaitingScreen), findsOneWidget);

      // Identify the member via RFID
      final idleContext = tester.element(find.byType(IdleWaitingScreen));
      final rfidProvider = idleContext.read<RfidProvider>();
      rfidProvider.startListening(idleContext);
      await rfidProvider.handleCardScan('test-card-001');
      await pumpFrames(tester);

      expect(find.byType(ProductSelectionScreen), findsOneWidget);

      // Add one "Pils 0,5l" (350 cents) to the cart
      await tester.tap(find.text('Pils 0,5l'));
      await pumpFrames(tester, count: 5);

      // Open the cart via the member bar's cart button
      await tester.tap(find.byIcon(Icons.shopping_cart_outlined));
      await pumpFrames(tester);

      expect(find.byType(ShoppingCartScreen), findsOneWidget);
      expect(find.text('Bezahlen'), findsOneWidget);

      return rfidProvider;
    }

    testWidgets(
        'two taps in the same frame create exactly one persisted transaction',
        (tester) async {
      final rfidProvider = await fillCartAndOpenCartScreen(tester);

      expect(await persistedTransactions(), isEmpty);

      // Two taps with no frame in between: the button has not had a chance to
      // repaint as disabled, so both taps reach the handler. Only the
      // provider-level re-entrancy guard can prevent the double charge here.
      await tester.tap(find.text('Bezahlen'));
      await tester.tap(find.text('Bezahlen'), warnIfMissed: false);

      // Let the slow checkout finish
      await pumpFrames(tester, count: 40);

      expect(cartService.createTransactionCalls, equals(1));

      final transactions = await persistedTransactions();
      expect(transactions, hasLength(1));
      expect(transactions.single.productId, equals('int-prod-1'));
      expect(transactions.single.amountCents, equals(350));
      expect(transactions.single.memberId, equals('int-member-1'));

      rfidProvider.stopListening();
    });

    testWidgets(
        'tap while checkout is in flight is ignored and button shows progress',
        (tester) async {
      final rfidProvider = await fillCartAndOpenCartScreen(tester);

      // First tap starts the 2s checkout
      await tester.tap(find.text('Bezahlen'));
      await tester.pump(const Duration(milliseconds: 100));

      // The button must now visibly be in its loading state
      expect(find.text('Wird verarbeitet…'), findsOneWidget);
      expect(find.text('Bezahlen'), findsNothing);
      expect(find.byType(CircularProgressIndicator), findsWidgets);

      // Second tap mid-flight — the button is disabled, so nothing happens
      await tester.tap(find.text('Wird verarbeitet…'), warnIfMissed: false);
      await tester.pump(const Duration(milliseconds: 100));

      expect(cartService.createTransactionCalls, equals(1));

      // Let the checkout finish and the app navigate to the confirmation
      await pumpFrames(tester, count: 40);

      expect(cartService.createTransactionCalls, equals(1));
      expect(await persistedTransactions(), hasLength(1));
      expect(find.byType(CheckoutConfirmationScreen), findsOneWidget);

      rfidProvider.stopListening();
    });

    testWidgets('cart cannot be edited while the checkout is in flight',
        (tester) async {
      final rfidProvider = await fillCartAndOpenCartScreen(tester);

      final cartContext = tester.element(find.byType(ShoppingCartScreen));
      final cartProvider = cartContext.read<CartProvider>();
      expect(cartProvider.itemCount, equals(1));

      await tester.tap(find.text('Bezahlen'));
      await tester.pump(const Duration(milliseconds: 100));

      // Quantity and delete controls are frozen during checkout
      await tester.tap(find.text('+'), warnIfMissed: false);
      await tester.tap(find.byIcon(Icons.delete_outline), warnIfMissed: false);
      await tester.pump(const Duration(milliseconds: 100));

      expect(cartProvider.itemCount, equals(1));

      await pumpFrames(tester, count: 40);

      // Exactly the originally submitted line was charged
      final transactions = await persistedTransactions();
      expect(transactions, hasLength(1));
      expect(transactions.single.amountCents, equals(350));

      rfidProvider.stopListening();
    });
  });
}
