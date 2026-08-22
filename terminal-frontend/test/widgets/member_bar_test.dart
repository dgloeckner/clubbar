import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/widgets/member_bar.dart';

class MockSessionController extends Mock implements SessionController {}

final _member = MembersCacheData(
  id: 'member-1',
  cardUid: 'card-123',
  firstName: 'John',
  lastName: 'Doe',
  preferredLanguage: 'de',
  isActive: 1,
  isSepaValid: 1,
  balanceCents: 0,
  updatedAt: '2025-02-01T10:00:00Z',
);

void main() {
  late MockSessionController session;

  setUp(() {
    session = MockSessionController();
    when(() => session.addListener(any())).thenReturn(null);
    when(() => session.removeListener(any())).thenReturn(null);
    when(() => session.isCriticalOperationInFlight).thenReturn(false);
  });

  Widget buildTestWidget({VoidCallback? onLogoutPressed, int balanceCents = 0}) {
    return MaterialApp(
      locale: const Locale('de'),
      localizationsDelegates: const [
        AppLocalizations.delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      supportedLocales: const [Locale('en'), Locale('de')],
      home: ChangeNotifierProvider<SessionController>.value(
        value: session,
        child: Scaffold(
          body: MemberBar(
            member: _member.copyWith(balanceCents: balanceCents),
            onLogoutPressed: onLogoutPressed,
          ),
        ),
      ),
    );
  }

  InkWell logoutButton(WidgetTester tester) => tester.widget<InkWell>(
        find.descendant(
          of: find.byKey(const Key('member-bar-logout')),
          matching: find.byType(InkWell),
        ),
      );

  /// The balance line, located by its label prefix — the amount carries a
  /// locale-specific non-breaking space, so it is matched loosely.
  Text balanceText(WidgetTester tester, String startsWith) => tester.widget<Text>(
        find.byWidgetPredicate(
          (w) => w is Text && (w.data ?? '').startsWith(startsWith),
        ),
      );

  group('MemberBar balance (#28)', () {
    testWidgets('labels an open tab and colours it neutral below the threshold',
        (tester) async {
      await tester.pumpWidget(buildTestWidget(balanceCents: 1480));

      final text = balanceText(tester, 'Offener Betrag: 14,80');
      expect(text.style?.color, AppColors.textPrimary);
    });

    testWidgets('colours a large open tab amber', (tester) async {
      await tester.pumpWidget(
        buildTestWidget(balanceCents: AppMoney.warnAboveCents + 1),
      );

      final text = balanceText(tester, 'Offener Betrag: 20,01');
      expect(text.style?.color, AppColors.semanticWarning);
    });

    testWidgets('labels credit and colours it green', (tester) async {
      await tester.pumpWidget(buildTestWidget(balanceCents: -500));

      final text = balanceText(tester, 'Guthaben: 5,00');
      expect(text.style?.color, AppColors.semanticSuccess);
    });

    testWidgets('a settled account is never shown as a warning',
        (tester) async {
      await tester.pumpWidget(buildTestWidget(balanceCents: 0));

      // Settled accounts get their own wording, not "Offener Betrag: 0,00"
      // (#296).
      final text = balanceText(tester, 'Nichts offen');
      expect(text.style?.color, AppColors.textPrimary);
    });
  });

  group('MemberBar details affordance (#39)', () {
    testWidgets(
        'shows a chevron and a tappable ripple over the whole member cluster',
        (tester) async {
      await tester.pumpWidget(buildTestWidget());

      expect(
        find.descendant(
          of: find.byKey(const Key('member-bar-details')),
          matching: find.byIcon(Icons.chevron_right),
        ),
        findsOneWidget,
      );

      final detailsInkWell = tester.widget<InkWell>(
        find.descendant(
          of: find.byKey(const Key('member-bar-details')),
          matching: find.byType(InkWell),
        ),
      );
      expect(detailsInkWell.onTap, isNotNull);

      // Avatar and name/balance both sit inside the same tappable cluster.
      final detailsFinder = find.byKey(const Key('member-bar-details'));
      expect(
        find.descendant(of: detailsFinder, matching: find.text('John Doe')),
        findsOneWidget,
      );
    });
  });

  group('MemberBar logout button is findable (member feedback)', () {
    // Members reported not finding the logout control, and calling it too
    // small. It was 52x52 — over the 44 px minimum — so the size was never
    // the real complaint: an unlabelled grey square on a grey bar reads as
    // decoration, and what is hard to see reads as small.

    Finder logoutChip() => find.descendant(
          of: find.byKey(const Key('member-bar-logout')),
          matching: find.byType(Container),
        );

    testWidgets('says what it does, in the member language', (tester) async {
      await tester.pumpWidget(buildTestWidget(onLogoutPressed: () {}));

      // 'Abmelden' existed in both .arb files and was wired to nothing.
      expect(
        find.descendant(
          of: find.byKey(const Key('member-bar-logout')),
          matching: find.text('Abmelden'),
        ),
        findsOneWidget,
      );
    });

    testWidgets('uses the arrow that points out, not in', (tester) async {
      await tester.pumpWidget(buildTestWidget(onLogoutPressed: () {}));

      // Icons.exit_to_app points *into* a bracket and is routinely read as
      // "log in" — the opposite of what this button does.
      expect(
        find.descendant(
          of: find.byKey(const Key('member-bar-logout')),
          matching: find.byIcon(Icons.logout),
        ),
        findsOneWidget,
      );
      expect(find.byIcon(Icons.exit_to_app), findsNothing);
    });

    testWidgets('has a boundary that clears WCAG 1.4.11', (tester) async {
      await tester.pumpWidget(buildTestWidget(onLogoutPressed: () {}));

      final decoration =
          tester.widget<Container>(logoutChip()).decoration as BoxDecoration;
      // The token is what carries the 3:1; contrast_test.dart holds the ratio
      // itself, this only pins that the button actually reaches for it.
      expect(decoration.border, isA<Border>());
      expect((decoration.border! as Border).top.color,
          AppColors.borderStrong);
    });

    testWidgets('is a wide target, and still exactly one bar tall',
        (tester) async {
      await tester.pumpWidget(buildTestWidget(onLogoutPressed: () {}));

      final size = tester.getSize(logoutChip().first);
      // Height is load-bearing: on the product screen this is the only
      // control in the row and it sets the band's height (#369).
      expect(size.height, 52.0);
      // The label is what makes it a button rather than a glyph; a square
      // would mean the label is gone again.
      expect(size.width, greaterThan(100.0));
    });

    testWidgets('announces itself once, as a button', (tester) async {
      final handle = tester.ensureSemantics();
      await tester.pumpWidget(buildTestWidget(onLogoutPressed: () {}));

      final node = tester.getSemantics(
        find.descendant(
          of: find.byKey(const Key('member-bar-logout')),
          matching: find.byType(InkWell),
        ),
      );
      expect(node.flagsCollection.isButton, isTrue,
          reason: 'InkWell does not set this on its own');
      // Exactly once: labelling the Semantics node *and* showing the text
      // made a screen reader read "Abmelden Abmelden".
      expect(node.label, 'Abmelden');
      handle.dispose();
    });

    testWidgets('a long member name yields rather than pushing it off-screen',
        (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          locale: const Locale('de'),
          localizationsDelegates: const [
            AppLocalizations.delegate,
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          supportedLocales: const [Locale('en'), Locale('de')],
          home: ChangeNotifierProvider<SessionController>.value(
            value: session,
            child: Scaffold(
              body: MemberBar(
                member: MembersCacheData(
                  id: 'member-2',
                  cardUid: 'card-456',
                  firstName: 'Maximiliane-Charlotte',
                  lastName: 'von Hohenberg-Lichtenstein',
                  preferredLanguage: 'de',
                  isActive: 1,
                  isSepaValid: 1,
                  balanceCents: 0,
                  updatedAt: '2025-02-01T10:00:00Z',
                ),
                onLogoutPressed: () {},
                showBackButton: true,
              ),
            ),
          ),
        ),
      );

      // No RenderFlex overflow, and the button is still whole.
      expect(tester.takeException(), isNull);
      expect(tester.getSize(logoutChip().first).width, greaterThan(100.0));
    });
  });

  group('MemberBar back arrow', () {
    Widget withBackButton() => MaterialApp(
          locale: const Locale('de'),
          localizationsDelegates: const [
            AppLocalizations.delegate,
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          supportedLocales: const [Locale('en'), Locale('de')],
          home: ChangeNotifierProvider<SessionController>.value(
            value: session,
            child: Scaffold(
              body: MemberBar(
                member: _member,
                showBackButton: true,
                onBackPressed: () {},
                onLogoutPressed: () {},
              ),
            ),
          ),
        );

    testWidgets('has a boundary that clears WCAG 1.4.11 too', (tester) async {
      await tester.pumpWidget(withBackButton());

      final decoration = tester
          .widget<Container>(
            find
                .descendant(
                  of: find.byKey(const Key('member-bar-back')),
                  matching: find.byType(Container),
                )
                .first,
          )
          .decoration as BoxDecoration;
      // Was a 40% blue at 1.8:1 — invisible as an edge; only the white glyph
      // inside kept the control findable.
      expect((decoration.border! as Border).top.color, AppColors.borderStrong);
    });

    testWidgets('has an accessible name without a visible caption',
        (tester) async {
      final handle = tester.ensureSemantics();
      await tester.pumpWidget(withBackButton());

      final node = tester.getSemantics(
        find.descendant(
          of: find.byKey(const Key('member-bar-back')),
          matching: find.byType(InkWell),
        ),
      );
      expect(node.flagsCollection.isButton, isTrue);
      expect(node.label, 'Zurück');

      // Deliberately no caption on screen: a left arrow is read correctly
      // without one, so the labelled logout next to it stays the louder of
      // the two. If this ever finds text, the asymmetry has been undone.
      expect(
        find.descendant(
          of: find.byKey(const Key('member-bar-back')),
          matching: find.byType(Text),
        ),
        findsNothing,
      );
      handle.dispose();
    });
  });

  group('MemberBar logout button', () {
    testWidgets('is enabled while no critical operation is in flight',
        (tester) async {
      await tester.pumpWidget(buildTestWidget(onLogoutPressed: () {}));

      expect(logoutButton(tester).onTap, isNotNull);
    });

    testWidgets('is disabled while a critical operation is in flight',
        (tester) async {
      when(() => session.isCriticalOperationInFlight).thenReturn(true);

      await tester.pumpWidget(buildTestWidget(onLogoutPressed: () {}));

      expect(logoutButton(tester).onTap, isNull);
    });

    testWidgets('tapping it during a critical operation does nothing',
        (tester) async {
      when(() => session.isCriticalOperationInFlight).thenReturn(true);
      var logoutTaps = 0;

      await tester.pumpWidget(
        buildTestWidget(onLogoutPressed: () => logoutTaps++),
      );
      await tester.tap(
        find.byKey(const Key('member-bar-logout')),
        warnIfMissed: false,
      );
      await tester.pump();

      expect(logoutTaps, 0);
    });
  });
}
