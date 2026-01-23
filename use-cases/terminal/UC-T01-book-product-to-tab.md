# UC-T01: Book Product to Tab

## Actor
Member

## Preconditions
- Member has valid RFID card
- Member exists in local cache
- Member is active
- Member has valid SEPA data (IBAN and mandate reference)

## Trigger
Member scans RFID card

## Main Flow
1. Member scans RFID card
2. System displays greeting with member name and current tab balance
3. System shows product view with category tabs
4. Member taps category tab to browse products
5. Member taps product tile to add to cart
6. Product tile shows quantity badge (1)
7. Balance preview updates to reflect cart contents
8. Member taps same or different products to add more
9. Member navigates to shopping cart
10. Member reviews cart items and total
11. Member taps "Buy" to confirm purchase
12. System creates transactions for all cart items
13. System displays confirmation with new tab balance
14. Member chooses: "Done" (logout) or "Continue Shopping"

## Postconditions
- Transactions recorded locally (one per line item)
- Tab balance increased by cart total
- Transactions queued for backend sync
- Shopping cart cleared

## Product View Layout

### Category Tabs

Only **active categories** are shown. Sorted by `display_order`.

| Tab | Examples |
|-----|----------|
| Beer | 0.3L, 0.5L, Craft |
| Soft Drinks | Cola, Fanta, Sprite |
| Water | Still, Sparkling |
| Coffee | Espresso, Cappuccino |
| Snacks | Chips, Nuts |

**Filtering Rules:**
- Category must have `is_active = true`
- Category must have at least one active product

### Product Tile Display

Only **active products in active categories** are shown.

| State | Display |
|-------|---------|
| Not in cart | Product name, price |
| In cart (qty > 0) | Product name, price, quantity badge |
| Inactive product | Hidden |
| Product in inactive category | Hidden |

### Navigation Elements
| Element | Action |
|---------|--------|
| Category tab | Switch to category's products |
| Product tile | Add to cart / increment quantity |
| Cart button | Navigate to shopping cart view |
| Cart badge | Shows total items in cart |
| Balance display | Shows current + preview balance |

## Shopping Cart Behavior

| Event | Cart Action |
|-------|-------------|
| New user scans card | Cart flushed |
| Checkout completed | Cart flushed |
| Navigate to products after checkout | Cart flushed |
| Session timeout | Cart flushed, user logged out |
| Tap product | Quantity incremented |
| Remove in cart view | Quantity decremented or item removed |

## Variants

### V1: Add Same Product Multiple Times
1. Member taps product tile
2. Badge shows "1"
3. Member taps same product again
4. Badge shows "2"
5. Each tap increments quantity

### V2: Add Products from Multiple Categories
1. Member adds product from "Beer" category
2. Member taps "Soft Drinks" tab
3. Member adds product from "Soft Drinks"
4. Both products in cart with respective quantities

### V3: Add, Remove, Add Cycle
1. Member adds Product A (qty: 1)
2. Member navigates to cart
3. Member removes Product A (qty: 0, removed)
4. Member returns to product view
5. Product A tile shows no badge
6. Member adds Product A again (qty: 1)
7. Member adds Product B (qty: 1)
8. Checkout creates 2 transactions

### V4: Remove and Re-add Same Product
1. Member adds Product A twice (qty: 2)
2. Member navigates to cart
3. Member decrements Product A (qty: 1)
4. Member returns to product view
5. Product A badge shows "1"
6. Member taps Product A (qty: 2)
7. Badge updates to "2"

### V5: Continue Shopping After Purchase
1. After checkout, member taps "Continue Shopping"
2. Cart is flushed (empty)
3. Member returns to product view
4. All product badges cleared
5. Member can start new purchase

### V6: No Action (View Only)
1. Member views products without adding any
2. Session times out
3. No transaction created

## UI Requirements
- Touch-optimized: minimum 48x48px tap targets
- Visual feedback on tap (highlight/animation)
- Price visible on each tile
- Product name in member's preferred language
- Quantity badge: small circle with number (top-right of tile)
- Category tabs: horizontally scrollable if many categories
- Balance preview updates immediately on each add

## Error Cases

### E1: Unknown Card
- Card UID not found in local cache
- Display "Unknown card" message
- Return to idle after 5 seconds

### E2: Inactive Member
- Member found but `is_active = false`
- Display "Account inactive" message
- No product selection allowed

### E3: Empty Cart Checkout
- Member taps "Buy" with empty cart
- Button disabled or shows "Cart empty"

### E4: Balance Limit Exceeded
- Cart total would cause balance to exceed configured maximum
- Warning banner displayed in cart view
- "Buy" button disabled
- Member must remove items to proceed
- See [UC-T12](./UC-T12-error-scenarios.md) for details

### E5: SEPA Mandate Invalid
- Member found but `is_sepa_valid = false` (missing IBAN or mandate reference)
- Display "SEPA mandate missing or invalid" message
- No product selection allowed
- Member must contact admin to set up payment details
- See [UC-T12](./UC-T12-error-scenarios.md) for details

## Test Derivation
- Happy path: scan, add product, checkout, verify transaction
- Add multiple different products: add 3 products, checkout, verify 3 transactions
- Add same product multiple times: tap product 3x, verify badge shows "3", checkout creates 1 transaction with qty 3
- Category navigation: add from Beer, switch to Snacks, add, verify both in cart
- Add-remove-add: add product, remove in cart, return, add again, verify works
- Remove partial: add 3x, remove 1x in cart, verify badge shows "2"
- Continue shopping: checkout, continue, add new items, verify fresh cart
- Badge cleared after checkout: checkout, continue, verify all badges reset
- New user clears cart: user A adds items, user B scans, verify cart flushed
- Timeout without checkout: add items, wait for timeout, verify no transactions
- Balance preview: add items, verify preview = current balance + cart total
- Product language: verify names display in member's preferred language
- SEPA invalid: scan card of member without IBAN, verify error message and no product view
- SEPA invalid (no mandate): scan card of member with IBAN but no mandate_reference, verify error
- Inactive category: deactivate category, verify tab hidden on terminal
- Inactive category products: products in inactive category hidden even if product is active
- Empty category: category with no active products not shown as tab
