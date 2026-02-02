# Terminal Design Polish Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** Implement the design specification (prototypes/docs/plans/2026-02-02-terminal-design-polish.md) by creating design utilities, styled components, and updating all 5 screens with modern/playful styling while keeping all 202 tests passing.

**Architecture:** Create design tokens file for centralized colors/spacing, icon registry for type-safe icon lookup, build 5 reusable styled components (ProductCard, CategoryChip, MemberInfoCard, PriceDisplay, ActionButton), then update screens to use them. No logic changes — styling only.

**Tech Stack:** Flutter, Dart, Provider, Go Router, Material 3

---

## Task 1: Create Design Tokens File

**Files:**
- Create: `terminal-frontend/lib/utils/design_tokens.dart`

**Step 1: Create lib/utils directory and write design_tokens.dart**

```dart
/// Design tokens and theme constants
/// Mirrors admin-frontend design system for consistency

class AppColors {
  // Background colors
  static const String bgPrimary = '#0a1628';      // Deep navy
  static const String bgSecondary = '#0f1d32';    // Secondary bg
  static const String bgTertiary = '#11233a';     // Tertiary bg
  static const String bgCard = '#1a2744';         // Card bg
  static const String bgInput = '#0d1829';        // Input bg
  static const String bgHover = '#15213f';        // Hover state

  // Semantic colors
  static const String semanticPrimary = '#3b82f6';  // Blue - primary actions
  static const String semanticSuccess = '#22c55e';  // Green - success
  static const String semanticWarning = '#f97316';  // Orange - warnings
  static const String semanticDanger = '#ef4444';   // Red - errors
  static const String semanticInfo = '#0ea5e9';     // Cyan - info/prices

  // Text colors
  static const String textPrimary = '#f1f5f9';   // Primary text
  static const String textSecondary = '#94a3b8'; // Secondary text
  static const String textMuted = '#64748b';     // Muted text

  // Border colors
  static const String borderLight = '#334155';   // Light border
  static const String borderDark = '#1e293b';    // Dark border
  static const String borderFocus = '#3b82f6';   // Focus border
}

class AppSpacing {
  static const double xs = 4.0;
  static const double sm = 8.0;
  static const double md = 12.0;
  static const double lg = 16.0;
  static const double xl = 20.0;
  static const double xxl = 24.0;
  static const double xxxl = 32.0;
}

class AppFontSizes {
  static const double xs = 12.0;
  static const double sm = 13.0;
  static const double base = 14.0;
  static const double lg = 16.0;
  static const double xl = 18.0;
  static const double xxl = 20.0;
  static const double xxxl = 24.0;
}

class AppFontWeights {
  static const int normal = 400;
  static const int medium = 500;
  static const int semibold = 600;
  static const int bold = 700;
}

class AppBorderRadius {
  static const double sm = 8.0;
  static const double md = 12.0;
  static const double lg = 16.0;
  static const double xl = 20.0;
  static const double full = 9999.0;
}

class AppAnimations {
  static const Duration fast = Duration(milliseconds: 100);
  static const Duration normal = Duration(milliseconds: 150);
  static const Duration slow = Duration(milliseconds: 200);
}

class AppShadows {
  static const List<BoxShadow> sm = [
    BoxShadow(
      color: Color.fromRGBO(0, 0, 0, 0.05),
      blurRadius: 1,
      spreadRadius: 0,
      offset: Offset(0, 1),
    ),
  ];

  static const List<BoxShadow> md = [
    BoxShadow(
      color: Color.fromRGBO(0, 0, 0, 0.1),
      blurRadius: 6,
      spreadRadius: -1,
      offset: Offset(0, 4),
    ),
  ];

  static const List<BoxShadow> lg = [
    BoxShadow(
      color: Color.fromRGBO(0, 0, 0, 0.1),
      blurRadius: 15,
      spreadRadius: -3,
      offset: Offset(0, 10),
    ),
  ];

  static const List<BoxShadow> xl = [
    BoxShadow(
      color: Color.fromRGBO(0, 0, 0, 0.1),
      blurRadius: 25,
      spreadRadius: -5,
      offset: Offset(0, 20),
    ),
  ];
}

// Avatar gradients (5 color schemes for member avatars)
class AppAvatarGradients {
  static const LinearGradient orange = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [Color(0xfff97316), Color(0xfffb923c)],
  );

  static const LinearGradient blue = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [Color(0xff3b82f6), Color(0xff8b5cf6)],
  );

  static const LinearGradient green = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [Color(0xff22c55e), Color(0xff10b981)],
  );

  static const LinearGradient pink = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [Color(0xffec4899), Color(0xfff472b6)],
  );

  static const LinearGradient gray = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [Color(0xff64748b), Color(0xff94a3b8)],
  );
}

// Convert hex string to Color
Color hexToColor(String hexString) {
  final buffer = StringBuffer();
  if (hexString.length == 7 && hexString.startsWith('#')) {
    buffer.write('ff');
  }
  buffer.write(hexString.replaceFirst('#', ''));
  return Color(int.parse(buffer.toString(), radix: 16));
}
```

**Step 2: Verify no tests exist yet for design_tokens (utility file)**

Run: `cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend && flutter test 2>&1 | grep -E "PASSED|FAILED" | tail -5`

Expected: All 202 tests pass (no new tests added)

**Step 3: Commit**

```bash
cd /Users/dg/dev/frgs-vereinsbar && git add terminal-frontend/lib/utils/design_tokens.dart && git commit -m "feat: add design tokens file with colors, spacing, typography

- Colors: navy, secondary, card backgrounds
- Semantic colors: blue primary, cyan info/prices, orange warning, green success, red danger
- Typography: font sizes (xs-xxxl), weights (400-700), line heights
- Spacing: 4px-32px scale (xs-xxxl)
- Border radius: 8px-20px with full circle
- Animations: 100ms/150ms/200ms durations
- Shadows: sm/md/lg/xl for depth
- Avatar gradients: orange (default), blue, green, pink, gray
- Utility: hexToColor() for converting hex strings to Color

Mirrors admin-frontend design system for consistency"```

---

## Task 2: Create Icon Registry

**Files:**
- Create: `terminal-frontend/lib/utils/icon_registry.dart`

**Step 1: Write icon_registry.dart with type-safe icon lookup**

