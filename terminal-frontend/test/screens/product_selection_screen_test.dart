import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'dart:convert';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/models/cart_item.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/providers/products_provider.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/screens/product_selection_screen.dart';
import 'package:clubbar_terminal/services/cart_service.dart';
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/services/sound_service.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/utils/formatters.dart';
import 'package:clubbar_terminal/widgets/cart_summary_bar.dart';
import 'package:clubbar_terminal/widgets/error_banner.dart';
import 'package:clubbar_terminal/widgets/loading_overlay.dart';
import 'package:clubbar_terminal/widgets/styled_components/category_chip.dart';
import 'package:clubbar_terminal/widgets/styled_components/product_card.dart';
import '../test_helpers.dart';

class MockProductsProvider extends Mock implements ProductsProvider {}
class MockCartProvider extends Mock implements CartProvider {}
class MockSyncProvider extends Mock implements SyncProvider {}
class MockMembersProvider extends Mock implements MembersProvider {}
class MockSessionController extends Mock implements SessionController {}
class MockSoundService extends Mock implements SoundService {}

class MockCartService extends Mock implements CartService {}

class FakeBuildContext extends Fake implements BuildContext {}

class FakeMembersCacheData extends Fake implements MembersCacheData {}

void main() {
  setUpAll(() {
    registerFallbackValue(FakeBuildContext());
    registerFallbackValue(FakeMembersCacheData());
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
    late MockSyncProvider mockSyncProvider;
    late MockMembersProvider mockMembersProvider;
    late MockSessionController mockSessionController;
    late MockSoundService mockSoundService;

    setUp(() {
      mockProductsProvider = MockProductsProvider();
      mockCartProvider = MockCartProvider();
      mockSyncProvider = MockSyncProvider();
      mockMembersProvider = MockMembersProvider();
      mockSessionController = MockSessionController();
      when(() => mockSessionController.isCriticalOperationInFlight).thenReturn(false);
      when(() => mockSessionController.addListener(any())).thenReturn(null);
      when(() => mockSessionController.removeListener(any())).thenReturn(null);
      mockSoundService = MockSoundService();

      // Setup sync provider mocks
      when(() => mockSyncProvider.isSyncing).thenReturn(false);
      when(() => mockSyncProvider.addListener(any())).thenReturn(null);
      when(() => mockSyncProvider.removeListener(any())).thenReturn(null);
      when(() => mockSyncProvider.pairingMismatch).thenReturn(false);
      // #395: MainLayout renders CredentialExpiredBanner above every
      // screen, and an unstubbed non-nullable getter throws rather than
      // reading as false.
      when(() => mockSyncProvider.credentialExpired).thenReturn(false);
      // Setup members provider mocks
      when(() => mockMembersProvider.selectedMember).thenReturn(null);
      when(() => mockMembersProvider.memberDeckel).thenReturn(0);
      when(() => mockMembersProvider.addListener(any())).thenReturn(null);
      when(() => mockMembersProvider.removeListener(any())).thenReturn(null);

      // Setup cart provider mocks — an empty cart, no checkout in flight.
      // The summary bar (#34) reads all of these on every build.
      when(() => mockCartProvider.total).thenReturn(0);
      when(() => mockCartProvider.itemCount).thenReturn(0);
      when(() => mockCartProvider.items).thenReturn([]);
      when(() => mockCartProvider.isLoading).thenReturn(false);
      when(() => mockCartProvider.lastError).thenReturn(null);
      when(() => mockCartProvider.addListener(any())).thenReturn(null);
      when(() => mockCartProvider.removeListener(any())).thenReturn(null);
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
              ChangeNotifierProvider<SyncProvider>.value(value: mockSyncProvider),
              ChangeNotifierProvider<MembersProvider>.value(value: mockMembersProvider),
              ChangeNotifierProvider<SessionController>.value(value: mockSessionController),
              Provider<SoundService>.value(value: mockSoundService),
            Provider<ConfigService>.value(value: createMockConfigService()),
              // The screens resolve a member's ceiling through the club
              // policy this service holds (ADR-0047).
              Provider<ConfigService>.value(value: createMockConfigService()),
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
                ChangeNotifierProvider<SyncProvider>.value(
                    value: mockSyncProvider),
                ChangeNotifierProvider<MembersProvider>.value(
                    value: mockMembersProvider),
                ChangeNotifierProvider<SessionController>.value(
                    value: mockSessionController),
                Provider<SoundService>.value(value: mockSoundService),
            Provider<ConfigService>.value(value: createMockConfigService()),
              // The screens resolve a member's ceiling through the club
              // policy this service holds (ADR-0047).
              Provider<ConfigService>.value(value: createMockConfigService()),
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
                ChangeNotifierProvider<SyncProvider>.value(
                    value: mockSyncProvider),
                ChangeNotifierProvider<MembersProvider>.value(
                    value: mockMembersProvider),
                ChangeNotifierProvider<SessionController>.value(
                    value: mockSessionController),
                Provider<SoundService>.value(value: mockSoundService),
            Provider<ConfigService>.value(value: createMockConfigService()),
              // The screens resolve a member's ceiling through the club
              // policy this service holds (ADR-0047).
              Provider<ConfigService>.value(value: createMockConfigService()),
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

      // The tile height used to be pinned at 218, measured when `xl` was 18.
      // Raising the kiosk default to 20 made the card 225 px and every tile
      // overflowed by 7 (#41). The height is derived from the type scale now,
      // and the scale is a deployment setting — so the fit has to hold at more
      // than the one scale that happens to ship.
      group('the tile tracks the type scale (#41)', () {
        /// Swap in a scale for one test and put the shipped one back after.
        void useScale(
          WidgetTester tester, {
          required double xs,
          required double sm,
          required double base,
          required double lg,
          required double xl,
          required double xxl,
          required double xxxl,
        }) {
          final shipped = {
            'xs': AppFontSizes.xs,
            'sm': AppFontSizes.sm,
            'base': AppFontSizes.base,
            'lg': AppFontSizes.lg,
            'xl': AppFontSizes.xl,
            'xxl': AppFontSizes.xxl,
            'xxxl': AppFontSizes.xxxl,
          };
          addTearDown(() => AppFontSizes.applyConfig(shipped));
          AppFontSizes.applyConfig({
            'xs': xs, 'sm': sm, 'base': base, 'lg': lg,
            'xl': xl, 'xxl': xxl, 'xxxl': xxxl,
          });
        }

        testWidgets('fits at the smaller scale the app used to ship',
            (WidgetTester tester) async {
          useScale(tester,
              xs: 12, sm: 13, base: 14, lg: 16, xl: 18, xxl: 20, xxxl: 24);
          await pumpCatalog(tester, 9);

          expect(tester.takeException(), isNull);
        });

        testWidgets('fits at a scale a large-print deployment might set',
            (WidgetTester tester) async {
          useScale(tester,
              xs: 16, sm: 17, base: 20, lg: 22, xl: 24, xxl: 26, xxxl: 30);
          await pumpCatalog(tester, 9);

          expect(tester.takeException(), isNull);
        });

        testWidgets('a bigger scale buys a taller tile, not a clipped one',
            (WidgetTester tester) async {
          useScale(tester,
              xs: 12, sm: 13, base: 14, lg: 16, xl: 18, xxl: 20, xxxl: 24);
          await pumpCatalog(tester, 9);
          final small = tester.getSize(find.byType(ProductCard).first).height;

          // The screen is pumped as a const widget, so an identical second
          // pumpWidget reuses the element tree and never re-reads the scale.
          // Drop the tree first to make the rebuild real.
          await tester.pumpWidget(const SizedBox.shrink());

          useScale(tester,
              xs: 16, sm: 17, base: 20, lg: 22, xl: 24, xxl: 26, xxxl: 30);
          await pumpCatalog(tester, 9);
          final large = tester.getSize(find.byType(ProductCard).first).height;

          expect(large, greaterThan(small),
              reason: 'a pinned height is what caused #41');
          expect(tester.takeException(), isNull);
        });
      });
    });

    // Issue #293: the grid used `padding: EdgeInsets.zero`, so the last row sat
    // flush against the bottom of the viewport with nothing between it and the
    // summary bar below (#34).
    //
    // #369 corrected the size of the fix, not the fix. #293 reserved a whole
    // CartSummaryBar.height on the premise that the bar was "sticky below the
    // grid, not part of its scroll extent" — but the bar is a plain Column
    // sibling *after* the Expanded, so the grid's viewport already ends where
    // the bar begins and the two can never overlap. That reservation was 93 px
    // of dead scroll at the end of the list, on a screen that could not spare
    // 19. What is left is a flat `md` gap, applied in both cart states.
    group('grid bottom padding clears the summary bar (#293)', () {
      Future<void> pumpCatalog(
        WidgetTester tester, {
        required int productCount,
        required bool cartHasItems,
        Size surface = const Size(1280, 800),
      }) async {
        tester.view.physicalSize = surface;
        tester.view.devicePixelRatio = 1.0;
        addTearDown(tester.view.reset);

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
          final product =
              invocation.positionalArguments[0] as ProductsCacheData;
          return 'Produkt ${product.id}';
        });

        if (cartHasItems) {
          when(() => mockCartProvider.items).thenReturn([
            CartItem(
              productId: 'prod-0',
              productName: 'Produkt prod-0',
              quantity: 1,
              priceCents: 250,
              language: 'de',
            ),
          ]);
          when(() => mockCartProvider.itemCount).thenReturn(1);
          when(() => mockCartProvider.total).thenReturn(250);
        } else {
          when(() => mockCartProvider.items).thenReturn([]);
          when(() => mockCartProvider.itemCount).thenReturn(0);
          when(() => mockCartProvider.total).thenReturn(0);
        }

        await tester.pumpWidget(
          createTestApp(
            child: MultiProvider(
              providers: [
                ChangeNotifierProvider<ProductsProvider>.value(
                    value: mockProductsProvider),
                ChangeNotifierProvider<CartProvider>.value(
                    value: mockCartProvider),
                ChangeNotifierProvider<SyncProvider>.value(
                    value: mockSyncProvider),
                ChangeNotifierProvider<MembersProvider>.value(
                    value: mockMembersProvider),
                ChangeNotifierProvider<SessionController>.value(
                    value: mockSessionController),
                Provider<SoundService>.value(value: mockSoundService),
            Provider<ConfigService>.value(value: createMockConfigService()),
              // The screens resolve a member's ceiling through the club
              // policy this service holds (ADR-0047).
              Provider<ConfigService>.value(value: createMockConfigService()),
              ],
              child: const Scaffold(body: ProductSelectionScreen()),
            ),
          ),
        );
      }

      testWidgets('no dead space at the bottom while the cart is empty',
          (WidgetTester tester) async {
        await pumpCatalog(tester, productCount: 40, cartHasItems: false);

        expect(find.byType(CartSummaryBar), findsNothing);
        final grid = tester.widget<GridView>(find.byType(GridView));
        expect(grid.padding, const EdgeInsets.only(bottom: AppSpacing.md));
      });

      testWidgets(
          'the gap stays a gap once the cart is not empty, not a whole bar',
          (WidgetTester tester) async {
        await pumpCatalog(tester, productCount: 40, cartHasItems: true);

        expect(find.byType(CartSummaryBar), findsOneWidget);
        final grid = tester.widget<GridView>(find.byType(GridView));
        expect(grid.padding, const EdgeInsets.only(bottom: AppSpacing.md));
        // The regression this guards: reserving the bar's height here does not
        // move the last row anywhere the viewport can see, it just adds that
        // much emptiness to the scroll.
        expect(
          (grid.padding as EdgeInsets).bottom,
          lessThan(CartSummaryBar.height),
        );
      });

      // Same three type scales as #41 — the reserved space is a fixed
      // constant, not derived from the tile's own font-driven height, so it
      // must not fight the tile sizing at any of them.
      for (final scale in [
        {
          'xs': 12.0, 'sm': 13.0, 'base': 14.0,
          'lg': 16.0, 'xl': 18.0, 'xxl': 20.0, 'xxxl': 24.0,
        },
        {
          'xs': 14.0, 'sm': 15.0, 'base': 16.0,
          'lg': 18.0, 'xl': 20.0, 'xxl': 24.0, 'xxxl': 28.0,
        },
        {
          'xs': 16.0, 'sm': 17.0, 'base': 20.0,
          'lg': 22.0, 'xl': 24.0, 'xxl': 26.0, 'xxxl': 30.0,
        },
      ]) {
        testWidgets(
            'the last tile scrolls fully above the bar at xl ${scale['xl']}',
            (WidgetTester tester) async {
          final shipped = {
            'xs': AppFontSizes.xs,
            'sm': AppFontSizes.sm,
            'base': AppFontSizes.base,
            'lg': AppFontSizes.lg,
            'xl': AppFontSizes.xl,
            'xxl': AppFontSizes.xxl,
            'xxxl': AppFontSizes.xxxl,
          };
          addTearDown(() => AppFontSizes.applyConfig(shipped));
          AppFontSizes.applyConfig(scale);

          await pumpCatalog(tester, productCount: 40, cartHasItems: true);
          expect(tester.takeException(), isNull);

          // Drag to the grid's true max scroll extent, not merely until the
          // last tile first becomes hit-testable (dragUntilVisible stops as
          // soon as any sliver of it is on screen, which is exactly the
          // half-covered state #293 is about).
          final gridScrollable = find.descendant(
            of: find.byType(GridView),
            matching: find.byType(Scrollable),
          );
          double previousOffset;
          double offset = -1;
          do {
            previousOffset = offset;
            await tester.drag(find.byType(GridView), const Offset(0, -600));
            await tester.pump();
            offset = tester
                .state<ScrollableState>(gridScrollable)
                .position
                .pixels;
          } while (offset != previousOffset);
          await tester.pumpAndSettle();

          final tileBottom =
              tester.getBottomLeft(find.byType(ProductCard).last).dy;
          final barTop = tester.getTopLeft(find.byType(CartSummaryBar)).dy;

          expect(tileBottom, lessThanOrEqualTo(barTop));
          // `lessThanOrEqualTo` alone passes for free (#369): at max scroll
          // extent the last tile's bottom *is* the viewport bottom less the
          // grid's own bottom padding, and the viewport bottom is barTop
          // whatever that padding happens to be — so the assertion above held
          // at 93 px, at 12 px and at 0. Pin the gap itself, which does not.
          expect(barTop - tileBottom, closeTo(AppSpacing.md, 1));
          expect(tester.takeException(), isNull);
        });
      }
    });

    // Issue #369: the screen is a non-scrolling Column whose only flexible
    // child is the grid, so every fixed band above it — member bar, banners,
    // category bar — is height the grid does not get, and nothing was checking
    // what was left. On a 1280x800 kiosk the grid ended up 19 px short of two
    // whole tile rows with no warning showing, and 111 px short with the
    // credit-limit banner up: the member saw one row of products and the top
    // half of another, prices sliced off.
    //
    // The surface is 744, not 800, and the screen is pumped bare: MainLayout
    // owns a fixed 56 px ClubBarHeader (ClubBarHeader.preferredSize) and hands
    // its child exactly what is left, so 800 - 56 is the body this screen
    // actually gets. Modelling it as the surface keeps the test off
    // MainLayout's provider surface without understating the chrome.
    //
    // Note the test font: flutter_test renders with a 1.0 line-height factor
    // where production measures ~1.34, so this harness under-reports the
    // font-driven chrome by a few px. There is ~39 px of real headroom behind
    // these assertions — do not trim the layout down to what the test font
    // says fits.
    group('two full product rows fit on the kiosk (#369)', () {
      const kioskBody = Size(1280, 744);

      /// Lays out the screen the way the issue's screenshots did: a member with
      /// a tab, one item already in the cart, a full catalogue.
      ///
      /// [deckelCents] drives the banner — 0 keeps it away, 8000 puts the
      /// member in the warning band (80% of AppConfig.balanceLimitCents).
      Future<void> pumpKiosk(
        WidgetTester tester, {
        required int deckelCents,
      }) async {
        tester.view.physicalSize = kioskBody;
        tester.view.devicePixelRatio = 1.0;
        addTearDown(tester.view.reset);

        final categories = [
          CategoriesCacheData(
            id: 'cat-1',
            names: jsonEncode({'de': 'Getränke'}),
            isActive: 1,
            updatedAt: '2025-02-01T10:00:00Z',
          ),
        ];
        final products = List.generate(
          40,
          (i) => ProductsCacheData(
            id: 'prod-$i',
            categoryId: 'cat-1',
            names: jsonEncode({'de': 'Alkoholfreies Bier $i'}),
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
          final product =
              invocation.positionalArguments[0] as ProductsCacheData;
          return 'Alkoholfreies Bier ${product.id}';
        });

        when(() => mockMembersProvider.selectedMember).thenReturn(
          MembersCacheData(
            id: 'member-1',
            cardUid: 'card-123',
            firstName: 'Daniel',
            lastName: 'Glöckner',
            preferredLanguage: 'de',
            isActive: 1,
            isSepaValid: 1,
            balanceCents: deckelCents,
            updatedAt: '2024-01-01T00:00:00Z',
          ),
        );
        when(() => mockMembersProvider.memberDeckel).thenReturn(deckelCents);

        when(() => mockCartProvider.items).thenReturn([
          CartItem(
            productId: 'prod-0',
            productName: 'Alkoholfreies Bier 0',
            quantity: 1,
            priceCents: 350,
            language: 'de',
          ),
        ]);
        when(() => mockCartProvider.itemCount).thenReturn(1);
        when(() => mockCartProvider.total).thenReturn(350);

        await tester.pumpWidget(
          createTestApp(
            child: MultiProvider(
              providers: [
                ChangeNotifierProvider<ProductsProvider>.value(
                    value: mockProductsProvider),
                ChangeNotifierProvider<CartProvider>.value(
                    value: mockCartProvider),
                ChangeNotifierProvider<SyncProvider>.value(
                    value: mockSyncProvider),
                ChangeNotifierProvider<MembersProvider>.value(
                    value: mockMembersProvider),
                ChangeNotifierProvider<SessionController>.value(
                    value: mockSessionController),
                Provider<SoundService>.value(value: mockSoundService),
            Provider<ConfigService>.value(value: createMockConfigService()),
              // The screens resolve a member's ceiling through the club
              // policy this service holds (ADR-0047).
              Provider<ConfigService>.value(value: createMockConfigService()),
              ],
              child: const Scaffold(body: ProductSelectionScreen()),
            ),
          ),
        );
      }

      /// Bottom edge of the last tile in the second row, unscrolled.
      ///
      /// The column count follows the screen width, so it is counted rather
      /// than assumed: every card sharing the first card's top edge is row one.
      /// `getBottomLeft` reports geometry even for a tile the viewport is
      /// clipping, which is the whole point — a clipped row still has a bottom,
      /// it is just below the fold.
      double secondRowBottom(WidgetTester tester) {
        final cards = find.byType(ProductCard);
        final firstTop = tester.getTopLeft(cards.first).dy;
        var columns = 0;
        while (columns < tester.widgetList(cards).length &&
            tester.getTopLeft(cards.at(columns)).dy == firstTop) {
          columns++;
        }
        return tester.getBottomLeft(cards.at(2 * columns - 1)).dy;
      }

      testWidgets('both rows are whole while the credit-limit banner is up',
          (WidgetTester tester) async {
        await pumpKiosk(tester, deckelCents: 8200);

        expect(find.byKey(const Key('credit-limit-banner')), findsOneWidget);
        expect(find.byType(CartSummaryBar), findsOneWidget);

        final gridBottom =
            tester.getBottomLeft(find.byType(GridView)).dy;
        expect(secondRowBottom(tester), lessThanOrEqualTo(gridBottom));
        expect(tester.takeException(), isNull);
      });

      testWidgets('both rows are whole with no banner at all',
          (WidgetTester tester) async {
        await pumpKiosk(tester, deckelCents: 0);

        expect(find.byKey(const Key('credit-limit-banner')), findsNothing);
        expect(find.byType(CartSummaryBar), findsOneWidget);

        final gridBottom =
            tester.getBottomLeft(find.byType(GridView)).dy;
        expect(secondRowBottom(tester), lessThanOrEqualTo(gridBottom));
        expect(tester.takeException(), isNull);
      });

      testWidgets('and at the legacy small type scale',
          (WidgetTester tester) async {
        final shipped = {
          'xs': AppFontSizes.xs,
          'sm': AppFontSizes.sm,
          'base': AppFontSizes.base,
          'lg': AppFontSizes.lg,
          'xl': AppFontSizes.xl,
          'xxl': AppFontSizes.xxl,
          'xxxl': AppFontSizes.xxxl,
        };
        addTearDown(() => AppFontSizes.applyConfig(shipped));
        AppFontSizes.applyConfig({
          'xs': 12.0, 'sm': 13.0, 'base': 14.0,
          'lg': 16.0, 'xl': 18.0, 'xxl': 20.0, 'xxxl': 24.0,
        });

        await pumpKiosk(tester, deckelCents: 8200);

        final gridBottom =
            tester.getBottomLeft(find.byType(GridView)).dy;
        expect(secondRowBottom(tester), lessThanOrEqualTo(gridBottom));
        expect(tester.takeException(), isNull);
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
                ChangeNotifierProvider<SyncProvider>.value(
                    value: mockSyncProvider),
                ChangeNotifierProvider<MembersProvider>.value(
                    value: mockMembersProvider),
                ChangeNotifierProvider<SessionController>.value(
                    value: mockSessionController),
                Provider<SoundService>.value(value: mockSoundService),
            Provider<ConfigService>.value(value: createMockConfigService()),
              // The screens resolve a member's ceiling through the club
              // policy this service holds (ADR-0047).
              Provider<ConfigService>.value(value: createMockConfigService()),
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
                ChangeNotifierProvider<SyncProvider>.value(
                    value: mockSyncProvider),
                ChangeNotifierProvider<MembersProvider>.value(
                    value: mockMembersProvider),
                ChangeNotifierProvider<SessionController>.value(
                    value: mockSessionController),
                Provider<SoundService>.value(value: mockSoundService),
            Provider<ConfigService>.value(value: createMockConfigService()),
              // The screens resolve a member's ceiling through the club
              // policy this service holds (ADR-0047).
              Provider<ConfigService>.value(value: createMockConfigService()),
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

    // Issue #34: the grid used to show an item *count* and nothing else, so
    // the running total — the number a tab-based system is about — lived one
    // screen away behind a mandatory interstitial. The summary bar puts the
    // total and checkout where the tapping happens.
    group('cart summary bar (#34)', () {
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

      final testMember = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        preferredLanguage: 'de',
        isActive: 1,
        isSepaValid: 1,
        balanceCents: 0,
        updatedAt: '2025-02-01T10:00:00Z',
      );

      /// A cart holding [quantity] beers at €3.50.
      void withCart(int quantity) {
        when(() => mockCartProvider.items).thenReturn([
          CartItem(
            productId: 'prod-beer',
            productName: 'Bier',
            quantity: quantity,
            priceCents: 350,
            language: 'de',
          ),
        ]);
        when(() => mockCartProvider.itemCount).thenReturn(quantity);
        when(() => mockCartProvider.total).thenReturn(quantity * 350);
      }

      /// The screen behind a real router, so `/cart` and the confirmation hop
      /// are exercised rather than stubbed.
      ///
      /// [cart] swaps in a real [CartProvider] for the tests that need the
      /// grid to actually change the cart rather than assert against a stub.
      Future<void> pumpScreen(
        WidgetTester tester, {
        CartProvider? cart,
        Size surface = const Size(1600, 900),
      }) async {
        tester.view.physicalSize = surface;
        tester.view.devicePixelRatio = 1.0;
        addTearDown(tester.view.reset);

        when(() => mockProductsProvider.categories).thenReturn([
          CategoriesCacheData(
            id: 'cat-1',
            names: jsonEncode({'de': 'Getränke'}),
            isActive: 1,
            updatedAt: '2025-02-01T10:00:00Z',
          ),
        ]);
        when(() => mockProductsProvider.products).thenReturn([beer]);
        when(() => mockProductsProvider.getVisibleProducts(any()))
            .thenReturn([beer]);
        when(() => mockProductsProvider.isProductAvailable(any()))
            .thenReturn(true);
        when(() => mockProductsProvider.lastError).thenReturn(null);
        when(() => mockProductsProvider.getTranslatedName(any(), any()))
            .thenReturn('Bier');
        when(() => mockMembersProvider.selectedMember).thenReturn(testMember);
        when(() => mockMembersProvider.sessionId).thenReturn('session-1');
        when(() => mockMembersProvider.refreshDeckel()).thenAnswer((_) async {});
        when(() => mockSoundService.play(any())).thenAnswer((_) async {});

        final router = GoRouter(
          initialLocation: '/products',
          routes: [
            GoRoute(
              path: '/products',
              builder: (context, state) =>
                  const Scaffold(body: ProductSelectionScreen()),
            ),
            GoRoute(
              path: '/cart',
              builder: (context, state) =>
                  const Scaffold(body: Center(child: Text('CART SCREEN'))),
            ),
            GoRoute(
              path: '/confirmation/:sessionId',
              builder: (context, state) =>
                  const Scaffold(body: Center(child: Text('CONFIRMATION'))),
            ),
          ],
        );

        await tester.pumpWidget(
          MultiProvider(
            providers: [
              ChangeNotifierProvider<ProductsProvider>.value(
                  value: mockProductsProvider),
              ChangeNotifierProvider<CartProvider>.value(
                  value: cart ?? mockCartProvider),
              ChangeNotifierProvider<SyncProvider>.value(
                  value: mockSyncProvider),
              ChangeNotifierProvider<MembersProvider>.value(
                  value: mockMembersProvider),
              ChangeNotifierProvider<SessionController>.value(
                  value: mockSessionController),
              Provider<SoundService>.value(value: mockSoundService),
            Provider<ConfigService>.value(value: createMockConfigService()),
              // The screens resolve a member's ceiling through the club
              // policy this service holds (ADR-0047).
              Provider<ConfigService>.value(value: createMockConfigService()),
            ],
            child: MaterialApp.router(
              routerConfig: router,
              locale: const Locale('de'),
              localizationsDelegates: const [
                AppLocalizations.delegate,
                GlobalMaterialLocalizations.delegate,
                GlobalWidgetsLocalizations.delegate,
                GlobalCupertinoLocalizations.delegate,
              ],
              supportedLocales: const [Locale('en'), Locale('de')],
            ),
          ),
        );
      }

      InkWell checkoutInkWell(WidgetTester tester) => tester.widget<InkWell>(
            find.descendant(
              of: find.byKey(const Key('checkout-button')),
              matching: find.byType(InkWell),
            ),
          );

      /// The amount on the bar — read off the bar itself, since the same
      /// figure can legitimately appear on a product tile.
      String runningTotal(WidgetTester tester) => tester
          .widget<Text>(find.byKey(const Key('cart-summary-total')))
          .data!;

      testWidgets('stays away while the cart is empty',
          (WidgetTester tester) async {
        await pumpScreen(tester);

        expect(find.byType(CartSummaryBar), findsNothing);
      });

      testWidgets('shows the running total once the cart is not empty',
          (WidgetTester tester) async {
        withCart(2);

        await pumpScreen(tester);

        expect(find.byType(CartSummaryBar), findsOneWidget);
        expect(runningTotal(tester), formatPrice(700, 'de'));
      });

      // The point of the bar: the member watches the amount climb while they
      // tap, instead of finding it out on another screen. Driven through a
      // real CartProvider, because a stubbed total cannot grow.
      testWidgets('the total grows as products are tapped',
          (WidgetTester tester) async {
        final cart = CartProvider(
          service: MockCartService(),
          config: createMockConfigService(),
          soundService: mockSoundService,
        );

        await pumpScreen(tester, cart: cart);
        expect(find.byType(CartSummaryBar), findsNothing);

        await tester.tap(find.text('Bier'));
        await tester.pump();
        expect(runningTotal(tester), formatPrice(350, 'de'));

        await tester.tap(find.text('Bier'));
        await tester.pump();
        expect(runningTotal(tester), formatPrice(700, 'de'));
      });

      // The bar shares a row with two buttons whose labels are translated and
      // whose font sizes are configurable per kiosk, so it has to survive the
      // narrowest screen the grid itself supports (see #29).
      for (final surface in [const Size(1024, 768), const Size(1920, 1080)]) {
        testWidgets(
            'lays out on a ${surface.width.toInt()}px screen without overflowing',
            (WidgetTester tester) async {
          withCart(2);
          when(() => mockMembersProvider.memberDeckel).thenReturn(9950);

          // The blocked state carries the longest of the three labels.
          await pumpScreen(tester, surface: surface);

          expect(tester.takeException(), isNull);
          expect(find.byType(CartSummaryBar), findsOneWidget);
        });
      }

      testWidgets('the projected tab is shown next to the total',
          (WidgetTester tester) async {
        withCart(2);
        when(() => mockMembersProvider.memberDeckel).thenReturn(1000);

        await pumpScreen(tester);

        final l10n = await AppLocalizations.delegate.load(const Locale('de'));
        expect(
          find.text(formatNewBalance(1700, l10n, 'de')),
          findsOneWidget,
        );
      });

      testWidgets('checkout runs without a detour through the cart screen',
          (WidgetTester tester) async {
        withCart(2);
        when(() => mockCartProvider.checkout(any(), any(), any()))
            .thenAnswer((_) async {});
        when(() => mockCartProvider.lastSessionId).thenReturn('session-1');

        await pumpScreen(tester);
        await tester.tap(find.byKey(const Key('checkout-button')));
        await tester.pumpAndSettle();

        verify(() => mockCartProvider.checkout(any(), testMember, 'session-1'))
            .called(1);
        expect(find.text('CONFIRMATION'), findsOneWidget);
        expect(find.text('CART SCREEN'), findsNothing);
      });

      testWidgets('the inactivity timer is suspended for that checkout',
          (WidgetTester tester) async {
        withCart(2);
        when(() => mockCartProvider.checkout(any(), any(), any()))
            .thenAnswer((_) async {});
        when(() => mockCartProvider.lastSessionId).thenReturn('session-1');

        await pumpScreen(tester);
        await tester.tap(find.byKey(const Key('checkout-button')));
        await tester.pumpAndSettle();

        // ADR-0027 rule 7 — the same guard the cart screen applies.
        verify(() => mockSessionController.beginCriticalOperation()).called(1);
        verify(() => mockSessionController.endCriticalOperation()).called(1);
      });

      testWidgets('the cart is still one tap away for edits',
          (WidgetTester tester) async {
        withCart(2);

        await pumpScreen(tester);
        await tester.tap(find.byKey(const Key('view-cart-button')));
        await tester.pumpAndSettle();

        expect(find.text('CART SCREEN'), findsOneWidget);
      });

      testWidgets('the credit limit blocks checkout here too (UC-T12)',
          (WidgetTester tester) async {
        withCart(2);
        when(() => mockMembersProvider.memberDeckel).thenReturn(9950);

        await pumpScreen(tester);

        expect(checkoutInkWell(tester).onTap, isNull);
        expect(find.text('Limit erreicht'), findsOneWidget);
      });

      group('while a checkout is in flight', () {
        Future<void> pumpInFlight(WidgetTester tester) async {
          withCart(2);
          when(() => mockCartProvider.isLoading).thenReturn(true);
          when(() => mockSessionController.isCriticalOperationInFlight)
              .thenReturn(true);
          await pumpScreen(tester);
        }

        testWidgets('the checkout button is disabled and says so',
            (WidgetTester tester) async {
          await pumpInFlight(tester);

          expect(checkoutInkWell(tester).onTap, isNull);
          expect(find.text('Wird verarbeitet…'), findsOneWidget);
        });

        testWidgets('the grid is frozen so no tile can join the paid cart',
            (WidgetTester tester) async {
          await pumpInFlight(tester);

          final overlay = tester.widget<LoadingOverlay>(
            find.ancestor(
              of: find.byType(GridView),
              matching: find.byType(LoadingOverlay),
            ),
          );
          expect(overlay.isLoading, isTrue);

          await tester.tap(find.text('Bier'), warnIfMissed: false);
          await tester.pump();

          verifyNever(() => mockCartProvider.addItem(
                any(),
                any(),
                any(),
                any(),
                any(),
                iconName: any(named: 'iconName'),
                requiresDispenser: any(named: 'requiresDispenser'),
              ));
        });

        testWidgets('the cart screen cannot be opened mid-checkout',
            (WidgetTester tester) async {
          await pumpInFlight(tester);

          await tester.tap(
            find.byKey(const Key('view-cart-button')),
            warnIfMissed: false,
          );
          // Not pumpAndSettle: the freeze overlay's spinner never settles.
          await tester.pump();
          await tester.pump(const Duration(milliseconds: 400));

          expect(find.text('CART SCREEN'), findsNothing);
          expect(find.byType(CartSummaryBar), findsOneWidget);
        });
      });

      // runCheckout deliberately leaves a cancellation in lastError for the
      // calling screen to render. Without this banner the grid would swallow
      // it and the member would be left wondering what happened.
      testWidgets('a cancelled checkout is acknowledged inline',
          (WidgetTester tester) async {
        withCart(2);
        when(() => mockCartProvider.lastError).thenReturn(
          const TerminalError(
            key: TerminalErrorKey.checkoutCancelled,
            sequence: 1,
          ),
        );

        await pumpScreen(tester);

        expect(find.byType(ErrorBanner), findsOneWidget);
        expect(
          find.text(await errorCopy(TerminalErrorKey.checkoutCancelled)),
          findsOneWidget,
        );
      });

      testWidgets('dismissing that acknowledgement clears the cart error',
          (WidgetTester tester) async {
        withCart(2);
        when(() => mockCartProvider.lastError).thenReturn(
          const TerminalError(
            key: TerminalErrorKey.checkoutCancelled,
            sequence: 1,
          ),
        );
        when(() => mockCartProvider.clearError()).thenReturn(null);

        await pumpScreen(tester);
        await tester.tap(find.byIcon(Icons.close));
        await tester.pump();

        verify(() => mockCartProvider.clearError()).called(1);
      });
    });
  });
}
