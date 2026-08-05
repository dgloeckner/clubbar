import 'package:fake_async/fake_async.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';

class MockMembersProvider extends Mock implements MembersProvider {}

class MockCartProvider extends Mock implements CartProvider {}

class FakeMembersCacheData extends Fake implements MembersCacheData {}

MembersCacheData _member(String id) => MembersCacheData(
      id: id,
      cardUid: 'card-$id',
      firstName: 'Test',
      lastName: id,
      preferredLanguage: 'de',
      isActive: 1,
      isSepaValid: 1,
      balanceCents: 0,
      updatedAt: '2025-02-01T10:00:00Z',
    );

void main() {
  setUpAll(() {
    registerFallbackValue(FakeMembersCacheData());
  });

  late MockMembersProvider members;
  late MockCartProvider cart;
  MembersCacheData? selected;

  SessionController buildController({
    Duration timeout = const Duration(seconds: 60),
    Duration warning = const Duration(seconds: 10),
  }) {
    return SessionController(
      membersProvider: members,
      cartProvider: cart,
      inactivityTimeout: timeout,
      warningDuration: warning,
    );
  }

  setUp(() {
    members = MockMembersProvider();
    cart = MockCartProvider();
    selected = null;

    when(() => members.selectedMember).thenAnswer((_) => selected);
    when(() => members.setSelectedMember(any())).thenAnswer((inv) async {
      selected = inv.positionalArguments[0] as MembersCacheData;
    });
    when(() => members.clearSelectedMember()).thenAnswer((_) {
      selected = null;
    });
    when(() => cart.clearCart()).thenReturn(null);
  });

  group('endSession', () {
    test('clears cart and member', () async {
      final controller = buildController();
      await controller.startSession(_member('a'));

      controller.endSession();

      // Cart cleared once at session start (defense) and once at end.
      verify(() => cart.clearCart()).called(2);
      verify(() => members.clearSelectedMember()).called(1);
      expect(controller.hasActiveSession, isFalse);
    });
  });

  group('startSession', () {
    test('starts with an empty cart (defensive clear before member set)',
        () async {
      final controller = buildController();

      final result = await controller.startSession(_member('a'));

      expect(result, SessionStartResult.started);
      verifyInOrder([
        () => cart.clearCart(),
        () => members.setSelectedMember(any()),
      ]);
      expect(controller.hasActiveSession, isTrue);
    });

    test('same member re-scan is a no-op: cart survives (ADR-0027 rule 4)',
        () async {
      final controller = buildController();
      await controller.startSession(_member('a'));
      clearInteractions(cart);
      clearInteractions(members);

      final result = await controller.startSession(_member('a'));

      expect(result, SessionStartResult.sameMemberNoOp);
      verifyNever(() => cart.clearCart());
      verifyNever(() => members.setSelectedMember(any()));
    });

    test('foreign scan during active session is rejected (ADR-0027 rule 3)',
        () async {
      final controller = buildController();
      await controller.startSession(_member('a'));
      clearInteractions(cart);
      clearInteractions(members);

      final result = await controller.startSession(_member('b'));

      expect(result, SessionStartResult.rejectedActiveSession);
      verifyNever(() => cart.clearCart());
      verifyNever(() => members.setSelectedMember(any()));
      verifyNever(() => members.clearSelectedMember());
      expect(selected!.id, 'a');
    });
  });

  group('inactivity timeout', () {
    test('shows warning after timeout, ends session after warning elapses',
        () {
      fakeAsync((async) {
        final controller = buildController();
        controller.startSession(_member('a'));
        async.flushMicrotasks();

        async.elapse(const Duration(seconds: 59));
        expect(controller.showTimeoutWarning, isFalse);

        async.elapse(const Duration(seconds: 1));
        expect(controller.showTimeoutWarning, isTrue);
        expect(controller.hasActiveSession, isTrue);

        async.elapse(const Duration(seconds: 10));
        expect(controller.hasActiveSession, isFalse);
        expect(controller.showTimeoutWarning, isFalse);
        verify(() => members.clearSelectedMember()).called(1);
      });
    });

    test('activity resets the timer', () {
      fakeAsync((async) {
        final controller = buildController();
        controller.startSession(_member('a'));
        async.flushMicrotasks();

        async.elapse(const Duration(seconds: 59));
        controller.recordActivity();
        async.elapse(const Duration(seconds: 59));

        expect(controller.showTimeoutWarning, isFalse);
        expect(controller.hasActiveSession, isTrue);
      });
    });

    test('tap during warning dismisses it and keeps the session', () {
      fakeAsync((async) {
        final controller = buildController();
        controller.startSession(_member('a'));
        async.flushMicrotasks();

        async.elapse(const Duration(seconds: 61));
        expect(controller.showTimeoutWarning, isTrue);

        controller.recordActivity();
        expect(controller.showTimeoutWarning, isFalse);

        async.elapse(const Duration(seconds: 59));
        expect(controller.hasActiveSession, isTrue);
      });
    });

    test('warning countdown is exposed in seconds', () {
      fakeAsync((async) {
        final controller = buildController();
        controller.startSession(_member('a'));
        async.flushMicrotasks();

        async.elapse(const Duration(seconds: 60));
        expect(controller.warningSecondsLeft, 10);

        async.elapse(const Duration(seconds: 4));
        expect(controller.warningSecondsLeft, 6);
      });
    });

    test('no timer runs without an active session', () {
      fakeAsync((async) {
        final controller = buildController();

        async.elapse(const Duration(minutes: 10));

        expect(controller.showTimeoutWarning, isFalse);
        verifyNever(() => members.clearSelectedMember());
      });
    });
  });

  group('critical operations (ADR-0027 rule 7)', () {
    test('timer is suspended while a checkout/dispense is in flight', () {
      fakeAsync((async) {
        final controller = buildController();
        controller.startSession(_member('a'));
        async.flushMicrotasks();

        controller.beginCriticalOperation();
        async.elapse(const Duration(minutes: 5));

        expect(controller.hasActiveSession, isTrue);
        expect(controller.showTimeoutWarning, isFalse);
      });
    });

    test('timer resumes when the critical operation ends', () {
      fakeAsync((async) {
        final controller = buildController();
        controller.startSession(_member('a'));
        async.flushMicrotasks();

        controller.beginCriticalOperation();
        async.elapse(const Duration(minutes: 5));
        controller.endCriticalOperation();

        async.elapse(const Duration(seconds: 61));
        expect(controller.showTimeoutWarning, isTrue);
      });
    });

    test('endSession resets the critical-operation count', () {
      fakeAsync((async) {
        final controller = buildController();
        controller.startSession(_member('a'));
        async.flushMicrotasks();

        controller.beginCriticalOperation();
        controller.endSession();

        controller.startSession(_member('b'));
        async.flushMicrotasks();
        async.elapse(const Duration(seconds: 61));

        expect(controller.showTimeoutWarning, isTrue,
            reason: 'a stale critical op must not suppress the next '
                'session\'s inactivity timer');
      });
    });
  });
}
