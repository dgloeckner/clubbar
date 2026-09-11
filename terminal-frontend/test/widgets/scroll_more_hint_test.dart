import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/widgets/scroll_more_hint.dart';

void main() {
  Future<void> pumpList(WidgetTester tester, int lines) => tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: SizedBox(
              height: 300,
              child: ScrollMoreHint(
                child: ListView.builder(
                  itemCount: lines,
                  itemBuilder: (context, i) =>
                      SizedBox(height: 60, child: Text('Line $i')),
                ),
              ),
            ),
          ),
        ),
      );

  double hintOpacity(WidgetTester tester) => tester
      .widget<AnimatedOpacity>(find.byKey(const Key('scroll-more-hint')))
      .opacity;

  /// The failure it exists for: a cart line below the fold read as missing.
  testWidgets('shows while content remains below the fold', (tester) async {
    await pumpList(tester, 20);
    await tester.pumpAndSettle();

    expect(hintOpacity(tester), 1.0);
  });

  testWidgets('goes once the member reaches the end', (tester) async {
    await pumpList(tester, 20);
    await tester.pumpAndSettle();

    await tester.drag(find.byType(ListView), const Offset(0, -5000));
    await tester.pumpAndSettle();

    expect(hintOpacity(tester), 0.0);
  });

  testWidgets('never shows on a list that fits', (tester) async {
    await pumpList(tester, 3);
    await tester.pumpAndSettle();

    expect(hintOpacity(tester), 0.0);
  });

  testWidgets('does not take the tap of the line under it', (tester) async {
    var tapped = false;
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: SizedBox(
            height: 300,
            child: ScrollMoreHint(
              child: ListView(
                children: [
                  for (var i = 0; i < 20; i++)
                    GestureDetector(
                      onTap: i == 4 ? () => tapped = true : null,
                      child: SizedBox(height: 60, child: Text('Line $i')),
                    ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    // Line 4 sits at 240–300: under the fade.
    await tester.tapAt(const Offset(20, 290));
    expect(tapped, isTrue);
  });
}
