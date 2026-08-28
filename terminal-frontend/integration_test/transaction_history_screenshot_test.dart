@Tags(['walkthrough'])
library;

// Captures the member's transaction history as a PNG, so a design change to
// it can be looked at rather than described.
//
// Tagged `walkthrough`, which is what CI excludes: this produces an image
// rather than asserting a behaviour, and the behaviour it would incidentally
// cover is already covered headlessly by the "grouped history" group in
// test/widgets/member_details_modal_test.dart. Run it by hand when the design
// changes and the change needs looking at.
//
// Runs as an integration test on a real macOS/Linux window for the same reason
// walkthrough_test.dart does: it renders with real fonts and a real compositor,
// so the image is what a member would see. A widget test renders the same tree
// but is not what anyone would trust a typography decision to.
//
//   flutter test integration_test/transaction_history_screenshot_test.dart -d macos
//
// The PNG lands in the app's sandbox container (macOS) — see
// scripts/build-terminal-walkthrough.sh for the path.

import 'dart:convert';
import 'dart:io';
import 'dart:ui' as ui;

import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/generated/terminal.swagger.dart';
import 'package:clubbar_terminal/providers/rfid_provider.dart';
import 'package:clubbar_terminal/repository/members_repository.dart';
import 'package:clubbar_terminal/repository/products_repository.dart';
import 'package:clubbar_terminal/screens/idle_waiting_screen.dart';
import 'package:clubbar_terminal/widgets/member_details_modal.dart';
import 'package:drift/drift.dart' show Value;
import 'package:drift/native.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';
import 'package:provider/provider.dart';

import 'test_helpers.dart';

/// ClubBarHeader runs a periodic Timer, so pumpAndSettle never settles.
Future<void> pumpFrames(WidgetTester tester, {int count = 20}) async {
  for (var i = 0; i < count; i++) {
    await tester.pump(const Duration(milliseconds: 100));
  }
}

Future<List<int>> captureFrame(WidgetTester tester) async {
  await tester.pump();
  final renderView = tester.binding.renderViews.first;
  final layer = renderView.debugLayer! as OffsetLayer;
  final image = await layer.toImage(renderView.paintBounds, pixelRatio: 2.0);
  final bytes = await image.toByteData(format: ui.ImageByteFormat.png);
  image.dispose();
  return bytes!.buffer.asUint8List();
}

/// The sketch this design came from: a Friday of four beers and two waters,
/// and a Wednesday of one Äppler. Anchored to *today* so the day headings read
/// as they would on the bar's own screen.
Future<ClubBarDatabase> seedHistoryDatabase() async {
  final db = ClubBarDatabase.forTesting(NativeDatabase.memory());
  final products = ProductsRepository(db);
  final members = MembersRepository(db);
  final seededAt = DateTime.parse('2025-02-01T10:00:00Z');

  await products.upsertCategories([
    Category(
      id: 'cat-drinks',
      names: {'de': 'Getränke', 'en': 'Drinks'},
      iconName: 'beer-pils',
      isActive: true,
      createdAt: seededAt,
      updatedAt: seededAt,
    ),
  ]);

  await products.upsertProducts([
    Product(
      id: 'prod-helles',
      categoryId: 'cat-drinks',
      names: {'de': 'Helles', 'en': 'Lager'},
      descriptions: null,
      priceCents: 250,
      iconName: 'beer-pils',
      isActive: true,
      createdAt: seededAt,
      updatedAt: seededAt,
    ),
    Product(
      id: 'prod-wasser',
      categoryId: 'cat-drinks',
      names: {'de': 'Wasser', 'en': 'Water'},
      descriptions: null,
      priceCents: 150,
      iconName: 'water',
      isActive: true,
      createdAt: seededAt,
      updatedAt: seededAt,
    ),
    Product(
      id: 'prod-appler',
      categoryId: 'cat-drinks',
      names: {'de': 'Äppler', 'en': 'Apple wine'},
      descriptions: null,
      priceCents: 320,
      iconName: 'cider-appler',
      isActive: true,
      createdAt: seededAt,
      updatedAt: seededAt,
    ),
  ]);

  await members.upsertMembers([
    Member(
      id: 'member-1',
      cardUid: 'history-card-001',
      firstName: 'Jana',
      lastName: 'Berger',
      preferredLanguage: 'de',
      isActive: true,
      isSepaValid: true,
      createdAt: seededAt,
      updatedAt: seededAt,
    ),
  ]);

  return db;
}

/// Writes the purchases the screenshot is of.
///
/// Deliberately **after** the app has started rather than as part of the seed:
/// the terminal syncs pending transactions on launch, and against the test's
/// always-200 network every row it uploads comes back accepted and is marked
/// synced — which drops it out of the local-unsynced query the history reads.
/// Seeded first, the modal opens empty.
Future<void> seedPurchases(ClubBarDatabase db) async {
  final today = DateTime.now();
  DateTime at(int daysAgo, int hour, int minute) => DateTime(
        today.year,
        today.month,
        today.day - daysAgo,
        hour,
        minute,
      );

  var counter = 0;
  Future<void> purchase(
    String productId,
    int amountCents,
    DateTime when, {
    int times = 1,
  }) async {
    for (var i = 0; i < times; i++) {
      counter++;
      await db.into(db.transactionsLocal).insert(
            TransactionsLocalCompanion.insert(
              id: 'txn-${counter.toString().padLeft(3, '0')}',
              memberId: 'member-1',
              productId: Value(productId),
              amountCents: amountCents,
              transactionType: 'purchase',
              createdAt: when.toUtc().toIso8601String(),
            ),
          );
    }
  }

  // Today: two visits to the bar, which the list collapses into two lines.
  await purchase('prod-helles', 250, at(0, 22, 10), times: 2);
  await purchase('prod-wasser', 150, at(0, 20, 5), times: 2);
  await purchase('prod-helles', 250, at(0, 18, 0), times: 2);

  // Two days ago: a single Äppler, which keeps its bare name — no "1 x".
  await purchase('prod-appler', 320, at(2, 19, 30));
}

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('captures the grouped transaction history', (tester) async {
    tester.view.physicalSize = const Size(1280, 800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    final db = await seedHistoryDatabase();
    addTearDown(() => db.close());

    await tester.pumpWidget(await buildTestApp(db));
    await pumpFrames(tester);

    expect(find.byType(IdleWaitingScreen), findsOneWidget);

    final idleContext = tester.element(find.byType(IdleWaitingScreen));
    final rfid = idleContext.read<RfidProvider>();
    rfid.startListening(idleContext);
    await rfid.handleCardScan('history-card-001');
    await pumpFrames(tester);

    await seedPurchases(db);

    await tester.tap(find.text('Jana Berger'));
    await pumpFrames(tester, count: 30);

    expect(find.byType(MemberDetailsModal), findsOneWidget);
    // Inside the modal, not on the product grid behind it: the count prefix
    // only exists in the grouped list, so finding it proves the list rendered.
    expect(find.text('4'), findsWidgets);
    // findsWidgets, not findsOneWidget: the product grid behind the sheet
    // carries the same name.
    expect(find.text('Äppler'), findsWidgets);

    final dir = Directory('walkthrough-screenshots');
    dir.createSync(recursive: true);
    final file = File('${dir.path}/transaction-history.png');
    file.writeAsBytesSync(await captureFrame(tester));
    File('${dir.path}/transaction-history.json').writeAsStringSync(
      const JsonEncoder.withIndent('  ').convert({'path': file.path}),
    );
  });
}
