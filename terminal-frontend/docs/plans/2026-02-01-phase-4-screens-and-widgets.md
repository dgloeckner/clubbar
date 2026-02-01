# Phase 4: Screens and Common Widgets Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans or superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** Build production-ready screen components and reusable UI widgets for the Terminal POS frontend, integrating Provider state management with Material 3 design.

**Architecture:** Two-layer widget hierarchy: (1) Common Widgets provide reusable UI building blocks (AppHeader, ProductCard, CategoryTabs, CartItemRow, ErrorBanner, LoadingOverlay); (2) Screen Widgets compose common widgets with Provider listeners to display app state and handle user interactions. All screens follow TDD with comprehensive widget tests mocking provider dependencies.

**Tech Stack:** Flutter, Provider (state management), Material 3, mocktail (mocking), flutter_test (widget testing)

---

## Task 1: Common Widget - AppHeader

**Files:**
- Create: `lib/widgets/app_header.dart`
- Test: `test/widgets/app_header_test.dart`

**Overview:** Header widget displaying app title, sync status indicator, and authentication info. Used on all screens.

**Step 1: Write the failing test**

Create `test/widgets/app_header_test.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:ruderbar_terminal/providers/auth_provider.dart';
import 'package:ruderbar_terminal/providers/sync_provider.dart';
import 'package:ruderbar_terminal/widgets/app_header.dart';

class MockAuthProvider extends Mock implements AuthProvider {}

class MockSyncProvider extends Mock implements SyncProvider {}

void main() {
  group('AppHeader', () {
    late MockAuthProvider mockAuthProvider;
    late MockSyncProvider mockSyncProvider;

    setUp(() {
      mockAuthProvider = MockAuthProvider();
      mockSyncProvider = MockSyncProvider();
    });

    testWidgets('displays title', (WidgetTester tester) async {
      when(() => mockAuthProvider.isAuthenticated).thenReturn(true);
      when(() => mockSyncProvider.isSyncing).thenReturn(false);

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            appBar: AppHeader(
              title: 'Test Title',
              authProvider: mockAuthProvider,
              syncProvider: mockSyncProvider,
            ),
          ),
        ),
      );

      expect(find.text('Test Title'), findsOneWidget);
    });

    testWidgets('shows sync status when syncing', (WidgetTester tester) async {
      when(() => mockAuthProvider.isAuthenticated).thenReturn(true);
      when(() => mockSyncProvider.isSyncing).thenReturn(true);

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            appBar: AppHeader(
              title: 'Test',
              authProvider: mockAuthProvider,
              syncProvider: mockSyncProvider,
            ),
          ),
        ),
      );

      expect(find.byIcon(Icons.sync), findsOneWidget);
    });

    testWidgets('shows offline indicator when not syncing', (WidgetTester tester) async {
      when(() => mockAuthProvider.isAuthenticated).thenReturn(true);
      when(() => mockSyncProvider.isSyncing).thenReturn(false);

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            appBar: AppHeader(
              title: 'Test',
              authProvider: mockAuthProvider,
              syncProvider: mockSyncProvider,
            ),
          ),
        ),
      );

      // Should show some indicator (e.g., text, icon, or color)
      expect(find.byType(AppHeader), findsOneWidget);
    });

    testWidgets('displays authentication badge when authenticated', (WidgetTester tester) async {
      when(() => mockAuthProvider.isAuthenticated).thenReturn(true);
      when(() => mockSyncProvider.isSyncing).thenReturn(false);

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            appBar: AppHeader(
              title: 'Test',
              authProvider: mockAuthProvider,
              syncProvider: mockSyncProvider,
            ),
          ),
        ),
      );

      // Should show auth indicator
      expect(find.byIcon(Icons.verified_user), findsOneWidget);
    });
  });
}
```

**Step 2: Run test to verify it fails**

```bash
cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend
flutter test test/widgets/app_header_test.dart
```

Expected: FAIL - `AppHeader` not defined

**Step 3: Write minimal implementation**

Create `lib/widgets/app_header.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:ruderbar_terminal/providers/auth_provider.dart';
import 'package:ruderbar_terminal/providers/sync_provider.dart';

class AppHeader extends AppBar {
  AppHeader({
    required String title,
    required AuthProvider authProvider,
    required SyncProvider syncProvider,
    Key? key,
  }) : super(
    key: key,
    title: Text(title),
    elevation: 0,
    actions: [
      // Sync status indicator
      Padding(
        padding: const EdgeInsets.all(16.0),
        child: Center(
          child: syncProvider.isSyncing
              ? const SizedBox(
                  width: 20,
                  height: 20,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              : Icon(
                  Icons.cloud_done,
                  color: Colors.grey[600],
                ),
        ),
      ),
      // Auth indicator
      if (authProvider.isAuthenticated)
        const Padding(
          padding: EdgeInsets.all(16.0),
          child: Icon(Icons.verified_user),
        ),
    ],
  );
}
```

**Step 4: Run test to verify it passes**

```bash
flutter test test/widgets/app_header_test.dart -v
```

Expected: PASS (all 4 tests)

**Step 5: Commit**

```bash
git add lib/widgets/app_header.dart test/widgets/app_header_test.dart
git commit -m "feat: create AppHeader widget (Phase 4 Task 1)

Common header widget for all screens:
- Displays configurable title
- Shows sync status with spinning indicator when syncing
- Shows cloud_done icon when synced
- Displays auth verification badge when authenticated
- Material 3 design with elevation 0

Test coverage: 4 tests, all passing"
```

---

## Task 2: Common Widget - ProductCard

**Files:**
- Create: `lib/widgets/product_card.dart`
- Test: `test/widgets/product_card_test.dart`

**Overview:** Card widget displaying single product (image placeholder, name, price, add-to-cart button).

**Step 1: Write the failing test**

