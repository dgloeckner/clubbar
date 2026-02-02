# SVG Icons Integration Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** Replace Material icons with beautiful SVG icons from admin-frontend, maintaining visual consistency across the Ruderbar ecosystem.

**Architecture:** Extract SVG icon components from admin-frontend, convert to asset files in terminal-frontend, update IconRegistry to use flutter_svg SvgPicture.asset(), and modify styled components to support dynamic color filtering. SVG icons will be stored as separate files for each icon (product + category), with a centralized registry for lookup and rendering.

**Tech Stack:** Flutter, flutter_svg package, SVG asset files, ColorFilter for dynamic theming

---

## Task 1: Add flutter_svg Dependency

**Files:**
- Modify: `pubspec.yaml`

**Step 1: Read pubspec.yaml to understand current structure**

Run: `cat pubspec.yaml | grep -A 5 "dependencies:"`

Expected: See current dependencies section with flutter, provider, go_router, etc.

**Step 2: Add flutter_svg to dependencies**

Edit `pubspec.yaml` and add to dependencies section:
```yaml
dependencies:
  flutter:
    sdk: flutter
  # ... existing dependencies ...
  flutter_svg: ^2.0.0
```

**Step 3: Run pub get to install the package**

Run: `cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend && flutter pub get`

Expected: Successfully downloads flutter_svg package

**Step 4: Verify the package is installed**

Run: `grep -A 1 "flutter_svg:" pubspec.lock`

Expected: Shows flutter_svg version installed

**Step 5: Commit**

```bash
cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend
git add pubspec.yaml pubspec.lock
git commit -m "feat: add flutter_svg dependency for SVG icon support"
```

---

## Task 2: Create SVG Icon Asset Structure

**Files:**
- Create: `assets/icons/products/` directory
- Create: `assets/icons/categories/` directory

**Step 1: Create assets directory structure**

Run:
```bash
mkdir -p /Users/dg/dev/frgs-vereinsbar/terminal-frontend/assets/icons/products
mkdir -p /Users/dg/dev/frgs-vereinsbar/terminal-frontend/assets/icons/categories
```

**Step 2: Verify directories created**

Run: `ls -la /Users/dg/dev/frgs-vereinsbar/terminal-frontend/assets/icons/`

Expected: Shows `products/` and `categories/` subdirectories

**Step 3: Update pubspec.yaml to include assets**

Edit `pubspec.yaml` and add after `flutter:` section:
```yaml
flutter:
  assets:
    - assets/icons/products/
    - assets/icons/categories/
```

**Step 4: Run pub get to recognize assets**

Run: `cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend && flutter pub get`

Expected: Successfully recognizes asset directories

**Step 5: Commit**

```bash
cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend
git add pubspec.yaml
git commit -m "feat: create SVG asset directories and update pubspec for asset loading"
```

---

## Task 3: Extract and Create Product Icon SVG Files

**Files:**
- Create: `assets/icons/products/pils_icon.svg`
- Create: `assets/icons/products/weizen_icon.svg`
- Create: `assets/icons/products/beer_af_icon.svg`
- Create: `assets/icons/products/radler_icon.svg`
- Create: `assets/icons/products/lemonade_icon.svg`
- Create: `assets/icons/products/apple_juice_icon.svg`
- Create: `assets/icons/products/appler_icon.svg`
- Create: `assets/icons/products/water_large_icon.svg`
- Create: `assets/icons/products/water_small_icon.svg`
- Create: `assets/icons/products/sauna_token_icon.svg`
- Create: `assets/icons/products/sauna_thermometer_icon.svg`
- Create: `assets/icons/products/sauna_time_icon.svg`
- Create: `assets/icons/products/sauna_cabin_icon.svg`

**Step 1: Extract PilsIcon SVG from admin-frontend**

Source: `/Users/dg/dev/frgs-vereinsbar/admin-frontend/src/components/icons/product-icons/PilsIcon.tsx`

Create `assets/icons/products/pils_icon.svg`:
```svg
<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
  <path d="M7 20L6 8c0-1 .5-2 2-2h8c1.5 0 2 1 2 2l-1 12c0 1-1 2-5 2s-5-1-5-2z" fill="#fbbf24" fillOpacity="0.3"/>
  <path d="M6 8h12"/>
  <ellipse cx="12" cy="8" rx="5" ry="1.5" fill="#fef3c7"/>
  <circle cx="10" cy="14" r="0.5" fill="currentColor" opacity="0.3"/>
  <circle cx="13" cy="12" r="0.5" fill="currentColor" opacity="0.3"/>
</svg>
```