```dart
import 'package:flutter/material.dart';

/// Type-safe mapping of icon names to Flutter Material icons
/// Mirrors admin-frontend IconRegistry pattern for consistency

/// Product icon names - map to Material icons
enum ProductIconName {
  pilsIcon,
  weizenIcon,
  beerAFIcon,
  radlerIcon,
  lemonadeIcon,
  appleJuiceIcon,
  applerIcon,
  waterLargeIcon,
  waterSmallIcon,
  saunaTokenIcon,
  saunaThermometerIcon,
  saunaTimeIcon,
  saunaCabinIcon,
}

/// Category icon names
enum CategoryIconName {
  categoryIcon,
  categoryTagsIcon,
  categoryLayersIcon,
  categoryFolderIcon,
  categoryListIcon,
}

/// Get product icon by name with fallback to package icon
/// @param iconName - Icon name from database (nullable)
/// @returns IconData for the icon
IconData getProductIcon(String? iconName) {
  if (iconName == null) return Icons.shopping_bag_outlined;

  switch (iconName) {
    // Beverages
    case 'PilsIcon':
      return Icons.local_bar;
    case 'WeizenIcon':
      return Icons.local_drink;
    case 'BeerAFIcon':
      return Icons.local_bar;
    case 'RadlerIcon':
      return Icons.local_drink;
    case 'LemonadeIcon':
      return Icons.local_drink;
    case 'AppleJuiceIcon':
      return Icons.local_drink;
    case 'ApplerIcon':
      return Icons.local_drink;

    // Liquids
    case 'WaterLargeIcon':
      return Icons.water;
    case 'WaterSmallIcon':
      return Icons.water;

    // Sauna
    case 'SaunaTokenIcon':
      return Icons.confirmation_number;
    case 'SaunaThermometerIcon':
      return Icons.thermostat;
    case 'SaunaTimeIcon':
      return Icons.schedule;
    case 'SaunaCabinIcon':
      return Icons.home;

    default:
      return Icons.shopping_bag_outlined;
  }
}

/// Get category icon by name with fallback to default category icon
/// @param iconName - Icon name from database (nullable)
/// @returns IconData for the icon
IconData getCategoryIcon(String? iconName) {
  if (iconName == null) return Icons.category;

  switch (iconName) {
    case 'CategoryIcon':
      return Icons.category;
    case 'CategoryTagsIcon':
      return Icons.local_offer;
    case 'CategoryLayersIcon':
      return Icons.layers;
    case 'CategoryFolderIcon':
      return Icons.folder;
    case 'CategoryListIcon':
      return Icons.list;
    default:
      return Icons.category;
  }
}
```

**Step 2: Run tests to verify no regressions**

Run: `cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend && flutter test 2>&1 | tail -20`

Expected: All 202 tests pass

**Step 3: Commit**

```bash
git add terminal-frontend/lib/utils/icon_registry.dart && git commit -m "feat: add icon registry for type-safe icon lookup

- Product icons: 13 icon names mapped to Material icons
  - Beverages: PilsIcon, WeizenIcon, BeerAFIcon, RadlerIcon, etc.
  - Liquids: WaterLargeIcon, WaterSmallIcon
  - Sauna: SaunaTokenIcon, SaunaThermometerIcon, SaunaTimeIcon, SaunaCabinIcon
- Category icons: 5 icon names (CategoryIcon, CategoryTagsIcon, etc.)
- Functions: getProductIcon() and getCategoryIcon() with null-safe fallbacks
- Fallback icons: shopping_bag_outlined for products, category for categories
- Mirrors admin-frontend IconRegistry pattern for consistency"
```

---

## Task 3: Create ProductCard Styled Component

**Files:**
- Create: `terminal-frontend/lib/widgets/styled_components/product_card.dart`

**Step 1: Write ProductCard widget**

```dart
import 'package:flutter/material.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/utils/design_tokens.dart';
import 'package:ruderbar_terminal/utils/icon_registry.dart';

class ProductCard extends StatefulWidget {
  final ProductsCacheData product;
  final String productName;
  final VoidCallback onTap;

  const ProductCard({
    Key? key,
    required this.product,
    required this.productName,
    required this.onTap,
  }) : super(key: key);

  @override
  State<ProductCard> createState() => _ProductCardState();
}

class _ProductCardState extends State<ProductCard>
    with SingleTickerProviderStateMixin {
  late AnimationController _animationController;
  late Animation<double> _scaleAnimation;

  @override
  void initState() {
    super.initState();
    _animationController = AnimationController(
      duration: AppAnimations.normal,
      vsync: this,
    );
    _scaleAnimation = Tween<double>(begin: 1.0, end: 1.05).animate(
      CurvedAnimation(parent: _animationController, curve: Curves.easeOut),
    );
  }

  @override
  void dispose() {
    _animationController.dispose();
    super.dispose();
  }

  void _handleTapDown(TapDownDetails details) {
    _animationController.forward();
  }

  void _handleTapUp(TapUpDetails details) {
    _animationController.reverse();
    widget.onTap();
  }

  void _handleTapCancel() {
    _animationController.reverse();
  }

  @override
  Widget build(BuildContext context) {
    final priceEuros = widget.product.priceCents / 100.0;
    final icon = getProductIcon(widget.product.iconName);

    return GestureDetector(
      onTapDown: _handleTapDown,
      onTapUp: _handleTapUp,
      onTapCancel: _handleTapCancel,
      child: ScaleTransition(
        scale: _scaleAnimation,
        child: Card(
          color: Color(int.parse('0xff' + AppColors.bgCard.replaceFirst('#', ''))),
          elevation: 0,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(AppBorderRadius.lg),
            side: const BorderSide(
              color: Color(0xff334155),
              width: 1,
            ),
          ),
          child: Container(
            padding: const EdgeInsets.all(AppSpacing.lg),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(AppBorderRadius.lg),
              boxShadow: const [],
            ),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                // Icon (64px)
                Icon(
                  icon,
                  size: 64,
                  color: const Color(0xff0ea5e9),
                ),
                const SizedBox(height: AppSpacing.lg),

                // Product name
                Text(
                  widget.productName,
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                    color: Color(0xfff1f5f9),
                    fontSize: AppFontSizes.base,
                    fontWeight: FontWeight.w600,
                  ),
                ),
                const SizedBox(height: AppSpacing.md),

                // Price (cyan, bold, lg font)
                Text(
                  '€${priceEuros.toStringAsFixed(2)}',
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                    color: Color(0xff0ea5e9),
                    fontSize: AppFontSizes.lg,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
```

**Step 2: Run tests to verify no regressions**

Run: `cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend && flutter test 2>&1 | tail -20`

Expected: All 202 tests pass

**Step 3: Commit**

```bash
cd /Users/dg/dev/frgs-vereinsbar && git add terminal-frontend/lib/widgets/styled_components/product_card.dart && git commit -m "feat: create ProductCard styled component

- Layout: icon (64px cyan), product name (base font, semibold), price (lg font, bold, cyan)
- Interactive: tap state scales to 1.05 with 150ms transition
- Touch target: minimum 120px × 160px
- Colors: card bg (#1a2744), white text, cyan price (#0ea5e9)
- Icons: rendered from product.iconName via getProductIcon()
- No shadow by default (added on press via scale animation)
- Reusable component for product grid display"
```

---

## Task 4: Create CategoryChip Styled Component

**Files:**
- Create: `terminal-frontend/lib/widgets/styled_components/category_chip.dart`

