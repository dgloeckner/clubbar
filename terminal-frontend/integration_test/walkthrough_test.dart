@Tags(['walkthrough'])
library;

import 'dart:convert';
import 'dart:io';
import 'dart:ui' as ui;

import 'package:drift/native.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';
import 'package:provider/provider.dart';

import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/generated/terminal.swagger.dart';
import 'package:clubbar_terminal/providers/rfid_provider.dart';
import 'package:clubbar_terminal/repository/members_repository.dart';
import 'package:clubbar_terminal/repository/products_repository.dart';
import 'package:clubbar_terminal/screens/checkout_confirmation_screen.dart';
import 'package:clubbar_terminal/screens/idle_waiting_screen.dart';
import 'package:clubbar_terminal/screens/product_selection_screen.dart';
import 'package:clubbar_terminal/screens/shopping_cart_screen.dart';

import 'test_helpers.dart';

/// Pump multiple frames because ClubBarHeader's periodic Timer prevents
/// pumpAndSettle from ever settling.
Future<void> pumpFrames(WidgetTester tester, {int count = 15}) async {
  for (int i = 0; i < count; i++) {
    await tester.pump(const Duration(milliseconds: 100));
  }
}

/// Capture the current frame as a PNG using dart:ui layer rendering.
/// This avoids the platform channel (takeScreenshot) which isn't available
/// on macOS desktop integration tests.
Future<List<int>> captureFrame(WidgetTester tester) async {
  await tester.pump();

  final renderView = tester.binding.renderViews.first;
  final layer = renderView.debugLayer as OffsetLayer;
  final bounds = renderView.paintBounds;

  final image = await layer.toImage(bounds, pixelRatio: 1.0);
  final byteData = await image.toByteData(format: ui.ImageByteFormat.png);
  image.dispose();

  return byteData!.buffer.asUint8List();
}

/// Screenshot manifest entry — matches admin walkthrough format so the
/// same ffmpeg slideshow pipeline can assemble the video.
Map<String, dynamic> _entry(int index, String file, String subtitle) =>
    {'index': index, 'file': file, 'subtitle': subtitle};

