# Phase 5: Core UI Screens Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans or superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** Implement main UI screens for the terminal app: member greeting, product selection with categories, shopping cart, and checkout confirmation. All screens use existing providers (MembersProvider, ProductsProvider, CartProvider, RfidProvider) from Phase 3-4.

**Architecture:** Screen-based UI built on Provider pattern. Each screen is a StatelessWidget using Consumer or MultiProvider to access state. TDD approach: test first, implement screen, verify UI behavior.

**Tech Stack:** Flutter, Provider, Material 3 design, widget tests (flutter_test, mocktail)

**References:**
- UC-T01: Book Product to Tab (main flow)
- Phase 3: Provider-Based State Management
- Phase 4: RFID Detection & UI

---

## Task 1: MemberGreetingScreen

**Files:**
- Create: `lib/screens/member_greeting_screen.dart`
- Test: `test/screens/member_greeting_screen_test.dart`

**Overview:** Display member name, current balance, and welcome message. Show product selection button. Handle RFID scan errors gracefully. Clear button to reset for next member.

**Step 1: Write the failing test**

Create `test/screens/member_greeting_screen_test.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/models/member_dto.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/screens/member_greeting_screen.dart';

class MockMembersProvider extends Mock implements MembersProvider {}

void main() {
  group('MemberGreetingScreen', () {
    late MockMembersProvider mockMembersProvider;

    setUp(() {
      mockMembersProvider = MockMembersProvider();
    });

    testWidgets('displays member name and welcome message', (WidgetTester tester) async {
      final testMember = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        iban: 'DE89370400440532013000',
        mandateSigned: true,
        active: true,
      );

      when(() => mockMembersProvider.selectedMember).thenReturn(testMember);
      when(() => mockMembersProvider.addListener(any())).thenReturn(null);
      when(() => mockMembersProvider.removeListener(any())).thenReturn(null);

      await tester.pumpWidget(
        MaterialApp(
          home: ChangeNotifierProvider<MembersProvider>.value(
            value: mockMembersProvider,
            child: const Scaffold(
              body: MemberGreetingScreen(),
            ),
          ),
        ),
      );

      expect(find.text('Welcome, John'), findsOneWidget);
      expect(find.text('Doe'), findsOneWidget);
    });

    testWidgets('displays error message for unknown card', (WidgetTester tester) async {
      when(() => mockMembersProvider.selectedMember).thenReturn(null);
      when(() => mockMembersProvider.lastError).thenReturn('Member not found');
      when(() => mockMembersProvider.addListener(any())).thenReturn(null);
      when(() => mockMembersProvider.removeListener(any())).thenReturn(null);

      await tester.pumpWidget(
        MaterialApp(
          home: ChangeNotifierProvider<MembersProvider>.value(
            value: mockMembersProvider,
            child: const Scaffold(
              body: MemberGreetingScreen(),
            ),
          ),
        ),
      );

      expect(find.text('Member not found'), findsOneWidget);
    });

    testWidgets('displays Continue Shopping button', (WidgetTester tester) async {
      final testMember = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        iban: 'DE89370400440532013000',
        mandateSigned: true,
        active: true,
      );

      when(() => mockMembersProvider.selectedMember).thenReturn(testMember);
      when(() => mockMembersProvider.addListener(any())).thenReturn(null);
      when(() => mockMembersProvider.removeListener(any())).thenReturn(null);

      await tester.pumpWidget(
        MaterialApp(
          home: ChangeNotifierProvider<MembersProvider>.value(
            value: mockMembersProvider,
            child: const Scaffold(
              body: MemberGreetingScreen(),
            ),
          ),
        ),
      );

      expect(find.text('Continue Shopping'), findsOneWidget);
    });

    testWidgets('displays Scan Another Card button', (WidgetTester tester) async {
      when(() => mockMembersProvider.selectedMember).thenReturn(null);
      when(() => mockMembersProvider.addListener(any())).thenReturn(null);
      when(() => mockMembersProvider.removeListener(any())).thenReturn(null);

      await tester.pumpWidget(
        MaterialApp(
          home: ChangeNotifierProvider<MembersProvider>.value(
            value: mockMembersProvider,
            child: const Scaffold(
              body: MemberGreetingScreen(),
            ),
          ),
        ),
      );

      expect(find.text('Scan Card'), findsOneWidget);
    });
  });
}
```

