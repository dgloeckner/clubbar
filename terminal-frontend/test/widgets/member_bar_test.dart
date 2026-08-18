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
