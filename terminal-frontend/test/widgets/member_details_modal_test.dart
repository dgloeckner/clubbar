import 'package:drift/drift.dart' show Value;
import 'package:drift/native.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/services/network_service.dart';
import 'package:clubbar_terminal/utils/formatters.dart';
import 'package:clubbar_terminal/widgets/member_details_modal.dart';
import '../test_helpers.dart';

class MockMembersProvider extends Mock implements MembersProvider {}

class MockNetworkService extends Mock implements NetworkService {}

void main() {
  // Issue #27: this modal used to render `e.toString()` straight into the
  // member's face — "NetworkException: Sync members failed: HTTP 500".
  group('MemberDetailsModal transaction history failure (#27)', () {
    late MockMembersProvider mockMembersProvider;
    late MockNetworkService mockNetworkService;
    late ClubBarDatabase db;

    setUp(() {
      db = ClubBarDatabase.forTesting(NativeDatabase.memory());
      mockMembersProvider = MockMembersProvider();
      mockNetworkService = MockNetworkService();

      when(() => mockMembersProvider.addListener(any())).thenReturn(null);
      when(() => mockMembersProvider.removeListener(any())).thenReturn(null);
      when(() => mockMembersProvider.selectedMember).thenReturn(
        MembersCacheData(
          id: 'member-1',
          cardUid: 'card-123',
          firstName: 'John',
          lastName: 'Doe',
          preferredLanguage: 'de',
          isActive: 1,
          isSepaValid: 1,
          balanceCents: 0,
          updatedAt: '2025-02-01T10:00:00Z',
        ),
      );

      // The exact failure that used to leak its text to the screen.
      when(() => mockNetworkService.checkHealth()).thenThrow(
        NetworkException('Sync members failed: HTTP 500'),
      );
    });

    tearDown(() async {
      await db.close();
    });

    Future<void> pumpModal(WidgetTester tester) async {
      await tester.pumpWidget(
        createTestApp(
          child: MultiProvider(
            providers: [
              ChangeNotifierProvider<MembersProvider>.value(
                value: mockMembersProvider,
              ),
              Provider<NetworkService>.value(value: mockNetworkService),
              Provider<ClubBarDatabase>.value(value: db),
            ],
            child: const Scaffold(body: MemberDetailsModal()),
          ),
        ),
      );
      await tester.pumpAndSettle();
    }

    testWidgets('shows the localized error, not the exception',
        (WidgetTester tester) async {
      await pumpModal(tester);

      expect(
        find.text(await errorCopy(TerminalErrorKey.transactionHistoryFailed)),
        findsOneWidget,
      );
    });

    testWidgets('no raw exception text reaches any rendered Text',
        (WidgetTester tester) async {
      await pumpModal(tester);

      final rendered = tester
          .widgetList<Text>(find.byType(Text))
          .map((t) => t.data ?? '')
          .join('\n');
      expect(rendered, isNot(contains('NetworkException')));
      expect(rendered, isNot(contains('HTTP 500')));
    });

    testWidgets('still offers a way out', (WidgetTester tester) async {
      await pumpModal(tester);

      expect(find.text('Erneut versuchen'), findsOneWidget);
    });
  });

  // Issue #32: offline, the modal used to claim history was unavailable while
  // the balance right above it already counted the very same purchases.
  group('MemberDetailsModal offline history (#32)', () {
    late MockMembersProvider mockMembersProvider;
    late MockNetworkService mockNetworkService;
    late ClubBarDatabase db;

    setUp(() async {
      db = ClubBarDatabase.forTesting(NativeDatabase.memory());
      mockMembersProvider = MockMembersProvider();
      mockNetworkService = MockNetworkService();

      when(() => mockMembersProvider.addListener(any())).thenReturn(null);
      when(() => mockMembersProvider.removeListener(any())).thenReturn(null);
      when(() => mockMembersProvider.memberDeckel).thenReturn(-350);
      when(() => mockMembersProvider.selectedMember).thenReturn(
        MembersCacheData(
          id: 'member-1',
          cardUid: 'card-123',
          firstName: 'John',
          lastName: 'Doe',
          preferredLanguage: 'de',
          isActive: 1,
          isSepaValid: 1,
          balanceCents: 0,
          updatedAt: '2026-02-01T10:00:00Z',
        ),
      );
      when(() => mockNetworkService.checkHealth()).thenAnswer((_) async => false);

      await db.into(db.membersCache).insert(
            MembersCacheCompanion.insert(
              id: 'member-1',
              preferredLanguage: 'de',
              isSepaValid: 1,
              updatedAt: '2026-02-01T10:00:00Z',
            ),
          );
      await db.into(db.categoriesCache).insert(
            CategoriesCacheCompanion.insert(
              id: 'cat-1',
              names: '{"de":"Getränke"}',
              updatedAt: '2026-02-01T10:00:00Z',
            ),
          );
      await db.into(db.productsCache).insert(
            ProductsCacheCompanion.insert(
              id: 'product-1',
              categoryId: 'cat-1',
              names: '{"de":"Pils","en":"Lager"}',
              priceCents: 350,
              updatedAt: '2026-02-01T10:00:00Z',
            ),
          );
    });

    tearDown(() async {
      await db.close();
    });

    Future<void> addUnsyncedPurchase() {
      return db.into(db.transactionsLocal).insert(
            TransactionsLocalCompanion.insert(
              id: 'tx-1',
              memberId: 'member-1',
              productId: const Value('product-1'),
              amountCents: 350,
              transactionType: 'purchase',
              createdAt: '2026-02-01T18:00:00Z',
            ),
          );
    }

    Future<void> pumpModal(WidgetTester tester) async {
      await tester.pumpWidget(
        createTestApp(
          child: MultiProvider(
            providers: [
              ChangeNotifierProvider<MembersProvider>.value(
                value: mockMembersProvider,
              ),
              Provider<NetworkService>.value(value: mockNetworkService),
              Provider<ClubBarDatabase>.value(value: db),
            ],
            child: const Scaffold(body: MemberDetailsModal()),
          ),
        ),
      );
      await tester.pumpAndSettle();
    }

    testWidgets('lists the purchases this terminal recorded',
        (WidgetTester tester) async {
      await addUnsyncedPurchase();
      await pumpModal(tester);

      expect(find.text('Pils'), findsOneWidget);
      expect(find.text('Transaktionshistorie offline nicht verfügbar'),
          findsNothing);
    });

    testWidgets('says why the list is short', (WidgetTester tester) async {
      await addUnsyncedPurchase();
      await pumpModal(tester);

      final l10n = await AppLocalizations.delegate.load(const Locale('de'));
      expect(find.text(l10n.offlineLocalTransactionsOnly), findsOneWidget);
    });

    testWidgets('falls back to the offline notice with nothing local to show',
        (WidgetTester tester) async {
      await pumpModal(tester);

      expect(find.text('Transaktionshistorie offline nicht verfügbar'),
          findsOneWidget);
    });
  });

  // A checkout writes one row per unit, so a round of four beers was four
  // identical rows, each repeating the full date. The list now carries a
  // heading per day and one line per product under it.
  group('MemberDetailsModal grouped history', () {
    late MockMembersProvider mockMembersProvider;
    late MockNetworkService mockNetworkService;
    late ClubBarDatabase db;

    setUp(() async {
      db = ClubBarDatabase.forTesting(NativeDatabase.memory());
      mockMembersProvider = MockMembersProvider();
      mockNetworkService = MockNetworkService();

      when(() => mockMembersProvider.addListener(any())).thenReturn(null);
      when(() => mockMembersProvider.removeListener(any())).thenReturn(null);
      when(() => mockMembersProvider.memberDeckel).thenReturn(-1300);
      when(() => mockMembersProvider.selectedMember).thenReturn(
        MembersCacheData(
          id: 'member-1',
          cardUid: 'card-123',
          firstName: 'Jana',
          lastName: 'Berger',
          preferredLanguage: 'de',
          isActive: 1,
          isSepaValid: 1,
          balanceCents: 0,
          updatedAt: '2026-02-01T10:00:00Z',
        ),
      );
      when(() => mockNetworkService.checkHealth()).thenAnswer((_) async => false);

      // The transactions reference this row; without it the inserts below are
      // refused by the foreign key rather than merely missing from the list.
      await db.into(db.membersCache).insert(
            MembersCacheCompanion.insert(
              id: 'member-1',
              preferredLanguage: 'de',
              isSepaValid: 1,
              updatedAt: '2026-02-01T10:00:00Z',
            ),
          );
      await db.into(db.categoriesCache).insert(
            CategoriesCacheCompanion.insert(
              id: 'cat-1',
              names: '{"de":"Getränke"}',
              updatedAt: '2026-02-01T10:00:00Z',
            ),
          );
      await db.into(db.productsCache).insert(
            ProductsCacheCompanion.insert(
              id: 'product-helles',
              categoryId: 'cat-1',
              names: '{"de":"Helles","en":"Lager"}',
              priceCents: 250,
              updatedAt: '2026-02-01T10:00:00Z',
            ),
          );
      await db.into(db.productsCache).insert(
            ProductsCacheCompanion.insert(
              id: 'product-appler',
              categoryId: 'cat-1',
              names: '{"de":"Äppler","en":"Apple wine"}',
              priceCents: 320,
              updatedAt: '2026-02-01T10:00:00Z',
            ),
          );
    });

    tearDown(() async {
      await db.close();
    });

    /// Local time, so the day a row falls on is the day the heading names —
    /// a UTC literal near midnight lands on the previous day in CET and the
    /// grouping assertion would fail for half the year.
    Future<void> purchase(
      String id,
      String productId,
      int amountCents,
      DateTime when,
    ) {
      return db.into(db.transactionsLocal).insert(
            TransactionsLocalCompanion.insert(
              id: id,
              memberId: 'member-1',
              productId: Value(productId),
              amountCents: amountCents,
              transactionType: 'purchase',
              createdAt: when.toUtc().toIso8601String(),
            ),
          );
    }

    Future<void> pumpModal(WidgetTester tester) async {
      // Taller than the default 800x600 test surface: the list is lazily
      // built, so a second day heading below the fold does not exist to be
      // found, and the assertion would fail for a layout reason rather than a
      // grouping one.
      tester.view.physicalSize = const Size(1200, 1600);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      await tester.pumpWidget(
        createTestApp(
          child: MultiProvider(
            providers: [
              ChangeNotifierProvider<MembersProvider>.value(
                value: mockMembersProvider,
              ),
              Provider<NetworkService>.value(value: mockNetworkService),
              Provider<ClubBarDatabase>.value(value: db),
            ],
            child: const Scaffold(body: MemberDetailsModal()),
          ),
        ),
      );
      await tester.pumpAndSettle();
    }

    testWidgets('shows one line per product, with its count and unit price',
        (WidgetTester tester) async {
      final evening = DateTime(2026, 2, 1, 18, 0);
      await purchase('tx-1', 'product-helles', 250, evening);
      await purchase('tx-2', 'product-helles', 250, evening);
      // A second visit the same evening: still one line of four.
      await purchase('tx-3', 'product-helles', 250, DateTime(2026, 2, 1, 22, 10));
      await purchase('tx-4', 'product-helles', 250, DateTime(2026, 2, 1, 22, 10));

      await pumpModal(tester);

      expect(find.text('Helles'), findsOneWidget);
      expect(find.text('4'), findsOneWidget);
      // Built with the app's own formatter: de_DE separates amount and symbol
      // with a non-breaking space, which a hand-typed literal does not.
      expect(find.text(formatPrice(1000, 'de')), findsWidgets); // line total
      expect(find.text(formatPrice(250, 'de')), findsOneWidget); // one costs
    });

    testWidgets('heads each day, and totals it', (WidgetTester tester) async {
      await purchase('tx-1', 'product-helles', 250, DateTime(2026, 2, 1, 18, 0));
      await purchase('tx-2', 'product-helles', 250, DateTime(2026, 2, 1, 18, 0));
      await purchase('tx-3', 'product-appler', 320, DateTime(2026, 1, 30, 19, 30));

      await pumpModal(tester);

      // Sunday 01.02.2026 and Friday 30.01.2026 — without ICU's trailing dot.
      expect(find.text('So, 01.02.'), findsOneWidget);
      expect(find.text('Fr, 30.01.'), findsOneWidget);
      expect(find.text(formatPrice(500, 'de')), findsWidgets);
      expect(find.text(formatPrice(320, 'de')), findsWidgets);
    });

    testWidgets('a single purchase carries no count and no unit price',
        (WidgetTester tester) async {
      await purchase('tx-1', 'product-appler', 320, DateTime(2026, 2, 1, 19, 30));

      await pumpModal(tester);

      expect(find.text('Äppler'), findsOneWidget);
      expect(find.text('1'), findsNothing,
          reason: 'a leading "1 x" on every line is the noise this removes');
      // Only the line total, which is also what one cost — stating it twice
      // would be the same number on two lines.
      expect(find.text(formatPrice(320, 'de')), findsNWidgets(2));
    });
  });

  // Issue #294: since the #33 router fix the sheet stayed open after a
  // language switch; the member's goal afterwards is shopping, not the sheet.
  group('MemberDetailsModal language switch auto-close (#294)', () {
    late MockMembersProvider mockMembersProvider;
    late MockNetworkService mockNetworkService;
    late ClubBarDatabase db;

    setUp(() {
      db = ClubBarDatabase.forTesting(NativeDatabase.memory());
      mockMembersProvider = MockMembersProvider();
      mockNetworkService = MockNetworkService();

      when(() => mockMembersProvider.addListener(any())).thenReturn(null);
      when(() => mockMembersProvider.removeListener(any())).thenReturn(null);
      when(() => mockMembersProvider.selectedMember).thenReturn(
        MembersCacheData(
          id: 'member-1',
          cardUid: 'card-123',
          firstName: 'John',
          lastName: 'Doe',
          preferredLanguage: 'de',
          isActive: 1,
          isSepaValid: 1,
          balanceCents: 0,
          updatedAt: '2026-02-01T10:00:00Z',
        ),
      );
      when(() => mockNetworkService.checkHealth())
          .thenAnswer((_) async => false);
      when(() => mockMembersProvider.updateSelectedMemberLanguage(any()))
          .thenAnswer((_) async {});
    });

    tearDown(() async {
      await db.close();
    });

    Future<void> pumpOpenModal(WidgetTester tester) async {
      // Providers must sit above MaterialApp's Navigator, not inside `home` —
      // a route pushed via showModalBottomSheet is a sibling of `home`, not
      // a descendant, so a provider scoped inside `home` would not reach it.
      await tester.pumpWidget(
        MultiProvider(
          providers: [
            ChangeNotifierProvider<MembersProvider>.value(
              value: mockMembersProvider,
            ),
            Provider<NetworkService>.value(value: mockNetworkService),
            Provider<ClubBarDatabase>.value(value: db),
          ],
          child: createTestApp(
            child: Scaffold(
              body: Builder(
                builder: (context) => TextButton(
                  onPressed: () => showMemberDetailsModal(context),
                  child: const Text('Open modal'),
                ),
              ),
            ),
          ),
        ),
      );
      await tester.tap(find.text('Open modal'));
      await tester.pumpAndSettle();
    }

    testWidgets('tapping a language updates it and dismisses the sheet',
        (WidgetTester tester) async {
      await pumpOpenModal(tester);
      expect(find.byType(MemberDetailsModal), findsOneWidget);

      await tester.tap(find.text('English'));
      await tester.pumpAndSettle();

      verify(() => mockMembersProvider.updateSelectedMemberLanguage('en'))
          .called(1);
      expect(find.byType(MemberDetailsModal), findsNothing);
    });

    testWidgets('a failed update keeps the sheet open',
        (WidgetTester tester) async {
      when(() => mockMembersProvider.updateSelectedMemberLanguage(any()))
          .thenThrow(Exception('update failed'));

      await pumpOpenModal(tester);
      expect(find.byType(MemberDetailsModal), findsOneWidget);

      await tester.tap(find.text('English'));
      await tester.pumpAndSettle();

      expect(find.byType(MemberDetailsModal), findsOneWidget);
    });

    testWidgets('tapping the language already in force changes nothing',
        (WidgetTester tester) async {
      await pumpOpenModal(tester);
      expect(find.byType(MemberDetailsModal), findsOneWidget);

      // The member is on 'de'. Tapping 'Deutsch' used to write the value it
      // already had and then dismiss the sheet on success — closing the
      // bookings the member was part-way through reading, for no change.
      await tester.tap(find.text('Deutsch'));
      await tester.pumpAndSettle();

      verifyNever(() => mockMembersProvider.updateSelectedMemberLanguage(any()));
      expect(find.byType(MemberDetailsModal), findsOneWidget);
    });

    testWidgets('the sheet is named for the bookings, above the language row',
        (WidgetTester tester) async {
      await pumpOpenModal(tester);

      // "Mitgliedsdetails" named the container; members were looking for
      // their bookings, and the member bar's button now uses this word too,
      // so the tap confirms itself.
      expect(find.text('Meine Buchungen'), findsOneWidget);

      // Language used to sit between the member's name and their bookings,
      // which on a 75%-height sheet started the list below the fold. It is
      // now a footer under the list — still one tap, still no scrolling.
      final listHeader = tester.getTopLeft(find.text('Letzte Transaktionen'));
      final languageLabel = tester.getTopLeft(find.text('Bevorzugte Sprache'));
      expect(listHeader.dy, lessThan(languageLabel.dy),
          reason: 'the reason for the visit comes before the setting');
    });
  });
}
