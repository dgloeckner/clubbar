// Acceptance test for issue #13: cart must not survive a session boundary.
// Uses real CartProvider + MembersProvider (services mocked) wired through
// the SessionController, mirroring the production object graph.
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/providers/products_provider.dart';
import 'package:clubbar_terminal/services/cart_service.dart';
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/services/members_service.dart';
import 'package:clubbar_terminal/services/products_service.dart';
import 'package:clubbar_terminal/services/sound_service.dart';

class MockCartService extends Mock implements CartService {}

class MockMembersService extends Mock implements MembersService {}

class MockConfigService extends Mock implements ConfigService {}

class MockSoundService extends Mock implements SoundService {}

class MockProductsService extends Mock implements ProductsService {}

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
  late ProductsProvider productsProvider;
  late SessionController sessionController;
  late MockProductsService productsService;

  setUp(() {
    final membersService = MockMembersService();
    when(() => membersService.getEffectiveBalance(any()))
        .thenAnswer((_) async => 0);
    // Offline: the balance refresh finds nothing fresh, so the cached
    // member stands (#374).
    when(() => membersService.refreshBalance(any()))
        .thenAnswer((_) async => null);

    final soundService = MockSoundService();
    when(() => soundService.play(any())).thenAnswer((_) async {});

    final configService = MockConfigService();
    when(() => configService.dispenserEnabled).thenReturn(false);

    productsService = MockProductsService();
    when(() => productsService.sortForDisplay(any()))
        .thenAnswer((i) => i.positionalArguments.first as List<ProductsCacheData>);

    cartProvider = CartProvider(
      service: MockCartService(),
      config: MockConfigService(),
      soundService: soundService,
    );
    membersProvider = MembersProvider(service: membersService);
    productsProvider = ProductsProvider(
      service: productsService,
      config: configService,
    );
    sessionController = SessionController(
      membersProvider: membersProvider,
      cartProvider: cartProvider,
      productsProvider: productsProvider,
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

  test(
      'a mid-session price sync does not change what member A\'s grid shows, '
      'but member B after logout sees the new price', () async {
    final category = CategoriesCacheData(
      id: 'cat-1',
      names: jsonEncode({'de': 'Getränke', 'en': 'Drinks'}),
      isActive: 1,
      updatedAt: '2025-02-01T10:00:00Z',
    );
    ProductsCacheData product(int priceCents) => ProductsCacheData(
          id: 'prod-1',
          categoryId: 'cat-1',
          names: jsonEncode({'de': 'Bier', 'en': 'Beer'}),
          descriptions: null,
          priceCents: priceCents,
          isActive: 1,
          requiresDispenser: 0,
          iconName: null,
          updatedAt: '2025-02-01T10:00:00Z',
        );

    when(() => productsService.getActiveCategoriesWithProducts())
        .thenAnswer((_) async => [(category, [product(500)])]);
    await productsProvider.refreshProducts();

    // Member A's session starts: the grid is frozen at 500.
    await sessionController.startSession(_member('member-a', 'Anna'));
    expect(productsProvider.getVisibleProducts('cat-1').single.priceCents,
        500);

    // A background sync cycle lands mid-session with a new price.
    when(() => productsService.getActiveCategoriesWithProducts())
        .thenAnswer((_) async => [(category, [product(600)])]);
    await productsProvider.refreshProducts();

    // A's grid must still show 500 — unchanged in front of the member.
    expect(productsProvider.getVisibleProducts('cat-1').single.priceCents,
        500);

    // A logs out; B scans next and sees the new price immediately.
    sessionController.endSession();
    await sessionController.startSession(_member('member-b', 'Ben'));

    expect(productsProvider.getVisibleProducts('cat-1').single.priceCents,
        600);
  });
}