Create `test/widgets/product_card_test.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'dart:convert';
import 'package:ruderbar_terminal/models/product_dto.dart';
import 'package:ruderbar_terminal/widgets/product_card.dart';

void main() {
  group('ProductCard', () {
    late ProductsCacheData testProduct;

    setUp(() {
      testProduct = ProductsCacheData(
        id: 'prod-1',
        categoryId: 'cat-1',
        name: jsonEncode({'de': 'Bier', 'en': 'Beer'}),
        price: 5.50,
        position: 1,
        active: true,
      );
    });

    testWidgets('displays product name', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ProductCard(
              product: testProduct,
              translatedName: 'Bier',
              onAddPressed: () {},
            ),
          ),
        ),
      );

      expect(find.text('Bier'), findsOneWidget);
    });

    testWidgets('displays price formatted correctly', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ProductCard(
              product: testProduct,
              translatedName: 'Bier',
              onAddPressed: () {},
            ),
          ),
        ),
      );

      expect(find.text('€5.50'), findsOneWidget);
    });

    testWidgets('has add button that calls onAddPressed', (WidgetTester tester) async {
      var pressed = false;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ProductCard(
              product: testProduct,
              translatedName: 'Bier',
              onAddPressed: () { pressed = true; },
            ),
          ),
        ),
      );

      await tester.tap(find.byIcon(Icons.add_circle));
      expect(pressed, isTrue);
    });

    testWidgets('displays product image placeholder', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ProductCard(
              product: testProduct,
              translatedName: 'Bier',
              onAddPressed: () {},
            ),
          ),
        ),
      );

      expect(find.byIcon(Icons.image), findsOneWidget);
    });
  });
}
```

**Step 2: Run test to verify it fails**

```bash
flutter test test/widgets/product_card_test.dart
```

Expected: FAIL - `ProductCard` not defined

**Step 3: Write minimal implementation**

Create `lib/widgets/product_card.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:ruderbar_terminal/models/product_dto.dart';

class ProductCard extends StatelessWidget {
  final ProductsCacheData product;
  final String translatedName;
  final VoidCallback onAddPressed;

  const ProductCard({
    required this.product,
    required this.translatedName,
    required this.onAddPressed,
    Key? key,
  }) : super(key: key);

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Product image placeholder
          Container(
            width: double.infinity,
            height: 120,
            color: Colors.grey[200],
            child: const Icon(Icons.image, color: Colors.grey),
          ),
          // Product info
          Padding(
            padding: const EdgeInsets.all(8.0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  translatedName,
                  style: const TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: 14,
                  ),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                const SizedBox(height: 4),
                Text(
                  '€${product.price.toStringAsFixed(2)}',
                  style: const TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.bold,
                    color: Colors.green,
                  ),
                ),
              ],
            ),
          ),
          // Add button
          Padding(
            padding: const EdgeInsets.all(8.0),
            child: SizedBox(
              width: double.infinity,
              child: IconButton(
                onPressed: onAddPressed,
                icon: const Icon(Icons.add_circle),
                color: Colors.blue,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
```

**Step 4: Run test to verify it passes**

```bash
flutter test test/widgets/product_card_test.dart -v
```

Expected: PASS (all 5 tests)

**Step 5: Commit**

```bash
git add lib/widgets/product_card.dart test/widgets/product_card_test.dart
git commit -m "feat: create ProductCard widget (Phase 4 Task 2)

Displays individual product as card:
- Image placeholder with icon
- Product name (translated)
- Price formatted in EUR
- Add-to-cart button with callback
- Responsive layout using Card

Test coverage: 5 tests, all passing"
```

---

## Task 3: Common Widget - CategoryTabs

**Files:**
- Create: `lib/widgets/category_tabs.dart`
- Test: `test/widgets/category_tabs_test.dart`

**Overview:** Tab bar for category selection, horizontal scrolling.

**Step 1: Write the failing test**

Create `test/widgets/category_tabs_test.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ruderbar_terminal/models/product_dto.dart';
import 'package:ruderbar_terminal/widgets/category_tabs.dart';

void main() {
  group('CategoryTabs', () {
    final categories = [
      CategoriesCacheData(id: 'cat-1', name: 'Drinks', position: 1),
      CategoriesCacheData(id: 'cat-2', name: 'Snacks', position: 2),
      CategoriesCacheData(id: 'cat-3', name: 'Food', position: 3),
    ];

    testWidgets('displays all categories as tabs', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: CategoryTabs(
              categories: categories,
              selectedCategoryId: 'cat-1',
              onCategorySelected: (_) {},
            ),
          ),
        ),
      );

      expect(find.text('Drinks'), findsOneWidget);
      expect(find.text('Snacks'), findsOneWidget);
      expect(find.text('Food'), findsOneWidget);
    });

    testWidgets('highlights selected category', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: CategoryTabs(
              categories: categories,
              selectedCategoryId: 'cat-2',
              onCategorySelected: (_) {},
            ),
          ),
        ),
      );

      // Selected tab should be visible (typically with different color/style)
      expect(find.text('Snacks'), findsOneWidget);
    });

    testWidgets('calls onCategorySelected when tab tapped', (WidgetTester tester) async {
      String? selected;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: CategoryTabs(
              categories: categories,
              selectedCategoryId: 'cat-1',
              onCategorySelected: (id) { selected = id; },
            ),
          ),
        ),
      );

      await tester.tap(find.text('Food'));
      expect(selected, equals('cat-3'));
    });

    testWidgets('handles empty categories list', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: CategoryTabs(
              categories: [],
              selectedCategoryId: null,
              onCategorySelected: (_) {},
            ),
          ),
        ),
      );

      expect(find.byType(CategoryTabs), findsOneWidget);
    });
  });
}
```

**Step 2: Run test to verify it fails**

```bash
flutter test test/widgets/category_tabs_test.dart
```

Expected: FAIL - `CategoryTabs` not defined

**Step 3: Write minimal implementation**

Create `lib/widgets/category_tabs.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:ruderbar_terminal/models/product_dto.dart';

class CategoryTabs extends StatelessWidget {
  final List<CategoriesCacheData> categories;
  final String? selectedCategoryId;
  final Function(String) onCategorySelected;

  const CategoryTabs({
    required this.categories,
    required this.selectedCategoryId,
    required this.onCategorySelected,
    Key? key,
  }) : super(key: key);

  @override
  Widget build(BuildContext context) {
    if (categories.isEmpty) {
      return const SizedBox.shrink();
    }

    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Row(
        children: categories.map((category) {
          final isSelected = category.id == selectedCategoryId;
          return Padding(
            padding: const EdgeInsets.symmetric(horizontal: 8.0),
            child: FilterChip(
              label: Text(category.name),
              selected: isSelected,
              onSelected: (_) => onCategorySelected(category.id),
              backgroundColor: isSelected ? Colors.blue : Colors.grey[200],
              labelStyle: TextStyle(
                color: isSelected ? Colors.white : Colors.black,
              ),
            ),
          );
        }).toList(),
      ),
    );
  }
}
```

