import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/widgets/loading_overlay.dart';

void main() {
  group('LoadingOverlay', () {
    testWidgets('displays spinner when loading is true', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: LoadingOverlay(
            isLoading: true,
            child: const Scaffold(body: Text('Content')),
          ),
        ),
      );

      expect(find.byType(CircularProgressIndicator), findsOneWidget);
    });

    testWidgets('shows child when loading is false', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: LoadingOverlay(
            isLoading: false,
            child: const Scaffold(body: Text('Content')),
          ),
        ),
      );

      expect(find.text('Content'), findsOneWidget);
      expect(find.byType(CircularProgressIndicator), findsNothing);
    });

    testWidgets('displays message when provided', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: LoadingOverlay(
            isLoading: true,
            message: 'Processing...',
            child: const Scaffold(body: Text('Content')),
          ),
        ),
      );

      expect(find.text('Processing...'), findsOneWidget);
    });

    testWidgets('does not display message when not provided', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: LoadingOverlay(
            isLoading: true,
            child: const Scaffold(body: Text('Content')),
          ),
        ),
      );

      expect(find.byType(CircularProgressIndicator), findsOneWidget);
    });

    testWidgets('child is not interactive when loading', (WidgetTester tester) async {
      var tapped = false;
      await tester.pumpWidget(
        MaterialApp(
          home: LoadingOverlay(
            isLoading: true,
            child: Scaffold(
              body: GestureDetector(
                onTap: () {
                  tapped = true;
                },
                child: const Text('Content'),
              ),
            ),
          ),
        ),
      );

      await tester.tap(find.text('Content'));
      expect(tapped, isFalse);
    });

    testWidgets('child is interactive when not loading', (WidgetTester tester) async {
      var tapped = false;
      await tester.pumpWidget(
        MaterialApp(
          home: LoadingOverlay(
            isLoading: false,
            child: Scaffold(
              body: GestureDetector(
                onTap: () {
                  tapped = true;
                },
                child: const Text('Content'),
              ),
            ),
          ),
        ),
      );

      await tester.tap(find.text('Content'));
      expect(tapped, isTrue);
    });

    testWidgets('overlay is transparent when not loading', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: LoadingOverlay(
            isLoading: false,
            child: const Scaffold(body: Text('Content')),
          ),
        ),
      );

      expect(find.text('Content'), findsOneWidget);
      expect(find.byType(CircularProgressIndicator), findsNothing);
    });
  });
}
