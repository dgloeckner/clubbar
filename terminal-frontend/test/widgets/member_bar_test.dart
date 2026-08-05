import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
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

  Widget buildTestWidget({VoidCallback? onLogoutPressed}) {
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
            member: _member,
            itemCount: 0,
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
