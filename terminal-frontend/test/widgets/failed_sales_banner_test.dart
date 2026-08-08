import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/models/failed_sale.dart';
import 'package:clubbar_terminal/providers/quarantine_provider.dart';
import 'package:clubbar_terminal/repository/members_repository.dart';
import 'package:clubbar_terminal/repository/transactions_repository.dart';
import 'package:clubbar_terminal/widgets/failed_sales_banner.dart';
import 'package:mocktail/mocktail.dart';

import '../test_helpers.dart';

class MockTransactionsRepository extends Mock
    implements TransactionsRepository {}

class MockMembersRepository extends Mock implements MembersRepository {}

/// A provider whose quarantine contents the test dictates directly — the
/// banner's contract is "show what the quarantine holds", not how it is read.
class FakeQuarantineProvider extends QuarantineProvider {
  FakeQuarantineProvider(this._sales)
      : super(
          transactionsRepo: MockTransactionsRepository(),
          membersRepo: MockMembersRepository(),
        );

  final List<FailedSale> _sales;

  @override
  List<FailedSale> get failedSales => _sales;

  @override
  bool get hasFailedSales => _sales.isNotEmpty;
}

void main() {
  Widget wrap(QuarantineProvider provider) {
    return createTestApp(
      child: ChangeNotifierProvider<QuarantineProvider>.value(
        value: provider,
        child: const Scaffold(body: FailedSalesBanner()),
      ),
    );
  }

  final sale = FailedSale(
    transactionId: 'txn-1',
    memberId: 'member-1',
    memberName: 'Max Mustermann',
    amountCents: 350,
    occurredAt: DateTime(2025, 2, 1, 20, 15),
    reason: 'unstorable',
  );

  testWidgets('an empty quarantine shows nothing', (tester) async {
    await tester.pumpWidget(wrap(FakeQuarantineProvider(const [])));

    expect(find.byKey(const Key('failed-sales-banner')), findsNothing);
  });

  testWidgets('a quarantined sale raises a persistent warning', (tester) async {
    await tester.pumpWidget(wrap(FakeQuarantineProvider([sale])));

    expect(find.byKey(const Key('failed-sales-banner')), findsOneWidget);
  });

  testWidgets('the warning opens a list naming member, amount and time',
      (tester) async {
    await tester.pumpWidget(wrap(FakeQuarantineProvider([sale])));

    await tester.tap(find.byKey(const Key('failed-sales-banner')));
    await tester.pumpAndSettle();

    expect(find.text('Max Mustermann'), findsOneWidget);
    expect(find.textContaining('3,50'), findsOneWidget);
    expect(find.textContaining('20:15'), findsOneWidget);
  });

  testWidgets('a sale whose member is unknown is still listed', (tester) async {
    final anonymous = FailedSale(
      transactionId: 'txn-2',
      memberId: 'member-gone',
      memberName: null,
      amountCents: 200,
      occurredAt: DateTime(2025, 2, 1, 21, 0),
      reason: 'not_found',
    );

    await tester.pumpWidget(wrap(FakeQuarantineProvider([anonymous])));
    await tester.tap(find.byKey(const Key('failed-sales-banner')));
    await tester.pumpAndSettle();

    expect(find.textContaining('2,00'), findsOneWidget);
  });
}