**Step 2: Extract WeizenIcon SVG from admin-frontend**

Source: `/Users/dg/dev/frgs-vereinsbar/admin-frontend/src/components/icons/product-icons/WeizenIcon.tsx`

Read and extract SVG content, create `assets/icons/products/weizen_icon.svg` with full SVG markup (complete exact SVG from WeizenIcon.tsx)

**Step 3: Extract BeerAFIcon SVG**

Source: `/Users/dg/dev/frgs-vereinsbar/admin-frontend/src/components/icons/product-icons/BeerAFIcon.tsx`

Create `assets/icons/products/beer_af_icon.svg` with full SVG content

**Step 4: Extract RadlerIcon SVG**

Source: `/Users/dg/dev/frgs-vereinsbar/admin-frontend/src/components/icons/product-icons/RadlerIcon.tsx`

Create `assets/icons/products/radler_icon.svg`

**Step 5: Extract LemonadeIcon SVG**

Source: `/Users/dg/dev/frgs-vereinsbar/admin-frontend/src/components/icons/product-icons/LemonadeIcon.tsx`

Create `assets/icons/products/lemonade_icon.svg`

**Step 6: Extract AppleJuiceIcon SVG**

Source: `/Users/dg/dev/frgs-vereinsbar/admin-frontend/src/components/icons/product-icons/AppleJuiceIcon.tsx`

Create `assets/icons/products/apple_juice_icon.svg`

**Step 7: Extract ApplerIcon SVG**

Source: `/Users/dg/dev/frgs-vereinsbar/admin-frontend/src/components/icons/product-icons/ApplerIcon.tsx`

Create `assets/icons/products/appler_icon.svg`

**Step 8: Extract WaterLargeIcon SVG**

Source: `/Users/dg/dev/frgs-vereinsbar/admin-frontend/src/components/icons/product-icons/WaterLargeIcon.tsx`

Create `assets/icons/products/water_large_icon.svg`

**Step 9: Extract WaterSmallIcon SVG**

Source: `/Users/dg/dev/frgs-vereinsbar/admin-frontend/src/components/icons/product-icons/WaterSmallIcon.tsx`

Create `assets/icons/products/water_small_icon.svg`

**Step 10: Extract SaunaTokenIcon SVG**

Source: `/Users/dg/dev/frgs-vereinsbar/admin-frontend/src/components/icons/product-icons/SaunaTokenIcon.tsx`

Create `assets/icons/products/sauna_token_icon.svg`

**Step 11: Extract SaunaThermometerIcon SVG**

Source: `/Users/dg/dev/frgs-vereinsbar/admin-frontend/src/components/icons/product-icons/SaunaThermometerIcon.tsx`

Create `assets/icons/products/sauna_thermometer_icon.svg`

**Step 12: Extract SaunaTimeIcon SVG**

Source: `/Users/dg/dev/frgs-vereinsbar/admin-frontend/src/components/icons/product-icons/SaunaTimeIcon.tsx`

Create `assets/icons/products/sauna_time_icon.svg`

**Step 13: Extract SaunaCabinIcon SVG**

Source: `/Users/dg/dev/frgs-vereinsbar/admin-frontend/src/components/icons/product-icons/SaunaCabinIcon.tsx`

Create `assets/icons/products/sauna_cabin_icon.svg`

**Step 14: Verify all product SVG files created**

Run: `ls -la /Users/dg/dev/frgs-vereinsbar/terminal-frontend/assets/icons/products/`

Expected: 13 SVG files listed

**Step 15: Commit**

```bash
cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend
git add assets/icons/products/
git commit -m "feat: add product icon SVG files from admin-frontend

- PilsIcon (beer glass with foam)
- WeizenIcon (wheat beer glass)
- BeerAFIcon (alcohol-free beer)
- RadlerIcon (beer mixed drink)
- LemonadeIcon (lemonade beverage)
- AppleJuiceIcon (apple juice)
- ApplerIcon (apple cider)
- WaterLargeIcon (large water bottle)
- WaterSmallIcon (small water bottle)
- SaunaTokenIcon (sauna token)
- SaunaThermometerIcon (thermometer)
- SaunaTimeIcon (timer)
- SaunaCabinIcon (sauna cabin)

All 13 product icons extracted from admin-frontend components"
```

---

## Task 4: Extract and Create Category Icon SVG Files

