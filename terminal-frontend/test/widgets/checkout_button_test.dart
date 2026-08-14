import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/widgets/checkout_button.dart';

import '../test_helpers.dart';

/// The checkout button's blocked states (#395).
///
/// Two conditions can stop a sale, and the button has to say which: a member
/// over their credit limit is a decision about one sale, while an expired
/// terminal credential means no sale can be banked at all. The second outranks
/// the first, because it is the one that explains why the till is useless.
void main() {
  Widget wrap({
    bool isLoading = false,
    bool isBlockedByLimit = false,
    bool isBlockedByCredential = false,
    required Future<void> Function() onPressed,
  }) {
    return createTestApp(
      child: Scaffold(
        body: CheckoutButton(
          isLoading: isLoading,
          isBlockedByLimit: isBlockedByLimit,
          isBlockedByCredential: isBlockedByCredential,
          onPressed: onPressed,
        ),
      ),
    );
  }

  testWidgets('an unblocked button runs the checkout', (tester) async {
    var pressed = false;
    await tester.pumpWidget(wrap(onPressed: () async {
      pressed = true;
    }));

    await tester.tap(find.byType(CheckoutButton));
    await tester.pumpAndSettle();

    expect(pressed, isTrue);
  });

  testWidgets('an expired credential stops the sale', (tester) async {
    var pressed = false;
    await tester.pumpWidget(wrap(
      isBlockedByCredential: true,
      onPressed: () async {
        pressed = true;
      },
    ));

    await tester.tap(find.byType(CheckoutButton));
    await tester.pumpAndSettle();

    expect(pressed, isFalse);
    expect(find.text('Verkauf gesperrt'), findsOneWidget);
  });

  testWidgets('the credential block outranks the credit-limit block',
      (tester) async {
    await tester.pumpWidget(wrap(
      isBlockedByLimit: true,
      isBlockedByCredential: true,
      onPressed: () async {},
    ));

    // Both are true; the label has to name the one that explains the till.
    expect(find.text('Verkauf gesperrt'), findsOneWidget);
  });
}
