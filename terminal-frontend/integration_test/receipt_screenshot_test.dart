@Tags(['walkthrough'])
library;

// Captures the post-checkout receipt as PNGs — one per state it can be in —
// so a design change to it can be looked at rather than described.
//
// Tagged `walkthrough`, which is what CI excludes: this produces images rather
// than asserting a behaviour, and the behaviour is covered headlessly by
// test/screens/checkout_confirmation_screen_test.dart. Run it by hand when the
// receipt changes and the change needs looking at.
//
// Runs as an integration test on a real desktop window for the same reason
// walkthrough_test.dart does: real fonts, real compositor, so the image is what
// a member would see.
//
//   xvfb-run flutter test integration_test/receipt_screenshot_test.dart -d linux
//   flutter test integration_test/receipt_screenshot_test.dart -d macos
//
// The PNGs land in `walkthrough-screenshots/receipt-*.png` relative to the
// app's working directory (macOS: its sandbox container — see
// scripts/build-terminal-walkthrough.sh for the path).
//
// Every scene but the partial dispense goes through the real checkout: the
// member's card is scanned, items are added and the checkout button tapped,
// so what is captured is what the terminal itself paints after a purchase. A
// partial dispense needs a token dispenser, so that session's rows are written
// the way `CartService.createTransactionsFromDispenseResult` writes them.

import 'dart:io';
import 'dart:ui' as ui;

import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/generated/terminal.swagger.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/providers/rfid_provider.dart';
import 'package:clubbar_terminal/repository/members_repository.dart';
import 'package:clubbar_terminal/repository/products_repository.dart';
import 'package:clubbar_terminal/repository/transactions_repository.dart';
import 'package:clubbar_terminal/screens/checkout_confirmation_screen.dart';
import 'package:clubbar_terminal/screens/idle_waiting_screen.dart';
import 'package:clubbar_terminal/screens/product_selection_screen.dart';
import 'package:clubbar_terminal/widgets/main_layout.dart';
import 'package:drift/drift.dart' show Value;
import 'package:drift/native.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:integration_test/integration_test.dart';
import 'package:provider/provider.dart';

import 'test_helpers.dart';

/// ClubBarHeader runs a periodic Timer, so pumpAndSettle never settles.
Future<void> pumpFrames(WidgetTester tester, {int count = 15}) async {
  for (var i = 0; i < count; i++) {
    await tester.pump(const Duration(milliseconds: 100));
  }
}

Future<List<int>> captureFrame(WidgetTester tester) async {
  await tester.pump();
  final renderView = tester.binding.renderViews.first;
  final layer = renderView.debugLayer! as OffsetLayer;
  final image = await layer.toImage(renderView.paintBounds, pixelRatio: 1.0);
  final bytes = await image.toByteData(format: ui.ImageByteFormat.png);
  image.dispose();
  return bytes!.buffer.asUint8List();
}

/// A repository whose receipt lookup can be made to fail, to reach the
/// fallback receipt (#16) through the real checkout.
class FailingLookupRepository extends TransactionsRepository {
  FailingLookupRepository(super.db);

  bool failSessionLookups = false;

  @override
  Future<int> getSessionTotal(String sessionId) {
    if (failSessionLookups) throw StateError('receipt lookup failed');
    return super.getSessionTotal(sessionId);
  }
}

const _seededAt = '2025-02-01T10:00:00Z';