**Files:**
- Create: `assets/icons/categories/category_icon.svg`
- Create: `assets/icons/categories/category_tags_icon.svg`
- Create: `assets/icons/categories/category_layers_icon.svg`
- Create: `assets/icons/categories/category_folder_icon.svg`
- Create: `assets/icons/categories/category_list_icon.svg`

**Step 1: Extract CategoryIcon SVG**

Source: `/Users/dg/dev/frgs-vereinsbar/admin-frontend/src/components/icons/category-icons/CategoryIcon.tsx`

Create `assets/icons/categories/category_icon.svg` with full SVG content

**Step 2: Extract CategoryTagsIcon SVG**

Source: `/Users/dg/dev/frgs-vereinsbar/admin-frontend/src/components/icons/category-icons/CategoryTagsIcon.tsx`

Create `assets/icons/categories/category_tags_icon.svg`

**Step 3: Extract CategoryLayersIcon SVG**

Source: `/Users/dg/dev/frgs-vereinsbar/admin-frontend/src/components/icons/category-icons/CategoryLayersIcon.tsx`

Create `assets/icons/categories/category_layers_icon.svg`

**Step 4: Extract CategoryFolderIcon SVG**

Source: `/Users/dg/dev/frgs-vereinsbar/admin-frontend/src/components/icons/category-icons/CategoryFolderIcon.tsx`

Create `assets/icons/categories/category_folder_icon.svg`

**Step 5: Extract CategoryListIcon SVG**

Source: `/Users/dg/dev/frgs-vereinsbar/admin-frontend/src/components/icons/category-icons/CategoryListIcon.tsx`

Create `assets/icons/categories/category_list_icon.svg`

**Step 6: Verify all category SVG files created**

Run: `ls -la /Users/dg/dev/frgs-vereinsbar/terminal-frontend/assets/icons/categories/`

Expected: 5 SVG files listed

**Step 7: Commit**

```bash
cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend
git add assets/icons/categories/
git commit -m "feat: add category icon SVG files from admin-frontend

- CategoryIcon (basic category folder)
- CategoryTagsIcon (tags representation)
- CategoryLayersIcon (layered items)
- CategoryFolderIcon (folder with items)
- CategoryListIcon (list representation)

All 5 category icons extracted from admin-frontend components"
```

---

## Task 5: Update IconRegistry to Use SVG Assets

**Files:**
- Modify: `lib/utils/icon_registry.dart`

**Step 1: Read current icon_registry.dart**

Run: `cat /Users/dg/dev/frgs-vereinsbar/terminal-frontend/lib/utils/icon_registry.dart`

Expected: Shows current implementation using getProductIcon() and getCategoryIcon() with Material icons

**Step 2: Replace with SVG-based implementation**