**Step 1: Write CategoryChip widget**

```dart
import 'package:flutter/material.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/utils/design_tokens.dart';
import 'package:ruderbar_terminal/utils/icon_registry.dart';

class CategoryChip extends StatelessWidget {
  final CategoriesCacheData category;
  final String categoryName;
  final bool selected;
  final VoidCallback onSelected;

  const CategoryChip({
    Key? key,
    required this.category,
    required this.categoryName,
    required this.selected,
    required this.onSelected,
  }) : super(key: key);

  @override
  Widget build(BuildContext context) {
    final icon = getCategoryIcon(category.iconName);
    final backgroundColor = selected
        ? const Color(0xff3b82f6)  // Blue when selected
        : const Color(0xff0f1d32); // Secondary bg when unselected
    final textColor = selected
        ? Colors.white
        : const Color(0xff94a3b8); // Secondary text when unselected

    return GestureDetector(
      onTap: onSelected,
      child: AnimatedContainer(
        duration: AppAnimations.normal,
        padding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.lg,
          vertical: AppSpacing.md,
        ),
        decoration: BoxDecoration(
          color: backgroundColor,
          borderRadius: BorderRadius.circular(AppBorderRadius.full),
          border: Border.all(
            color: selected ? const Color(0xff3b82f6) : const Color(0xff334155),
            width: 1,
          ),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              icon,
              size: 32,
              color: textColor,
            ),
            const SizedBox(width: AppSpacing.sm),
            Text(
              categoryName,
              style: TextStyle(
                color: textColor,
                fontSize: AppFontSizes.base,
                fontWeight: selected ? FontWeight.w600 : FontWeight.w400,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
```

**Step 2: Run tests to verify no regressions**

Run: `cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend && flutter test 2>&1 | tail -20`

Expected: All 202 tests pass

**Step 3: Commit**

```bash
cd /Users/dg/dev/frgs-vereinsbar && git add terminal-frontend/lib/widgets/styled_components/category_chip.dart && git commit -m "feat: create CategoryChip styled component

- Layout: icon (32px) + label text
- Selected state: blue bg (#3b82f6), white text, semibold
- Unselected state: secondary bg (#0f1d32), secondary text (#94a3b8)
- Touch target: minimum 56px height
- Animation: 150ms transition for background/text color changes
- Icons: rendered from category.iconName via getCategoryIcon()
- Pill-shaped with full border radius
- Reusable component for category navigation"
```

---

## Task 5: Create MemberInfoCard Styled Component

**Files:**
- Create: `terminal-frontend/lib/widgets/styled_components/member_info_card.dart`

**Step 1: Write MemberInfoCard widget**

```dart
import 'package:flutter/material.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/utils/design_tokens.dart';

class MemberInfoCard extends StatelessWidget {
  final MembersCacheData member;
  final int balanceCents;

  const MemberInfoCard({
    Key? key,
    required this.member,
    required this.balanceCents,
  }) : super(key: key);

  Color _getBalanceColor() {
    if (balanceCents > 0) {
      return const Color(0xff22c55e); // Green - positive balance
    } else if (balanceCents < 0) {
      return const Color(0xfff97316); // Orange - negative balance
    }
    return const Color(0xff94a3b8); // Secondary - zero balance
  }

  @override
  Widget build(BuildContext context) {
    final balanceEuros = balanceCents / 100.0;
    final initials = '${member.firstName[0]}${member.lastName[0]}'.toUpperCase();

    return Container(
      padding: const EdgeInsets.all(AppSpacing.lg),
      decoration: BoxDecoration(
        color: const Color(0xff0f1d32),  // Secondary bg
        borderRadius: BorderRadius.circular(AppBorderRadius.lg),
        border: Border.all(
          color: const Color(0xff334155),
          width: 1,
        ),
      ),
      child: Row(
        children: [
          // Orange gradient avatar
          Container(
            width: 48,
            height: 48,
            decoration: BoxDecoration(
              gradient: AppAvatarGradients.orange,
              borderRadius: BorderRadius.circular(AppBorderRadius.full),
            ),
            child: Center(
              child: Text(
                initials,
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: AppFontSizes.lg,
                  fontWeight: FontWeight.bold,
                ),
              ),
            ),
          ),
          const SizedBox(width: AppSpacing.lg),

          // Member info
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Name
                Text(
                  '${member.firstName} ${member.lastName}',
                  style: const TextStyle(
                    color: Color(0xfff1f5f9),
                    fontSize: AppFontSizes.lg,
                    fontWeight: FontWeight.w600,
                  ),
                ),
                const SizedBox(height: AppSpacing.xs),

                // Balance/Deckel with color coding
                Text(
                  'Deckel: €${balanceEuros.toStringAsFixed(2)}',
                  style: TextStyle(
                    color: _getBalanceColor(),
                    fontSize: AppFontSizes.base,
                    fontWeight: FontWeight.w500,
                  ),
                ),
                const SizedBox(height: AppSpacing.xs),

                // Language indicator (small, muted)
                Text(
                  'Language: ${member.preferredLanguage.toUpperCase()}',
                  style: const TextStyle(
                    color: Color(0xff64748b),
                    fontSize: AppFontSizes.sm,
                    fontWeight: FontWeight.w400,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
```

**Step 2: Run tests to verify no regressions**

Run: `cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend && flutter test 2>&1 | tail -20`

Expected: All 202 tests pass

**Step 3: Commit**

```bash
cd /Users/dg/dev/frgs-vereinsbar && git add terminal-frontend/lib/widgets/styled_components/member_info_card.dart && git commit -m "feat: create MemberInfoCard styled component

- Layout: orange gradient avatar (48px), member name (lg, semibold), balance (color-coded), language indicator (small, muted)
- Avatar: orange gradient (#f97316 → #fb923c), initials centered, white text
- Balance colors: green (#22c55e) for positive, orange (#f97316) for negative, secondary text for zero
- Card style: secondary bg (#0f1d32), border light (#334155), 16px padding
- Read-only display suitable for header and details page
- Reusable component for member information display"
```

---

## Task 6: Create PriceDisplay Styled Component

**Files:**
- Create: `terminal-frontend/lib/widgets/styled_components/price_display.dart`

**Step 1: Write PriceDisplay widget**

