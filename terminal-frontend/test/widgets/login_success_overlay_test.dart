// The shell surface that turns a successful scan into the login celebration.
// Occurrence semantics mirror the scan feedback banner: one burst per
// LoginMoment sequence — replays on remount or duplicate notifications would
// turn the reward into noise.
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/models/login_moment.dart';
import 'package:clubbar_terminal/providers/rfid_provider.dart';
import 'package:clubbar_terminal/widgets/login_success_overlay.dart';
import '../test_helpers.dart';

/// A real [ChangeNotifier] standing in for [RfidProvider] so the overlay
/// subscribes and rebuilds the way it does in production.
class FakeRfidProvider extends ChangeNotifier implements RfidProvider {
  LoginMoment? _loginMoment;
  int _sequence = 0;

  @override
  LoginMoment? get loginMoment => _loginMoment;

  void login(MembersCacheData member) {
    _loginMoment = LoginMoment(member: member, sequence: ++_sequence);
    notifyListeners();
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

MembersCacheData _member(String firstName) => MembersCacheData(
      id: 'member-$firstName',
      cardUid: 'card-1',
      firstName: firstName,
      lastName: 'Member',
      preferredLanguage: 'de',
      isActive: 1,
      isSepaValid: 1,
      balanceCents: 0,
      updatedAt: '2025-02-01T10:00:00Z',
    );

void main() {
  late FakeRfidProvider rfid;

  setUp(() => rfid = FakeRfidProvider());

  Future<void> pumpShell(WidgetTester tester) async {
    await tester.pumpWidget(createTestApp(
      child: ChangeNotifierProvider<RfidProvider>.value(
        value: rfid,
        child: const Scaffold(body: LoginSuccessOverlay()),
      ),
    ));
    // Let the post-frame subscription land.
    await tester.pump();
  }

  group('LoginSuccessOverlay', () {
    testWidgets('plays the burst in the root overlay when a member logs in',
        (tester) async {
      await pumpShell(tester);
      expect(find.byType(LoginBurst), findsNothing);

      rfid.login(_member('Anna'));
      await tester.pump();

      expect(find.byType(LoginBurst), findsOneWidget);
    });

    testWidgets('removes the burst once it has played out', (tester) async {
      await pumpShell(tester);
      rfid.login(_member('Anna'));
      await tester.pump();

      await tester.pump(LoginBurst.duration + const Duration(milliseconds: 50));
      await tester.pump();

      expect(find.byType(LoginBurst), findsNothing);
    });

    testWidgets('a duplicate notification does not replay the burst',
        (tester) async {
      await pumpShell(tester);
      rfid.login(_member('Anna'));
      await tester.pump();
      await tester.pump(LoginBurst.duration + const Duration(milliseconds: 50));
      await tester.pump();

      // Same moment, notified again — e.g. an unrelated provider change.
      rfid.notifyListeners();
      await tester.pump();

      expect(find.byType(LoginBurst), findsNothing);
    });

    testWidgets('the next login plays its own burst', (tester) async {
      await pumpShell(tester);
      rfid.login(_member('Anna'));
      await tester.pump();
      await tester.pump(LoginBurst.duration + const Duration(milliseconds: 50));
      await tester.pump();

      rfid.login(_member('Ben'));
      await tester.pump();

      expect(find.byType(LoginBurst), findsOneWidget);
    });

    testWidgets('a login from before the overlay mounted is not replayed',
        (tester) async {
      rfid.login(_member('Anna'));

      await pumpShell(tester);
      await tester.pump();

      expect(find.byType(LoginBurst), findsNothing);
    });
  });
}