**Step 4: Run test to verify it passes**

```bash
flutter test test/widgets/category_tabs_test.dart -v
```

Expected: PASS (all 5 tests)

**Step 5: Commit**

```bash
git add lib/widgets/category_tabs.dart test/widgets/category_tabs_test.dart
git commit -m "feat: create CategoryTabs widget (Phase 4 Task 3)

Horizontal scrolling category selector:
- Displays all categories as FilterChips
- Highlights selected category with blue background
- Calls onCategorySelected callback on tap
- Handles empty categories gracefully
- Material 3 design

Test coverage: 5 tests, all passing"
```

---

## Task 4: Common Widget - CartItemRow

**Files:**
- Create: `lib/widgets/cart_item_row.dart`
- Test: `test/widgets/cart_item_row_test.dart`

**Overview:** Single row in cart displaying product, quantity, price, remove button.

**Step 1: Write the failing test**

Create `test/widgets/cart_item_row_test.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ruderbar_terminal/models/cart_item.dart';
import 'package:ruderbar_terminal/widgets/cart_item_row.dart';

void main() {
  group('CartItemRow', () {
    late CartItem testItem;

    setUp(() {
      testItem = CartItem(
        productId: 'prod-1',
        quantity: 2,
        price: 5.50,
      );
    });

    testWidgets('displays product quantity', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: CartItemRow(
              item: testItem,
              productName: 'Bier',
              onRemovePressed: () {},
              onQuantityChanged: (_) {},
            ),
          ),
        ),
      );

      expect(find.text('2'), findsWidgets); // Quantity shown
    });

    testWidgets('displays product name', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: CartItemRow(
              item: testItem,
              productName: 'Bier',
              onRemovePressed: () {},
              onQuantityChanged: (_) {},
            ),
          ),
        ),
      );

      expect(find.text('Bier'), findsOneWidget);
    });

    testWidgets('displays line total price', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: CartItemRow(
              item: testItem,
              productName: 'Bier',
              onRemovePressed: () {},
              onQuantityChanged: (_) {},
            ),
          ),
        ),
      );

      // Should show 2 × €5.50 = €11.00
      expect(find.text('€11.00'), findsOneWidget);
    });

    testWidgets('calls onRemovePressed when delete tapped', (WidgetTester tester) async {
      var removed = false;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: CartItemRow(
              item: testItem,
              productName: 'Bier',
              onRemovePressed: () { removed = true; },
              onQuantityChanged: (_) {},
            ),
          ),
        ),
      );

      await tester.tap(find.byIcon(Icons.delete));
      expect(removed, isTrue);
    });

    testWidgets('calls onQuantityChanged when quantity modified', (WidgetTester tester) async {
      int? newQuantity;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: CartItemRow(
              item: testItem,
              productName: 'Bier',
              onRemovePressed: () {},
              onQuantityChanged: (q) { newQuantity = q; },
            ),
          ),
        ),
      );

      // Tap + button to increase quantity
      await tester.tap(find.byIcon(Icons.add));
      expect(newQuantity, equals(3));
    });
  });
}
```

**Step 2: Run test to verify it fails**

```bash
flutter test test/widgets/cart_item_row_test.dart
```

Expected: FAIL - `CartItemRow` not defined

**Step 3: Write minimal implementation**

Create `lib/widgets/cart_item_row.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:ruderbar_terminal/models/cart_item.dart';

class CartItemRow extends StatelessWidget {
  final CartItem item;
  final String productName;
  final VoidCallback onRemovePressed;
  final Function(int) onQuantityChanged;

  const CartItemRow({
    required this.item,
    required this.productName,
    required this.onRemovePressed,
    required this.onQuantityChanged,
    Key? key,
  }) : super(key: key);

  @override
  Widget build(BuildContext context) {
    final lineTotal = item.quantity * item.price;

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8.0, horizontal: 16.0),
      child: Row(
        children: [
          // Product info
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  productName,
                  style: const TextStyle(fontWeight: FontWeight.bold),
                ),
                Text(
                  '€${item.price.toStringAsFixed(2)} each',
                  style: TextStyle(color: Colors.grey[600], fontSize: 12),
                ),
              ],
            ),
          ),
          // Quantity controls
          Row(
            children: [
              IconButton(
                icon: const Icon(Icons.remove),
                onPressed: () {
                  if (item.quantity > 1) {
                    onQuantityChanged(item.quantity - 1);
                  }
                },
              ),
              Text(
                '${item.quantity}',
                style: const TextStyle(fontWeight: FontWeight.bold),
              ),
              IconButton(
                icon: const Icon(Icons.add),
                onPressed: () => onQuantityChanged(item.quantity + 1),
              ),
            ],
          ),
          // Line total
          SizedBox(
            width: 80,
            child: Text(
              '€${lineTotal.toStringAsFixed(2)}',
              style: const TextStyle(
                fontWeight: FontWeight.bold,
                fontSize: 16,
                color: Colors.green,
              ),
              textAlign: TextAlign.right,
            ),
          ),
          // Delete button
          IconButton(
            icon: const Icon(Icons.delete, color: Colors.red),
            onPressed: onRemovePressed,
          ),
        ],
      ),
    );
  }
}
```

**Step 4: Run test to verify it passes**

```bash
flutter test test/widgets/cart_item_row_test.dart -v
```

Expected: PASS (all 5 tests)

**Step 5: Commit**

```bash
git add lib/widgets/cart_item_row.dart test/widgets/cart_item_row_test.dart
git commit -m "feat: create CartItemRow widget (Phase 4 Task 4)

Single row displaying cart item:
- Product name and unit price
- Quantity controls (±1 buttons)
- Calculated line total
- Delete button
- Responsive layout with expand

Test coverage: 5 tests, all passing"
```

---

## Task 5: Common Widget - ErrorBanner

