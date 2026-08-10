# UC-T01: Book Product to Tab

**Implementation Status**: Implemented

## Actor
Member

## Preconditions
- Member has valid RFID card
- Member exists in local cache
- Member is active
- Member has an **active SEPA mandate** (see [ADR-0006](../../adr/0006-sepa-mandate-reference-strategy.md), amended: a mandate is one record carrying reference, IBAN **and signature date**)

## Trigger
Member scans RFID card

## Main Flow
1. Member scans RFID card
2. System displays greeting with member name and current tab balance
3. System shows product view with category tabs
4. Member taps category tab to browse products
5. Member taps product tile to add to cart
6. Product tile shows quantity badge (1)
7. Summary bar appears at the bottom of the product view with the running total and a "Buy" button
8. Balance preview updates to reflect cart contents
9. Member taps same or different products to add more; the running total grows with each tap
10. Member taps "Buy" in the summary bar to confirm purchase
11. System creates transactions for all cart items
12. System displays confirmation with new tab balance
13. Member chooses: "Done" (logout) or "Continue Shopping"

The shopping cart view (UC-T11) is an optional detour for reviewing or removing
items, not a step on the way to paying.

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

### Summary Bar

Shown at the bottom of the product view **whenever the cart is not empty**, and
absent otherwise. It carries the running total so the member can watch what they
are about to spend without leaving the grid.

| Element | Description |
|---------|-------------|
| Total label | "Total" |
| Running total | Sum of all line items, updated on every tap |
| New balance | Current tab + running total (preview) |
| View cart | Secondary button; opens the cart view for review and removal |
| Buy button | Primary button; confirms the purchase from here (same three states as the cart view's — normal, processing, blocked by limit) |

**Rules:**
- The credit limit blocks "Buy" here exactly as it does in the cart view (UC-T12)
- While a checkout is in flight the product grid is frozen and "View cart" is inert, so no tile can be added to a cart that has already been billed
- A checkout cancelled from here is acknowledged by an inline, dismissible banner — not a modal

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

### V7: Checkout Without Visiting the Cart
1. Member taps two products
2. Summary bar shows the running total
3. Member taps "Buy" in the summary bar
4. Transactions are created and the confirmation view is shown
5. The cart view was never opened

### V8: Review Before Paying
1. Member taps "View cart" in the summary bar
2. Cart view opens with the items listed
3. Member removes an item
4. Member returns to the product view
5. Summary bar shows the reduced total

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

### E5: No Active SEPA Mandate
- Member found but `is_sepa_valid = false` — **no active mandate**
- Display a message that says what happened **and what to do about it**. At an unstaffed bar a bare refusal becomes a call to the Kassenwart; the wording matters more here than in a staffed venue.

> ⚠️ **The terminal decides from its last sync.** A member whose mandate ends after that sync is still served until the terminal next syncs. Those drinks are real and already consumed, so the **server stores and flags them — it must never reject them** ([ADR-0020](../../adr/0020-sepa-mandate-requirement-terminal-access.md), amended). Two layers: the terminal blocks preventively, where refusal costs nothing because the drink is not yet poured; the server backstops, where it is.

> A member locked out by a returned direct debit is restored by a `bank_transfer` settlement ([UC-A35](../admin/UC-A35-manual-settlement.md)).
- No product selection allowed
- Member must contact admin to set up payment details
- See [UC-T12](./UC-T12-error-scenarios.md) for details

## Test Derivation
- Summary bar hidden on empty cart: scan, verify no bar; add an item, verify bar appears
- Running total grows: tap the same product twice, verify the bar's total doubles
- Checkout from the product view: add items, tap "Buy" in the bar, verify confirmation without opening the cart
- View cart from the bar: tap "View cart", verify the cart view opens
- Limit blocks the bar's "Buy": exceed the limit, verify the button is disabled and labelled accordingly
- Grid frozen mid-checkout: start a checkout, tap a tile, verify nothing is added
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
- No mandate: scan card of a member with no active mandate, verify the message names the remedy and no product view opens
- IBAN alone is not enough: a member with an IBAN but no signed mandate must be refused — this is the case the old predicate wrongly admitted
- Inactive category: deactivate category, verify tab hidden on terminal
- Inactive category products: products in inactive category hidden even if product is active
- Empty category: category with no active products not shown as tab