/// Creates a walkthrough-specific database with rich test data:
/// 3 categories (Drinks, Snacks, Sauna) with multiple products each,
/// and 1 member (Jane Smith).
Future<ClubBarDatabase> createWalkthroughDatabase() async {
  final db = ClubBarDatabase.forTesting(NativeDatabase.memory());

  final productsRepo = ProductsRepository(db);
  final membersRepo = MembersRepository(db);

  await productsRepo.upsertCategories([
    Category(
      id: 'wt-cat-drinks',
      names: {'de': 'Getränke', 'en': 'Drinks'},
      iconName: 'beer-pils',
      isActive: true,
      createdAt: DateTime.parse('2025-02-01T10:00:00Z'),
      updatedAt: DateTime.parse('2025-02-01T10:00:00Z'),
    ),
    Category(
      id: 'wt-cat-snacks',
      names: {'de': 'Snacks', 'en': 'Snacks'},
      iconName: 'food-bretzel',
      isActive: true,
      createdAt: DateTime.parse('2025-02-01T10:00:00Z'),
      updatedAt: DateTime.parse('2025-02-01T10:00:00Z'),
    ),
    Category(
      id: 'wt-cat-sauna',
      names: {'de': 'Sauna', 'en': 'Sauna'},
      iconName: 'sauna-token',
      isActive: true,
      createdAt: DateTime.parse('2025-02-01T10:00:00Z'),
      updatedAt: DateTime.parse('2025-02-01T10:00:00Z'),
    ),
  ]);

  await productsRepo.upsertProducts([
    // -- Drinks --
    Product(
      id: 'wt-prod-pils',
      iconName: 'beer-pils',
      categoryId: 'wt-cat-drinks',
      names: {'de': 'Pils 0,5l', 'en': 'Pils 0.5l'},
      descriptions: null,
      priceCents: 350,
      isActive: true,
      createdAt: DateTime.parse('2025-02-01T10:00:00Z'),
      updatedAt: DateTime.parse('2025-02-01T10:00:00Z'),
    ),
    Product(
      id: 'wt-prod-weizen',
      iconName: 'beer-weizen',
      categoryId: 'wt-cat-drinks',
      names: {'de': 'Weizen 0,5l', 'en': 'Weizen 0.5l'},
      descriptions: null,
      priceCents: 380,
      isActive: true,
      createdAt: DateTime.parse('2025-02-01T10:00:00Z'),
      updatedAt: DateTime.parse('2025-02-01T10:00:00Z'),
    ),
    Product(
      id: 'wt-prod-radler',
      iconName: 'beer-radler',
      categoryId: 'wt-cat-drinks',
      names: {'de': 'Radler 0,5l', 'en': 'Radler 0.5l'},
      descriptions: null,
      priceCents: 300,
      isActive: true,
      createdAt: DateTime.parse('2025-02-01T10:00:00Z'),
      updatedAt: DateTime.parse('2025-02-01T10:00:00Z'),
    ),
    Product(
      id: 'wt-prod-water',
      iconName: 'water-small',
      categoryId: 'wt-cat-drinks',
      names: {'de': 'Wasser 0,33l', 'en': 'Water 0.33l'},
      descriptions: null,
      priceCents: 150,
      isActive: true,
      createdAt: DateTime.parse('2025-02-01T10:00:00Z'),
      updatedAt: DateTime.parse('2025-02-01T10:00:00Z'),
    ),
    Product(
      id: 'wt-prod-apfelschorle',
      iconName: 'spritzer-apple',
      categoryId: 'wt-cat-drinks',
      names: {'de': 'Apfelschorle', 'en': 'Apple Spritzer'},
      descriptions: null,
      priceCents: 200,
      isActive: true,
      createdAt: DateTime.parse('2025-02-01T10:00:00Z'),
      updatedAt: DateTime.parse('2025-02-01T10:00:00Z'),
    ),
    Product(
      id: 'wt-prod-coffee',
      iconName: 'coffee',
      categoryId: 'wt-cat-drinks',
      names: {'de': 'Kaffee', 'en': 'Coffee'},
      descriptions: null,
      priceCents: 200,
      isActive: true,
      createdAt: DateTime.parse('2025-02-01T10:00:00Z'),
      updatedAt: DateTime.parse('2025-02-01T10:00:00Z'),
    ),
    // -- Snacks --
    Product(
      id: 'wt-prod-pretzel',
      iconName: 'food-bretzel',
      categoryId: 'wt-cat-snacks',
      names: {'de': 'Brezel', 'en': 'Pretzel'},
      descriptions: null,
      priceCents: 250,
      isActive: true,
      createdAt: DateTime.parse('2025-02-01T10:00:00Z'),
      updatedAt: DateTime.parse('2025-02-01T10:00:00Z'),
    ),
    Product(
      id: 'wt-prod-nuts',
      iconName: 'food-crackers',
      categoryId: 'wt-cat-snacks',
      names: {'de': 'Erdnüsse', 'en': 'Peanuts'},
      descriptions: null,
      priceCents: 150,
      isActive: true,
      createdAt: DateTime.parse('2025-02-01T10:00:00Z'),
      updatedAt: DateTime.parse('2025-02-01T10:00:00Z'),
    ),
    Product(
      id: 'wt-prod-chips',
      iconName: 'food-crisps',
      categoryId: 'wt-cat-snacks',
      names: {'de': 'Chips', 'en': 'Chips'},
      descriptions: null,
      priceCents: 200,
      isActive: true,
      createdAt: DateTime.parse('2025-02-01T10:00:00Z'),
      updatedAt: DateTime.parse('2025-02-01T10:00:00Z'),
    ),
    // -- Sauna --
    Product(
      id: 'wt-prod-sauna-session',
      iconName: 'sauna-session',
      categoryId: 'wt-cat-sauna',
      names: {'de': 'Sauna-Session', 'en': 'Sauna Session'},
      descriptions: null,
      priceCents: 500,
      isActive: true,
      createdAt: DateTime.parse('2025-02-01T10:00:00Z'),
      updatedAt: DateTime.parse('2025-02-01T10:00:00Z'),
    ),
    Product(
      id: 'wt-prod-sauna-towel',
      iconName: 'sauna-towel',
      categoryId: 'wt-cat-sauna',
      names: {'de': 'Handtuch-Verleih', 'en': 'Towel Rental'},
      descriptions: null,
      priceCents: 200,
      isActive: true,
      createdAt: DateTime.parse('2025-02-01T10:00:00Z'),
      updatedAt: DateTime.parse('2025-02-01T10:00:00Z'),
    ),
    Product(
      id: 'wt-prod-sauna-aufguss',
      iconName: 'sauna-infusion',
      categoryId: 'wt-cat-sauna',
      names: {'de': 'Aufguss-Event', 'en': 'Infusion Event'},
      descriptions: null,
      priceCents: 300,
      isActive: true,
      createdAt: DateTime.parse('2025-02-01T10:00:00Z'),
      updatedAt: DateTime.parse('2025-02-01T10:00:00Z'),
    ),
  ]);

  await membersRepo.upsertMembers([
    Member(
      id: 'wt-member-1',
      cardUid: 'walkthrough-card-001',
      firstName: 'Jane',
      lastName: 'Smith',
      preferredLanguage: 'de',
      isActive: true,
      isSepaValid: true,
      createdAt: DateTime.parse('2025-02-01T10:00:00Z'),
      updatedAt: DateTime.parse('2025-02-01T10:00:00Z'),
    ),
  ]);

  return db;
}

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  // macOS sandboxes the app to ~/Library/Containers/<bundle-id>/Data/,
  // so relative paths land there. The build script knows this location.
  final screenshotsDir = Directory('walkthrough-screenshots');
  final manifestPath = '${screenshotsDir.path}/manifest.json';
  final entries = <Map<String, dynamic>>[];
  var counter = 0;

  /// Take a PNG screenshot and append it to the manifest.
  Future<void> capture(WidgetTester tester, String subtitle) async {
    counter++;
    final filename = 'screenshot-${counter.toString().padLeft(3, '0')}.png';

    final bytes = await captureFrame(tester);

    screenshotsDir.createSync(recursive: true);
    File('${screenshotsDir.path}/$filename').writeAsBytesSync(bytes);

    entries.add(_entry(counter, filename, subtitle));

    // Write manifest after each capture (crash-safe)
    final manifest = const JsonEncoder.withIndent('  ')
        .convert({'screenshots': entries});
    File(manifestPath).writeAsStringSync('$manifest\n');
  }

  group('Terminal walkthrough', () {
    testWidgets('Core happy path', (tester) async {
      // 1280x800: typical 10" touchscreen for POS terminals
      tester.view.physicalSize = const Size(1280, 800);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      // Clean previous screenshots
      if (screenshotsDir.existsSync()) {
        screenshotsDir.deleteSync(recursive: true);
      }

      final db = await createWalkthroughDatabase();
      addTearDown(() => db.close());

      final app = await buildTestApp(db);
      await tester.pumpWidget(app);
      await pumpFrames(tester, count: 20);

      // -- Scene 1: Idle screen --
      expect(find.byType(IdleWaitingScreen), findsOneWidget);
      await capture(tester, 'Scan your member card');

      // -- Scene 2: RFID scan -> product selection (German) --
      final idleContext = tester.element(find.byType(IdleWaitingScreen));
      final rfidProvider = idleContext.read<RfidProvider>();
      rfidProvider.startListening(idleContext);
      await rfidProvider.handleCardScan('walkthrough-card-001');
      await pumpFrames(tester, count: 20);

      expect(find.byType(ProductSelectionScreen), findsOneWidget);

      // -- Scene 2: Products shown in German (member's default language) --
      await capture(tester, 'Products shown in German');

      // -- Scene 3: Open member details modal --
      expect(find.text('Jane Smith'), findsOneWidget);
      await tester.tap(find.text('Jane Smith'));
      // Bottom sheet animation needs many frames
      await pumpFrames(tester, count: 30);

      // Capture modal with language selection visible (German still active)
      expect(find.text('English'), findsOneWidget);
      await capture(tester, 'Member details with language selection');

      // -- Scene 4: Tap English — the sheet closes automatically once the
      // language switch succeeds (#294), so shopping continues right away --
      await tester.tap(find.text('English'));
      await pumpFrames(tester, count: 30);

      // -- Scene 5: Products now shown in English --
      await capture(tester, 'Products now in English');

      // -- Scene 6: Select drinks --
      await tester.tap(find.text('Pils 0.5l'));
      await pumpFrames(tester, count: 5);
      await tester.tap(find.text('Weizen 0.5l'));
      await pumpFrames(tester, count: 5);

      await capture(tester, 'Select your drinks');

      // -- Scene 5: Switch to Snacks category and add a snack --
      await tester.tap(find.text('Snacks'));
      await pumpFrames(tester, count: 10);

      await tester.tap(find.text('Pretzel'));
      await pumpFrames(tester, count: 5);

      await capture(tester, 'Add a snack');

      // -- Scene 6: Switch to Sauna category and add a session --
      await tester.tap(find.text('Sauna'));
      await pumpFrames(tester, count: 10);

      await tester.tap(find.text('Sauna Session'));
      await pumpFrames(tester, count: 5);

      await capture(tester, 'Book a sauna session');

      // -- Scene 7: Shopping cart --
      await tester.tap(find.byIcon(Icons.shopping_cart_outlined));
      await pumpFrames(tester, count: 20);

      expect(find.byType(ShoppingCartScreen), findsOneWidget);
      await capture(tester, 'Review your order');

      // -- Scene 8: Checkout confirmation --
      // The checkout button text is "Checkout" in English
      final checkoutButton = find.text('Checkout');
      expect(checkoutButton, findsOneWidget);
      await tester.tap(checkoutButton);
      await pumpFrames(tester, count: 30);

      expect(find.byType(CheckoutConfirmationScreen), findsOneWidget);
      await capture(tester, 'Order confirmed');

      rfidProvider.stopListening();
    });
  });
}
