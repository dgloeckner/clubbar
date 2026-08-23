// The login success animation: quick, one-shot, and skippable for reduced
// motion. The burst is a celebration, not a loading screen — so the tests
// hold it to finishing on time and to never blocking the member's next tap.
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/widgets/login_success_overlay.dart';
import '../test_helpers.dart';

void main() {
  /// The greeting a member actually sees, resolved from the ARB rather than
  /// copied out as a literal.
  late String annaGreeting;

  setUpAll(() async {
    final l10n = await AppLocalizations.delegate.load(const Locale('de'));
    annaGreeting = l10n.loginWelcome('Anna');
  });

  Widget buildBurst({
    required VoidCallback onCompleted,
    bool disableAnimations = false,
  }) =>
      createTestApp(
        child: MediaQuery(
          data: MediaQueryData(disableAnimations: disableAnimations),
          child: Stack(
            children: [
              LoginBurst(firstName: 'Anna', onCompleted: onCompleted),
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