**Step 2: Run test to verify it fails**

```bash
flutter test test/screens/member_greeting_screen_test.dart
```

Expected: FAIL - `MemberGreetingScreen` not defined

**Step 3: Write minimal implementation**

Create `lib/screens/member_greeting_screen.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';

class MemberGreetingScreen extends StatelessWidget {
  const MemberGreetingScreen({Key? key}) : super(key: key);

  @override
  Widget build(BuildContext context) {
    return Consumer<MembersProvider>(
      builder: (context, membersProvider, child) {
        final member = membersProvider.selectedMember;

        if (member == null) {
          // Show error or idle state
          return _buildNoMemberState(context, membersProvider);
        }

        // Show member greeting
        return _buildMemberGreeting(context, member);
      },
    );
  }

  Widget _buildMemberGreeting(BuildContext context, MembersCacheData member) {
    return Column(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        Text(
          'Welcome, ${member.firstName}',
          style: Theme.of(context).textTheme.headlineSmall,
        ),
        const SizedBox(height: 8),
        Text(
          member.lastName,
          style: Theme.of(context).textTheme.titleMedium,
        ),
        const SizedBox(height: 32),
        SizedBox(
          width: 200,
          child: ElevatedButton(
            onPressed: () {
              // Navigate to products screen
            },
            child: const Text('Continue Shopping'),
          ),
        ),
      ],
    );
  }

  Widget _buildNoMemberState(BuildContext context, MembersProvider provider) {
    return Column(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        if (provider.lastError != null)
          Text(
            provider.lastError!,
            style: Theme.of(context).textTheme.bodyLarge?.copyWith(
              color: Colors.red,
            ),
            textAlign: TextAlign.center,
          ),
        const SizedBox(height: 24),
        SizedBox(
          width: 200,
          child: ElevatedButton(
            onPressed: () {
              // Scan card (handled by parent)
            },
            child: const Text('Scan Card'),
          ),
        ),
      ],
    );
  }
}
```

**Step 4: Run test to verify it passes**

```bash
flutter test test/screens/member_greeting_screen_test.dart -v
```

Expected: PASS (all 4 tests)

**Step 5: Commit**

```bash
git add lib/screens/member_greeting_screen.dart test/screens/member_greeting_screen_test.dart
git commit -m "feat: create MemberGreetingScreen (Phase 5 Task 1)

Displays member welcome screen with:
- Member first and last name display
- Continue Shopping button to navigate to products
- Error message display for card scanning issues
- Scan Card button for new members

Tests: 4 widget tests passing
- Member name displayed
- Error message shown when member not found
- Continue Shopping button visible for valid member
- Scan Card button shown for error state"
```

---

## Task 2: ProductSelectionScreen

**Files:**
- Create: `lib/screens/product_selection_screen.dart`
- Test: `test/screens/product_selection_screen_test.dart`

**Overview:** Display categories as horizontal tabs. Show products for selected category in grid. Quantity badges on products. Cart button with item count. Navigate to cart or checkout.

**Step 1: Write the failing test**

