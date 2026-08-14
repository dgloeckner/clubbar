import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/providers/products_provider.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/services/network_service.dart';
import 'package:clubbar_terminal/services/sync_service.dart';
import 'package:clubbar_terminal/widgets/credential_expired_banner.dart';

import '../test_helpers.dart';

class MockSyncService extends Mock implements SyncService {}

class MockNetworkService extends Mock implements NetworkService {}

class MockMembersProvider extends Mock implements MembersProvider {}

class MockProductsProvider extends Mock implements ProductsProvider {}

/// A provider whose credential state the test dictates directly — the banner's
/// contract is "show what SyncProvider reports", not how it got there.
class FakeSyncProvider extends SyncProvider {
  FakeSyncProvider({required bool expired})
      : _expired = expired,
        super(
          syncService: MockSyncService(),
          membersProvider: MockMembersProvider(),
          productsProvider: MockProductsProvider(),
          networkService: MockNetworkService(),
        );

  final bool _expired;

  @override
  bool get credentialExpired => _expired;
}

void main() {
  Widget wrap(SyncProvider provider) {
    return createTestApp(
      child: ChangeNotifierProvider<SyncProvider>.value(
        value: provider,
        child: const Scaffold(body: CredentialExpiredBanner()),
      ),
    );
  }

  testWidgets('a working credential shows nothing', (tester) async {
    await tester.pumpWidget(wrap(FakeSyncProvider(expired: false)));

    expect(find.byKey(const Key('credential-expired-banner')), findsNothing);
  });

  testWidgets('an expired credential raises a persistent warning',
      (tester) async {
    await tester.pumpWidget(wrap(FakeSyncProvider(expired: true)));

    expect(find.byKey(const Key('credential-expired-banner')), findsOneWidget);
  });

  testWidgets('the banner opens a dialog that names who has to act',
      (tester) async {
    await tester.pumpWidget(wrap(FakeSyncProvider(expired: true)));

    await tester.tap(find.byKey(const Key('credential-expired-banner')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('credential-expired-dialog')), findsOneWidget);
  });

  /// Unlike the pairing mismatch there is nothing staff can authorise here, so
  /// the dialog must not offer them a way to "resume" — dismissing it leaves
  /// the block exactly where it was.
  testWidgets('dismissing the dialog leaves the block in place', (tester) async {
    await tester.pumpWidget(wrap(FakeSyncProvider(expired: true)));

    await tester.tap(find.byKey(const Key('credential-expired-banner')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Schließen'));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('credential-expired-dialog')), findsNothing);
    expect(find.byKey(const Key('credential-expired-banner')), findsOneWidget);
  });
}
