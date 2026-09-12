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
  FakeSyncProvider({required CredentialRefusalReason? reason})
      : _reason = reason,
        super(
          syncService: MockSyncService(),
          membersProvider: MockMembersProvider(),
          productsProvider: MockProductsProvider(),
          networkService: MockNetworkService(),
        );

  final CredentialRefusalReason? _reason;

  @override
  CredentialRefusalReason? get credentialRefusal => _reason;

  @override
  bool get credentialExpired => _reason != null;
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
    await tester.pumpWidget(wrap(FakeSyncProvider(reason: null)));

    expect(find.byKey(const Key('credential-expired-banner')), findsNothing);
  });

  testWidgets('an expired credential raises a persistent warning',
      (tester) async {
    await tester.pumpWidget(
      wrap(FakeSyncProvider(reason: CredentialRefusalReason.expired)),
    );

    expect(find.byKey(const Key('credential-expired-banner')), findsOneWidget);
  });

  /// #890 — a revoked token or a deactivated terminal must block exactly like
  /// an expired one, not fall through as an ordinary network error.
  testWidgets('a revoked credential raises the same persistent warning',
      (tester) async {
    await tester.pumpWidget(
      wrap(FakeSyncProvider(reason: CredentialRefusalReason.revoked)),
    );

    expect(find.byKey(const Key('credential-expired-banner')), findsOneWidget);
  });

  /// The two reasons must not read the same: staff need to know whether this
  /// is a routine rotation or a "call the club" situation (#890).
  testWidgets('the wording differs between an expired and a revoked credential',
      (tester) async {
    await tester.pumpWidget(
      wrap(FakeSyncProvider(reason: CredentialRefusalReason.expired)),
    );
    final expiredText = tester
        .widget<Text>(find.descendant(
          of: find.byKey(const Key('credential-expired-banner')),
          matching: find.byType(Text),
        ))
        .data;

    await tester.pumpWidget(
      wrap(FakeSyncProvider(reason: CredentialRefusalReason.revoked)),
    );
    final revokedText = tester
        .widget<Text>(find.descendant(
          of: find.byKey(const Key('credential-expired-banner')),
          matching: find.byType(Text),
        ))
        .data;

    expect(expiredText, isNot(equals(revokedText)));
  });

  testWidgets('the banner opens a dialog that names who has to act',
      (tester) async {
    await tester.pumpWidget(
      wrap(FakeSyncProvider(reason: CredentialRefusalReason.expired)),
    );

    await tester.tap(find.byKey(const Key('credential-expired-banner')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('credential-expired-dialog')), findsOneWidget);
  });

  testWidgets(
      'the dialog for a revoked credential names the club, not a rotation',
      (tester) async {
    await tester.pumpWidget(
      wrap(FakeSyncProvider(reason: CredentialRefusalReason.revoked)),
    );

    await tester.tap(find.byKey(const Key('credential-expired-banner')));
    await tester.pumpAndSettle();

    expect(find.text('Zugang entzogen'), findsOneWidget);
  });

  /// Unlike the pairing mismatch there is nothing staff can authorise here, so
  /// the dialog must not offer them a way to "resume" — dismissing it leaves
  /// the block exactly where it was.
  testWidgets('dismissing the dialog leaves the block in place', (tester) async {
    await tester.pumpWidget(
      wrap(FakeSyncProvider(reason: CredentialRefusalReason.expired)),
    );

    await tester.tap(find.byKey(const Key('credential-expired-banner')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Schließen'));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('credential-expired-dialog')), findsNothing);
    expect(find.byKey(const Key('credential-expired-banner')), findsOneWidget);
  });
}