Create `test/screens/product_selection_screen_test.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'dart:convert';
import 'package:ruderbar_terminal/models/product_dto.dart';
import 'package:ruderbar_terminal/providers/products_provider.dart';
import 'package:ruderbar_terminal/providers/cart_provider.dart';
import 'package:ruderbar_terminal/screens/product_selection_screen.dart';

class MockProductsProvider extends Mock implements ProductsProvider {}
class MockCartProvider extends Mock implements CartProvider {}

void main() {
  group('ProductSelectionScreen', () {
    late MockProductsProvider mockProductsProvider;
    late MockCartProvider mockCartProvider;

    setUp(() {
      mockProductsProvider = MockProductsProvider();
      mockCartProvider = MockCartProvider();
    });

    testWidgets('displays category tabs', (WidgetTester tester) async {
      final categories = [
        CategoriesCacheData(id: 'cat-1', name: 'Beer', position: 1),
        CategoriesCacheData(id: 'cat-2', name: 'Soft Drinks', position: 2),
      ];

      when(() => mockProductsProvider.categories).thenReturn(categories);
      when(() => mockProductsProvider.products).thenReturn([]);
      when(() => mockProductsProvider.addListener(any())).thenReturn(null);
      when(() => mockProductsProvider.removeListener(any())).thenReturn(null);
      when(() => mockCartProvider.addListener(any())).thenReturn(null);
      when(() => mockCartProvider.removeListener(any())).thenReturn(null);
      when(() => mockCartProvider.itemCount).thenReturn(0);

      await tester.pumpWidget(
        MaterialApp(
          home: MultiProvider(
            providers: [
              ChangeNotifierProvider<ProductsProvider>.value(value: mockProductsProvider),
              ChangeNotifierProvider<CartProvider>.value(value: mockCartProvider),
            ],
            child: const Scaffold(
              body: ProductSelectionScreen(),
            ),
          ),
        ),
      );

      expect(find.text('Beer'), findsOneWidget);
      expect(find.text('Soft Drinks'), findsOneWidget);
    });

    testWidgets('displays products for selected category', (WidgetTester tester) async {
      final categories = [
        CategoriesCacheData(id: 'cat-1', name: 'Beer', position: 1),
      ];
      final products = [
        ProductsCacheData(
          id: 'prod-1',
          categoryId: 'cat-1',
          name: jsonEncode({'de': 'Bier', 'en': 'Beer'}),
          price: 5.0,
          position: 1,
          active: true,
        ),
      ];

      when(() => mockProductsProvider.categories).thenReturn(categories);
      when(() => mockProductsProvider.products).thenReturn(products);
      when(() => mockProductsProvider.getTranslatedName(any(), any())).thenReturn('Beer');
      when(() => mockProductsProvider.addListener(any())).thenReturn(null);
      when(() => mockProductsProvider.removeListener(any())).thenReturn(null);
      when(() => mockCartProvider.addListener(any())).thenReturn(null);
      when(() => mockCartProvider.removeListener(any())).thenReturn(null);
      when(() => mockCartProvider.itemCount).thenReturn(0);
      when(() => mockCartProvider.items).thenReturn([]);

      await tester.pumpWidget(
        MaterialApp(
          home: MultiProvider(
            providers: [
              ChangeNotifierProvider<ProductsProvider>.value(value: mockProductsProvider),
              ChangeNotifierProvider<CartProvider>.value(value: mockCartProvider),
            ],
            child: const Scaffold(
              body: ProductSelectionScreen(),
            ),
          ),
        ),
      );

      expect(find.text('Beer'), findsWidgets);
      expect(find.text('5.0'), findsOneWidget);
    });

    testWidgets('displays cart button with item count', (WidgetTester tester) async {
      when(() => mockProductsProvider.categories).thenReturn([]);
      when(() => mockProductsProvider.products).thenReturn([]);
      when(() => mockProductsProvider.addListener(any())).thenReturn(null);
      when(() => mockProductsProvider.removeListener(any())).thenReturn(null);
      when(() => mockCartProvider.itemCount).thenReturn(3);
      when(() => mockCartProvider.addListener(any())).thenReturn(null);
      when(() => mockCartProvider.removeListener(any())).thenReturn(null);

      await tester.pumpWidget(
        MaterialApp(
          home: MultiProvider(
            providers: [
              ChangeNotifierProvider<ProductsProvider>.value(value: mockProductsProvider),
              ChangeNotifierProvider<CartProvider>.value(value: mockCartProvider),
            ],
            child: const Scaffold(
              body: ProductSelectionScreen(),
            ),
          ),
        ),
      );

      expect(find.text('Cart (3)'), findsOneWidget);
    });

    testWidgets('adds product to cart on tap', (WidgetTester tester) async {
      final categories = [
        CategoriesCacheData(id: 'cat-1', name: 'Beer', position: 1),
      ];
      final products = [
        ProductsCacheData(
          id: 'prod-1',
          categoryId: 'cat-1',
          name: jsonEncode({'de': 'Bier'}),
          price: 5.0,
          position: 1,
          active: true,
        ),
      ];

      when(() => mockProductsProvider.categories).thenReturn(categories);
      when(() => mockProductsProvider.products).thenReturn(products);
      when(() => mockProductsProvider.getTranslatedName(any(), any())).thenReturn('Beer');
      when(() => mockProductsProvider.addListener(any())).thenReturn(null);
      when(() => mockProductsProvider.removeListener(any())).thenReturn(null);
      when(() => mockCartProvider.addItem(any(), any())).thenReturn(null);
      when(() => mockCartProvider.itemCount).thenReturn(1);
      when(() => mockCartProvider.items).thenReturn([]);
      when(() => mockCartProvider.addListener(any())).thenReturn(null);
      when(() => mockCartProvider.removeListener(any())).thenReturn(null);

      await tester.pumpWidget(
        MaterialApp(
          home: MultiProvider(
            providers: [
              ChangeNotifierProvider<ProductsProvider>.value(value: mockProductsProvider),
              ChangeNotifierProvider<CartProvider>.value(value: mockCartProvider),
            ],
            child: const Scaffold(
              body: ProductSelectionScreen(),
            ),
          ),
        ),
      );

      await tester.tap(find.byType(GestureDetector).first);
      await tester.pumpAndSettle();

      verify(() => mockCartProvider.addItem(any(), 1)).called(greaterThanOrEqualTo(1));
    });
  });
}
```