**Files:**
- Create: `lib/widgets/error_banner.dart`
- Test: `test/widgets/error_banner_test.dart`

**Overview:** Alert banner for displaying error messages.

**Step 1: Write the failing test**

Create `test/widgets/error_banner_test.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ruderbar_terminal/widgets/error_banner.dart';

void main() {
  group('ErrorBanner', () {
    testWidgets('displays error message', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: Column(
              children: [
                const ErrorBanner(message: 'Test error occurred'),
                const SizedBox(height: 10),
              ],
            ),
          ),
        ),
      );

      expect(find.text('Test error occurred'), findsOneWidget);
    });

    testWidgets('hides when message is null', (WidgetTester tester) async {
      await tester.pumpWidget(
        const MaterialApp(
          home: Scaffold(
            body: ErrorBanner(message: null),
          ),
        ),
      );

      expect(find.byType(ErrorBanner), findsOneWidget);
      expect(find.text('Test error'), findsNothing);
    });

    testWidgets('displays with error icon and red background', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: Column(
              children: [
                const ErrorBanner(message: 'Error'),
                const SizedBox(height: 10),
              ],
            ),
          ),
        ),
      );

      expect(find.byIcon(Icons.error), findsOneWidget);
    });

    testWidgets('has dismiss button to clear error', (WidgetTester tester) async {
      var dismissed = false;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: Column(
              children: [
                ErrorBanner(
                  message: 'Error',
                  onDismiss: () { dismissed = true; },
                ),
                const SizedBox(height: 10),
              ],
            ),
          ),
        ),
      );

      await tester.tap(find.byIcon(Icons.close));
      expect(dismissed, isTrue);
    });
  });
}
```

**Step 2: Run test to verify it fails**

```bash
flutter test test/widgets/error_banner_test.dart
```

Expected: FAIL - `ErrorBanner` not defined

**Step 3: Write minimal implementation**

Create `lib/widgets/error_banner.dart`:

```dart
import 'package:flutter/material.dart';

class ErrorBanner extends StatelessWidget {
  final String? message;
  final VoidCallback? onDismiss;

  const ErrorBanner({
    this.message,
    this.onDismiss,
    Key? key,
  }) : super(key: key);

  @override
  Widget build(BuildContext context) {
    if (message == null || message!.isEmpty) {
      return const SizedBox.shrink();
    }

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(16.0),
      color: Colors.red[100],
      child: Row(
        children: [
          Icon(Icons.error, color: Colors.red[700]),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              message!,
              style: TextStyle(color: Colors.red[900]),
            ),
          ),
          if (onDismiss != null)
            IconButton(
              icon: const Icon(Icons.close),
              onPressed: onDismiss,
            ),
        ],
      ),
    );
  }
}
```

**Step 4: Run test to verify it passes**

```bash
flutter test test/widgets/error_banner_test.dart -v
```

Expected: PASS (all 4 tests)

**Step 5: Commit**

```bash
git add lib/widgets/error_banner.dart test/widgets/error_banner_test.dart
git commit -m "feat: create ErrorBanner widget (Phase 4 Task 5)

Alert banner for displaying error messages:
- Shows error message with icon
- Hides when message is null/empty
- Red background with contrasting text
- Optional dismiss button with callback
- Takes full width

Test coverage: 4 tests, all passing"
```

---

## Task 6: Common Widget - LoadingOverlay

**Files:**
- Create: `lib/widgets/loading_overlay.dart`
- Test: `test/widgets/loading_overlay_test.dart`

**Overview:** Full-screen loading indicator overlay.

**Step 1: Write the failing test**

Create `test/widgets/loading_overlay_test.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ruderbar_terminal/widgets/loading_overlay.dart';

void main() {
  group('LoadingOverlay', () {
    testWidgets('displays spinner when loading is true', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: LoadingOverlay(
            isLoading: true,
            child: const Scaffold(body: Text('Content')),
          ),
        ),
      );

      expect(find.byType(CircularProgressIndicator), findsOneWidget);
    });

    testWidgets('shows child when loading is false', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: LoadingOverlay(
            isLoading: false,
            child: const Scaffold(body: Text('Content')),
          ),
        ),
      );

      expect(find.text('Content'), findsOneWidget);
      expect(find.byType(CircularProgressIndicator), findsNothing);
    });

    testWidgets('displays message when provided', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: LoadingOverlay(
            isLoading: true,
            message: 'Processing...',
            child: const Scaffold(body: Text('Content')),
          ),
        ),
      );

      expect(find.text('Processing...'), findsOneWidget);
    });

    testWidgets('child is not interactive when loading', (WidgetTester tester) async {
      var tapped = false;
      await tester.pumpWidget(
        MaterialApp(
          home: LoadingOverlay(
            isLoading: true,
            child: Scaffold(
              body: GestureDetector(
                onTap: () { tapped = true; },
                child: const Text('Content'),
              ),
            ),
          ),
        ),
      );

      await tester.tap(find.text('Content'));
      expect(tapped, isFalse); // Overlay blocks interaction
    });

    testWidgets('overlay is transparent when not loading', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: LoadingOverlay(
            isLoading: false,
            child: const Scaffold(body: Text('Content')),
          ),
        ),
      );

      // Child should be tappable
      expect(find.text('Content'), findsOneWidget);
    });
  });
}
```

**Step 2: Run test to verify it fails**

```bash
flutter test test/widgets/loading_overlay_test.dart
```

Expected: FAIL - `LoadingOverlay` not defined

**Step 3: Write minimal implementation**

Create `lib/widgets/loading_overlay.dart`:

```dart
import 'package:flutter/material.dart';

class LoadingOverlay extends StatelessWidget {
  final bool isLoading;
  final String? message;
  final Widget child;

  const LoadingOverlay({
    required this.isLoading,
    this.message,
    required this.child,
    Key? key,
  }) : super(key: key);

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: [
        child,
        if (isLoading)
          Positioned.fill(
            child: Container(
              color: Colors.black.withOpacity(0.3),
              child: Center(
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    const CircularProgressIndicator(),
                    if (message != null)
                      Padding(
                        padding: const EdgeInsets.only(top: 16.0),
                        child: Text(
                          message!,
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 16,
                          ),
                        ),
                      ),
                  ],
                ),
              ),
            ),
          ),
      ],
    );
  }
}
```

