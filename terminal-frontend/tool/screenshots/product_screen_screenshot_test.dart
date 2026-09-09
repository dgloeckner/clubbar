// Screenshot harness for the member bar and product grid at kiosk size.
//
// Not part of the test suite: it lives outside `test/` so `flutter test` never
// runs it, and it asserts nothing. Run it to regenerate the images:
//
//   flutter test tool/screenshots/product_screen_screenshot_test.dart \
//     --update-goldens
//
// The frames are 1280x800 at a device pixel ratio of 1 — the pixels the
// terminal's own panel shows, not a retina rendering of them — and go through
// the real app: the walkthrough database, a card scan, taps on tiles. Goldens
// are the mechanism only because `--update-goldens` is the supported way to
// get a rendered frame onto disk.
import 'dart:io';

import 'package:drift/native.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';

import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/generated/terminal.swagger.dart';
import 'package:clubbar_terminal/main.dart';
import 'package:clubbar_terminal/providers/rfid_provider.dart';
import 'package:clubbar_terminal/repository/members_repository.dart';
import 'package:clubbar_terminal/repository/products_repository.dart';
import 'package:clubbar_terminal/screens/idle_waiting_screen.dart';
import 'package:clubbar_terminal/screens/product_selection_screen.dart';
import 'package:clubbar_terminal/screens/shopping_cart_screen.dart';

import '../../integration_test/test_helpers.dart';

/// The test renderer draws every glyph as a box unless real fonts are loaded.
Future<void> _loadFonts() async {
  final root = Platform.environment['FLUTTER_ROOT'];
  if (root == null) {
    throw StateError('FLUTTER_ROOT is unset — run this through `flutter test`.');
  }
  final fonts = '$root/bin/cache/artifacts/material_fonts';

  Future<ByteData> read(String path) async =>
      ByteData.view(Uint8List.fromList(await File(path).readAsBytes()).buffer);

  final roboto = FontLoader('Roboto')
    ..addFont(read('$fonts/Roboto-Regular.ttf'))
    ..addFont(read('$fonts/Roboto-Medium.ttf'))
    ..addFont(read('$fonts/Roboto-Bold.ttf'));
  await roboto.load();

  final icons = FontLoader('MaterialIcons')
    ..addFont(read('$fonts/MaterialIcons-Regular.otf'));
  await icons.load();

  // The header clock asks for the family by the name pubspec declares.
  final mono = FontLoader('JetBrains Mono')
    ..addFont(read('assets/fonts/JetBrainsMono-Medium.ttf'));
  await mono.load();
}

/// ClubBarHeader's minute timer keeps pumpAndSettle from ever settling.
Future<void> _pumpFrames(WidgetTester tester, {int count = 15}) async {
  for (var i = 0; i < count; i++) {
    await tester.pump(const Duration(milliseconds: 100));
  }
}

final _t0 = DateTime.parse('2025-02-01T10:00:00Z');

Product _product(String id, String cat, String icon, String de, String en,
        int cents) =>
    Product(
      id: id,
      iconName: icon,
      categoryId: cat,
      names: {'de': de, 'en': en},
      descriptions: null,
      priceCents: cents,
      isActive: true,
      createdAt: _t0,
      updatedAt: _t0,
    );

/// A catalogue the size of a real club's, with the long German names that
/// make the tile's type size matter.
Future<ClubBarDatabase> _database() async {
  final db = ClubBarDatabase.forTesting(NativeDatabase.memory());
  final products = ProductsRepository(db);
  final members = MembersRepository(db);

  await products.upsertCategories([
    Category(
        id: 'cat-drinks',
        names: {'de': 'Getränke', 'en': 'Drinks'},
        iconName: 'beer-pils',
        isActive: true,
        createdAt: _t0,
        updatedAt: _t0),
    Category(
        id: 'cat-soft',
        names: {'de': 'Alkoholfrei', 'en': 'Soft drinks'},
        iconName: 'water-small',
        isActive: true,
        createdAt: _t0,
        updatedAt: _t0),
    Category(
        id: 'cat-snacks',
        names: {'de': 'Snacks', 'en': 'Snacks'},
        iconName: 'food-bretzel',
        isActive: true,
        createdAt: _t0,
        updatedAt: _t0),
    Category(
        id: 'cat-sauna',
        names: {'de': 'Sauna', 'en': 'Sauna'},
        iconName: 'sauna-token',
        isActive: true,
        createdAt: _t0,
        updatedAt: _t0),
  ]);

  await products.upsertProducts([
    _product('p-pils', 'cat-drinks', 'beer-pils', 'Pils 0,5l', 'Pils 0.5l', 350),
    _product('p-helles', 'cat-drinks', 'beer-pils', 'Helles 0,5l', 'Lager 0.5l',
        350),
    _product('p-weizen', 'cat-drinks', 'beer-weizen', 'Weizen 0,5l',
        'Wheat beer 0.5l', 380),
    _product('p-radler', 'cat-drinks', 'beer-radler', 'Radler 0,5l',
        'Shandy 0.5l', 300),
    _product('p-alkfrei', 'cat-drinks', 'beer-weizen', 'Alkoholfreies Weizen',
        'Alcohol-free wheat beer', 350),
    _product('p-weinschorle', 'cat-drinks', 'spritzer-apple',
        'Weinschorle 0,25l', 'Wine spritzer 0.25l', 300),
    _product('p-aperol', 'cat-drinks', 'spritzer-apple', 'Aperol Spritz',
        'Aperol Spritz', 550),
    _product('p-apfelschorle', 'cat-drinks', 'spritzer-apple',
        'Apfelschorle 0,5l', 'Apple spritzer 0.5l', 250),
    _product('p-spezi', 'cat-drinks', 'water-small', 'Cola-Mix 0,5l',
        'Cola mix 0.5l', 250),
    _product('p-wasser', 'cat-drinks', 'water-small', 'Wasser 0,75l',
        'Water 0.75l', 200),
    _product('p-kaffee', 'cat-drinks', 'coffee', 'Kaffee', 'Coffee', 200),
    _product('p-tee', 'cat-drinks', 'coffee', 'Tee', 'Tea', 150),
    _product('p-brezel', 'cat-snacks', 'food-bretzel', 'Brezel', 'Pretzel', 250),
    _product('p-nuesse', 'cat-snacks', 'food-crackers', 'Erdnüsse', 'Peanuts',
        150),
    _product('p-chips', 'cat-snacks', 'food-crisps', 'Chips', 'Crisps', 200),
    _product('p-sauna', 'cat-sauna', 'sauna-session', 'Sauna-Session',
        'Sauna session', 500),
    _product('p-handtuch', 'cat-sauna', 'sauna-towel', 'Handtuch-Verleih',
        'Towel rental', 200),
  ]);

  await members.upsertMembers([
    Member(
      id: 'm-jane',
      cardUid: 'card-jane',
      firstName: 'Jane',
      lastName: 'Smith',
      preferredLanguage: 'de',
      isActive: true,
      isSepaValid: true,
      createdAt: _t0,
      updatedAt: _t0,
    ),
    Member(
      id: 'm-max',
      cardUid: 'card-max',
      firstName: 'Maximilian',
      lastName: 'Müller-Lüdenscheidt',
      preferredLanguage: 'de',
      isActive: true,
      isSepaValid: true,
      createdAt: _t0,
      updatedAt: _t0,
    ),
  ]);
  // Inside the credit limit's warning band, so the banner is up (80 % of
  // AppConfig.balanceLimitCents).
  await members.updateMemberBalance('m-max', 8200);
  await members.updateMemberBalance('m-jane', 1250);

  return db;
}

