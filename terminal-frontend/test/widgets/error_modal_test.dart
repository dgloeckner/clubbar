import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ruderbar_terminal/widgets/error_modal.dart';
import '../test_helpers.dart';

void main() {
  group('ErrorModal', () {
    testWidgets('displays error message and dismiss button', (WidgetTester tester) async {
      await tester.pumpWidget(
        createTestApp(
          child: Scaffold(
            body: Center(
              child: ElevatedButton(
                onPressed: () {
                  showErrorModal(
                    tester.element(find.byType(ElevatedButton)),
                    'Test error message',
                  );
                },
                child: const Text('Show Error'),
              ),
            ),
          ),
        ),
      );

      await tester.tap(find.byType(ElevatedButton));
      await tester.pumpAndSettle();

      expect(find.text('Test error message'), findsOneWidget);
      expect(find.text('Schließen'), findsOneWidget); // "Dismiss" in German
      expect(find.text('Fehler'), findsOneWidget); // "Error" in German
    });

    testWidgets('displays retry button when onRetry callback provided', (WidgetTester tester) async {
      bool retryPressed = false;

      await tester.pumpWidget(
        createTestApp(
          child: Scaffold(
            body: Center(
              child: ElevatedButton(
                onPressed: () {
                  showErrorModal(
                    tester.element(find.byType(ElevatedButton)),
                    'Test error with retry',
                    onRetry: () {
                      retryPressed = true;
                    },
                  );
                },
                child: const Text('Show Error'),
              ),
            ),
          ),
        ),
      );

      await tester.tap(find.byType(ElevatedButton));
      await tester.pumpAndSettle();

      expect(find.text('Erneut versuchen'), findsOneWidget); // "Retry" in German
      expect(find.text('Schließen'), findsOneWidget); // "Dismiss" in German

      await tester.tap(find.text('Erneut versuchen'));
      await tester.pumpAndSettle();

      expect(retryPressed, isTrue);
    });

    testWidgets('dismiss button closes dialog without calling retry', (WidgetTester tester) async {
      bool retryPressed = false;

      await tester.pumpWidget(
        createTestApp(
          child: Scaffold(
            body: Center(
              child: ElevatedButton(
                onPressed: () {
                  showErrorModal(
                    tester.element(find.byType(ElevatedButton)),
                    'Test error',
                    onRetry: () {
                      retryPressed = true;
                    },
                  );
                },
                child: const Text('Show Error'),
              ),
            ),
          ),
        ),
      );

      await tester.tap(find.byType(ElevatedButton));
      await tester.pumpAndSettle();

      await tester.tap(find.text('Schließen')); // "Dismiss" in German
      await tester.pumpAndSettle();

      expect(find.byType(AlertDialog), findsNothing);
      expect(retryPressed, isFalse);
    });
  });
}
