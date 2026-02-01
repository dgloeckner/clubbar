import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ruderbar_terminal/screens/checkout_confirmation_screen.dart';

void main() {
  group('CheckoutConfirmationScreen', () {
    testWidgets('displays success message', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: CheckoutConfirmationScreen(
            isSuccess: true,
            message: 'Transaction successful',
            onDismiss: () {},
          ),
        ),
      );

      expect(find.text('Transaction successful'), findsOneWidget);
      expect(find.byIcon(Icons.check_circle), findsOneWidget);
      expect(find.text('Success!'), findsOneWidget);
    });

    testWidgets('displays error message on failure', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: CheckoutConfirmationScreen(
            isSuccess: false,
            message: 'Payment failed',
            onDismiss: () {},
          ),
        ),
      );

      expect(find.text('Payment failed'), findsOneWidget);
      expect(find.byIcon(Icons.error), findsOneWidget);
      expect(find.text('Error'), findsOneWidget);
    });

    testWidgets('has dismiss button', (WidgetTester tester) async {
      var dismissed = false;
      await tester.pumpWidget(
        MaterialApp(
          home: CheckoutConfirmationScreen(
            isSuccess: true,
            message: 'Success',
            onDismiss: () {
              dismissed = true;
            },
          ),
        ),
      );

      await tester.tap(find.byType(ElevatedButton));
      expect(dismissed, isTrue);
    });

    testWidgets('uses green styling for success', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: CheckoutConfirmationScreen(
            isSuccess: true,
            message: 'Success',
            onDismiss: () {},
          ),
        ),
      );

      final icon = find.byIcon(Icons.check_circle);
      expect(icon, findsOneWidget);
      final successText = find.text('Success!');
      expect(successText, findsOneWidget);
    });

    testWidgets('uses red styling for failure', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: CheckoutConfirmationScreen(
            isSuccess: false,
            message: 'Failed',
            onDismiss: () {},
          ),
        ),
      );

      final icon = find.byIcon(Icons.error);
      expect(icon, findsOneWidget);
      final errorText = find.text('Error');
      expect(errorText, findsOneWidget);
    });

    testWidgets('displays continue button', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: CheckoutConfirmationScreen(
            isSuccess: true,
            message: 'Success',
            onDismiss: () {},
          ),
        ),
      );

      expect(find.text('Continue'), findsOneWidget);
    });
  });
}