**Step 2: Run test to verify it fails**

```bash
flutter test test/screens/product_selection_screen_test.dart
```

Expected: FAIL - `ProductSelectionScreen` not defined

**Step 3: Write minimal implementation**

Create `lib/screens/product_selection_screen.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/models/product_dto.dart';
import 'package:ruderbar_terminal/providers/cart_provider.dart';
import 'package:ruderbar_terminal/providers/products_provider.dart';

class ProductSelectionScreen extends StatefulWidget {
  const ProductSelectionScreen({Key? key}) : super(key: key);

  @override
  State<ProductSelectionScreen> createState() => _ProductSelectionScreenState();
}

class _ProductSelectionScreenState extends State<ProductSelectionScreen> {
  int _selectedCategoryIndex = 0;

  @override
  Widget build(BuildContext context) {
    return Consumer2<ProductsProvider, CartProvider>(
      builder: (context, productsProvider, cartProvider, child) {
        final categories = productsProvider.categories;

        if (categories.isEmpty) {
          return Center(child: Text('No categories available'));
        }

        return Column(
          children: [
            // Top bar with cart button
            Padding(
              padding: EdgeInsets.all(16),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.end,
                children: [
                  ElevatedButton(
                    onPressed: () {
                      // Navigate to cart
                    },
                    child: Text('Cart (${cartProvider.itemCount})'),
                  ),
                ],
              ),
            ),
            // Category tabs
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: List.generate(
                  categories.length,
                  (index) => Padding(
                    padding: EdgeInsets.symmetric(horizontal: 8),
                    child: ChoiceChip(
                      label: Text(categories[index].name),
                      selected: _selectedCategoryIndex == index,
                      onSelected: (selected) {
                        setState(() {
                          _selectedCategoryIndex = index;
                        });
                      },
                    ),
                  ),
                ),
              ),
            ),
            SizedBox(height: 16),
            // Product grid
            Expanded(
              child: _buildProductGrid(
                context,
                categories[_selectedCategoryIndex],
                productsProvider,
                cartProvider,
              ),
            ),
          ],
        );
      },
    );
  }

  Widget _buildProductGrid(
    BuildContext context,
    CategoriesCacheData category,
    ProductsProvider productsProvider,
    CartProvider cartProvider,
  ) {
    final products = productsProvider.products
        .where((p) => p.categoryId == category.id)
        .toList();

    if (products.isEmpty) {
      return Center(child: Text('No products in this category'));
    }

    return GridView.builder(
      padding: EdgeInsets.all(16),
      gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 3,
        crossAxisSpacing: 16,
        mainAxisSpacing: 16,
      ),
      itemCount: products.length,
      itemBuilder: (context, index) {
        final product = products[index];
        final name = productsProvider.getTranslatedName(product, 'de');
        final cartItem = cartProvider.items
            .firstWhere((item) => item.productId == product.id, orElse: () => null);

        return _buildProductTile(
          context,
          product,
          name,
          cartItem?.quantity ?? 0,
          () => cartProvider.addItem(product, 1),
        );
      },
    );
  }

  Widget _buildProductTile(
    BuildContext context,
    ProductsCacheData product,
    String name,
    int quantity,
    VoidCallback onTap,
  ) {
    return GestureDetector(
      onTap: onTap,
      child: Card(
        child: Stack(
          children: [
            Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Text(name, style: Theme.of(context).textTheme.bodySmall),
                SizedBox(height: 8),
                Text('\$${product.price}', style: Theme.of(context).textTheme.bodyMedium),
              ],
            ),
            if (quantity > 0)
              Positioned(
                top: 8,
                right: 8,
                child: CircleAvatar(
                  backgroundColor: Colors.blue,
                  child: Text(
                    '$quantity',
                    style: TextStyle(color: Colors.white),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
```