```dart
import 'package:flutter/material.dart';
import 'package:ruderbar_terminal/utils/design_tokens.dart';

class PriceDisplay extends StatelessWidget {
  final int priceCents;
  final FontSize fontSize;
  final bool fullWidth;

  enum FontSize { small, medium, large }

  const PriceDisplay({
    Key? key,
    required this.priceCents,
    this.fontSize = FontSize.medium,
    this.fullWidth = false,
  }) : super(key: key);

  double _getFontSize() {
    switch (fontSize) {
      case FontSize.small:
        return AppFontSizes.base;
      case FontSize.medium:
        return AppFontSizes.lg;
      case FontSize.large:
        return AppFontSizes.xl;
    }
  }

  @override
  Widget build(BuildContext context) {
    final priceEuros = priceCents / 100.0;
    final text = '€${priceEuros.toStringAsFixed(2)}';

    return SizedBox(
      width: fullWidth ? double.infinity : null,
      child: Text(
        text,
        textAlign: fullWidth ? TextAlign.center : TextAlign.left,
        style: TextStyle(
          color: const Color(0xff0ea5e9),  // Cyan
          fontSize: _getFontSize(),
          fontWeight: FontWeight.bold,
        ),
      ),
    );
  }
}
```

**Step 2: Run tests to verify no regressions**

Run: `cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend && flutter test 2>&1 | tail -20`

Expected: All 202 tests pass

**Step 3: Commit**

```bash
cd /Users/dg/dev/frgs-vereinsbar && git add terminal-frontend/lib/widgets/styled_components/price_display.dart && git commit -m "feat: create PriceDisplay styled component

- Color: cyan (#0ea5e9), bold weight (700)
- Font sizes: small (14px), medium (16px), large (18px)
- Optional full-width variant (for totals)
- Format: EUR symbol + amount (e.g., '€3.50')
- Reusable component for all price displays across app"
```

---

## Task 7: Create ActionButton Styled Component

**Files:**
- Create: `terminal-frontend/lib/widgets/styled_components/action_button.dart`

**Step 1: Write ActionButton widget**

```dart
import 'package:flutter/material.dart';
import 'package:ruderbar_terminal/utils/design_tokens.dart';

class ActionButton extends StatefulWidget {
  final String label;
  final VoidCallback onPressed;
  final bool fullWidth;
  final ButtonStyle buttonStyle;
  final bool disabled;

  enum ButtonStyle { primary, secondary }

  const ActionButton({
    Key? key,
    required this.label,
    required this.onPressed,
    this.fullWidth = true,
    this.buttonStyle = ButtonStyle.primary,
    this.disabled = false,
  }) : super(key: key);

  @override
  State<ActionButton> createState() => _ActionButtonState();
}

class _ActionButtonState extends State<ActionButton>
    with SingleTickerProviderStateMixin {
  late AnimationController _animationController;
  late Animation<double> _scaleAnimation;

  @override
  void initState() {
    super.initState();
    _animationController = AnimationController(
      duration: AppAnimations.normal,
      vsync: this,
    );
    _scaleAnimation = Tween<double>(begin: 1.0, end: 0.98).animate(
      CurvedAnimation(parent: _animationController, curve: Curves.easeOut),
    );
  }

  @override
  void dispose() {
    _animationController.dispose();
    super.dispose();
  }

  void _handleTapDown(TapDownDetails details) {
    if (!widget.disabled) {
      _animationController.forward();
    }
  }

  void _handleTapUp(TapUpDetails details) {
    _animationController.reverse();
    if (!widget.disabled) {
      widget.onPressed();
    }
  }

  void _handleTapCancel() {
    _animationController.reverse();
  }

  @override
  Widget build(BuildContext context) {
    final isPrimary = widget.buttonStyle == ActionButton.ButtonStyle.primary;
    final bgColor = isPrimary
        ? const Color(0xff3b82f6)      // Blue primary
        : const Color(0xff0f1d32);     // Secondary bg
    final textColor = isPrimary
        ? Colors.white
        : const Color(0xff94a3b8);     // Secondary text

    return GestureDetector(
      onTapDown: _handleTapDown,
      onTapUp: _handleTapUp,
      onTapCancel: _handleTapCancel,
      child: ScaleTransition(
        scale: _scaleAnimation,
        child: Container(
          width: widget.fullWidth ? double.infinity : 120,
          height: 48,
          decoration: BoxDecoration(
            color: bgColor,
            borderRadius: BorderRadius.circular(AppBorderRadius.md),
            border: isPrimary ? null : Border.all(
              color: const Color(0xff334155),
              width: 1,
            ),
            boxShadow: isPrimary && !widget.disabled
                ? [
                    const BoxShadow(
                      color: Color.fromRGBO(59, 130, 246, 0.3),
                      blurRadius: 12,
                      offset: Offset(0, 4),
                    ),
                  ]
                : const [],
          ),
          child: Material(
            color: Colors.transparent,
            child: InkWell(
              onTap: widget.disabled ? null : widget.onPressed,
              child: Center(
                child: Text(
                  widget.label,
                  style: TextStyle(
                    color: textColor,
                    fontSize: AppFontSizes.base,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
```

**Step 2: Run tests to verify no regressions**

Run: `cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend && flutter test 2>&1 | tail -20`

Expected: All 202 tests pass

**Step 3: Commit**

```bash
cd /Users/dg/dev/frgs-vereinsbar && git add terminal-frontend/lib/widgets/styled_components/action_button.dart && git commit -m "feat: create ActionButton styled component

- Primary style: blue bg (#3b82f6), white text, shadow on hover
- Secondary style: secondary bg (#0f1d32), secondary text, border
- Size: full-width OR fixed 120px, 48px height (touch-friendly)
- Interactions: scale 0.98 on press, 150ms transitions
- Optional disabled state (disables all interactions)
- Reusable button component for all CTA actions across app"
```

---

## Task 8: Update Idle Waiting Screen with Design Tokens & Styling

**Files:**
- Modify: `terminal-frontend/lib/screens/idle_waiting_screen.dart`

**Step 1: Read current idle_waiting_screen.dart**

Run: `head -50 /Users/dg/dev/frgs-vereinsbar/terminal-frontend/lib/screens/idle_waiting_screen.dart`

**Step 2: Rewrite idle_waiting_screen.dart with design tokens**

```dart
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/providers/rfid_provider.dart';
import 'package:ruderbar_terminal/utils/design_tokens.dart';
import 'package:ruderbar_terminal/widgets/rfid_detector_button.dart';

class IdleWaitingScreen extends StatelessWidget {
  const IdleWaitingScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xff0a1628), // Deep navy background
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            child: Padding(
              padding: const EdgeInsets.symmetric(
                vertical: AppSpacing.xxxl,
                horizontal: AppSpacing.lg,
              ),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  // Welcome text
                  Text(
                    'Durstig?',
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                      color: Color(0xfff1f5f9), // Primary text
                      fontSize: AppFontSizes.xxxl,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                  const SizedBox(height: AppSpacing.md),

                  // Subtitle
                  Text(
                    'Halte deine Karte an den Scanner',
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                      color: Color(0xff94a3b8), // Secondary text
                      fontSize: AppFontSizes.base,
                      fontWeight: FontWeight.w400,
                    ),
                  ),
                  const SizedBox(height: AppSpacing.xxxl),

                  // RFID button (glowing effect handled in RfidDetectorButton)
                  const RfidDetectorButton(),
                  const SizedBox(height: AppSpacing.xxxl),

                  // Optional demo button
                  Consumer<RfidProvider>(
                    builder: (context, rfidProvider, child) {
                      return ElevatedButton(
                        onPressed: !rfidProvider.isScanning
                            ? () => rfidProvider.simulateCardDetection(context)
                            : null,
                        style: ElevatedButton.styleFrom(
                          backgroundColor: const Color(0xff3b82f6),
                          disabledBackgroundColor: const Color(0xff334155),
                          padding: const EdgeInsets.symmetric(
                            horizontal: AppSpacing.xl,
                            vertical: AppSpacing.md,
                          ),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(AppBorderRadius.md),
                          ),
                        ),
                        child: const Text(
                          'Demo: Scan Card',
                          style: TextStyle(
                            color: Colors.white,
                            fontSize: AppFontSizes.base,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      );
                    },
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
```