void main() {
  setUpAll(_loadFonts);

  Future<void> shoot(WidgetTester tester, String name) async {
    await _pumpFrames(tester);
    await expectLater(
      find.byType(ClubBarTerminalApp),
      matchesGoldenFile('out/$name.png'),
    );
  }

  /// The card scan and the app's construction do real I/O (temp files for
  /// the config, the database), which never completes inside a widget test's
  /// fake-async zone — hence [WidgetTester.runAsync] around both.
  Future<RfidProvider> scan(WidgetTester tester, String cardUid) async {
    final idleContext = tester.element(find.byType(IdleWaitingScreen));
    final rfid = idleContext.read<RfidProvider>();
    rfid.startListening(idleContext);
    await tester.runAsync(() => rfid.handleCardScan(cardUid));
    // Past the login-success overlay.
    await _pumpFrames(tester, count: 25);
    expect(find.byType(ProductSelectionScreen), findsOneWidget);
    return rfid;
  }

  /// A tile tap; the cart write behind it is real I/O too.
  Future<void> tap(WidgetTester tester, String label) async {
    await tester.tap(find.text(label));
    await tester.runAsync(
        () => Future<void>.delayed(const Duration(milliseconds: 50)));
    await _pumpFrames(tester, count: 3);
  }

  Future<ClubBarDatabase> boot(WidgetTester tester) async {
    await tester.binding.setSurfaceSize(const Size(1280, 800));
    tester.view.physicalSize = const Size(1280, 800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final db = (await tester.runAsync(_database))!;
    addTearDown(db.close);

    final app = (await tester.runAsync(() => buildTestApp(db)))!;
    await tester.pumpWidget(app);
    await _pumpFrames(tester, count: 20);
    return db;
  }

  Future<void> endSession(WidgetTester tester, RfidProvider rfid) async {
    rfid.stopListening();
    // A context below the providers: the logout button is on both screens.
    final context = tester.element(find.byKey(const Key('member-bar-logout')));
    context.read<SessionController>().endSession();
    await _pumpFrames(tester);
    await tester.pumpWidget(const SizedBox.shrink());
  }

  testWidgets('product selection', (tester) async {
    await boot(tester);

    // 1. Fresh session, nothing in the cart yet.
    final rfid = await scan(tester, 'card-jane');
    await shoot(tester, '01-product-grid');

    // 2. Two tiles tapped: quantity badges and the summary bar.
    await tap(tester, 'Pils 0,5l');
    await tap(tester, 'Pils 0,5l');
    await tap(tester, 'Alkoholfreies Weizen');
    await shoot(tester, '02-product-grid-with-cart');

    // 3. The cart screen, with the member bar's back button.
    await tester.tap(find.byKey(const Key('view-cart-button')));
    await _pumpFrames(tester, count: 20);
    expect(find.byType(ShoppingCartScreen), findsOneWidget);
    await shoot(tester, '03-cart');

    await endSession(tester, rfid);
  });

  testWidgets('long name and credit-limit banner', (tester) async {
    await boot(tester);

    // Worst case for the band above the grid: the longest name a member is
    // likely to have, a tab inside the warning band, and an item in the
    // cart — the layout #369 measured.
    final rfid = await scan(tester, 'card-max');
    await tap(tester, 'Pils 0,5l');
    await shoot(tester, '04-long-name-with-banner');

    await endSession(tester, rfid);
  });
}