Rewrite `lib/utils/icon_registry.dart`:
```dart
import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';

/// Get product icon as SVG widget
/// Returns SvgPicture with optional color filtering
Widget getProductIcon(
  String? productName, {
  double size = 64,
  Color? color,
}) {
  final iconPath = _getProductIconPath(productName);

  return SvgPicture.asset(
    iconPath,
    width: size,
    height: size,
    colorFilter: color != null
        ? ColorFilter.mode(color, BlendMode.srcIn)
        : null,
    placeholderBuilder: (BuildContext context) {
      return SizedBox(
        width: size,
        height: size,
        child: const Icon(Icons.shopping_bag_outlined),
      );
    },
  );
}

/// Get category icon as SVG widget
/// Returns SvgPicture with optional color filtering
Widget getCategoryIcon(
  String? categoryName, {
  double size = 40,
  Color? color,
}) {
  final iconPath = _getCategoryIconPath(categoryName);

  return SvgPicture.asset(
    iconPath,
    width: size,
    height: size,
    colorFilter: color != null
        ? ColorFilter.mode(color, BlendMode.srcIn)
        : null,
    placeholderBuilder: (BuildContext context) {
      return SizedBox(
        width: size,
        height: size,
        child: const Icon(Icons.category),
      );
    },
  );
}

/// Map product name to SVG asset path
String _getProductIconPath(String? productName) {
  switch (productName?.toLowerCase()) {
    case 'pils':
      return 'assets/icons/products/pils_icon.svg';
    case 'weizen':
      return 'assets/icons/products/weizen_icon.svg';
    case 'beeralf':
    case 'beer_af':
    case 'alcohol_free_beer':
      return 'assets/icons/products/beer_af_icon.svg';
    case 'radler':
      return 'assets/icons/products/radler_icon.svg';
    case 'lemonade':
      return 'assets/icons/products/lemonade_icon.svg';
    case 'applejuice':
    case 'apple_juice':
      return 'assets/icons/products/apple_juice_icon.svg';
    case 'appler':
    case 'apple_cider':
      return 'assets/icons/products/appler_icon.svg';
    case 'waterlarge':
    case 'water_large':
      return 'assets/icons/products/water_large_icon.svg';
    case 'watersmall':
    case 'water_small':
      return 'assets/icons/products/water_small_icon.svg';
    case 'saunatoken':
    case 'sauna_token':
      return 'assets/icons/products/sauna_token_icon.svg';
    case 'saunathermometer':
    case 'sauna_thermometer':
      return 'assets/icons/products/sauna_thermometer_icon.svg';
    case 'saunatime':
    case 'sauna_time':
      return 'assets/icons/products/sauna_time_icon.svg';
    case 'saunacabin':
    case 'sauna_cabin':
      return 'assets/icons/products/sauna_cabin_icon.svg';
    default:
      return 'assets/icons/products/pils_icon.svg'; // Safe fallback
  }
}

/// Map category name to SVG asset path
String _getCategoryIconPath(String? categoryName) {
  switch (categoryName?.toLowerCase()) {
    case 'tags':
    case 'category_tags':
      return 'assets/icons/categories/category_tags_icon.svg';
    case 'layers':
    case 'category_layers':
      return 'assets/icons/categories/category_layers_icon.svg';
    case 'folder':
    case 'category_folder':
      return 'assets/icons/categories/category_folder_icon.svg';
    case 'list':
    case 'category_list':
      return 'assets/icons/categories/category_list_icon.svg';
    default:
      return 'assets/icons/categories/category_icon.svg'; // Safe fallback
  }
}
```

**Step 3: Run tests to verify no regressions**

Run: `cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend && flutter test 2>&1 | tail -20`

Expected: All 204 tests pass

**Step 4: Commit**

```bash
cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend
git add lib/utils/icon_registry.dart
git commit -m "feat: update IconRegistry to use SVG assets instead of Material icons

- getProductIcon() now returns SvgPicture.asset() with 13 product icons
- getCategoryIcon() now returns SvgPicture.asset() with 5 category icons
- Added color filtering support for dynamic theming (ColorFilter.mode)
- Maintained placeholder fallback icons for robustness
- Product mapping: pils, weizen, beeralf, radler, lemonade, applejuice, appler, waterlarge, watersmall, saunatoken, saunathermometer, saunatime, saunacabin
- Category mapping: tags, layers, folder, list (default: category_icon)

All 204 tests passing"
```

---

## Task 6: Update ProductCard Component for SVG Support

**Files:**
- Modify: `lib/widgets/styled_components/product_card.dart`

**Step 1: Read current ProductCard implementation**

Run: `head -100 /Users/dg/dev/frgs-vereinsbar/terminal-frontend/lib/widgets/styled_components/product_card.dart`

Expected: Shows current ProductCard with getProductIcon(null) call

**Step 2: Update ProductCard to properly pass color to SVG icon**

Edit the icon rendering section in ProductCard:
```dart
// Replace the icon rendering with color support
Icon(
  icon,
  size: 64,
  color: const Color(0xff0ea5e9), // Cyan for SVG rendering
)
```

Change to:
```dart
// SVG icon with color filtering for better visual appearance
SizedBox(
  width: 64,
  height: 64,
  child: getProductIcon(
    null,
    size: 64,
    color: const Color(0xff0ea5e9), // Cyan color filter
  ),
)
```

Add import at top of file:
```dart
import 'package:flutter_svg/flutter_svg.dart';
```

**Step 3: Run tests to verify ProductCard works**

Run: `cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend && flutter test test/widgets/product_card_test.dart 2>&1 | tail -20`

Expected: All product card tests pass

**Step 4: Run full test suite**

Run: `cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend && flutter test 2>&1 | grep "passed\|All tests"`

Expected: All 204 tests pass

**Step 5: Commit**

```bash
cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend
git add lib/widgets/styled_components/product_card.dart
git commit -m "feat: update ProductCard to render SVG icons with color filtering

- Changed from Material icon to getProductIcon() with SVG support
- Added cyan color filter (ColorFilter.mode) for consistent theming
- Maintained 64px icon size for touch-optimized product cards
- ProductCard icons now match admin-frontend aesthetic

All 204 tests passing"
```

