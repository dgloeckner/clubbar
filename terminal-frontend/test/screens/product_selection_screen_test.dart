import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'dart:convert';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/providers/products_provider.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/providers/auth_provider.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/screens/product_selection_screen.dart';
import 'package:clubbar_terminal/services/sound_service.dart';
import 'package:clubbar_terminal/widgets/styled_components/category_chip.dart';
import '../test_helpers.dart';

class MockProductsProvider extends Mock implements ProductsProvider {}
class MockCartProvider extends Mock implements CartProvider {}
class MockAuthProvider extends Mock implements AuthProvider {}
class MockSyncProvider extends Mock implements SyncProvider {}
class MockMembersProvider extends Mock implements MembersProvider {}
class MockSessionController extends Mock implements SessionController {}
class MockSoundService extends Mock implements SoundService {}

void main() {
  setUpAll(() {
    registerFallbackValue(ProductsCacheData(
      id: 'test',
      categoryId: 'test',
      names: '{}',
      descriptions: null,
      priceCents: 0,
      isActive: 1,
      requiresDispenser: 0,
      iconName: null,
      updatedAt: '2025-02-01T10:00:00Z',
    ));
    registerFallbackValue(SoundEvent.categorySwitch);
  });

  group('ProductSelectionScreen', () {
    late MockProductsProvider mockProductsProvider;
    late MockCartProvider mockCartProvider;
    late MockAuthProvider mockAuthProvider;
    late MockSyncProvider mockSyncProvider;
    late MockMembersProvider mockMembersProvider;
    late MockSessionController mockSessionController;
    late MockSoundService mockSoundService;

    setUp(() {
      mockProductsProvider = MockProductsProvider();
      mockCartProvider = MockCartProvider();
      mockAuthProvider = MockAuthProvider();
      mockSyncProvider = MockSyncProvider();
      mockMembersProvider = MockMembersProvider();
      mockSessionController = MockSessionController();
      when(() => mockSessionController.isCriticalOperationInFlight).thenReturn(false);
      when(() => mockSessionController.addListener(any())).thenReturn(null);
      when(() => mockSessionController.removeListener(any())).thenReturn(null);
      mockSoundService = MockSoundService();

      // Setup auth provider mocks
      when(() => mockAuthProvider.isAuthenticated).thenReturn(true);
      when(() => mockAuthProvider.addListener(any())).thenReturn(null);
      when(() => mockAuthProvider.removeListener(any())).thenReturn(null);

      // Setup sync provider mocks
      when(() => mockSyncProvider.isSyncing).thenReturn(false);
      when(() => mockSyncProvider.addListener(any())).thenReturn(null);
      when(() => mockSyncProvider.removeListener(any())).thenReturn(null);

      // Setup members provider mocks
      when(() => mockMembersProvider.selectedMember).thenReturn(null);
      when(() => mockMembersProvider.addListener(any())).thenReturn(null);
      when(() => mockMembersProvider.removeListener(any())).thenReturn(null);
    });

    testWidgets('displays choice chips for categories', (WidgetTester tester) async {
      final categories = [
        CategoriesCacheData(
          id: 'cat-1',
          names: jsonEncode({'de': 'Bier'}),
          isActive: 1,
          updatedAt: '2025-02-01T10:00:00Z',
        ),
      ];

      when(() => mockProductsProvider.categories).thenReturn(categories);
      when(() => mockProductsProvider.products).thenReturn([]);
      when(() => mockProductsProvider.addListener(any())).thenReturn(null);
      when(() => mockProductsProvider.removeListener(any())).thenReturn(null);
      when(() => mockCartProvider.addListener(any())).thenReturn(null);
      when(() => mockCartProvider.removeListener(any())).thenReturn(null);
      when(() => mockCartProvider.itemCount).thenReturn(0);
      when(() => mockCartProvider.items).thenReturn([]);

      await tester.pumpWidget(
        createTestApp(
          child: MultiProvider(
            providers: [
              ChangeNotifierProvider<ProductsProvider>.value(value: mockProductsProvider),
              ChangeNotifierProvider<CartProvider>.value(value: mockCartProvider),
              ChangeNotifierProvider<AuthProvider>.value(value: mockAuthProvider),
              ChangeNotifierProvider<SyncProvider>.value(value: mockSyncProvider),
              ChangeNotifierProvider<MembersProvider>.value(value: mockMembersProvider),
              ChangeNotifierProvider<SessionController>.value(value: mockSessionController),
            ],
            child: const Scaffold(body: ProductSelectionScreen()),
          ),
        ),
      );

      expect(find.byType(CategoryChip), findsWidgets);
    });

    testWidgets('displays grid view when categories exist', (WidgetTester tester) async {
      final categories = [
        CategoriesCacheData(
          id: 'cat-1',
          names: jsonEncode({'de': 'Bier'}),
          isActive: 1,
          updatedAt: '2025-02-01T10:00:00Z',
        ),
      ];

      when(() => mockProductsProvider.categories).thenReturn(categories);
      when(() => mockProductsProvider.products).thenReturn([]);
      when(() => mockProductsProvider.addListener(any())).thenReturn(null);
      when(() => mockProductsProvider.removeListener(any())).thenReturn(null);
      when(() => mockCartProvider.addListener(any())).thenReturn(null);
      when(() => mockCartProvider.removeListener(any())).thenReturn(null);
      when(() => mockCartProvider.itemCount).thenReturn(0);
      when(() => mockCartProvider.items).thenReturn([]);

      await tester.pumpWidget(
        createTestApp(
          child: MultiProvider(
            providers: [
              ChangeNotifierProvider<ProductsProvider>.value(value: mockProductsProvider),
              ChangeNotifierProvider<CartProvider>.value(value: mockCartProvider),
              ChangeNotifierProvider<AuthProvider>.value(value: mockAuthProvider),
              ChangeNotifierProvider<SyncProvider>.value(value: mockSyncProvider),
              ChangeNotifierProvider<MembersProvider>.value(value: mockMembersProvider),
              ChangeNotifierProvider<SessionController>.value(value: mockSessionController),
            ],
            child: const Scaffold(body: ProductSelectionScreen()),
          ),
        ),
      );

      // When no products in category, shows empty message instead of grid
      expect(find.text('Keine Produkte in dieser Kategorie'), findsOneWidget);
    });

    testWidgets('displays cart button', (WidgetTester tester) async {
      final categories = [
        CategoriesCacheData(
          id: 'cat-1',
          names: jsonEncode({'de': 'Bier'}),
          isActive: 1,
          updatedAt: '2025-02-01T10:00:00Z',
        ),
      ];

      final testMember = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        preferredLanguage: 'de',
        isActive: 1,
        isSepaValid: 1,
        balanceCents: 0,
        updatedAt: '2024-01-01T00:00:00Z',
      );

      when(() => mockProductsProvider.categories).thenReturn(categories);
      when(() => mockProductsProvider.products).thenReturn([]);
      when(() => mockProductsProvider.addListener(any())).thenReturn(null);
      when(() => mockProductsProvider.removeListener(any())).thenReturn(null);
      when(() => mockCartProvider.itemCount).thenReturn(5);
      when(() => mockCartProvider.addListener(any())).thenReturn(null);
      when(() => mockCartProvider.removeListener(any())).thenReturn(null);
      when(() => mockMembersProvider.selectedMember).thenReturn(testMember);
      when(() => mockMembersProvider.addListener(any())).thenReturn(null);
      when(() => mockMembersProvider.removeListener(any())).thenReturn(null);

      await tester.pumpWidget(
        createTestApp(
          child: MultiProvider(
            providers: [
              ChangeNotifierProvider<ProductsProvider>.value(value: mockProductsProvider),
              ChangeNotifierProvider<CartProvider>.value(value: mockCartProvider),
              ChangeNotifierProvider<AuthProvider>.value(value: mockAuthProvider),
              ChangeNotifierProvider<SyncProvider>.value(value: mockSyncProvider),
              ChangeNotifierProvider<MembersProvider>.value(value: mockMembersProvider),
              ChangeNotifierProvider<SessionController>.value(value: mockSessionController),
            ],
            child: const Scaffold(body: ProductSelectionScreen()),
          ),
        ),
      );

      // Cart button should show badge with item count
      expect(find.byIcon(Icons.shopping_cart_outlined), findsOneWidget);
      expect(find.text('5'), findsOneWidget);
    });

    testWidgets('renders successfully when empty', (WidgetTester tester) async {
      when(() => mockProductsProvider.categories).thenReturn([]);
      when(() => mockProductsProvider.products).thenReturn([]);
      when(() => mockProductsProvider.addListener(any())).thenReturn(null);
      when(() => mockProductsProvider.removeListener(any())).thenReturn(null);
      when(() => mockCartProvider.itemCount).thenReturn(0);
      when(() => mockCartProvider.addListener(any())).thenReturn(null);
      when(() => mockCartProvider.removeListener(any())).thenReturn(null);

      await tester.pumpWidget(
        createTestApp(
          child: MultiProvider(
            providers: [
              ChangeNotifierProvider<ProductsProvider>.value(value: mockProductsProvider),
              ChangeNotifierProvider<CartProvider>.value(value: mockCartProvider),
              ChangeNotifierProvider<AuthProvider>.value(value: mockAuthProvider),
              ChangeNotifierProvider<SyncProvider>.value(value: mockSyncProvider),
              ChangeNotifierProvider<MembersProvider>.value(value: mockMembersProvider),
              ChangeNotifierProvider<SessionController>.value(value: mockSessionController),
            ],
            child: const Scaffold(body: ProductSelectionScreen()),
          ),
        ),
      );

      expect(find.byType(ProductSelectionScreen), findsOneWidget);
    });

    testWidgets('plays categorySwitch sound when category chip is tapped', (WidgetTester tester) async {
      final categories = [
        CategoriesCacheData(
          id: 'cat-1',
          names: jsonEncode({'de': 'Bier'}),
          isActive: 1,
          updatedAt: '2025-02-01T10:00:00Z',
        ),
        CategoriesCacheData(
          id: 'cat-2',
          names: jsonEncode({'de': 'Snacks'}),
          isActive: 1,
          updatedAt: '2025-02-01T10:00:00Z',
        ),
      ];

      when(() => mockProductsProvider.categories).thenReturn(categories);
      when(() => mockProductsProvider.products).thenReturn([]);
      when(() => mockProductsProvider.addListener(any())).thenReturn(null);
      when(() => mockProductsProvider.removeListener(any())).thenReturn(null);
      when(() => mockCartProvider.addListener(any())).thenReturn(null);
      when(() => mockCartProvider.removeListener(any())).thenReturn(null);
      when(() => mockCartProvider.itemCount).thenReturn(0);
      when(() => mockCartProvider.items).thenReturn([]);
      when(() => mockSoundService.play(any())).thenAnswer((_) async {});

      await tester.pumpWidget(
        createTestApp(
          child: MultiProvider(
            providers: [
              ChangeNotifierProvider<ProductsProvider>.value(value: mockProductsProvider),
              ChangeNotifierProvider<CartProvider>.value(value: mockCartProvider),
              ChangeNotifierProvider<AuthProvider>.value(value: mockAuthProvider),
              ChangeNotifierProvider<SyncProvider>.value(value: mockSyncProvider),
              ChangeNotifierProvider<MembersProvider>.value(value: mockMembersProvider),
              ChangeNotifierProvider<SessionController>.value(value: mockSessionController),
              Provider<SoundService>.value(value: mockSoundService),
            ],
            child: const Scaffold(body: ProductSelectionScreen()),
          ),
        ),
      );

      // Tap the second category chip to trigger categorySwitch sound
      final chips = find.byType(CategoryChip);
      expect(chips, findsNWidgets(2));
      await tester.tap(chips.at(1));
      await tester.pump();

      verify(() => mockSoundService.play(SoundEvent.categorySwitch)).called(1);
    });
  });
}
