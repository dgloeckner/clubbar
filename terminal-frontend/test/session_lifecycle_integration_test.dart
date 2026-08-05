// Acceptance test for issue #13: cart must not survive a session boundary.
// Uses real CartProvider + MembersProvider (services mocked) wired through
// the SessionController, mirroring the production object graph.
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/services/cart_service.dart';
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/services/members_service.dart';
import 'package:clubbar_terminal/services/sound_service.dart';

class MockCartService extends Mock implements CartService {}

class MockMembersService extends Mock implements MembersService {}

class MockConfigService extends Mock implements ConfigService {}

class MockSoundService extends Mock implements SoundService {}

class FakeMembersCacheData extends Fake implements MembersCacheData {}

MembersCacheData _member(String id, String firstName) => MembersCacheData(
      id: id,
      cardUid: 'card-$id',
      firstName: firstName,
      lastName: 'Member',
      preferredLanguage: 'de',
      isActive: 1,
      isSepaValid: 1,
      balanceCents: 0,
      updatedAt: '2025-02-01T10:00:00Z',
    );

void main() {
  setUpAll(() {
    registerFallbackValue(FakeMembersCacheData());
    registerFallbackValue(SoundEvent.productAdd);
  });

  late CartProvider cartProvider;
  late MembersProvider membersProvider;
  late SessionController sessionController;

  setUp(() {
    final membersService = MockMembersService();
    when(() => membersService.getEffectiveBalance(any()))
        .thenAnswer((_) async => 0);

    final soundService = MockSoundService();
    when(() => soundService.play(any())).thenAnswer((_) async {});

    cartProvider = CartProvider(
      service: MockCartService(),
      config: MockConfigService(),
      soundService: soundService,
    );
    membersProvider = MembersProvider(service: membersService);
    sessionController = SessionController(
      membersProvider: membersProvider,
      cartProvider: cartProvider,
    );
  });

  test(
      'issue #13: member A adds items, logs out — member B starts with an '
      'empty cart', () async {
    // Member A scans and fills the cart.
    await sessionController.startSession(_member('member-a', 'Anna'));
    cartProvider.addItem('prod-1', 'Pils 0,5l', 350, 2, 'de');
    expect(cartProvider.itemCount, 2);

    // A presses logout (any logout path goes through endSession).
    sessionController.endSession();
    expect(membersProvider.selectedMember, isNull);
    expect(cartProvider.items, isEmpty);

    // Member B scans: fresh session, cart badge must show 0.
    await sessionController.startSession(_member('member-b', 'Ben'));
    expect(membersProvider.selectedMember!.id, 'member-b');
    expect(cartProvider.itemCount, 0);
    expect(cartProvider.total, 0);
  });

  test('a foreign card tap mid-session never inherits or clears the cart',
      () async {
    await sessionController.startSession(_member('member-a', 'Anna'));
    cartProvider.addItem('prod-1', 'Pils 0,5l', 350, 1, 'de');

    final result =
        await sessionController.startSession(_member('member-b', 'Ben'));

    expect(result, SessionStartResult.rejectedActiveSession);
    expect(membersProvider.selectedMember!.id, 'member-a');
    expect(cartProvider.itemCount, 1, reason: 'A\'s cart is untouched');
  });
}