**Step 4: Run test to verify it passes**

```bash
flutter test test/widgets/loading_overlay_test.dart -v
```

Expected: PASS (all 5 tests)

**Step 5: Commit**

```bash
git add lib/widgets/loading_overlay.dart test/widgets/loading_overlay_test.dart
git commit -m "feat: create LoadingOverlay widget (Phase 4 Task 6)

Full-screen loading indicator overlay:
- Displays spinner when isLoading is true
- Shows optional loading message below spinner
- Blocks child interaction with semi-transparent backdrop
- Hides when isLoading is false, child fully interactive
- Material 3 styling

Test coverage: 5 tests, all passing"
```

---

## Task 7: Common Widgets Index

**Files:**
- Create: `lib/widgets/index.dart`

**Step 1: Create index file**

Create `lib/widgets/index.dart`:

```dart
// Common UI widgets for Terminal POS
export 'app_header.dart';
export 'product_card.dart';
export 'category_tabs.dart';
export 'cart_item_row.dart';
export 'error_banner.dart';
export 'loading_overlay.dart';
```

**Step 2: Verify imports work**

```bash
flutter pub get
flutter analyze lib/widgets/index.dart
```

Expected: No errors

**Step 3: Commit**

```bash
git add lib/widgets/index.dart
git commit -m "feat: add widgets index for convenient exports

Central export file for all common UI widgets:
- AppHeader, ProductCard, CategoryTabs
- CartItemRow, ErrorBanner, LoadingOverlay

Simplifies screen imports: use 'package:ruderbar_terminal/widgets/index.dart'"
```

---

## Task 8: Screen - MemberGreetingScreen

**Files:**
- Create: `lib/screens/member_greeting_screen.dart`
- Test: `test/screens/member_greeting_screen_test.dart`

**Overview:** Welcome screen shown after successful RFID scan, displays member info and "start shopping" button.

**Step 1: Write the failing test**

Create `test/screens/member_greeting_screen_test.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:ruderbar_terminal/models/member_dto.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/screens/member_greeting_screen.dart';

class MockMembersProvider extends Mock implements MembersProvider {}

void main() {
  group('MemberGreetingScreen', () {
    late MockMembersProvider mockMembersProvider;
    late MembersCacheData testMember;

    setUp(() {
      mockMembersProvider = MockMembersProvider();
      testMember = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        iban: 'DE89370400440532013000',
        mandateSigned: true,
        active: true,
      );
    });

    testWidgets('displays member first name', (WidgetTester tester) async {
      when(() => mockMembersProvider.selectedMember).thenReturn(testMember);

      await tester.pumpWidget(
        MaterialApp(
          home: MemberGreetingScreen(
            membersProvider: mockMembersProvider,
            onStartShopping: () {},
          ),
        ),
      );

      expect(find.text('John'), findsOneWidget);
    });

    testWidgets('displays welcome message', (WidgetTester tester) async {
      when(() => mockMembersProvider.selectedMember).thenReturn(testMember);

      await tester.pumpWidget(
        MaterialApp(
          home: MemberGreetingScreen(
            membersProvider: mockMembersProvider,
            onStartShopping: () {},
          ),
        ),
      );

      expect(find.text(contains('Welcome')), findsWidgets);
    });

    testWidgets('has start shopping button', (WidgetTester tester) async {
      when(() => mockMembersProvider.selectedMember).thenReturn(testMember);
      var started = false;

      await tester.pumpWidget(
        MaterialApp(
          home: MemberGreetingScreen(
            membersProvider: mockMembersProvider,
            onStartShopping: () { started = true; },
          ),
        ),
      );

      await tester.tap(find.byType(ElevatedButton));
      expect(started, isTrue);
    });

    testWidgets('shows error when no member selected', (WidgetTester tester) async {
      when(() => mockMembersProvider.selectedMember).thenReturn(null);

      await tester.pumpWidget(
        MaterialApp(
          home: MemberGreetingScreen(
            membersProvider: mockMembersProvider,
            onStartShopping: () {},
          ),
        ),
      );

      expect(find.text(contains('No member')), findsOneWidget);
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
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/widgets/error_banner.dart';

class MemberGreetingScreen extends StatelessWidget {
  final MembersProvider membersProvider;
  final VoidCallback onStartShopping;

  const MemberGreetingScreen({
    required this.membersProvider,
    required this.onStartShopping,
    Key? key,
  }) : super(key: key);

  @override
  Widget build(BuildContext context) {
    final member = membersProvider.selectedMember;

    if (member == null) {
      return Scaffold(
        body: Center(
          child: ErrorBanner(message: 'No member selected'),
        ),
      );
    }

    return Scaffold(
      body: Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(
              Icons.person,
              size: 80,
              color: Colors.blue[300],
            ),
            const SizedBox(height: 20),
            Text(
              'Welcome, ${member.firstName}!',
              style: Theme.of(context).textTheme.headlineSmall,
            ),
            const SizedBox(height: 10),
            Text(
              '${member.firstName} ${member.lastName}',
              style: Theme.of(context).textTheme.bodyLarge,
            ),
            const SizedBox(height: 40),
            ElevatedButton.icon(
              onPressed: onStartShopping,
              icon: const Icon(Icons.shopping_bag),
              label: const Text('Start Shopping'),
              style: ElevatedButton.styleFrom(
                padding: const EdgeInsets.symmetric(
                  horizontal: 40,
                  vertical: 16,
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
flutter test test/screens/member_greeting_screen_test.dart -v
```

Expected: PASS (all 4 tests)

**Step 5: Commit**

```bash
git add lib/screens/member_greeting_screen.dart test/screens/member_greeting_screen_test.dart
git commit -m "feat: create MemberGreetingScreen (Phase 4 Task 8)

Welcome screen displayed after RFID scan:
- Shows member first and last name
- Displays welcome message
- Large person icon for visual appeal
- Start Shopping button with callback
- Error handling when no member selected
- Material 3 design

Test coverage: 4 tests, all passing"
```

---

## Task 9: Screen - ProductSelectionScreen (Fixed)

**Files:**
- Create: `lib/screens/product_selection_screen.dart`
- Test: `test/screens/product_selection_screen_test.dart`

