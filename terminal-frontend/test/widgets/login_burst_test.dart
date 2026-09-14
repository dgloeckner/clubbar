// The login success animation: quick, one-shot, and skippable for reduced
// motion. The burst is a celebration, not a loading screen — so the tests
// hold it to finishing on time and to never blocking the member's next tap.
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/utils/greeting.dart';
import 'package:clubbar_terminal/widgets/login_success_overlay.dart';
import '../test_helpers.dart';

void main() {
  /// One evening, pinned: the greeting depends on the terminal's clock
  /// (#929) and a test that read the real one would say something different
  /// before lunch.
  final evening = DateTime(2026, 9, 13, 20, 42);

  late AppLocalizations l10n;

  /// The greeting a member actually sees, resolved from the ARB rather than
  /// copied out as a literal.
  late String annaGreeting;

  setUpAll(() async {
    l10n = await AppLocalizations.delegate.load(const Locale('de'));
    annaGreeting = l10n.loginWelcome(greetingText(l10n, evening), 'Anna');
  });

  Widget buildBurst({
    required VoidCallback onCompleted,
    bool disableAnimations = false,
    DateTime? now,
    Key? key,
  }) =>
      createTestApp(
        child: MediaQuery(
          data: MediaQueryData(disableAnimations: disableAnimations),
          child: Stack(
            children: [
              LoginBurst(
                key: key,
                firstName: 'Anna',
                onCompleted: onCompleted,
                now: now ?? evening,
              ),
            ],
          ),
        ),
      );

  group('LoginBurst', () {
    testWidgets('greets the member by first name mid-animation',
        (tester) async {
      await tester.pumpWidget(buildBurst(onCompleted: () {}));
      // Two thirds in: the greeting has slid into place, the fade-out has
      // not started.
      await tester.pump(LoginBurst.duration * 0.66);

      expect(find.text(annaGreeting), findsOneWidget);
      expect(annaGreeting, 'Guten Abend, Anna!');
    });

    // Issue #929: the burst used to say "Hi Anna!" at nine in the morning
    // and at eleven at night. It reads the terminal's own clock now.
    testWidgets('greets by the hour of the day', (tester) async {
      for (final (at, expected) in [
        (DateTime(2026, 9, 13, 9), 'Guten Morgen, Anna!'),
        (DateTime(2026, 9, 13, 14), 'Hallo, Anna!'),
        (DateTime(2026, 9, 13, 23), 'Guten Abend, Anna!'),
      ]) {
        // A distinct key per hour: each login raises its own burst, and one
        // that reused the previous State would keep the previous greeting —
        // which is exactly the fixing-at-construction this asserts.
        await tester.pumpWidget(buildBurst(
          onCompleted: () {},
          now: at,
          key: ValueKey(at),
        ));
        await tester.pump(LoginBurst.duration * 0.66);

        expect(find.text(expected), findsOneWidget);
        await tester.pumpAndSettle();
      }
    });

    testWidgets('never blocks taps to the screen underneath', (tester) async {
      await tester.pumpWidget(buildBurst(onCompleted: () {}));
      await tester.pump(LoginBurst.duration * 0.5);

      final ignorePointer = tester.widget<IgnorePointer>(
        find
            .descendant(
              of: find.byType(LoginBurst),
              matching: find.byType(IgnorePointer),
            )
            .first,
      );
      expect(ignorePointer.ignoring, isTrue);
    });

    testWidgets('calls onCompleted exactly once when the burst has played out',
        (tester) async {
      var completed = 0;
      await tester.pumpWidget(buildBurst(onCompleted: () => completed++));

      await tester.pump(LoginBurst.duration * 0.9);
      expect(completed, 0, reason: 'still fading out — not done yet');

      await tester.pump(LoginBurst.duration * 0.2);
      expect(completed, 1);
    });

    testWidgets('reduced motion skips the celebration entirely',
        (tester) async {
      var completed = 0;
      await tester.pumpWidget(buildBurst(
        onCompleted: () => completed++,
        disableAnimations: true,
      ));
      await tester.pump();

      expect(completed, 1);
      expect(find.text(annaGreeting), findsNothing);
    });
  });
}
