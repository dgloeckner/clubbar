import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/widgets/styled_components/category_chip.dart';

CategoriesCacheData _category() => CategoriesCacheData(
      id: 'cat-1',
      names: '{"de":"Getränke"}',
      isActive: 1,
      updatedAt: '2025-02-01T10:00:00Z',
    );

Widget _host(Widget child, {double width = 400}) => MaterialApp(
      home: Scaffold(
        body: Center(
          child: SizedBox(width: width, child: child),
        ),
      ),
    );

void main() {
  group('CategoryChip', () {
    // Issue #30: the label had no line or overflow policy, so a long German
    // category name in a narrow chip overflowed instead of ellipsizing.
    testWidgets('truncates a long label instead of overflowing',
        (WidgetTester tester) async {
      await tester.pumpWidget(
        _host(
          CategoryChip(
            category: _category(),
            categoryName: 'Alkoholfreie Getränke und Erfrischungen',
            selected: false,
            onSelected: () {},
          ),
          width: 200,
        ),
      );

      expect(tester.takeException(), isNull);

      final label = tester.widget<Text>(
        find.text('Alkoholfreie Getränke und Erfrischungen'),
      );
      expect(label.maxLines, 1);
      expect(label.overflow, TextOverflow.ellipsis);
    });

    testWidgets('a short label is not clipped', (WidgetTester tester) async {
      await tester.pumpWidget(
        _host(
          CategoryChip(
            category: _category(),
            categoryName: 'Bier',
            selected: true,
            onSelected: () {},
          ),
        ),
      );

      expect(tester.takeException(), isNull);
      expect(find.text('Bier'), findsOneWidget);
    });
  });
}