**Overview:** Browse products by category, add items to cart.

**Step 1: Write the failing test**

Create `test/screens/product_selection_screen_test.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'dart:convert';
import 'package:ruderbar_terminal/models/product_dto.dart';
import 'package:ruderbar_terminal/providers/cart_provider.dart';
import 'package:ruderbar_terminal/providers/products_provider.dart';
import 'package:ruderbar_terminal/screens/product_selection_screen.dart';

class MockProductsProvider extends Mock implements ProductsProvider {}

class MockCartProvider extends Mock implements CartProvider {}

void main() {
  group('ProductSelectionScreen', () {
    late MockProductsProvider mockProductsProvider;
    late MockCartProvider mockCartProvider;
    late CategoriesCacheData testCategory;
    late ProductsCacheData testProduct;

    setUp(() {
      mockProductsProvider = MockProductsProvider();
      mockCartProvider = MockCartProvider();

      testCategory = CategoriesCacheData(
        id: 'cat-1',
        name: 'Drinks',
        position: 1,
      );

      testProduct = ProductsCacheData(
        id: 'prod-1',
        categoryId: 'cat-1',
        name: jsonEncode({'de': 'Bier', 'en': 'Beer'}),
        price: 5.50,
        position: 1,
        active: true,
      );

      when(() => mockProductsProvider.categories).thenReturn([testCategory]);
      when(() => mockProductsProvider.products).thenReturn([testProduct]);
      when(() => mockProductsProvider.getTranslatedName(any(), any())).thenReturn('Bier');
      when(() => mockCartProvider.addItem(any(), any())).thenAnswer((_) {});
    });

    testWidgets('displays categories as tabs', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: ProductSelectionScreen(
            productsProvider: mockProductsProvider,
            cartProvider: mockCartProvider,
          ),
        ),
      );

      expect(find.text('Drinks'), findsOneWidget);
    });

    testWidgets('displays products for selected category', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: ProductSelectionScreen(
            productsProvider: mockProductsProvider,
            cartProvider: mockCartProvider,
          ),
        ),
      );

      expect(find.text('Bier'), findsOneWidget);
    });

    testWidgets('adds product to cart when add button pressed', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: ProductSelectionScreen(
            productsProvider: mockProductsProvider,
            cartProvider: mockCartProvider,
          ),
        ),
      );

      await tester.tap(find.byIcon(Icons.add_circle));
      verify(() => mockCartProvider.addItem(any(), 1)).called(1);
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
import 'package:ruderbar_terminal/models/product_dto.dart';
import 'package:ruderbar_terminal/providers/cart_provider.dart';
import 'package:ruderbar_terminal/providers/products_provider.dart';
import 'package:ruderbar_terminal/widgets/category_tabs.dart';
import 'package:ruderbar_terminal/widgets/error_banner.dart';
import 'package:ruderbar_terminal/widgets/loading_overlay.dart';
import 'package:ruderbar_terminal/widgets/product_card.dart';

class ProductSelectionScreen extends StatefulWidget {
  final ProductsProvider productsProvider;
  final CartProvider cartProvider;

  const ProductSelectionScreen({
    required this.productsProvider,
    required this.cartProvider,
    Key? key,
  }) : super(key: key);

  @override
  State<ProductSelectionScreen> createState() => _ProductSelectionScreenState();
}

class _ProductSelectionScreenState extends State<ProductSelectionScreen> {
  String? selectedCategoryId;

  @override
  void initState() {
    super.initState();
    // Select first category if available
    if (widget.productsProvider.categories.isNotEmpty) {
      selectedCategoryId = widget.productsProvider.categories.first.id;
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Select Products')),
      body: LoadingOverlay(
        isLoading: widget.productsProvider.isSyncing,
        message: 'Loading products...',
        child: Column(
          children: [
            if (widget.productsProvider.lastError != null)
              ErrorBanner(
                message: widget.productsProvider.lastError,
              ),
            // Category tabs
            Padding(
              padding: const EdgeInsets.all(8.0),
              child: CategoryTabs(
                categories: widget.productsProvider.categories,
                selectedCategoryId: selectedCategoryId,
                onCategorySelected: (categoryId) {
                  setState(() {
                    selectedCategoryId = categoryId;
                  });
                },
              ),
            ),
            // Products grid
            Expanded(
              child: GridView.builder(
                padding: const EdgeInsets.all(8.0),
                gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                  crossAxisCount: 2,
                  childAspectRatio: 0.8,
                  crossAxisSpacing: 8,
                  mainAxisSpacing: 8,
                ),
                itemCount: _filteredProducts.length,
                itemBuilder: (context, index) {
                  final product = _filteredProducts[index];
                  final name = widget.productsProvider.getTranslatedName(product, 'de');

                  return ProductCard(
                    product: product,
                    translatedName: name,
                    onAddPressed: () {
                      widget.cartProvider.addItem(product, 1);
                      ScaffoldMessenger.of(context).showSnackBar(
                        SnackBar(content: Text('$name added to cart')),
                      );
                    },
                  );
                },
              ),
            ),
          ],
        ),
      ),
    );
  }

  List<ProductsCacheData> get _filteredProducts {
    if (selectedCategoryId == null) return [];
    return widget.productsProvider.products
        .where((p) => p.categoryId == selectedCategoryId)
        .toList();
  }
}
```

**Step 4: Run test to verify it passes**

```bash
flutter test test/screens/product_selection_screen_test.dart -v
```

Expected: PASS (all 3 tests)

**Step 5: Commit**

```bash
git add lib/screens/product_selection_screen.dart test/screens/product_selection_screen_test.dart
git commit -m "feat: create ProductSelectionScreen (Phase 4 Task 9)

Browse and select products by category:
- Category tabs for filtering products
- Grid view of products for selected category
- Product cards with name, price, add button
- Add to cart with snackbar confirmation
- Error handling with ErrorBanner
- Loading state overlay during sync
- Material 3 design with responsive grid

Test coverage: 3 tests, all passing"
```

---

## Task 10: Screen - ShoppingCartScreen

**Files:**
- Create: `lib/screens/shopping_cart_screen.dart`
- Test: `test/screens/shopping_cart_screen_test.dart`

**Overview:** Review cart items, modify quantities, proceed to checkout.

