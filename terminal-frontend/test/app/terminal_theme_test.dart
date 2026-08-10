import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/app/terminal_theme.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';

void main() {
  // Issue #301: every visible control was hand-painted except where one
  // wasn't — a bare ElevatedButton (the partial-dispense confirm button)
  // rendered in default Material 3 seed colors that matched nothing else in
  // the app. Central component themes make a bare widget correct by
  // construction instead of relying on every call site remembering to style
  // itself.
  group('buildTerminalTheme (#301)', () {
    testWidgets('a bare ElevatedButton renders the strong-blue/white style',
        (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          theme: buildTerminalTheme(),
          home: Scaffold(
            body: ElevatedButton(
              onPressed: () {},
              child: const Text('Confirm'),
            ),
          ),
        ),
      );

      // A bare button carries no style of its own — whatever it renders in
      // comes entirely from elevatedButtonTheme.
      final theme = Theme.of(tester.element(find.byType(ElevatedButton)));
      final resolvedBackground =
          theme.elevatedButtonTheme.style?.backgroundColor?.resolve({});
      final resolvedForeground =
          theme.elevatedButtonTheme.style?.foregroundColor?.resolve({});

      expect(resolvedBackground, hexToColor(AppColors.semanticPrimaryStrong));
      expect(resolvedForeground, Colors.white);
    });

    testWidgets('a bare TextButton renders the secondary-text style',
        (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          theme: buildTerminalTheme(),
          home: Scaffold(
            body: TextButton(
              onPressed: () {},
              child: const Text('Dismiss'),
            ),
          ),
        ),
      );

      final theme = Theme.of(tester.element(find.byType(TextButton)));
      final resolvedForeground =
          theme.textButtonTheme.style?.foregroundColor?.resolve({});

      expect(resolvedForeground, hexToColor(AppColors.textSecondary));
    });

    test('bottom sheets and dialogs use the app card background', () {
      final theme = buildTerminalTheme();

      expect(theme.bottomSheetTheme.backgroundColor,
          hexToColor(AppColors.bgCard));
      expect(theme.bottomSheetTheme.modalBackgroundColor,
          hexToColor(AppColors.bgCard));
      expect(theme.dialogTheme.backgroundColor, hexToColor(AppColors.bgCard));
    });
  });
}