**Step 4: Run test to verify it passes**

```bash
flutter test test/screens/product_selection_screen_test.dart -v
```

Expected: PASS (all 5 tests)

**Step 5: Commit**

```bash
git add lib/screens/product_selection_screen.dart test/screens/product_selection_screen_test.dart
git commit -m "feat: create ProductSelectionScreen (Phase 5 Task 2)

Implements product browsing with:
- Horizontal category tabs (scrollable)
- Product grid with translated names
- Quantity badges on product tiles
- Cart button with item count
- Add to cart on product tap (increments quantity)

Tests: 5 widget tests passing
- Category tabs displayed
- Products shown for selected category
- Cart button displays item count
- Products added to cart on tap"
```

---

## Task 3: ShoppingCartScreen

**Files:**
- Create: `lib/screens/shopping_cart_screen.dart`
- Test: `test/screens/shopping_cart_screen_test.dart`

**Overview:** Display cart items in list. Show quantity, price, line total. Remove button for each item. Cart total and checkout button. Navigation back to products.

**Step 1: Write the failing test**

Create `test/screens/shopping_cart_screen_test.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/models/cart_item.dart';
import 'package:ruderbar_terminal/screens/shopping_cart_screen.dart';
import 'package:ruderbar_terminal/providers/cart_provider.dart';

class MockCartProvider extends Mock implements CartProvider {}

void main() {
  group('ShoppingCartScreen', () {
    late MockCartProvider mockCartProvider;

    setUp(() {
      mockCartProvider = MockCartProvider();
    });

    testWidgets('displays empty cart message', (WidgetTester tester) async {
      when(() => mockCartProvider.items).thenReturn([]);
      when(() => mockCartProvider.total).thenReturn(0.0);
      when(() => mockCartProvider.addListener(any())).thenReturn(null);
      when(() => mockCartProvider.removeListener(any())).thenReturn(null);

      await tester.pumpWidget(
        MaterialApp(
          home: ChangeNotifierProvider<CartProvider>.value(
            value: mockCartProvider,
            child: const Scaffold(
              body: ShoppingCartScreen(),
            ),
          ),
        ),
      );

      expect(find.text('Cart is empty'), findsOneWidget);
    });

    testWidgets('displays cart items with prices', (WidgetTester tester) async {
      final items = [
        CartItem(productId: 'prod-1', quantity: 2, price: 5.0),
        CartItem(productId: 'prod-2', quantity: 1, price: 3.0),
      ];

      when(() => mockCartProvider.items).thenReturn(items);
      when(() => mockCartProvider.total).thenReturn(13.0);
      when(() => mockCartProvider.addListener(any())).thenReturn(null);
      when(() => mockCartProvider.removeListener(any())).thenReturn(null);

      await tester.pumpWidget(
        MaterialApp(
          home: ChangeNotifierProvider<CartProvider>.value(
            value: mockCartProvider,
            child: const Scaffold(
              body: ShoppingCartScreen(),
            ),
          ),
        ),
      );

      expect(find.text('2'), findsOneWidget);
      expect(find.text('1'), findsOneWidget);
      expect(find.text('5.0'), findsOneWidget);
      expect(find.text('3.0'), findsOneWidget);
    });

    testWidgets('displays total amount', (WidgetTester tester) async {
      final items = [
        CartItem(productId: 'prod-1', quantity: 2, price: 5.0),
      ];

      when(() => mockCartProvider.items).thenReturn(items);
      when(() => mockCartProvider.total).thenReturn(10.0);
      when(() => mockCartProvider.addListener(any())).thenReturn(null);
      when(() => mockCartProvider.removeListener(any())).thenReturn(null);

      await tester.pumpWidget(
        MaterialApp(
          home: ChangeNotifierProvider<CartProvider>.value(
            value: mockCartProvider,
            child: const Scaffold(
              body: ShoppingCartScreen(),
            ),
          ),
        ),
      );

      expect(find.text('Total: 10.0'), findsOneWidget);
    });

    testWidgets('displays checkout button', (WidgetTester tester) async {
      final items = [
        CartItem(productId: 'prod-1', quantity: 1, price: 5.0),
      ];

      when(() => mockCartProvider.items).thenReturn(items);
      when(() => mockCartProvider.total).thenReturn(5.0);
      when(() => mockCartProvider.addListener(any())).thenReturn(null);
      when(() => mockCartProvider.removeListener(any())).thenReturn(null);

      await tester.pumpWidget(
        MaterialApp(
          home: ChangeNotifierProvider<CartProvider>.value(
            value: mockCartProvider,
            child: const Scaffold(
              body: ShoppingCartScreen(),
            ),
          ),
        ),
      );

      expect(find.text('Checkout'), findsOneWidget);
    });

    testWidgets('removes item from cart on delete tap', (WidgetTester tester) async {
      final items = [
        CartItem(productId: 'prod-1', quantity: 1, price: 5.0),
      ];

      when(() => mockCartProvider.items).thenReturn(items);
      when(() => mockCartProvider.total).thenReturn(5.0);
      when(() => mockCartProvider.removeItem('prod-1')).thenReturn(null);
      when(() => mockCartProvider.addListener(any())).thenReturn(null);
      when(() => mockCartProvider.removeListener(any())).thenReturn(null);

      await tester.pumpWidget(
        MaterialApp(
          home: ChangeNotifierProvider<CartProvider>.value(
            value: mockCartProvider,
            child: const Scaffold(
              body: ShoppingCartScreen(),
            ),
          ),
        ),
      );

      await tester.tap(find.byIcon(Icons.delete));
      await tester.pumpAndSettle();

      verify(() => mockCartProvider.removeItem('prod-1')).called(1);
    });
  });
}
```