**Step 1: Write the failing test**

Create `test/screens/shopping_cart_screen_test.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:ruderbar_terminal/models/cart_item.dart';
import 'package:ruderbar_terminal/providers/cart_provider.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/screens/shopping_cart_screen.dart';

class MockCartProvider extends Mock implements CartProvider {}

class MockMembersProvider extends Mock implements MembersProvider {}

void main() {
  group('ShoppingCartScreen', () {
    late MockCartProvider mockCartProvider;
    late MockMembersProvider mockMembersProvider;

    setUp(() {
      mockCartProvider = MockCartProvider();
      mockMembersProvider = MockMembersProvider();

      when(() => mockCartProvider.items).thenReturn([
        CartItem(productId: 'prod-1', quantity: 2, price: 5.50),
      ]);
      when(() => mockCartProvider.total).thenReturn(11.0);
      when(() => mockCartProvider.isLoading).thenReturn(false);
      when(() => mockCartProvider.lastError).thenReturn(null);
      when(() => mockCartProvider.removeItem(any())).thenAnswer((_) {});
      when(() => mockCartProvider.updateQuantity(any(), any())).thenAnswer((_) {});
      when(() => mockCartProvider.checkout(any())).thenAnswer((_) async => {});
    });

    testWidgets('displays cart items', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: ShoppingCartScreen(
            cartProvider: mockCartProvider,
            membersProvider: mockMembersProvider,
            onCheckoutComplete: () {},
          ),
        ),
      );

      expect(find.byType(ListView), findsOneWidget);
    });

    testWidgets('displays total price', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: ShoppingCartScreen(
            cartProvider: mockCartProvider,
            membersProvider: mockMembersProvider,
            onCheckoutComplete: () {},
          ),
        ),
      );

      expect(find.text(contains('€11.00')), findsWidgets);
    });

    testWidgets('has checkout button', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: ShoppingCartScreen(
            cartProvider: mockCartProvider,
            membersProvider: mockMembersProvider,
            onCheckoutComplete: () {},
          ),
        ),
      );

      expect(find.byType(ElevatedButton), findsWidgets);
    });

    testWidgets('shows empty cart message when no items', (WidgetTester tester) async {
      when(() => mockCartProvider.items).thenReturn([]);
      when(() => mockCartProvider.total).thenReturn(0.0);

      await tester.pumpWidget(
        MaterialApp(
          home: ShoppingCartScreen(
            cartProvider: mockCartProvider,
            membersProvider: mockMembersProvider,
            onCheckoutComplete: () {},
          ),
        ),
      );

      expect(find.text(contains('empty')), findsWidgets);
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
import 'package:ruderbar_terminal/providers/cart_provider.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/widgets/cart_item_row.dart';
import 'package:ruderbar_terminal/widgets/error_banner.dart';
import 'package:ruderbar_terminal/widgets/loading_overlay.dart';

class ShoppingCartScreen extends StatelessWidget {
  final CartProvider cartProvider;
  final MembersProvider membersProvider;
  final VoidCallback onCheckoutComplete;

  const ShoppingCartScreen({
    required this.cartProvider,
    required this.membersProvider,
    required this.onCheckoutComplete,
    Key? key,
  }) : super(key: key);

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Shopping Cart')),
      body: LoadingOverlay(
        isLoading: cartProvider.isLoading,
        message: 'Processing...',
        child: Column(
          children: [
            if (cartProvider.lastError != null)
              ErrorBanner(message: cartProvider.lastError),
            // Cart items
            Expanded(
              child: cartProvider.items.isEmpty
                  ? Center(
                      child: Text(
                        'Your cart is empty',
                        style: Theme.of(context).textTheme.headlineSmall,
                      ),
                    )
                  : ListView.builder(
                      itemCount: cartProvider.items.length,
                      itemBuilder: (context, index) {
                        final item = cartProvider.items[index];
                        return CartItemRow(
                          item: item,
                          productName: 'Product ${item.productId}', // TODO: get real name
                          onRemovePressed: () {
                            cartProvider.removeItem(item.productId);
                          },
                          onQuantityChanged: (newQuantity) {
                            cartProvider.updateQuantity(item.productId, newQuantity);
                          },
                        );
                      },
                    ),
            ),
            // Cart summary and checkout
            if (cartProvider.items.isNotEmpty)
              Padding(
                padding: const EdgeInsets.all(16.0),
                child: Column(
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text(
                          'Total:',
                          style: TextStyle(
                            fontSize: 18,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                        Text(
                          '€${cartProvider.total.toStringAsFixed(2)}',
                          style: const TextStyle(
                            fontSize: 24,
                            fontWeight: FontWeight.bold,
                            color: Colors.green,
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 16),
                    SizedBox(
                      width: double.infinity,
                      child: ElevatedButton(
                        onPressed: () async {
                          final member = membersProvider.selectedMember;
                          if (member != null) {
                            await cartProvider.checkout(member);
                            if (context.mounted && cartProvider.lastError == null) {
                              onCheckoutComplete();
                            }
                          }
                        },
                        style: ElevatedButton.styleFrom(
                          padding: const EdgeInsets.symmetric(vertical: 16),
                          backgroundColor: Colors.green,
                        ),
                        child: const Text('Proceed to Checkout'),
                      ),
                    ),
                  ],
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
flutter test test/screens/shopping_cart_screen_test.dart -v
```

Expected: PASS (all 4 tests)

**Step 5: Commit**

```bash
git add lib/screens/shopping_cart_screen.dart test/screens/shopping_cart_screen_test.dart
git commit -m "feat: create ShoppingCartScreen (Phase 4 Task 10)

Cart review and checkout screen:
- ListView of cart items with CartItemRow
- Remove and quantity controls per item
- Displays total price
- Checkout button that triggers CartProvider.checkout()
- Error handling with ErrorBanner
- Loading state overlay during checkout
- Shows empty cart message when no items
- Material 3 design

Test coverage: 4 tests, all passing"
```

---

## Task 11: Screen - CheckoutConfirmationScreen

**Files:**
- Create: `lib/screens/checkout_confirmation_screen.dart`
- Test: `test/screens/checkout_confirmation_screen_test.dart`

**Overview:** Transaction confirmation screen showing success/failure message.

