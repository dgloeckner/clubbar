import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'dart:convert';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/providers/products_provider.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/providers/auth_provider.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/screens/product_selection_screen.dart';
import 'package:clubbar_terminal/services/sound_service.dart';
import 'package:clubbar_terminal/widgets/error_banner.dart';
import 'package:clubbar_terminal/widgets/styled_components/category_chip.dart';
import 'package:clubbar_terminal/widgets/styled_components/product_card.dart';
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
      when(() => mockMembersProvider.memberDeckel).thenReturn(0);
      when(() => mockCartProvider.total).thenReturn(0);
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
      when(() => mockProductsProvider.getVisibleProducts(any()))
          .thenReturn([]);
      when(() => mockProductsProvider.isProductAvailable(any()))
          .thenReturn(true);
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
      when(() => mockProductsProvider.getVisibleProducts(any()))
          .thenReturn([]);
      when(() => mockProductsProvider.isProductAvailable(any()))
          .thenReturn(true);
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
      when(() => mockProductsProvider.getVisibleProducts(any()))
          .thenReturn([]);
      when(() => mockProductsProvider.isProductAvailable(any()))
          .thenReturn(true);
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
      when(() => mockProductsProvider.getVisibleProducts(any()))
          .thenReturn([]);
      when(() => mockProductsProvider.isProductAvailable(any()))
          .thenReturn(true);
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
      when(() => mockProductsProvider.getVisibleProducts(any()))
          .thenReturn([]);
      when(() => mockProductsProvider.isProductAvailable(any()))
          .thenReturn(true);
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

    // Issue #27: a stale product list is worth saying out loud, but the
    // member can still buy everything already cached — so it is an inline
    // banner, not a blocking modal.
    group('refresh failure banner (#27)', () {
      Future<void> pumpScreen(WidgetTester tester) async {
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
        when(() => mockProductsProvider.getVisibleProducts(any()))
            .thenReturn([]);
        when(() => mockProductsProvider.isProductAvailable(any()))
            .thenReturn(true);
        when(() => mockCartProvider.itemCount).thenReturn(0);
        when(() => mockCartProvider.items).thenReturn([]);

        await tester.pumpWidget(
          createTestApp(
            child: MultiProvider(
              providers: [
                ChangeNotifierProvider<ProductsProvider>.value(
                    value: mockProductsProvider),
                ChangeNotifierProvider<CartProvider>.value(
                    value: mockCartProvider),
                ChangeNotifierProvider<AuthProvider>.value(
                    value: mockAuthProvider),
                ChangeNotifierProvider<SyncProvider>.value(
                    value: mockSyncProvider),
                ChangeNotifierProvider<MembersProvider>.value(
                    value: mockMembersProvider),
                ChangeNotifierProvider<SessionController>.value(
                    value: mockSessionController),
                Provider<SoundService>.value(value: mockSoundService),
              ],
              child: const Scaffold(body: ProductSelectionScreen()),
            ),
          ),
        );
      }

      testWidgets('shows localized copy when the refresh failed',
          (WidgetTester tester) async {
        when(() => mockProductsProvider.lastError).thenReturn(
          const TerminalError(
            key: TerminalErrorKey.productsRefreshFailed,
            sequence: 1,
          ),
        );

        await pumpScreen(tester);

        expect(find.byType(ErrorBanner), findsOneWidget);
        expect(
          find.text(await errorCopy(TerminalErrorKey.productsRefreshFailed)),
          findsOneWidget,
        );
      });

      testWidgets('dismissing it clears the provider error',
          (WidgetTester tester) async {
        when(() => mockProductsProvider.lastError).thenReturn(
          const TerminalError(
            key: TerminalErrorKey.productsRefreshFailed,
            sequence: 1,
          ),
        );
        when(() => mockProductsProvider.clearError()).thenReturn(null);

        await pumpScreen(tester);
        await tester.tap(find.byIcon(Icons.close));
        await tester.pump();

        verify(() => mockProductsProvider.clearError()).called(1);
      });

      testWidgets('absent when nothing failed', (WidgetTester tester) async {
        when(() => mockProductsProvider.lastError).thenReturn(null);

        await pumpScreen(tester);

        expect(find.byType(ErrorBanner), findsNothing);
      });

      // UC-T12: "Add to cart | Preview balance vs max | Warning shown, item
      // still added". The member should meet the ceiling while choosing, not
      // only once they open the cart.
      testWidgets('warns about the credit limit while choosing products',
          (WidgetTester tester) async {
        when(() => mockProductsProvider.lastError).thenReturn(null);
        when(() => mockMembersProvider.memberDeckel).thenReturn(9000);
        when(() => mockCartProvider.total).thenReturn(2000);

        await pumpScreen(tester);

        expect(find.byKey(const Key('credit-limit-banner')), findsOneWidget);
      });

      testWidgets('stays quiet while the tab is well inside the limit',
          (WidgetTester tester) async {
        when(() => mockProductsProvider.lastError).thenReturn(null);
        when(() => mockMembersProvider.memberDeckel).thenReturn(1000);

        await pumpScreen(tester);

        expect(find.byKey(const Key('credit-limit-banner')), findsNothing);
      });
    });

    // Issue #29: the grid used to derive its aspect ratio from the number of
    // products, so tiles shrank without bound as the catalog grew (and could
    // even be computed negative), ballooned when a category held three items,
    // and never scrolled. Tile size is now a constant; the catalog scrolls.
    group('product grid sizing (#29)', () {
      /// A kiosk-sized surface, so the numbers below mean what they say.
      Future<void> setSurface(WidgetTester tester, Size size) async {
        tester.view.physicalSize = size;
        tester.view.devicePixelRatio = 1.0;
        addTearDown(tester.view.reset);
      }

      Future<void> pumpCatalog(
        WidgetTester tester,
        int productCount, {
        Size surface = const Size(1280, 800),
      }) async {
        await setSurface(tester, surface);

        final categories = [
          CategoriesCacheData(
            id: 'cat-1',
            names: jsonEncode({'de': 'Getränke'}),
            isActive: 1,
            updatedAt: '2025-02-01T10:00:00Z',
          ),
        ];
        final products = List.generate(
          productCount,
          (i) => ProductsCacheData(
            id: 'prod-$i',
            categoryId: 'cat-1',
            names: jsonEncode({'de': 'Produkt $i'}),
            descriptions: null,
            priceCents: 250 + i,
            isActive: 1,
            requiresDispenser: 0,
            iconName: null,
            updatedAt: '2025-02-01T10:00:00Z',
          ),
        );

        when(() => mockProductsProvider.categories).thenReturn(categories);
        when(() => mockProductsProvider.products).thenReturn(products);
        when(() => mockProductsProvider.getVisibleProducts(any()))
            .thenReturn(products);
        when(() => mockProductsProvider.isProductAvailable(any()))
            .thenReturn(true);
        when(() => mockProductsProvider.lastError).thenReturn(null);
        when(() => mockProductsProvider.getTranslatedName(any(), any()))
            .thenAnswer((invocation) {
          final product = invocation.positionalArguments[0] as ProductsCacheData;
          // Two-line names are the worst case for vertical fit.
          return 'Ein ziemlich langer Produktname ${product.id}';
        });
        when(() => mockCartProvider.itemCount).thenReturn(0);
        when(() => mockCartProvider.items).thenReturn([]);

        await tester.pumpWidget(
          createTestApp(
            child: MultiProvider(
              providers: [
                ChangeNotifierProvider<ProductsProvider>.value(
                    value: mockProductsProvider),
                ChangeNotifierProvider<CartProvider>.value(
                    value: mockCartProvider),
                ChangeNotifierProvider<AuthProvider>.value(
                    value: mockAuthProvider),
                ChangeNotifierProvider<SyncProvider>.value(
                    value: mockSyncProvider),
                ChangeNotifierProvider<MembersProvider>.value(
                    value: mockMembersProvider),
                ChangeNotifierProvider<SessionController>.value(
                    value: mockSessionController),
                Provider<SoundService>.value(value: mockSoundService),
              ],
              child: const Scaffold(body: ProductSelectionScreen()),
            ),
          ),
        );
      }

      for (final count in [1, 9, 40]) {
        testWidgets('lays out $count products without overflowing',
            (WidgetTester tester) async {
          await pumpCatalog(tester, count);

          expect(tester.takeException(), isNull);
          expect(find.byType(ProductCard), findsWidgets);
        });
      }

      testWidgets('survives a screen too narrow for four columns',
          (WidgetTester tester) async {
        await pumpCatalog(tester, 12, surface: const Size(640, 480));

        expect(tester.takeException(), isNull);
        expect(find.byType(ProductCard), findsWidgets);
      });

      testWidgets('a sparse category gets the same tile size as a full one',
          (WidgetTester tester) async {
        await pumpCatalog(tester, 3);
        final sparseTile = tester.getSize(find.byType(ProductCard).first);

        await pumpCatalog(tester, 40);
        final fullTile = tester.getSize(find.byType(ProductCard).first);

        expect(sparseTile, fullTile);
        // Readable, not screen-tall: three snacks must not become giant cards.
        expect(sparseTile.height, lessThan(320));
        expect(sparseTile.height, greaterThan(160));
      });

      testWidgets('a catalog taller than the screen scrolls',
          (WidgetTester tester) async {
        await pumpCatalog(tester, 40);

        final grid = tester.widget<GridView>(find.byType(GridView));
        expect(grid.physics, isNot(isA<NeverScrollableScrollPhysics>()));

        final firstTileBefore =
            tester.getTopLeft(find.byType(ProductCard).first).dy;
        await tester.drag(find.byType(GridView), const Offset(0, -300));
        await tester.pumpAndSettle();

        expect(
          tester.getTopLeft(find.byType(ProductCard).first).dy,
          lessThan(firstTileBefore),
        );
        expect(tester.takeException(), isNull);
      });

      testWidgets('column count follows the screen width, not a fixed 4',
          (WidgetTester tester) async {
        await pumpCatalog(tester, 12, surface: const Size(1920, 1080));
        final wideTile = tester.getSize(find.byType(ProductCard).first);

        await pumpCatalog(tester, 12, surface: const Size(1024, 768));
        final narrowTile = tester.getSize(find.byType(ProductCard).first);

        // Same tile, more of them per row — width does not stretch with the
        // screen, and height is identical either way.
        expect(wideTile.height, narrowTile.height);
        expect((wideTile.width - narrowTile.width).abs(), lessThan(40));
      });
    });

    // Issue #30: the bar was a plain Row of Expanded chips sized for two tabs.
    // A club with six categories — or one long German name like "Alkoholfreie
    // Getränke" — blew the row out with a RenderFlex overflow. The bar now
    // stretches to fill while the chips fit and scrolls horizontally once they
    // do not.
    group('category bar overflow (#30)', () {
      Future<void> pumpCategories(
        WidgetTester tester,
        List<String> names, {
        Size surface = const Size(1280, 800),
      }) async {
        tester.view.physicalSize = surface;
        tester.view.devicePixelRatio = 1.0;
        addTearDown(tester.view.reset);

        final categories = List.generate(
          names.length,
          (i) => CategoriesCacheData(
            id: 'cat-$i',
            names: jsonEncode({'de': names[i]}),
            isActive: 1,
            updatedAt: '2025-02-01T10:00:00Z',
          ),
        );

        when(() => mockProductsProvider.categories).thenReturn(categories);
        when(() => mockProductsProvider.products).thenReturn([]);
        when(() => mockProductsProvider.getVisibleProducts(any()))
            .thenReturn([]);
        when(() => mockProductsProvider.isProductAvailable(any()))
            .thenReturn(true);
        when(() => mockProductsProvider.lastError).thenReturn(null);
        when(() => mockCartProvider.itemCount).thenReturn(0);
        when(() => mockCartProvider.items).thenReturn([]);

        await tester.pumpWidget(
          createTestApp(
            child: MultiProvider(
              providers: [
                ChangeNotifierProvider<ProductsProvider>.value(
                    value: mockProductsProvider),
                ChangeNotifierProvider<CartProvider>.value(
                    value: mockCartProvider),
                ChangeNotifierProvider<AuthProvider>.value(
                    value: mockAuthProvider),
                ChangeNotifierProvider<SyncProvider>.value(
                    value: mockSyncProvider),
                ChangeNotifierProvider<MembersProvider>.value(
                    value: mockMembersProvider),
                ChangeNotifierProvider<SessionController>.value(
                    value: mockSessionController),
                Provider<SoundService>.value(value: mockSoundService),
              ],
              child: const Scaffold(body: ProductSelectionScreen()),
            ),
          ),
        );
      }

      /// Long, real-world German category names — the worst case the issue
      /// reports.
      const longGermanNames = [
        'Alkoholfreie Getränke',
        'Alkoholische Getränke',
        'Heißgetränke',
        'Snacks und Süßigkeiten',
        'Frühstück und Kuchen',
        'Sonderveranstaltungen',
      ];

      for (final count in [2, 3, 6]) {
        testWidgets('lays out $count categories without overflowing',
            (WidgetTester tester) async {
          await pumpCategories(tester, longGermanNames.take(count).toList());

          expect(tester.takeException(), isNull);
          expect(find.byType(CategoryChip), findsNWidgets(count));
        });
      }

      testWidgets('six long names still fit on a narrow kiosk',
          (WidgetTester tester) async {
        await pumpCategories(
          tester,
          longGermanNames,
          surface: const Size(1024, 768),
        );

        expect(tester.takeException(), isNull);
        expect(find.byType(CategoryChip), findsNWidgets(6));
      });

      testWidgets('the bar scrolls horizontally when the chips do not fit',
          (WidgetTester tester) async {
        await pumpCategories(
          tester,
          longGermanNames,
          surface: const Size(1024, 768),
        );

        final bar = find.byKey(const Key('category-bar'));
        expect(bar, findsOneWidget);

        final firstChipBefore =
            tester.getTopLeft(find.byType(CategoryChip).first).dx;
        await tester.drag(bar, const Offset(-300, 0));
        await tester.pumpAndSettle();

        expect(
          tester.getTopLeft(find.byType(CategoryChip).first).dx,
          lessThan(firstChipBefore),
        );
        expect(tester.takeException(), isNull);
      });

      testWidgets('a short bar still stretches across the full width',
          (WidgetTester tester) async {
        await pumpCategories(tester, ['Bier', 'Wein', 'Snacks']);

        final chips = find.byType(CategoryChip);
        final left = tester.getTopLeft(chips.first).dx;
        final right = tester.getTopRight(chips.at(2)).dx;
        final barWidth = tester.getSize(find.byKey(const Key('category-bar'))).width;

        // Chips plus their gaps span the bar: no dead space on the right.
        expect(right - left, closeTo(barWidth, 1));
      });

      testWidgets('a short bar does not scroll', (WidgetTester tester) async {
        await pumpCategories(tester, ['Bier', 'Wein', 'Snacks']);

        final bar = find.byKey(const Key('category-bar'));
        final firstChipBefore =
            tester.getTopLeft(find.byType(CategoryChip).first).dx;
        await tester.drag(bar, const Offset(-300, 0));
        await tester.pumpAndSettle();

        expect(
          tester.getTopLeft(find.byType(CategoryChip).first).dx,
          firstChipBefore,
        );
      });
    });

    // Issue #31: the grid used to filter products itself, which meant a
    // terminal with no dispenser still sold sauna tokens and a jammed one
    // only said so at checkout.
    group('dispenser-gated products', () {
      final beer = ProductsCacheData(
        id: 'prod-beer',
        categoryId: 'cat-1',
        names: jsonEncode({'de': 'Bier'}),
        descriptions: null,
        priceCents: 350,
        isActive: 1,
        requiresDispenser: 0,
        iconName: null,
        updatedAt: '2025-02-01T10:00:00Z',
      );

      final token = ProductsCacheData(
        id: 'prod-token',
        categoryId: 'cat-1',
        names: jsonEncode({'de': 'Sauna-Token'}),
        descriptions: null,
        priceCents: 200,
        isActive: 1,
        requiresDispenser: 1,
        iconName: null,
        updatedAt: '2025-02-01T10:00:00Z',
      );

      Future<void> pumpGrid(
        WidgetTester tester, {
        required List<ProductsCacheData> visible,
        required Set<String> unavailableIds,
      }) async {
        final categories = [
          CategoriesCacheData(
            id: 'cat-1',
            names: jsonEncode({'de': 'Getränke'}),
            isActive: 1,
            updatedAt: '2025-02-01T10:00:00Z',
          ),
        ];

        when(() => mockProductsProvider.categories).thenReturn(categories);
        when(() => mockProductsProvider.products)
            .thenReturn([beer, token]);
        when(() => mockProductsProvider.getVisibleProducts('cat-1'))
            .thenReturn(visible);
        when(() => mockProductsProvider.isProductAvailable(any()))
            .thenAnswer((invocation) {
          final product =
              invocation.positionalArguments[0] as ProductsCacheData;
          return !unavailableIds.contains(product.id);
        });
        when(() => mockProductsProvider.lastError).thenReturn(null);
        when(() => mockProductsProvider.getTranslatedName(any(), any()))
            .thenAnswer((invocation) {
          final product =
              invocation.positionalArguments[0] as ProductsCacheData;
          return product.id == 'prod-token' ? 'Sauna-Token' : 'Bier';
        });
        when(() => mockCartProvider.itemCount).thenReturn(0);
        when(() => mockCartProvider.items).thenReturn([]);
        when(() => mockCartProvider.addItem(
              any(),
              any(),
              any(),
              any(),
              any(),
              iconName: any(named: 'iconName'),
              requiresDispenser: any(named: 'requiresDispenser'),
            )).thenReturn(null);

        await tester.pumpWidget(
          createTestApp(
            child: MultiProvider(
              providers: [
                ChangeNotifierProvider<ProductsProvider>.value(
                    value: mockProductsProvider),
                ChangeNotifierProvider<CartProvider>.value(
                    value: mockCartProvider),
                ChangeNotifierProvider<AuthProvider>.value(
                    value: mockAuthProvider),
                ChangeNotifierProvider<SyncProvider>.value(
                    value: mockSyncProvider),
                ChangeNotifierProvider<MembersProvider>.value(
                    value: mockMembersProvider),
                ChangeNotifierProvider<SessionController>.value(
                    value: mockSessionController),
                Provider<SoundService>.value(value: mockSoundService),
              ],
              child: const Scaffold(body: ProductSelectionScreen()),
            ),
          ),
        );
      }

      testWidgets('shows only the products the provider deems visible',
          (WidgetTester tester) async {
        // A terminal without a dispenser: getVisibleProducts drops the token.
        await pumpGrid(tester, visible: [beer], unavailableIds: {});

        expect(find.byType(ProductCard), findsOneWidget);
        expect(find.text('Bier'), findsOneWidget);
        expect(find.text('Sauna-Token'), findsNothing);
      });

      testWidgets(
          'shows the empty-category message when everything is filtered out',
          (WidgetTester tester) async {
        await pumpGrid(tester, visible: [], unavailableIds: {});

        expect(find.byType(ProductCard), findsNothing);
        expect(find.text('Keine Produkte in dieser Kategorie'), findsOneWidget);
      });

      testWidgets('keeps an unavailable token on the grid, with a reason',
          (WidgetTester tester) async {
        await pumpGrid(
          tester,
          visible: [beer, token],
          unavailableIds: {'prod-token'},
        );

        final l10n = await AppLocalizations.delegate.load(const Locale('de'));

        expect(find.byType(ProductCard), findsNWidgets(2));
        expect(find.text('Sauna-Token'), findsOneWidget);
        expect(
          find.text(l10n.productUnavailableDispenserOffline),
          findsOneWidget,
        );
      });

      testWidgets('an unavailable token cannot be added to the cart',
          (WidgetTester tester) async {
        await pumpGrid(
          tester,
          visible: [beer, token],
          unavailableIds: {'prod-token'},
        );

        await tester.tap(find.text('Sauna-Token'));
        await tester.pumpAndSettle();

        verifyNever(() => mockCartProvider.addItem(
              'prod-token',
              any(),
              any(),
              any(),
              any(),
              iconName: any(named: 'iconName'),
              requiresDispenser: any(named: 'requiresDispenser'),
            ));
      });

      testWidgets('an available product is still addable',
          (WidgetTester tester) async {
        await pumpGrid(
          tester,
          visible: [beer, token],
          unavailableIds: {'prod-token'},
        );

        await tester.tap(find.text('Bier'));
        await tester.pumpAndSettle();

        verify(() => mockCartProvider.addItem(
              'prod-beer',
              'Bier',
              350,
              1,
              'de',
              iconName: any(named: 'iconName'),
              requiresDispenser: any(named: 'requiresDispenser'),
            )).called(1);
      });
    });
  });
}