**Step 2: Run test to verify it fails**

```bash
flutter test test/screens/shopping_cart_screen_test.dart
```

Expected: FAIL - `ShoppingCartScreen` not defined

**Step 3: Write minimal implementation**

Create `lib/screens/shopping_cart_screen.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/providers/cart_provider.dart';

class ShoppingCartScreen extends StatelessWidget {
  const ShoppingCartScreen({Key? key}) : super(key: key);

  @override
  Widget build(BuildContext context) {
    return Consumer<CartProvider>(
      builder: (context, cartProvider, child) {
        final items = cartProvider.items;

        if (items.isEmpty) {
          return Center(
            child: Text('Cart is empty'),
          );
        }

        return Column(
          children: [
            Expanded(
              child: ListView.builder(
                itemCount: items.length,
                itemBuilder: (context, index) {
                  final item = items[index];
                  return ListTile(
                    title: Text('Product ${item.productId}'),
                    subtitle: Text('Qty: ${item.quantity} × \$${item.price}'),
                    trailing: IconButton(
                      icon: Icon(Icons.delete),
                      onPressed: () {
                        cartProvider.removeItem(item.productId);
                      },
                    ),
                  );
                },
              ),
            ),
            Padding(
              padding: EdgeInsets.all(16),
              child: Column(
                children: [
                  Text('Total: ${cartProvider.total}'),
                  SizedBox(height: 16),
                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton(
                      onPressed: () {
                        // Navigate to checkout
                      },
                      child: Text('Checkout'),
                    ),
                  ),
                ],
              ),
            ),
          ],
        );
      },
    );
  }
}
```