**Step 3: Run tests to verify no regressions**

Run: `cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend && flutter test 2>&1 | tail -20`

Expected: All 202 tests pass

**Step 4: Commit**

```bash
cd /Users/dg/dev/frgs-vereinsbar && git commit -am "feat: style Idle Waiting Screen with design tokens

- Full-height navy background (#0a1628)
- Centered vertical layout: welcome text + subtitle + RFID button + demo button
- Welcome text: xl font (24px), bold, primary text color
- Subtitle: base font (14px), secondary text color
- RFID button: 140px diameter, centered, glowing when scanning
- Demo button: primary blue (#3b82f6), 48px height, full padding
- Padding: 32px vertical, 16px horizontal for safe area
- All design tokens applied consistently"
```

---

## Task 9: Update RfidDetectorButton with Design Tokens & Glowing Animation

**Files:**
- Modify: `terminal-frontend/lib/widgets/rfid_detector_button.dart`

**Step 1: Update RfidDetectorButton with glowing animation**

```dart
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/providers/rfid_provider.dart';
import 'package:ruderbar_terminal/utils/design_tokens.dart';

class RfidDetectorButton extends StatefulWidget {
  const RfidDetectorButton({Key? key}) : super(key: key);

  @override
  State<RfidDetectorButton> createState() => _RfidDetectorButtonState();
}

class _RfidDetectorButtonState extends State<RfidDetectorButton>
    with SingleTickerProviderStateMixin {
  late AnimationController _glowController;
  late Animation<double> _glowAnimation;

  @override
  void initState() {
    super.initState();
    _glowController = AnimationController(
      duration: const Duration(milliseconds: 2000),
      vsync: this,
    )..repeat(reverse: true);

    _glowAnimation = Tween<double>(begin: 15.0, end: 30.0).animate(
      CurvedAnimation(parent: _glowController, curve: Curves.easeInOut),
    );
  }

  @override
  void dispose() {
    _glowController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Consumer<RfidProvider>(
      builder: (context, rfidProvider, child) {
        // Stop animation when not scanning
        if (rfidProvider.isScanning && !_glowController.isAnimating) {
          _glowController.repeat(reverse: true);
        } else if (!rfidProvider.isScanning && _glowController.isAnimating) {
          _glowController.stop();
        }

        return GestureDetector(
          onTap: rfidProvider.isScanning
              ? null
              : () => rfidProvider.simulateCardDetection(context),
          child: AnimatedBuilder(
            animation: _glowAnimation,
            builder: (context, child) {
              return Container(
                width: 140,
                height: 140,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  gradient: LinearGradient(
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                    colors: rfidProvider.isScanning
                        ? [
                            Colors.blue.shade400,
                            Colors.teal.shade300,
                          ]
                        : [
                            Colors.blue.shade200,
                            Colors.teal.shade200,
                          ],
                  ),
                  boxShadow: [
                    BoxShadow(
                      color: const Color(0xff0ea5e9).withOpacity(
                        rfidProvider.isScanning ? 0.6 : 0.3,
                      ),
                      blurRadius: rfidProvider.isScanning ? _glowAnimation.value : 15,
                      spreadRadius: rfidProvider.isScanning ? 5 : 0,
                    ),
                  ],
                ),
                child: Center(
                  child: rfidProvider.isScanning
                      ? SizedBox(
                          width: 60,
                          height: 60,
                          child: CircularProgressIndicator(
                            valueColor: AlwaysStoppedAnimation(
                              Colors.white.withOpacity(0.8),
                            ),
                            strokeWidth: 3,
                          ),
                        )
                      : const Icon(
                          Icons.contactless,
                          size: 60,
                          color: Colors.white,
                        ),
                ),
              );
            },
          ),
        );
      },
    );
  }
}
```

**Step 2: Run tests to verify no regressions**

Run: `cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend && flutter test 2>&1 | tail -20`

Expected: All 202 tests pass

**Step 3: Commit**

```bash
cd /Users/dg/dev/frgs-vereinsbar && git commit -am "feat: update RfidDetectorButton with design tokens and glowing animation

- Shape: circular 140px diameter
- Background: linear gradient (blue → teal)
- Icon: contactless icon, white, 60px
- Scanning state: pulse animation with cyan glow
- Glow animation: 2-second pulse cycle, 15-30px blur radius, 5px spread
- Gradient brightens when scanning
- CircularProgressIndicator overlay during scanning
- Cursor disabled when scanning
- Animation: 150ms default transitions for all changes
- All design tokens applied consistently"
```

---

## Task 10: Update Product Selection Screen

**Files:**
- Modify: `terminal-frontend/lib/screens/product_selection_screen.dart`

**Step 1: Rewrite product_selection_screen.dart using ProductCard and CategoryChip**