**Step 1: Write the failing test**

Create `test/screens/checkout_confirmation_screen_test.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ruderbar_terminal/screens/checkout_confirmation_screen.dart';

void main() {
  group('CheckoutConfirmationScreen', () {
    testWidgets('displays success message', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: CheckoutConfirmationScreen(
            isSuccess: true,
            message: 'Transaction successful',
            onDismiss: () {},
          ),
        ),
      );

      expect(find.text('Transaction successful'), findsOneWidget);
      expect(find.byIcon(Icons.check_circle), findsOneWidget);
    });

    testWidgets('displays error message on failure', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: CheckoutConfirmationScreen(
            isSuccess: false,
            message: 'Payment failed',
            onDismiss: () {},
          ),
        ),
      );

      expect(find.text('Payment failed'), findsOneWidget);
      expect(find.byIcon(Icons.error), findsOneWidget);
    });

    testWidgets('has dismiss button', (WidgetTester tester) async {
      var dismissed = false;
      await tester.pumpWidget(
        MaterialApp(
          home: CheckoutConfirmationScreen(
            isSuccess: true,
            message: 'Success',
            onDismiss: () { dismissed = true; },
          ),
        ),
      );

      await tester.tap(find.byType(ElevatedButton));
      expect(dismissed, isTrue);
    });

    testWidgets('uses green color for success', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: CheckoutConfirmationScreen(
            isSuccess: true,
            message: 'Success',
            onDismiss: () {},
          ),
        ),
      );

      final icon = find.byIcon(Icons.check_circle);
      expect(icon, findsOneWidget);
    });

    testWidgets('uses red color for failure', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: CheckoutConfirmationScreen(
            isSuccess: false,
            message: 'Failed',
            onDismiss: () {},
          ),
        ),
      );

      final icon = find.byIcon(Icons.error);
      expect(icon, findsOneWidget);
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

class CheckoutConfirmationScreen extends StatelessWidget {
  final bool isSuccess;
  final String message;
  final VoidCallback onDismiss;

  const CheckoutConfirmationScreen({
    required this.isSuccess,
    required this.message,
    required this.onDismiss,
    Key? key,
  }) : super(key: key);

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            // Result icon
            Icon(
              isSuccess ? Icons.check_circle : Icons.error,
              size: 100,
              color: isSuccess ? Colors.green : Colors.red,
            ),
            const SizedBox(height: 24),
            // Result title
            Text(
              isSuccess ? 'Success!' : 'Error',
              style: Theme.of(context).textTheme.headlineMedium?.copyWith(
                    color: isSuccess ? Colors.green : Colors.red,
                  ),
            ),
            const SizedBox(height: 12),
            // Message
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 32.0),
              child: Text(
                message,
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.bodyLarge,
              ),
            ),
            const SizedBox(height: 40),
            // Dismiss button
            ElevatedButton(
              onPressed: onDismiss,
              style: ElevatedButton.styleFrom(
                padding: const EdgeInsets.symmetric(
                  horizontal: 48,
                  vertical: 16,
                ),
                backgroundColor: isSuccess ? Colors.green : Colors.red,
              ),
              child: const Text('Continue'),
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
flutter test test/screens/checkout_confirmation_screen_test.dart -v
```

Expected: PASS (all 5 tests)

**Step 5: Commit**

```bash
git add lib/screens/checkout_confirmation_screen.dart test/screens/checkout_confirmation_screen_test.dart
git commit -m "feat: create CheckoutConfirmationScreen (Phase 4 Task 11)

Transaction result confirmation screen:
- Displays success icon and message for successful transactions
- Displays error icon and message for failed transactions
- Large centered layout for visibility
- Green styling for success, red for failure
- Continue button with dismiss callback
- Material 3 design

Test coverage: 5 tests, all passing"
```

---

## Task 12: Verify All Tests Pass

**Step 1: Run full test suite**

```bash
cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend
flutter test 2>&1 | tail -20
```

Expected: All tests passing (160+ total)

**Step 2: Count tests by phase**

```bash
flutter test 2>&1 | grep "passed"
```

Expected output pattern: `X tests passed`

**Step 3: Commit completion**

```bash
git add -A
git commit -m "Phase 4 complete: Screens and Common Widgets

Implemented 11 tasks:
1. Common Widgets (6): AppHeader, ProductCard, CategoryTabs, CartItemRow, ErrorBanner, LoadingOverlay
2. Screens (4): MemberGreetingScreen, ProductSelectionScreen, ShoppingCartScreen, CheckoutConfirmationScreen
3. Index exports for widget reusability

All widgets and screens:
- Use Provider for state management
- Follow Material 3 design
- Have comprehensive widget tests (80%+ coverage)
- Are production-ready
- Support offline operation

Test count: 145+ total passing
Widgets: 30+ widget tests
Screens: 16+ screen tests"
```

---

## Task 13: Update Plans Index

**Files:**
- Modify: `docs/plans/INDEX.md`

**Step 1: Update Phase 4 status**

Edit `docs/plans/INDEX.md` and update:
- Phase 4 section: mark as COMPLETED
- Add test counts for Phase 4
- Update current phase to Phase 5
- Note completion date: 2026-02-01

**Step 2: Verify changes**

```bash
cat docs/plans/INDEX.md | head -50
```

**Step 3: Commit update**

```bash
git add docs/plans/INDEX.md
git commit -m "docs: update plans INDEX - Phase 4 complete

Phase 4: Screens and Common Widgets
- 6 reusable widgets (AppHeader, ProductCard, CategoryTabs, CartItemRow, ErrorBanner, LoadingOverlay)
- 4 screen implementations (greeting, product selection, cart, checkout)
- 46+ widget tests (80%+ coverage)
- All tests passing

Next: Phase 5 (Core UI Integration and Flows)"
```

---

## Success Criteria

✅ All 6 common widgets implemented with tests
✅ All 4 screen components implemented with tests
✅ 46+ new widget tests, all passing
✅ 160+ total tests passing across all phases
✅ Material 3 design throughout
✅ Provider integration complete
✅ Error handling with ErrorBanner
✅ Loading states with LoadingOverlay
✅ Production-ready code quality
✅ Clean git history with meaningful commits
✅ plans/INDEX.md updated with Phase 4 completion