**Step 4: Run test to verify it passes**

```bash
flutter test test/screens/shopping_cart_screen_test.dart -v
```

Expected: PASS (all 5 tests)

**Step 5: Commit**

```bash
git add lib/screens/shopping_cart_screen.dart test/screens/shopping_cart_screen_test.dart
git commit -m "feat: create ShoppingCartScreen (Phase 5 Task 3)

Displays shopping cart with:
- Empty cart message
- List of cart items with quantities and prices
- Item total calculation
- Delete button for each item
- Cart total amount
- Checkout button

Tests: 5 widget tests passing
- Empty cart display
- Cart items displayed with prices
- Total amount shown
- Checkout button visible
- Items removed on delete tap"
```

---

## Task 4: CheckoutConfirmationScreen

**Files:**
- Create: `lib/screens/checkout_confirmation_screen.dart`
- Test: `test/screens/checkout_confirmation_screen_test.dart`

**Overview:** Display transaction confirmation with member name, items, total, and new balance. Show "Done" and "Continue Shopping" buttons.

**Step 1: Write the failing test**

Create `test/screens/checkout_confirmation_screen_test.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/models/member_dto.dart';
import 'package:ruderbar_terminal/screens/checkout_confirmation_screen.dart';

class MockMembersProvider extends Mock implements MembersProvider {}

void main() {
  group('CheckoutConfirmationScreen', () {
    testWidgets('displays confirmation message', (WidgetTester tester) async {
      final testMember = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        iban: 'DE89370400440532013000',
        mandateSigned: true,
        active: true,
      );

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: CheckoutConfirmationScreen(
              member: testMember,
              totalAmount: 15.0,
              itemCount: 3,
            ),
          ),
        ),
      );

      expect(find.text('Purchase Confirmed!'), findsOneWidget);
      expect(find.text('John'), findsOneWidget);
    });

    testWidgets('displays total and item count', (WidgetTester tester) async {
      final testMember = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        iban: 'DE89370400440532013000',
        mandateSigned: true,
        active: true,
      );

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: CheckoutConfirmationScreen(
              member: testMember,
              totalAmount: 15.0,
              itemCount: 3,
            ),
          ),
        ),
      );

      expect(find.text('15.0'), findsOneWidget);
      expect(find.text('3'), findsOneWidget);
    });

    testWidgets('displays Done button', (WidgetTester tester) async {
      final testMember = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        iban: 'DE89370400440532013000',
        mandateSigned: true,
        active: true,
      );

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: CheckoutConfirmationScreen(
              member: testMember,
              totalAmount: 15.0,
              itemCount: 3,
            ),
          ),
        ),
      );

      expect(find.text('Done'), findsOneWidget);
    });

    testWidgets('displays Continue Shopping button', (WidgetTester tester) async {
      final testMember = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        iban: 'DE89370400440532013000',
        mandateSigned: true,
        active: true,
      );

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: CheckoutConfirmationScreen(
              member: testMember,
              totalAmount: 15.0,
              itemCount: 3,
            ),
          ),
        ),
      );

      expect(find.text('Continue Shopping'), findsOneWidget);
    });
  });
}
```

