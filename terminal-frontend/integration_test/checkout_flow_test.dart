import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';
import 'package:provider/provider.dart';

import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/providers/rfid_provider.dart';
import 'package:clubbar_terminal/screens/idle_waiting_screen.dart';
import 'package:clubbar_terminal/screens/product_selection_screen.dart';
import 'package:clubbar_terminal/widgets/styled_components/product_card.dart';

import 'test_helpers.dart';

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
      // Use pump() instead of pumpAndSettle() because ClubBarHeader has a
      // periodic Timer (clock update every second) that prevents settling.
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
      // with the test member's card UID (seeded as 'test-card-001')
      final context = tester.element(find.byType(IdleWaitingScreen));
      final rfidProvider = context.read<RfidProvider>();
      await rfidProvider.handleCardScan('test-card-001');
      await tester.pump(const Duration(milliseconds: 500));

      // After successful scan, the router should redirect to /products
      // because MembersProvider.selectedMember is now non-null
      expect(find.byType(ProductSelectionScreen), findsOneWidget);

      // Verify the member name appears in the member bar
      expect(find.text('Test'), findsOneWidget);
      expect(find.text('Member'), findsOneWidget);
    });

    testWidgets('Product selection adds item to cart with correct price',
        (tester) async {
      final app = await buildTestApp(db);
      await tester.pumpWidget(app);
      await tester.pump(const Duration(milliseconds: 500));

      // First, identify a member to reach the product selection screen
      final idleContext = tester.element(find.byType(IdleWaitingScreen));
      final rfidProvider = idleContext.read<RfidProvider>();
      await rfidProvider.handleCardScan('test-card-001');
      await tester.pump(const Duration(milliseconds: 500));

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
      await tester.pump(const Duration(milliseconds: 500));

      // After tapping, the product card should show a quantity badge "1x"
      expect(find.text('1x'), findsOneWidget);

      // Verify the cart provider reflects the added item
      final productContext = tester.element(find.byType(ProductSelectionScreen));
      final cartProvider = productContext.read<CartProvider>();
      expect(cartProvider.itemCount, equals(1));
      expect(cartProvider.total, equals(350));

      // Tap the same product again to increase quantity
      await tester.tap(find.text('Pils 0,5l'));
      await tester.pump(const Duration(milliseconds: 500));

      // Quantity badge should now show "2x"
      expect(find.text('2x'), findsOneWidget);
      expect(cartProvider.itemCount, equals(2));
      expect(cartProvider.total, equals(700));
    });
  });
}
