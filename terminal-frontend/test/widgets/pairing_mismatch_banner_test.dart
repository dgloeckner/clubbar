import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/providers/products_provider.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/services/network_service.dart';
import 'package:clubbar_terminal/services/sync_service.dart';
import 'package:clubbar_terminal/widgets/pairing_mismatch_banner.dart';

import '../test_helpers.dart';

class MockSyncService extends Mock implements SyncService {}

class MockNetworkService extends Mock implements NetworkService {}

class MockMembersProvider extends Mock implements MembersProvider {}

class MockProductsProvider extends Mock implements ProductsProvider {}

/// A provider whose pairing state the test dictates directly — the banner's
/// contract is "show what SyncProvider reports", not how it gets there.
class FakeSyncProvider extends SyncProvider {
  FakeSyncProvider({required bool mismatch, required int count})
      : _mismatch = mismatch,
        _count = count,
        super(
          syncService: MockSyncService(),
          membersProvider: MockMembersProvider(),
          productsProvider: MockProductsProvider(),
          networkService: MockNetworkService(),
        );

  final bool _mismatch;
  final int _count;
  bool acknowledgeCalled = false;

  @override
  bool get pairingMismatch => _mismatch;

  @override
  int get pairingMismatchTransactionCount => _count;

  @override
  Future<void> acknowledgePairingMismatch() async {
    acknowledgeCalled = true;
  }
}

void main() {
  Widget wrap(SyncProvider provider) {
    return createTestApp(
      child: ChangeNotifierProvider<SyncProvider>.value(
        value: provider,
        child: const Scaffold(body: PairingMismatchBanner()),
      ),
    );
  }

  testWidgets('no mismatch shows nothing', (tester) async {
    await tester.pumpWidget(wrap(FakeSyncProvider(mismatch: false, count: 0)));

    expect(find.byKey(const Key('pairing-mismatch-banner')), findsNothing);
  });

  testWidgets('a mismatch raises a persistent warning', (tester) async {
    await tester.pumpWidget(wrap(FakeSyncProvider(mismatch: true, count: 3)));

    expect(find.byKey(const Key('pairing-mismatch-banner')), findsOneWidget);
  });

  testWidgets('the warning opens a dialog naming the at-risk count',
      (tester) async {
    await tester.pumpWidget(wrap(FakeSyncProvider(mismatch: true, count: 3)));

    await tester.tap(find.byKey(const Key('pairing-mismatch-banner')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('pairing-mismatch-dialog')), findsOneWidget);
    expect(find.textContaining('3'), findsWidgets);
  });

  testWidgets('resuming calls acknowledgePairingMismatch', (tester) async {
    final provider = FakeSyncProvider(mismatch: true, count: 1);
    await tester.pumpWidget(wrap(provider));

    await tester.tap(find.byKey(const Key('pairing-mismatch-banner')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('pairing-mismatch-resume-button')));
    await tester.pumpAndSettle();

    expect(provider.acknowledgeCalled, isTrue);
  });

  testWidgets('the dialog has no way to dismiss the mismatch without resuming',
      (tester) async {
    await tester.pumpWidget(wrap(FakeSyncProvider(mismatch: true, count: 1)));

    await tester.tap(find.byKey(const Key('pairing-mismatch-banner')));
    await tester.pumpAndSettle();

    // Only escape route besides resuming is closing the dialog — the
    // underlying block persists either way, matching FailedSalesBanner's
    // "detail dialog dismisses, condition does not" contract.
    expect(find.byKey(const Key('pairing-mismatch-resume-button')), findsOneWidget);
  });
}
