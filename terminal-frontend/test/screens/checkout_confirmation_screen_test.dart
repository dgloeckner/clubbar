import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:go_router/go_router.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/models/receipt_line.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/repository/transactions_repository.dart';
import 'package:clubbar_terminal/screens/checkout_confirmation_screen.dart';
import 'package:clubbar_terminal/services/members_service.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';

class MockCartProvider extends Mock implements CartProvider {}
class MockMembersProvider extends Mock implements MembersProvider {}
class MockMembersService extends Mock implements MembersService {}
class FakeMembersCacheData extends Fake implements MembersCacheData {}
class MockSessionController extends Mock implements SessionController {}
class MockTransactionsRepository extends Mock implements TransactionsRepository {}

/// intl sets a no-break space between amount and sign ("14,50 €"), which is
/// what the `\u00a0` escapes in the expectations below spell out.

/// The round the receipt is issued for: two Pils, a Brezel, a sauna session.
const _round = [
  ReceiptLine(
    productId: 'prod-pils',
    namesJson: '{"de":"Pils 0,5l","en":"Pils 0.5l"}',
    iconName: 'beer-pils',
    quantity: 2,
    unitPriceCents: 350,
    totalCents: 700,
  ),
  ReceiptLine(
    productId: 'prod-brezel',
    namesJson: '{"de":"Brezel","en":"Pretzel"}',
    iconName: 'food-bretzel',
    quantity: 1,
    unitPriceCents: 250,
    totalCents: 250,
  ),
  ReceiptLine(
    productId: 'prod-sauna',
    namesJson: '{"de":"Sauna-Session","en":"Sauna session"}',
    iconName: 'sauna-session',
    quantity: 1,
    unitPriceCents: 500,
    totalCents: 500,
  ),
];

/// Five tokens asked for, three came out.
const _partialRound = [
  ReceiptLine(
    productId: 'prod-token',
    namesJson: '{"de":"Sauna-Token","en":"Sauna token"}',
    iconName: 'sauna-token',
    quantity: 3,
    unitPriceCents: 200,
    totalCents: 600,
    requestedQuantity: 5,
  ),
];