```dart
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'dart:convert';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/providers/cart_provider.dart';
import 'package:ruderbar_terminal/providers/products_provider.dart';
import 'package:ruderbar_terminal/providers/auth_provider.dart';
import 'package:ruderbar_terminal/providers/sync_provider.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/utils/design_tokens.dart';
import 'package:ruderbar_terminal/widgets/app_header.dart';
import 'package:ruderbar_terminal/widgets/styled_components/product_card.dart';
import 'package:ruderbar_terminal/widgets/styled_components/category_chip.dart';
import 'package:ruderbar_terminal/widgets/styled_components/member_info_card.dart';

class ProductSelectionScreen extends StatefulWidget {
  const ProductSelectionScreen({super.key});

  @override
  State<ProductSelectionScreen> createState() => _ProductSelectionScreenState();
}

class _ProductSelectionScreenState extends State<ProductSelectionScreen> {
  int _selectedCategoryIndex = 0;

  String _getCategoryName(CategoriesCacheData category) {
    try {
      final names = jsonDecode(category.names) as Map<String, dynamic>;
      return names['de'] ?? 'Category';
    } catch (_) {
      return 'Category';
    }
  }

  @override
  Widget build(BuildContext context) {
    return Consumer3<ProductsProvider, CartProvider, MembersProvider>(
      builder: (context, productsProvider, cartProvider, membersProvider, child) {
        final categories = productsProvider.categories;
        final selectedMember = membersProvider.selectedMember;

        if (categories.isEmpty) {
          return Scaffold(
            backgroundColor: const Color(0xff0a1628),
            body: const Center(
              child: Text(
                'No categories available',
                style: TextStyle(color: Colors.white),
              ),
            ),
          );
        }

        return Scaffold(
          backgroundColor: const Color(0xff0a1628),
          appBar: AppHeader(
            title: 'Products',
            authProvider: context.read<AuthProvider>(),
            syncProvider: context.read<SyncProvider>(),
            membersProvider: context.read<MembersProvider>(),
          ),
          body: SingleChildScrollView(
            child: Column(
              children: [
                // Member info card header
                if (selectedMember != null)
                  Padding(
                    padding: const EdgeInsets.all(AppSpacing.lg),
                    child: MemberInfoCard(
                      member: selectedMember,
                      balanceCents: membersProvider.selectedMemberBalance,
                    ),
                  ),

                // Cart button
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: AppSpacing.lg),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.end,
                    children: [
                      ElevatedButton(
                        onPressed: () {
                          context.go('/cart');
                        },
                        style: ElevatedButton.styleFrom(
                          backgroundColor: const Color(0xff3b82f6),
                        ),
                        child: Text(
                          'Cart (${cartProvider.itemCount})',
                          style: const TextStyle(color: Colors.white),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: AppSpacing.lg),

                // Category tabs (horizontal scrollable)
                SingleChildScrollView(
                  scrollDirection: Axis.horizontal,
                  padding: const EdgeInsets.symmetric(horizontal: AppSpacing.lg),
                  child: Row(
                    children: List.generate(
                      categories.length,
                      (index) => Padding(
                        padding: const EdgeInsets.only(right: AppSpacing.md),
                        child: CategoryChip(
                          category: categories[index],
                          categoryName: _getCategoryName(categories[index]),
                          selected: _selectedCategoryIndex == index,
                          onSelected: () {
                            setState(() {
                              _selectedCategoryIndex = index;
                            });
                          },
                        ),
                      ),
                    ),
                  ),
                ),
                const SizedBox(height: AppSpacing.lg),

                // Product grid (2 columns, portrait optimized)
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: AppSpacing.lg),
                  child: _buildProductGrid(
                    context,
                    categories[_selectedCategoryIndex],
                    productsProvider,
                    cartProvider,
                  ),
                ),
                const SizedBox(height: AppSpacing.xl),
              ],
            ),
          ),
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
      return const Center(
        child: Text(
          'No products in this category',
          style: TextStyle(color: Color(0xff94a3b8)),
        ),
      );
    }

    return GridView.builder(
      padding: EdgeInsets.zero,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 2,
        crossAxisSpacing: AppSpacing.lg,
        mainAxisSpacing: AppSpacing.lg,
        childAspectRatio: 0.85,
      ),
      itemCount: products.length,
      itemBuilder: (context, index) {
        final product = products[index];
        final name = productsProvider.getTranslatedName(product, 'de');

        return ProductCard(
          product: product,
          productName: name,
          onTap: () => cartProvider.addItem(
            product.id,
            name,
            product.priceCents,
            1,
            'de',
          ),
        );
      },
    );
  }
}
```

**Step 2: Run tests to verify no regressions**

Run: `cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend && flutter test 2>&1 | tail -20`

Expected: All 202 tests pass

**Step 3: Commit**

```bash
cd /Users/dg/dev/frgs-vereinsbar && git commit -am "feat: style Product Selection Screen with design tokens and components

- Background: navy (#0a1628)
- Member info card in header with balance/Deckel in cyan
- Category tabs: horizontal scroll, CategoryChip component
  - Selected: blue bg (#3b82f6), white text
  - Unselected: secondary bg (#0f1d32), secondary text
- Product grid: 2 columns (portrait), ProductCard component
  - Icon 64px, product name, cyan price
  - Tap state scales 1.05 with 150ms transition
- Cart button: top-right with item count badge
- Padding: 16px edges, 16px between sections
- All design tokens applied consistently"
```

---

## Task 11: Update Member Details Page

**Files:**
- Modify: `terminal-frontend/lib/screens/member_details_page.dart`

**Step 1: Read current member_details_page.dart**

Run: `head -50 /Users/dg/dev/frgs-vereinsbar/terminal-frontend/lib/screens/member_details_page.dart`

**Step 2: Update member_details_page.dart with design tokens**

```dart
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/utils/design_tokens.dart';
import 'package:ruderbar_terminal/widgets/styled_components/member_info_card.dart';

class MemberDetailsPage extends StatelessWidget {
  const MemberDetailsPage({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xff0a1628),
      appBar: AppBar(
        backgroundColor: const Color(0xff0f1d32),
        title: const Text(
          'Member Info',
          style: TextStyle(
            color: Color(0xfff1f5f9),
            fontSize: AppFontSizes.xl,
            fontWeight: FontWeight.w600,
          ),
        ),
        leading: IconButton(
          icon: const Icon(Icons.arrow_back, color: Color(0xfff1f5f9)),
          onPressed: () => Navigator.of(context).pop(),
        ),
      ),
      body: SafeArea(
        child: Consumer<MembersProvider>(
          builder: (context, membersProvider, child) {
            final member = membersProvider.selectedMember;

            if (member == null) {
              return const Center(
                child: Text(
                  'No member selected',
                  style: TextStyle(color: Color(0xff94a3b8)),
                ),
              );
            }

            return SingleChildScrollView(
              padding: const EdgeInsets.all(AppSpacing.lg),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // Member card
                  MemberInfoCard(
                    member: member,
                    balanceCents: membersProvider.selectedMemberBalance,
                  ),
                  const SizedBox(height: AppSpacing.xl),

                  // Additional info section
                  Text(
                    'Account Information',
                    style: const TextStyle(
                      color: Color(0xfff1f5f9),
                      fontSize: AppFontSizes.lg,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  const SizedBox(height: AppSpacing.lg),

                  // Account status
                  Container(
                    padding: const EdgeInsets.all(AppSpacing.lg),
                    decoration: BoxDecoration(
                      color: const Color(0xff1a2744),
                      borderRadius: BorderRadius.circular(AppBorderRadius.lg),
                      border: Border.all(
                        color: const Color(0xff334155),
                        width: 1,
                      ),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        _buildInfoRow(
                          'Status',
                          member.isActive == 1 ? 'Active' : 'Inactive',
                          member.isActive == 1
                              ? const Color(0xff22c55e)
                              : const Color(0xfff97316),
                        ),
                        const SizedBox(height: AppSpacing.md),
                        _buildInfoRow(
                          'SEPA Valid',
                          member.isSepaValid == 1 ? 'Yes' : 'No',
                          member.isSepaValid == 1
                              ? const Color(0xff22c55e)
                              : const Color(0xfff97316),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            );
          },
        ),
      ),
    );
  }

  Widget _buildInfoRow(String label, String value, Color valueColor) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(
          label,
          style: const TextStyle(
            color: Color(0xff94a3b8),
            fontSize: AppFontSizes.base,
            fontWeight: FontWeight.w500,
          ),
        ),
        Text(
          value,
          style: TextStyle(
            color: valueColor,
            fontSize: AppFontSizes.base,
            fontWeight: FontWeight.w600,
          ),
        ),
      ],
    );
  }
}
```

