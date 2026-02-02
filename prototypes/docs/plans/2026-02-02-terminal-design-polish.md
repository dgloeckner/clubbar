# Terminal Frontend Design Polish

> **Status**: Design Validated & Ready for Implementation
> **For Claude**: Use superpowers:writing-plans to create detailed implementation plan, then superpowers:subagent-driven-development to execute

**Goal:** Transform the Terminal POS app from functional to production-ready with modern, playful design that matches the prototype aesthetic, optimized for Raspberry Pi touchscreen deployment.

**Design Aesthetic:** Modern/Playful with bold colors, emoji-driven product icons, energetic interactions, dark theme (deep navy background), and premium visual polish.

**Architecture:** Hybrid component approach — create reusable styled components for complex elements (ProductCard, CategoryChip, MemberInfoCard), apply design tokens consistently across all screens, maintain existing Provider/Router architecture unchanged.

**Tech Stack:** Flutter, Provider, Go Router, Material 3, custom icon system mirroring admin-frontend patterns

---

## Design System (From Admin Frontend)

### Color Palette
- **Primary Background**: `#0a1628` (deep navy)
- **Secondary Background**: `#0f1d32`
- **Card Background**: `#1a2744`
- **Semantic Colors**:
  - Primary (blue): `#3b82f6` — buttons, primary actions
  - Success (green): `#22c55e` — positive balance, success states
  - Warning (orange): `#f97316` — member avatar, balance warnings
  - Danger (red): `#ef4444` — errors, removal actions
  - Info (cyan): `#0ea5e9` — prices, information highlights
- **Text Colors**:
  - Primary: `#f1f5f9`
  - Secondary: `#94a3b8`
  - Muted: `#64748b`
- **Borders**: `#334155` (light), `#1e293b` (dark)

### Typography
- **Font Family**: System default (-apple-system, Segoe UI, Roboto)
- **Font Sizes**: xs (12px), sm (13px), base (14px), lg (16px), xl (18px), 2xl (20px), 3xl (24px)
- **Font Weights**: normal (400), medium (500), semibold (600), bold (700)
- **Line Heights**: tight (1.2), normal (1.5), relaxed (1.75)

### Spacing & Layout
- **Scale**: xs (4px), sm (8px), md (12px), lg (16px), xl (20px), 2xl (24px), 3xl (32px)
- **Border Radius**: sm (8px), md (12px), lg (16px), xl (20px), full (9999px)
- **Shadows**: sm, md, lg, xl, modal (for depth)

### Animations
- **Fast**: 100ms ease-in-out
- **Default**: 150ms ease-in-out
- **Slow**: 200ms ease-in-out

### Touch Optimization
- **Minimum Touch Target**: 48px (buttons, cards, chips)
- **Padding**: 16px–24px for comfortable touch
- **Spacing**: Generous vertical gaps (16–24px) for portrait layout
- **Orientation**: Portrait-first design (testable on desktop)

---

## Icon System

**Pattern**: Mirror admin-frontend IconRegistry — map icon names from database to Flutter widgets

**Product Icons** (13 available):
- Beverages: PilsIcon, WeizenIcon, BeerAFIcon, RadlerIcon, LemonadeIcon, AppleJuiceIcon, ApplerIcon
- Liquids: WaterLargeIcon, WaterSmallIcon
- Sauna: SaunaTokenIcon, SaunaThermometerIcon, SaunaTimeIcon, SaunaCabinIcon
- Fallback: PackageIcon

**Category Icons** (5 available):
- CategoryIcon, CategoryTagsIcon, CategoryLayersIcon, CategoryFolderIcon, CategoryListIcon
- Fallback: CategoryIcon

**Implementation**:
- Create `lib/utils/icon_registry.dart` with type-safe icon lookup functions
- Support dynamic sizing (48px–64px for products, 32px–40px for categories)
- Render as Material icons, custom SVG widgets, or emoji as appropriate

---

## Styled Components