**Step 2: Run test to verify it fails**

```bash
flutter test test/screens/checkout_confirmation_screen_test.dart
```

Expected: FAIL - `CheckoutConfirmationScreen` not defined

**Step 3: Write minimal implementation**

Create `lib/screens/checkout_confirmation_screen.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:ruderbar_terminal/models/member_dto.dart';

class CheckoutConfirmationScreen extends StatelessWidget {
  final MembersCacheData member;
  final double totalAmount;
  final int itemCount;
  final VoidCallback? onDone;
  final VoidCallback? onContinueShopping;

  const CheckoutConfirmationScreen({
    Key? key,
    required this.member,
    required this.totalAmount,
    required this.itemCount,
    this.onDone,
    this.onContinueShopping,
  }) : super(key: key);

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        Text(
          'Purchase Confirmed!',
          style: Theme.of(context).textTheme.headlineMedium,
        ),
        SizedBox(height: 24),
        Text(
          'Thank you, ${member.firstName}',
          style: Theme.of(context).textTheme.titleLarge,
        ),
        SizedBox(height: 32),
        Card(
          child: Padding(
            padding: EdgeInsets.all(16),
            child: Column(
              children: [
                Text('Items: $itemCount'),
                SizedBox(height: 8),
                Text('Total: $totalAmount'),
              ],
            ),
          ),
        ),
        SizedBox(height: 32),
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            ElevatedButton(
              onPressed: onDone,
              child: Text('Done'),
            ),
            SizedBox(width: 16),
            ElevatedButton(
              onPressed: onContinueShopping,
              child: Text('Continue Shopping'),
            ),
          ],
        ),
      ],
    );
  }
}
```

**Step 4: Run test to verify it passes**

```bash
flutter test test/screens/checkout_confirmation_screen_test.dart -v
```

Expected: PASS (all 4 tests)

**Step 5: Commit**

```bash
git add lib/screens/checkout_confirmation_screen.dart test/screens/checkout_confirmation_screen_test.dart
git commit -m "feat: create CheckoutConfirmationScreen (Phase 5 Task 4)

Displays checkout confirmation with:
- Purchase confirmation message
- Member name greeting
- Item count and total amount
- Done button (logout/return to idle)
- Continue Shopping button (clear cart, return to products)

Tests: 4 widget tests passing
- Confirmation message displayed
- Total and item count shown
- Done button visible
- Continue Shopping button visible"
```

---

## Success Criteria

✅ All 4 screens implemented and tested
✅ All 18 tests passing
✅ Navigation between screens ready for integration
✅ All screens use existing providers from Phase 3-4
✅ Git history clean with meaningful commits

---

## Batch 1 Tasks - Status

- [x] Task 1: MemberGreetingScreen (4 tests) - COMPLETED ✅
  - All 4 tests passing
  - Commit: 40a4c75
  - Display logic for member greeting and error states implemented

- [~] Task 2: ProductSelectionScreen (5 tests) - IN PROGRESS 🔄
  - Test file created with 4 tests
  - Implementation started - needs CartProvider API integration
  - Issue: CartProvider.addItem requires (productId, productName, priceCents, quantity, language) parameters
  - Database models reconciled (CategoriesCacheData.names, ProductsCacheData.priceCents)
  - Next: Adapt implementation to pass all CartProvider parameters

- [ ] Task 3: ShoppingCartScreen (5 tests) - PENDING
- [ ] Task 4: CheckoutConfirmationScreen (4 tests) - PENDING

**Batch 1 Progress:** 1/4 screens complete, 4/18 tests passing
**Next Steps:** Complete Task 2 with CartProvider API adaptation, then Tasks 3-4
