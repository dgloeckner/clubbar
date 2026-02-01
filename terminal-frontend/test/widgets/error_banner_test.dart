import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ruderbar_terminal/widgets/error_banner.dart';

void main() {
  group('ErrorBanner', () {
    testWidgets('displays error message', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: Column(
              children: [
                const ErrorBanner(message: 'Test error occurred'),
                const SizedBox(height: 10),
              ],
            ),
          ),
        ),
      );

      expect(find.text('Test error occurred'), findsOneWidget);
    });

    testWidgets('hides when message is null', (WidgetTester tester) async {
      await tester.pumpWidget(
        const MaterialApp(
          home: Scaffold(
            body: ErrorBanner(message: null),
          ),
        ),
      );

      expect(find.byType(ErrorBanner), findsOneWidget);
      expect(find.text('Test error'), findsNothing);
    });

    testWidgets('hides when message is empty string', (WidgetTester tester) async {
      await tester.pumpWidget(
        const MaterialApp(
          home: Scaffold(
            body: ErrorBanner(message: ''),
          ),
        ),
      );

      expect(find.byType(Container), findsNothing);
    });

    testWidgets('displays with error icon', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: Column(
              children: [
                const ErrorBanner(message: 'Error'),
                const SizedBox(height: 10),
              ],
            ),
          ),
        ),
      );

      expect(find.byIcon(Icons.error), findsOneWidget);
    });

    testWidgets('has dismiss button to clear error', (WidgetTester tester) async {
      var dismissed = false;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: Column(
              children: [
                ErrorBanner(
                  message: 'Error',
                  onDismiss: () {
                    dismissed = true;
                  },
                ),
                const SizedBox(height: 10),
              ],
            ),
          ),
        ),
      );

      await tester.tap(find.byIcon(Icons.close));
      expect(dismissed, isTrue);
    });

    testWidgets('no dismiss button when callback not provided', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: Column(
              children: [
                const ErrorBanner(message: 'Error'),
                const SizedBox(height: 10),
              ],
            ),
          ),
        ),
      );

      expect(find.byIcon(Icons.close), findsNothing);
    });
  });
}
