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
}