**Step 3: Run tests to verify no regressions**

Run: `cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend && flutter test 2>&1 | tail -20`

Expected: All 202 tests pass

**Step 4: Commit**

```bash
cd /Users/dg/dev/frgs-vereinsbar && git commit -am "feat: style Member Details Page with design tokens

- Background: navy (#0a1628)
- AppBar: secondary bg (#0f1d32), white text, back button
- Member info card: orange avatar, name, balance, language
- Account information section with styled container
- Status/SEPA fields with color-coded values
- Colors: green (#22c55e) for active/valid, orange (#f97316) for inactive/invalid
- Padding: 16px edges, 16px vertical gaps
- All design tokens applied consistently"
```

---

## Task 12: Update Shopping Cart Screen

**Files:**
- Modify: `terminal-frontend/lib/screens/shopping_cart_screen.dart`

**Step 1: Read and update shopping_cart_screen.dart**

```dart
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/providers/cart_provider.dart';
import 'package:ruderbar_terminal/utils/design_tokens.dart';
import 'package:ruderbar_terminal/widgets/styled_components/action_button.dart';
import 'package:ruderbar_terminal/widgets/styled_components/price_display.dart';
import 'package:ruderbar_terminal/utils/icon_registry.dart';

class ShoppingCartScreen extends StatelessWidget {
  const ShoppingCartScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xff0a1628),
      appBar: AppBar(
        backgroundColor: const Color(0xff0f1d32),
        title: const Text(
          'Cart',
          style: TextStyle(
            color: Color(0xfff1f5f9),
            fontSize: AppFontSizes.xl,
            fontWeight: FontWeight.w600,
          ),
        ),
        leading: IconButton(
          icon: const Icon(Icons.arrow_back, color: Color(0xfff1f5f9)),
          onPressed: () => context.go('/products'),
        ),
      ),
      body: Consumer<CartProvider>(
        builder: (context, cartProvider, child) {
          if (cartProvider.items.isEmpty) {
            return const Center(
              child: Text(
                'Your cart is empty',
                style: TextStyle(
                  color: Color(0xff94a3b8),
                  fontSize: AppFontSizes.lg,
                ),
              ),
            );
          }

          final totalCents = cartProvider.items.fold<int>(
            0,
            (sum, item) => sum + (item.priceCents * item.quantity),
          );

          return Column(
            children: [
              // Item list
              Expanded(
                child: ListView.builder(
                  padding: const EdgeInsets.all(AppSpacing.lg),
                  itemCount: cartProvider.items.length,
                  itemBuilder: (context, index) {
                    final item = cartProvider.items[index];
                    final icon = getProductIcon(item.productIconName);

                    return Container(
                      margin: const EdgeInsets.only(bottom: AppSpacing.md),
                      padding: const EdgeInsets.all(AppSpacing.md),
                      decoration: BoxDecoration(
                        color: const Color(0xff1a2744),
                        borderRadius: BorderRadius.circular(AppBorderRadius.lg),
                        border: Border.all(
                          color: const Color(0xff334155),
                          width: 1,
                        ),
                      ),
                      child: Row(
                        children: [
                          // Icon
                          Icon(
                            icon,
                            size: 40,
                            color: const Color(0xff0ea5e9),
                          ),
                          const SizedBox(width: AppSpacing.md),

                          // Name and quantity
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  item.productName,
                                  style: const TextStyle(
                                    color: Color(0xfff1f5f9),
                                    fontSize: AppFontSizes.base,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                                const SizedBox(height: AppSpacing.xs),
                                Text(
                                  'Qty: ${item.quantity}',
                                  style: const TextStyle(
                                    color: Color(0xff94a3b8),
                                    fontSize: AppFontSizes.sm,
                                  ),
                                ),
                              ],
                            ),
                          ),

                          // Price
                          Column(
                            crossAxisAlignment: CrossAxisAlignment.end,
                            children: [
                              PriceDisplay(
                                priceCents: item.priceCents * item.quantity,
                                fontSize: PriceDisplay.FontSize.medium,
                              ),
                              const SizedBox(height: AppSpacing.xs),
                              GestureDetector(
                                onTap: () =>
                                    cartProvider.removeItem(item.productId),
                                child: const Text(
                                  'Remove',
                                  style: TextStyle(
                                    color: Color(0xffef4444),
                                    fontSize: AppFontSizes.sm,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ],
                      ),
                    );
                  },
                ),
              ),

              // Total section (sticky bottom)
              Container(
                decoration: const BoxDecoration(
                  border: Border(
                    top: BorderSide(
                      color: Color(0xff334155),
                      width: 1,
                    ),
                  ),
                ),
                padding: const EdgeInsets.all(AppSpacing.lg),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text(
                          'Total:',
                          style: TextStyle(
                            color: Color(0xfff1f5f9),
                            fontSize: AppFontSizes.lg,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                        PriceDisplay(
                          priceCents: totalCents,
                          fontSize: PriceDisplay.FontSize.large,
                        ),
                      ],
                    ),
                    const SizedBox(height: AppSpacing.md),

                    // Checkout button
                    ActionButton(
                      label: 'Proceed to Checkout',
                      onPressed: () => context.go('/checkout'),
                      buttonStyle: ActionButton.ButtonStyle.primary,
                      fullWidth: true,
                    ),
                    const SizedBox(height: AppSpacing.sm),

                    // Back to products button
                    ActionButton(
                      label: 'Back to Products',
                      onPressed: () => context.go('/products'),
                      buttonStyle: ActionButton.ButtonStyle.secondary,
                      fullWidth: true,
                    ),
                  ],
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}
```

**Step 2: Run tests to verify no regressions**

Run: `cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend && flutter test 2>&1 | tail -20`

Expected: All 202 tests pass

**Step 3: Commit**

```bash
cd /Users/dg/dev/frgs-vereinsbar && git commit -am "feat: style Shopping Cart Screen with design tokens

- Background: navy (#0a1628)
- AppBar: secondary bg (#0f1d32), white text, back button
- Item list: each item in card with icon, name, qty, price, remove button
- Card style: card bg (#1a2744), border light (#334155), 12px padding
- Remove button: red (#ef4444), clickable text
- Total section: sticky bottom with border top
  - Total: cyan (#0ea5e9), xl font, bold
- Buttons: stacked at bottom
  - Checkout: primary blue (#3b82f6), full-width, 56px height
  - Back: secondary style, full-width, 48px height
- PriceDisplay component used for all prices
- All design tokens applied consistently"
```