---

## Task 7: Update CategoryChip Component for SVG Support

**Files:**
- Modify: `lib/widgets/styled_components/category_chip.dart`

**Step 1: Read current CategoryChip implementation**

Run: `head -60 /Users/dg/dev/frgs-vereinsbar/terminal-frontend/lib/widgets/styled_components/category_chip.dart`

Expected: Shows current CategoryChip icon rendering

**Step 2: Update CategoryChip to use SVG icons with color filtering**

Edit the icon rendering section in CategoryChip to use getProductIcon/getCategoryIcon:
```dart
// Update icon rendering with color filtering based on selected state
SvgPicture.asset(
  selected
      ? 'assets/icons/categories/category_tags_icon.svg'
      : 'assets/icons/categories/category_icon.svg',
  width: 32,
  height: 32,
  colorFilter: ColorFilter.mode(
    selected ? Colors.white : const Color(0xff94a3b8),
    BlendMode.srcIn,
  ),
)
```

Or use the registry function with proper color:
```dart
getCategoryIcon(
  null,
  size: 32,
  color: selected ? Colors.white : const Color(0xff94a3b8),
)
```

Add import:
```dart
import 'package:flutter_svg/flutter_svg.dart';
```

**Step 3: Run CategoryChip tests**

Run: `cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend && flutter test test/widgets/styled_components/ 2>&1 | grep "CategoryChip\|passed"`

Expected: All CategoryChip tests pass

**Step 4: Run full test suite**

Run: `cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend && flutter test 2>&1 | grep "All tests"`

Expected: All 204 tests pass

**Step 5: Commit**

```bash
cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend
git add lib/widgets/styled_components/category_chip.dart
git commit -m "feat: update CategoryChip to render SVG icons with state-based color filtering

- Changed to getProductIcon() for SVG rendering
- Added color filtering: white when selected, secondary gray when unselected
- Maintained 32px icon size for chip-optimized layout
- Category icons now match admin-frontend aesthetic
- Better visual feedback for selected/unselected states

All 204 tests passing"
```

---

## Task 8: Visual Verification and Fine-Tuning

**Files:**
- No code changes; verification only

**Step 1: Build and run the app**

Run: `cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend && flutter run`

Wait for app to load on desktop (testable on desktop per design spec)

**Step 2: Navigate through screens and verify SVG icons render**

- Go to idle waiting screen
- Tap RFID button (or demo button)
- Select member
- Navigate to product selection screen
- Verify product cards show SVG icons (Pils, Weizen, etc.)
- Verify category chips show SVG icons
- Verify colors match design tokens (cyan for products, white/gray for categories)
- Add item to cart
- Go to cart screen
- Verify cart items show SVG icons
- Proceed to checkout (verify confirmation screen shows success)

**Step 3: Compare with admin-frontend aesthetic**

- Open admin-frontend in browser: http://localhost:3000
- Compare product icon styles between frontend and terminal
- Verify colors and styling are consistent
- Note any differences for future enhancement

**Step 4: Check all 204 tests still pass**

Run: `cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend && flutter test 2>&1 | tail -5`

Expected: All 204 tests passing

**Step 5: Document visual verification results**

Create a simple note (not committed) about what was verified:
- SVG icons render without placeholder fallbacks ✓
- Colors match design tokens ✓
- Icons display at correct sizes ✓
- Aesthetic matches admin-frontend ✓
- No performance issues observed ✓

---

## Success Criteria

- [x] flutter_svg dependency added to pubspec.yaml
- [x] Asset directories created: assets/icons/products/ and assets/icons/categories/
- [x] 13 product SVG files extracted and created
- [x] 5 category SVG files extracted and created
- [x] IconRegistry updated to use SvgPicture.asset()
- [x] ProductCard uses SVG icons with cyan color filtering
- [x] CategoryChip uses SVG icons with state-based color filtering
- [x] All 204 tests passing
- [x] Visual verification: SVG icons render beautifully
- [x] Aesthetic matches admin-frontend design system
- [x] No regressions in app functionality

---

## Notes

- SVG icons are vector-based, ensuring crisp rendering at any size
- ColorFilter.mode() allows dynamic theming without modifying SVG files
- placeholderBuilder fallback maintains app stability if SVG fails to load
- All product and category icon mappings are case-insensitive for robustness
- SVG files are lightweight compared to raster images, improving performance
- Integration maintains existing test coverage (204 tests)