/// A bar with a Friday-evening spread: drinks, a snack, sauna tokens. Two
/// members — one German-speaking with a tab already open, one
/// English-speaking with credit — so the receipt is seen in both languages
/// and both balance colours.
Future<ClubBarDatabase> seedDatabase() async {
  final db = ClubBarDatabase.forTesting(NativeDatabase.memory());
  final products = ProductsRepository(db);
  final members = MembersRepository(db);
  final seededAt = DateTime.parse(_seededAt);

  await products.upsertCategories([
    Category(
      id: 'cat-drinks',
      names: {'de': 'Getränke', 'en': 'Drinks'},
      iconName: 'beer-pils',
      isActive: true,
      createdAt: seededAt,
      updatedAt: seededAt,
    ),
    Category(
      id: 'cat-snacks',
      names: {'de': 'Snacks', 'en': 'Snacks'},
      iconName: 'food-bretzel',
      isActive: true,
      createdAt: seededAt,
      updatedAt: seededAt,
    ),
    Category(
      id: 'cat-sauna',
      names: {'de': 'Sauna', 'en': 'Sauna'},
      iconName: 'sauna-token',
      isActive: true,
      createdAt: seededAt,
      updatedAt: seededAt,
    ),
  ]);

  Product product(
    String id,
    String category,
    Map<String, String> names,
    int priceCents,
    String icon, {
    bool requiresDispenser = false,
  }) =>
      Product(
        id: id,
        categoryId: category,
        names: names,
        descriptions: null,
        priceCents: priceCents,
        iconName: icon,
        isActive: true,
        requiresDispenser: requiresDispenser,
        createdAt: seededAt,
        updatedAt: seededAt,
      );

  await products.upsertProducts([
    product('prod-pils', 'cat-drinks', {'de': 'Pils 0,5l', 'en': 'Pils 0.5l'},
        350, 'beer-pils'),
    product('prod-weizen', 'cat-drinks',
        {'de': 'Weizen 0,5l', 'en': 'Weizen 0.5l'}, 380, 'beer-weizen'),
    product('prod-water', 'cat-drinks',
        {'de': 'Wasser 0,33l', 'en': 'Water 0.33l'}, 150, 'water-small'),
    product('prod-pretzel', 'cat-snacks', {'de': 'Brezel', 'en': 'Pretzel'},
        250, 'food-bretzel'),
    product('prod-sauna', 'cat-sauna',
        {'de': 'Sauna-Session', 'en': 'Sauna session'}, 500, 'sauna-session'),
    product('prod-token', 'cat-sauna',
        {'de': 'Sauna-Token', 'en': 'Sauna token'}, 200, 'sauna-token',
        requiresDispenser: true),
  ]);

  await members.upsertMembers([
    Member(
      id: 'member-jana',
      cardUid: 'receipt-card-jana',
      firstName: 'Jana',
      lastName: 'Meier',
      preferredLanguage: 'de',
      isActive: true,
      isSepaValid: true,
      createdAt: seededAt,
      updatedAt: seededAt,
    ),
    Member(
      id: 'member-tom',
      cardUid: 'receipt-card-tom',
      firstName: 'Tom',
      lastName: 'Baker',
      preferredLanguage: 'en',
      isActive: true,
      isSepaValid: true,
      createdAt: seededAt,
      updatedAt: seededAt,
    ),
  ]);
  // Jana already has a tab open; Tom is in credit.
  await members.updateMemberBalance('member-jana', 1230);
  await members.updateMemberBalance('member-tom', -2000);

  return db;
}

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  group('Receipt screenshots', () {
    testWidgets('every state of the post-checkout receipt', (tester) async {
      // 1280x800: typical 10" touchscreen for POS terminals
      tester.view.physicalSize = const Size(1280, 800);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      final db = await seedDatabase();
      addTearDown(() => db.close());
      final repo = FailingLookupRepository(db);

      final app = await buildTestApp(db, transactionsRepository: repo);
      await tester.pumpWidget(app);
      await pumpFrames(tester, count: 20);
      expect(find.byType(IdleWaitingScreen), findsOneWidget);

      final dir = Directory('walkthrough-screenshots')
        ..createSync(recursive: true);
      Future<void> capture(String name) async {
        File('${dir.path}/receipt-$name.png')
            .writeAsBytesSync(await captureFrame(tester));
      }

      // The app shell, not the idle screen: the scan handler navigates with
      // the context it was started with, and the idle screen is unmounted
      // the moment the first card is accepted.
      final shell = tester.element(find.byType(MainLayout));
      final rfid = shell.read<RfidProvider>();
      final members = shell.read<MembersProvider>();
      final cart = shell.read<CartProvider>();
      rfid.startListening(shell);
      addTearDown(rfid.stopListening);

      /// Scans [cardUid] — from idle, or over a receipt (ADR-0027 rule 9).
      Future<void> scan(String cardUid) async {
        await rfid.handleCardScan(cardUid);
        await pumpFrames(tester, count: 20);
        expect(find.byType(ProductSelectionScreen), findsOneWidget);
      }

      /// The round both members buy: two Pils, a pretzel, a sauna session.
      void fillCart(String language) {
        final names = language == 'de'
            ? ['Pils 0,5l', 'Brezel', 'Sauna-Session']
            : ['Pils 0.5l', 'Pretzel', 'Sauna session'];
        cart.addItem('prod-pils', names[0], 350, 2, language,
            iconName: 'beer-pils');
        cart.addItem('prod-pretzel', names[1], 250, 1, language,
            iconName: 'food-bretzel');
        cart.addItem('prod-sauna', names[2], 500, 1, language,
            iconName: 'sauna-session');
      }

      Future<void> checkout() async {
        await pumpFrames(tester, count: 5);
        await tester.tap(find.byKey(const Key('checkout-button')));
        await pumpFrames(tester, count: 15);
        expect(find.byType(CheckoutConfirmationScreen), findsOneWidget);
      }

      // -- 1: the ordinary receipt, in German, on a tab already open --
      await scan('receipt-card-jana');
      fillCart('de');
      await checkout();
      await capture('de');

      // -- 2: the same round in English, paid from credit --
      await scan('receipt-card-tom');
      fillCart('en');
      await checkout();
      await capture('en');

      // -- 3: a partial dispense — 5 tokens asked for, 3 came out --
      await scan('receipt-card-jana');
      final partialSession = members.sessionId!;
      final now = DateTime.now().toUtc().toIso8601String();
      for (var i = 0; i < 3; i++) {
        await db.into(db.transactionsLocal).insert(TransactionsLocalCompanion(
          id: Value('partial-$i'),
          memberId: const Value('member-jana'),
          productId: const Value('prod-token'),
          amountCents: const Value(200),
          transactionType: const Value('purchase'),
          createdAt: Value(now),
          synced: const Value(0),
          dispenserTxId: const Value('disp-tx-partial'),
          dispenserRequested: const Value(5),
          dispenserActual: const Value(3),
          sessionId: Value(partialSession),
          unitPriceCents: const Value(200),
        ));
      }
      await members.refreshDeckel();
      final products = tester.element(find.byType(ProductSelectionScreen));
      GoRouter.of(products).go('/confirmation/$partialSession');
      await pumpFrames(tester, count: 15);
      expect(find.byType(CheckoutConfirmationScreen), findsOneWidget);
      await capture('partial');

      // -- 4: the receipt whose details could not be read back (#16) --
      await scan('receipt-card-tom');
      fillCart('en');
      repo.failSessionLookups = true;
      await checkout();
      await capture('fallback');
      repo.failSessionLookups = false;
    });
  });
}
