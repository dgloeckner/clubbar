import 'dart:convert';
import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';
import 'package:provider/provider.dart';

import 'package:clubbar_terminal/providers/rfid_provider.dart';
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
      // Consistent 1920x1080 for video
      tester.view.physicalSize = const Size(1920, 1080);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      // Clean previous screenshots
      if (screenshotsDir.existsSync()) {
        screenshotsDir.deleteSync(recursive: true);
      }

      final db = await createTestDatabase();
      addTearDown(() => db.close());

      final app = await buildTestApp(db);
      await tester.pumpWidget(app);
      await pumpFrames(tester, count: 20);

      // -- Scene 1: Idle screen --
      expect(find.byType(IdleWaitingScreen), findsOneWidget);
      await capture(tester, 'Scan your member card');

      // -- Scene 2: RFID scan -> product selection --
      final idleContext = tester.element(find.byType(IdleWaitingScreen));
      final rfidProvider = idleContext.read<RfidProvider>();
      rfidProvider.startListening(idleContext);
      await rfidProvider.handleCardScan('test-card-001');
      await pumpFrames(tester, count: 20);

      expect(find.byType(ProductSelectionScreen), findsOneWidget);
      await capture(tester, 'Choose your drinks');

      // -- Scene 3: Add items to cart --
      // Tap Pils twice and Wasser once
      await tester.tap(find.text('Pils 0,5l'));
      await pumpFrames(tester, count: 5);
      await tester.tap(find.text('Pils 0,5l'));
      await pumpFrames(tester, count: 5);
      await tester.tap(find.text('Wasser 0,33l'));
      await pumpFrames(tester, count: 5);

      await capture(tester, 'Add items to your cart');

      // -- Scene 4: Shopping cart --
      // Tap the cart button (blue button with shopping cart icon)
      await tester.tap(find.byIcon(Icons.shopping_cart_outlined));
      await pumpFrames(tester, count: 20);

      expect(find.byType(ShoppingCartScreen), findsOneWidget);
      await capture(tester, 'Review your order');

      // -- Scene 5: Checkout confirmation --
      // Tap the green checkout button
      final checkoutButton = find.text('Bezahlen');
      expect(checkoutButton, findsOneWidget);
      await tester.tap(checkoutButton);
      await pumpFrames(tester, count: 30);

      expect(find.byType(CheckoutConfirmationScreen), findsOneWidget);
      await capture(tester, 'Order confirmed');

      rfidProvider.stopListening();
    });
  });
}