void main() {
  setUpAll(() => registerFallbackValue(FakeMembersCacheData()));

  group('CheckoutConfirmationScreen', () {
    late MockCartProvider mockCartProvider;
    late MockMembersProvider mockMembersProvider;
    late MockSessionController mockSessionController;
    late MockTransactionsRepository mockRepo;

    setUp(() {
      mockCartProvider = MockCartProvider();
      mockMembersProvider = MockMembersProvider();
      mockSessionController = MockSessionController();
      mockRepo = MockTransactionsRepository();

      // Session controller mocks (owns all session teardown, ADR-0027)
      when(() => mockSessionController.endSession()).thenReturn(true);
      when(() => mockSessionController.recordActivity()).thenReturn(null);
      when(() => mockSessionController.addListener(any())).thenReturn(null);
      when(() => mockSessionController.removeListener(any())).thenReturn(null);

      // checkout() empties the cart before this screen mounts, so the billed
      // amount only survives on the provider.
      when(() => mockCartProvider.clearCart()).thenReturn(null);
      when(() => mockCartProvider.total).thenReturn(0);
      when(() => mockCartProvider.lastCheckoutTotalCents).thenReturn(2500);
      when(() => mockCartProvider.addListener(any())).thenReturn(null);
      when(() => mockCartProvider.removeListener(any())).thenReturn(null);

      when(() => mockMembersProvider.clearSelectedMember()).thenReturn(null);
      when(() => mockMembersProvider.selectedMember).thenReturn(null);
      when(() => mockMembersProvider.memberDeckel).thenReturn(1450);
      when(() => mockMembersProvider.addListener(any())).thenReturn(null);
      when(() => mockMembersProvider.removeListener(any())).thenReturn(null);

      // Repository mocks — an ordinary (non-partial) round by default
      when(() => mockRepo.getSessionTotal(any())).thenAnswer((_) async => 1450);
      when(() => mockRepo.getSessionLines(any()))
          .thenAnswer((_) async => _round);
    });

    Widget wrap(Widget child, {MembersProvider? membersProvider}) {
      return MultiProvider(
        providers: [
          ChangeNotifierProvider<CartProvider>.value(value: mockCartProvider),
          ChangeNotifierProvider<MembersProvider>.value(
            value: membersProvider ?? mockMembersProvider,
          ),
          ChangeNotifierProvider<SessionController>.value(
            value: mockSessionController,
          ),
          Provider<TransactionsRepository>.value(value: mockRepo),
        ],
        child: child,
      );
    }

    /// Pumps the receipt with `/idle` and `/products` destinations wired up,
    /// settled past the post-frame callback that starts its clock.
    Future<void> pumpReceipt(
      WidgetTester tester, {
      MembersProvider? membersProvider,
    }) async {
      final router = GoRouter(
        initialLocation: '/',
        routes: [
          GoRoute(
            path: '/',
            builder: (context, state) => wrap(
              const CheckoutConfirmationScreen(sessionId: 'sess-abc123'),
              membersProvider: membersProvider,
            ),
          ),
          GoRoute(
            path: '/idle',
            builder: (context, state) => const Scaffold(
              body: Center(child: Text('Idle')),
            ),
          ),
          GoRoute(
            path: '/products',
            builder: (context, state) => const Scaffold(
              body: Center(child: Text('Products')),
            ),
          ),
        ],
      );

      await tester.pumpWidget(
        MaterialApp.router(
          routerConfig: router,
          localizationsDelegates: const [
            AppLocalizations.delegate,
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          supportedLocales: const [Locale('de'), Locale('en')],
          locale: const Locale('de'),
        ),
      );

      // Resolve the FutureBuilder, then run the post-frame callback.
      await tester.pump();
      await tester.pump();
      await tester.pump();
    }

    testWidgets('keeps the receipt on the member it was issued to when the '
        'session is taken over (#26)', (WidgetTester tester) async {
      final anna = MembersCacheData(
        id: 'member-a',
        cardUid: '1',
        firstName: 'Anna',
        lastName: 'Member',
        preferredLanguage: 'de',
        isActive: 1,
        isSepaValid: 1,
        balanceCents: 0,
        updatedAt: '2025-02-01T10:00:00Z',
      );
      // A real provider: the point of the test is what happens when it
      // *notifies*, which a mocked addListener would swallow.
      final membersService = MockMembersService();
      when(() => membersService.getEffectiveBalance(any()))
          .thenAnswer((_) async => -1250);
      // Offline: the balance refresh finds nothing fresh, so the cached
      // member stands (#374).
      when(() => membersService.refreshBalance(any()))
          .thenAnswer((_) async => null);
      final membersProvider = MembersProvider(service: membersService);
      await membersProvider.setSelectedMember(anna);

      await pumpReceipt(tester, membersProvider: membersProvider);
      expect(find.text('Anna Member'), findsOneWidget);
      expect(find.text('Guthaben: 12,50\u00a0€'), findsOneWidget);

      // A card tap on this screen ends Anna's session and starts the next
      // member's before the receipt fades out — the receipt must not repaint
      // with a cleared or foreign identity.
      membersProvider.clearSelectedMember();
      await tester.pump();

      expect(find.text('Anna Member'), findsOneWidget);
      expect(find.text('Guthaben: 12,50\u00a0€'), findsOneWidget);
    });

    testWidgets('says what was booked: every line, its count, and the total',
        (WidgetTester tester) async {
      await pumpReceipt(tester);

      expect(find.text('Buchung erfolgreich!'), findsOneWidget);
      expect(find.byIcon(Icons.check_circle), findsOneWidget);

      // The lines the member put in the cart, in the reader's language.
      expect(find.text('2 ×'), findsOneWidget);
      expect(find.text('Pils 0,5l'), findsOneWidget);
      expect(find.text('7,00\u00a0€'), findsOneWidget);
      expect(find.text('Brezel'), findsOneWidget);
      expect(find.text('Sauna-Session'), findsOneWidget);
      expect(find.text('5,00\u00a0€'), findsOneWidget);
      // ...and what it came to.
      expect(find.text('Gesamt'), findsOneWidget);
      expect(find.text('14,50\u00a0€'), findsOneWidget);
    });

    testWidgets('says where the tab stands now, under its own caption',
        (WidgetTester tester) async {
      await pumpReceipt(tester);

      expect(find.text('Dein Deckel jetzt'), findsOneWidget);
      // memberDeckel is the balance *after* the purchase — the cart screen
      // awaits refreshDeckel() before navigating here.
      final balance = tester.widget<Text>(find.byKey(const Key('receipt-balance')));
      expect(balance.data, 'Offener Betrag: 14,50\u00a0€');
      expect(balance.style!.color, balanceColor(1450));
    });

    testWidgets('has no buttons at all', (WidgetTester tester) async {
      await pumpReceipt(tester);

      // Members were unsure what a button on a finished purchase would do
      // to it. The receipt states the facts and leaves by itself.
      expect(find.byType(ElevatedButton), findsNothing);
      expect(find.byType(FilledButton), findsNothing);
      expect(find.byType(OutlinedButton), findsNothing);
      expect(find.byType(TextButton), findsNothing);
      expect(find.text('Fertig'), findsNothing);
      expect(find.text('Weiter einkaufen'), findsNothing);
      // Nor a number to keep checking on.
      expect(find.textContaining('Weiterleitung'), findsNothing);
      expect(find.textContaining('Sekunden'), findsNothing);
    });

    testWidgets('never shows the raw session UUID (#25)',
        (WidgetTester tester) async {
      await pumpReceipt(tester);

      expect(find.text('sess-abc123'), findsNothing);
    });

    testWidgets('leaves on its own after 8 seconds (ADR-0027 rule 10)',
        (WidgetTester tester) async {
      await pumpReceipt(tester);

      // Just short of the dwell: still on the receipt.
      await tester.pump(const Duration(seconds: 7));
      expect(find.text('Idle'), findsNothing);

      await tester.pump(const Duration(seconds: 1));
      await tester.pumpAndSettle();

      expect(find.text('Idle'), findsOneWidget);
      // Checkout completion ends the session (ADR-0027); endSession() owns
      // clearing the cart and the selected member.
      verify(() => mockSessionController.endSession()).called(1);
    });

    testWidgets('shows the time it has left as a bar that drains, not a count',
        (WidgetTester tester) async {
      await pumpReceipt(tester);

      double dwellFraction() => double.parse(
            tester
                .getSemantics(find.byKey(const Key('receipt-dwell')))
                .value,
          );

      expect(dwellFraction(), 1.0);
      await tester.pump(const Duration(seconds: 4));
      expect(dwellFraction(), 0.5);
      await tester.pump(const Duration(seconds: 2));
      expect(dwellFraction(), 0.25);
    });

    testWidgets('a tap anywhere dismisses it at once',
        (WidgetTester tester) async {
      await pumpReceipt(tester);

      // Not on a control — on the receipt itself.
      await tester.tap(find.text('Buchung erfolgreich!'));
      await tester.pumpAndSettle();

      verify(() => mockSessionController.endSession()).called(1);
      expect(find.text('Idle'), findsOneWidget);
    });

    testWidgets('stays put when the session refuses to end (ADR-0027 rule 7)',
        (WidgetTester tester) async {
      // A critical operation in flight makes endSession() refuse. Navigating
      // anyway lands on /idle with a member still selected, which the router
      // bounces straight back to /products — a session that was supposed to
      // be over, resumed.
      when(() => mockSessionController.endSession()).thenReturn(false);

      await pumpReceipt(tester);
      await tester.tap(find.byKey(const Key('receipt')));
      await tester.pumpAndSettle();

      expect(find.text('Idle'), findsNothing);
      expect(find.text('Buchung erfolgreich!'), findsOneWidget);
    });

    group('partial dispense', () {
      setUp(() {
        when(() => mockRepo.getSessionTotal(any()))
            .thenAnswer((_) async => 600);
        when(() => mockRepo.getSessionLines(any()))
            .thenAnswer((_) async => _partialRound);
      });

      testWidgets('says how many came out, and what the round would have cost',
          (WidgetTester tester) async {
        await pumpReceipt(tester);

        expect(find.text('Nur 3 Tokens ausgegeben'), findsOneWidget);
        expect(find.byIcon(Icons.warning_amber_rounded), findsOneWidget);
        // The line counts what came out...
        expect(find.text('3 ×'), findsOneWidget);
        expect(find.text('Sauna-Token'), findsOneWidget);
        // ...the total is what was billed, with the full price struck through.
        expect(find.text('6,00\u00a0€'), findsNWidgets(2)); // line and total
        final struck = tester.widget<Text>(find.text('10,00\u00a0€'));
        expect(struck.style!.decoration, TextDecoration.lineThrough);
      });

      testWidgets('stays longer, because it needs reading',
          (WidgetTester tester) async {
        await pumpReceipt(tester);

        // Well past the ordinary dwell: still here.
        await tester.pump(const Duration(seconds: 12));
        expect(find.text('Idle'), findsNothing);
        verifyNever(() => mockSessionController.endSession());

        await tester.pump(const Duration(seconds: 8));
        await tester.pumpAndSettle();
        expect(find.text('Idle'), findsOneWidget);
      });

      testWidgets('has no confirm button either — a tap dismisses it',
          (WidgetTester tester) async {
        await pumpReceipt(tester);

        expect(find.byType(ElevatedButton), findsNothing);
        expect(find.text('Alles klar!'), findsNothing);

        await tester.tap(find.byKey(const Key('receipt')));
        await tester.pumpAndSettle();
        expect(find.text('Idle'), findsOneWidget);
      });
    });

    group('when the receipt cannot be read back (#16)', () {
      setUp(() {
        when(() => mockRepo.getSessionTotal(any()))
            .thenThrow(Exception('session gone'));
      });

      testWidgets('still confirms the purchase, and says what is missing',
          (WidgetTester tester) async {
        await pumpReceipt(tester);

        // The purchase happened — say so, and explain what is missing.
        expect(find.text('Buchung erfolgreich!'), findsOneWidget);
        expect(find.byIcon(Icons.check_circle), findsOneWidget);
        expect(find.textContaining('Belegdaten'), findsOneWidget);
        // Never a bare spinner: that is indistinguishable from loading.
        expect(find.byType(CircularProgressIndicator), findsNothing);
        // No lines — it could not read them — but no UUID either (#25).
        expect(find.text('Pils 0,5l'), findsNothing);
        expect(find.text('sess-abc123'), findsNothing);
      });

      testWidgets('falls back to what the checkout recorded as billed',
          (WidgetTester tester) async {
        await pumpReceipt(tester);

        // The cart is already empty here; the €25.00 comes from what
        // checkout recorded as billed.
        expect(find.text('Gesamt'), findsOneWidget);
        expect(find.text('25,00\u00a0€'), findsOneWidget);
        // The tab is known regardless — it was refreshed before navigating.
        expect(find.text('Dein Deckel jetzt'), findsOneWidget);
        expect(find.text('Offener Betrag: 14,50\u00a0€'), findsOneWidget);
      });

      testWidgets('omits an amount it does not have',
          (WidgetTester tester) async {
        when(() => mockCartProvider.lastCheckoutTotalCents).thenReturn(0);
        await pumpReceipt(tester);

        // Still a receipt — just without a figure it cannot vouch for.
        expect(find.text('Buchung erfolgreich!'), findsOneWidget);
        expect(find.byKey(const Key('receipt-total')), findsNothing);
      });

      testWidgets('is not bounced to idle — it gets the longer dwell',
          (WidgetTester tester) async {
        await pumpReceipt(tester);

        // The unexplained bounce after 8 s was the bug (#16).
        await tester.pump(const Duration(seconds: 12));
        expect(find.text('Idle'), findsNothing);
        verifyNever(() => mockSessionController.endSession());

        // But a walked-away terminal must not sit on a receipt forever.
        await tester.pump(const Duration(seconds: 8));
        await tester.pumpAndSettle();
        expect(find.text('Idle'), findsOneWidget);
      });

      testWidgets('is dismissed by a tap', (WidgetTester tester) async {
        await pumpReceipt(tester);

        await tester.tap(find.text('Buchung erfolgreich!'));
        await tester.pumpAndSettle();

        verify(() => mockSessionController.endSession()).called(1);
        expect(find.text('Idle'), findsOneWidget);
      });
    });
  });
}