---

## Task 13: Update Checkout Confirmation Screen

**Files:**
- Modify: `terminal-frontend/lib/screens/checkout_confirmation_screen.dart`

**Step 1: Read and update checkout_confirmation_screen.dart**

```dart
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/providers/cart_provider.dart';
import 'package:ruderbar_terminal/utils/design_tokens.dart';
import 'package:ruderbar_terminal/widgets/styled_components/price_display.dart';

class CheckoutConfirmationScreen extends StatefulWidget {
  const CheckoutConfirmationScreen({super.key});

  @override
  State<CheckoutConfirmationScreen> createState() =>
      _CheckoutConfirmationScreenState();
}

class _CheckoutConfirmationScreenState extends State<CheckoutConfirmationScreen> {
  @override
  void initState() {
    super.initState();
    // Auto-dismiss after 3 seconds
    Future.delayed(const Duration(seconds: 3), () {
      if (mounted) {
        context.go('/idle');
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xff0a1628),
      body: Consumer2<MembersProvider, CartProvider>(
        builder: (context, membersProvider, cartProvider, child) {
          final member = membersProvider.selectedMember;
          final totalCents = cartProvider.items.fold<int>(
            0,
            (sum, item) => sum + (item.priceCents * item.quantity),
          );
          final transactionRef = DateTime.now().millisecondsSinceEpoch.toString();

          return SafeArea(
            child: Center(
              child: SingleChildScrollView(
                padding: const EdgeInsets.symmetric(
                  horizontal: AppSpacing.lg,
                  vertical: AppSpacing.xxxl,
                ),
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    // Success checkmark
                    Container(
                      width: 80,
                      height: 80,
                      decoration: const BoxDecoration(
                        shape: BoxShape.circle,
                        color: Color(0xff22c55e),
                      ),
                      child: const Icon(
                        Icons.check,
                        size: 48,
                        color: Colors.white,
                      ),
                    ),
                    const SizedBox(height: AppSpacing.xl),

                    // Success text
                    const Text(
                      'Payment Successful',
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        color: Color(0xfff1f5f9),
                        fontSize: AppFontSizes.xxxl,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                    const SizedBox(height: AppSpacing.lg),

                    // Member name
                    if (member != null)
                      Text(
                        '${member.firstName} ${member.lastName}',
                        textAlign: TextAlign.center,
                        style: const TextStyle(
                          color: Color(0xff94a3b8),
                          fontSize: AppFontSizes.lg,
                          fontWeight: FontWeight.w500,
                        ),
                      ),
                    const SizedBox(height: AppSpacing.xl),

                    // Total amount
                    Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        const Text(
                          'Total: ',
                          style: TextStyle(
                            color: Color(0xfff1f5f9),
                            fontSize: AppFontSizes.xl,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                        PriceDisplay(
                          priceCents: totalCents,
                          fontSize: PriceDisplay.FontSize.large,
                        ),
                      ],
                    ),
                    const SizedBox(height: AppSpacing.lg),

                    // Transaction reference
                    Container(
                      padding: const EdgeInsets.all(AppSpacing.md),
                      decoration: BoxDecoration(
                        color: const Color(0xff0f1d32),
                        borderRadius: BorderRadius.circular(AppBorderRadius.md),
                        border: Border.all(
                          color: const Color(0xff334155),
                          width: 1,
                        ),
                      ),
                      child: Column(
                        children: [
                          const Text(
                            'Transaction Reference',
                            style: TextStyle(
                              color: Color(0xff64748b),
                              fontSize: AppFontSizes.sm,
                              fontWeight: FontWeight.w500,
                            ),
                          ),
                          const SizedBox(height: AppSpacing.xs),
                          Text(
                            transactionRef,
                            textAlign: TextAlign.center,
                            style: const TextStyle(
                              color: Color(0xfff1f5f9),
                              fontSize: AppFontSizes.base,
                              fontFamily: 'monospace',
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: AppSpacing.xl),

                    // Balance after transaction
                    if (member != null)
                      Text(
                        'Balance: €${(membersProvider.selectedMemberBalance / 100).toStringAsFixed(2)}',
                        textAlign: TextAlign.center,
                        style: TextStyle(
                          color: membersProvider.selectedMemberBalance >= 0
                              ? const Color(0xff22c55e)
                              : const Color(0xfff97316),
                          fontSize: AppFontSizes.lg,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    const SizedBox(height: AppSpacing.xxxl),

                    // Auto-dismiss message
                    const Text(
                      'Redirecting in 3 seconds...',
                      style: TextStyle(
                        color: Color(0xff64748b),
                        fontSize: AppFontSizes.sm,
                        fontWeight: FontWeight.w400,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          );
        },
      ),
    );
  }
}
```

**Step 2: Run tests to verify no regressions**

Run: `cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend && flutter test 2>&1 | tail -20`

Expected: All 202 tests pass

**Step 3: Commit**

```bash
cd /Users/dg/dev/frgs-vereinsbar && git commit -am "feat: style Checkout Confirmation Screen with design tokens

- Background: navy (#0a1628)
- Success state: green checkmark (48px, #22c55e), centered
- Success text: xl font (24px), bold, white, 'Payment Successful'
- Member name: lg font, secondary text color
- Total amount: cyan (#0ea5e9), xl font, bold
- Transaction reference: in card with border, monospace font
- Balance: color-coded (green for positive, orange for negative)
- Auto-dismiss: 3-second delay with countdown message
- Centered vertical layout, 32px top/bottom padding
- All design tokens applied consistently"
```

---

## Final Verification

**Step 1: Run full test suite to verify no regressions**

Run: `cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend && flutter test 2>&1 | tail -30`

Expected: All 202 tests pass (exact count shown at end)

**Step 2: Visual inspection on desktop**

Run: `cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend && flutter run -d chrome`

Verify:
- [ ] Idle screen: navy background, centered welcome text, glowing RFID button
- [ ] Product selection: member card header, category tabs (blue selected), 2-column product grid with cyan prices
- [ ] Member details: member card, account info section
- [ ] Cart: item list with prices, total in cyan, checkout button
- [ ] Checkout: green checkmark, member name, cyan total, transaction ref, auto-dismiss

**Step 3: Compare to prototype**

- [ ] Colors match (navy #0a1628, cyan #0ea5e9, orange #f97316, etc.)
- [ ] Icons render correctly
- [ ] Touch targets appear >= 48px
- [ ] Animations smooth and responsive

**Step 4: Commit final verification**

```bash
cd /Users/dg/dev/frgs-vereinsbar && git log --oneline | head -15
```

Verify 13 commits created (1 design tokens + 1 icon registry + 5 components + 1 RfidButton + 5 screens)