### ProductCard
- **Layout**: Icon (64px) centered, product name (base font, semibold), price (cyan #0ea5e9, bold, lg font)
- **Interaction**: Tap state scales to 1.05, subtle shadow highlight, 150ms transition
- **Touch Target**: Minimum 120px × 160px
- **Colors**: Card bg (#1a2744), white text, cyan price
- **Icon**: Rendered from product.iconName via getProductIcon()

### CategoryChip
- **Layout**: Icon (32px–40px) + label text
- **Selected State**: Blue background (#3b82f6), white text, semibold
- **Unselected State**: Secondary bg (#0f1d32), secondary text (#94a3b8)
- **Touch Target**: Minimum 56px height
- **Interaction**: 150ms transition, clear selection feedback

### MemberInfoCard
- **Layout**: Orange gradient avatar (48px), member first/last name (lg font, semibold), balance display (green/orange based on sign), language indicator (small, muted)
- **Avatar Gradient**: Linear gradient (orange #f97316 → light orange #fb923c)
- **Balance Colors**: Positive (green #22c55e), negative (orange #f97316), zero (secondary text)
- **Card Style**: Secondary bg (#0f1d32), border light (#334155), 16px padding

### RfidDetectorButton
- **Shape**: Circular 140px diameter
- **Background**: Linear gradient (blue → teal)
- **Icon**: Contactless icon, white, 60px
- **Scanning State**: Pulse animation with cyan glow (30px blur, 5px spread), CircularProgressIndicator overlay, gradient brightens
- **Interaction**: Clickable when not scanning, disabled cursor when scanning
- **Animation**: 150ms default transitions

### PriceDisplay
- **Color**: Cyan (#0ea5e9), bold weight (700)
- **Font Size**: lg (16px) to xl (18px)
- **Format**: EUR symbol + amount (e.g., "€3.50")
- **Variant**: Can be inline or full-width depending on context

### ActionButton
- **Size**: Full-width OR fixed 120px, 48px height (touch-friendly)
- **Primary Style**: Blue background (#3b82f6), white text, semibold
- **Hover/Press**: Box shadow on hover, scale 0.98 on press
- **Secondary Style**: Secondary bg (#0f1d32), secondary text, similar sizing
- **Transition**: 150ms default

---

## Screen Designs (Touch-Optimized Portrait)

### 1. Idle Waiting Screen
- **Background**: Full-height navy (#0a1628)
- **Layout**: Centered vertical stack
  - Emoji/text welcome message ("Durstig?" / "Thirsty?")
  - Subtitle description text (secondary text color, sm font)
  - Large glowing RFID button (140px diameter, centered)
  - Optional demo/test button below
- **Padding**: 32px top/bottom
- **Animations**: Button glows with 2-3 second pulse cycle when scanning

### 2. Product Selection Screen
- **Header**:
  - Member info card (orange avatar, name, balance/Deckel amount in cyan)
  - Top-right: Cart button with orange badge showing item count
  - Refresh icon button (bottom-right or header area)
- **Category Tabs**:
  - Horizontal scrollable row
  - 56px height (touch-friendly)
  - Icon (32px) + label text
  - Selected: blue bg, white text
  - Unselected: secondary bg, secondary text
- **Product Grid**:
  - 2 columns (portrait tablet optimization)
  - 16px gaps between cards
  - ProductCard component (icon 64px, name, cyan price)
  - Tap state: scale 1.05, shadow highlight, 150ms transition
- **Overall Padding**: 16px edges, 16px between sections

### 3. Member Details Page
- **Header**: Back button, "Member Info" title
- **Member Card**:
  - Avatar (48px orange gradient), name (xl font, semibold)
  - Fields: preferred language, balance (color-coded), Deckel/account status
  - Read-only display, subtle borders, secondary background
- **Layout**: Centered card with padding, no interactive elements
- **Spacing**: 16px–24px vertical gaps

### 4. Shopping Cart Screen
- **Header**: "Cart" title, item count (badge)
- **Item List**:
  - Horizontal scroll per item (icon, name, quantity, price)
  - Remove button per item (red #ef4444, small, right side)
  - 12px padding per row, 8px gaps
- **Total Section** (sticky bottom or scrollable):
  - "Total: €XX.XX" (cyan #0ea5e9, xl font, bold)
  - Transaction reference (muted text, sm font)
  - Spacing: 16px top border (light #334155)
- **Buttons** (bottom, stacked):
  - Checkout: Full-width, 56px height, primary blue (#3b82f6)
  - Back to Products: Full-width, 48px height, secondary style
  - 8px gap between buttons
- **Padding**: 16px edges, 24px bottom (safe area)

### 5. Checkout Confirmation Screen
- **Success State**:
  - Green checkmark icon (48px, #22c55e)
  - "Payment Successful" text (xl font, bold, white)
  - Member name (lg font, secondary text)
  - Total amount (cyan #0ea5e9, xl font, bold)
  - Transaction reference (muted text, sm font, monospace)
  - Balance after transaction (color-coded: green/orange)
- **Layout**: Centered vertical stack, 32px top/bottom padding
- **Auto-Dismiss**: 3-second delay, then navigate to idle screen OR show "Back to Products" button
- **Background**: Navy (#0a1628)
- **Colors**: Green checkmark, cyan total, white primary text, secondary/muted for details

---

## Implementation Structure

### Files to Create
- `lib/utils/design_tokens.dart` — Colors, spacing, typography constants
- `lib/utils/icon_registry.dart` — Icon lookup functions (getProductIcon, getCategoryIcon)
- `lib/widgets/styled_components/product_card.dart` — ProductCard component
- `lib/widgets/styled_components/category_chip.dart` — CategoryChip component
- `lib/widgets/styled_components/member_info_card.dart` — MemberInfoCard component
- `lib/widgets/styled_components/price_display.dart` — Reusable price widget
- `lib/widgets/styled_components/action_button.dart` — Reusable button component

### Files to Update
- `lib/widgets/rfid_detector_button.dart` — Apply design tokens, glowing animation
- `lib/screens/idle_waiting_screen.dart` — Navy background, centered layout, glowing button
- `lib/screens/product_selection_screen.dart` — Use ProductCard, CategoryChip, design tokens
- `lib/screens/member_details_page.dart` — Use MemberInfoCard, design tokens
- `lib/screens/shopping_cart_screen.dart` — Use design tokens, layout styling, total section
- `lib/screens/checkout_confirmation_screen.dart` — Success state styling, auto-dismiss

### No Changes Required
- `lib/providers/*` — Provider logic unchanged
- `lib/config/app_router.dart` — Navigation unchanged
- `lib/main.dart` — Initialization unchanged
- All tests — 202 tests remain passing (styling is cosmetic)

---

## Testing & Validation

### Test Coverage
- All 202 existing tests remain passing (no logic changes)
- No new test logic required (styling changes only)
- Widget tests can optionally verify visual properties (button sizes, colors), but not mandatory
- E2E tests verify full flow still works: RFID scan → product selection → checkout

### Validation Checkpoints
1. ✓ Colors match design system (navy, cyan, orange, etc.)
2. ✓ Icons render correctly from IconRegistry
3. ✓ Touch targets minimum 48px (buttons, cards, chips)
4. ✓ RFID button glows and animates when scanning
5. ✓ Product cards display icons at 64px with names and cyan prices
6. ✓ Member avatar displays orange gradient
7. ✓ Transitions are smooth (150ms default, 100ms fast)
8. ✓ Screens display correctly in portrait orientation
9. ✓ All 202 tests passing
10. ✓ Desktop preview shows modern/playful design matching prototype

### Development Approach
1. Create design_tokens.dart and icon_registry.dart (no tests required)
2. Build each styled component individually (ProductCard → CategoryChip → MemberInfoCard → etc.)
3. Update screens one-by-one, running full test suite after each
4. Commit after each component/screen (frequent commits)
5. Final verification: visual inspection on desktop, test suite passes, comparison to prototype

---

## Success Criteria

- [x] Design specification complete and validated
- [ ] All styled components created and implemented
- [ ] All 5 screens updated with design tokens and new components
- [ ] 202 tests passing (no regressions)
- [ ] Desktop preview shows polished, modern/playful design
- [ ] Touch targets verified for Raspberry Pi usability (48px minimum)
- [ ] Icon system working (products and categories display correct icons)
- [ ] Colors, typography, spacing, and animations match design system
- [ ] Animations smooth and responsive (150ms transitions, glowing RFID button)

---

## Next Steps

Ready to proceed with implementation? Choose one:

1. **Subagent-Driven (This Session)** — I dispatch fresh subagent per task, code review between tasks, fast iteration in this session
2. **Parallel Session** — You open new session with `superpowers:executing-plans` for batch execution with checkpoints

**Recommendation**: Subagent-Driven approach keeps momentum and catches issues early with code review after each task.
