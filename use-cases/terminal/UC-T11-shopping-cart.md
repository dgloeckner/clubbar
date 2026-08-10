# UC-T11: Shopping Cart Management

**Implementation Status**: Implemented

## Actor
Member

## Preconditions
- Member is identified (RFID scanned)
- Member has added items to cart (UC-T01)

## Trigger
Member taps the cart button, or "View cart" in the product view's summary bar,
to review items before purchase

> The cart view is **not** on the way to paying: the product view's summary bar
> totals the cart and checks out directly (UC-T01). This view exists for what
> the grid cannot do — seeing the whole order at once and removing a line
> outright. Both surfaces run the same checkout and enforce the same credit
> limit.

## Main Flow
1. Member taps cart button in product view
2. System displays shopping cart view
3. Cart shows list of items with quantities and prices
4. Cart shows total amount
5. Cart shows balance preview (current balance + total)
6. Member reviews items
7. Member taps "Buy" to confirm purchase
8. System creates transactions for all items
9. System displays confirmation with new balance
10. Member chooses "Done" or "Continue Shopping"

## Postconditions
- Transactions recorded (one per line item)
- Tab balance updated
- Shopping cart cleared

## Cart View Elements

| Element | Description |
|---------|-------------|
| Item list | Product name, quantity, line total |
| Quantity controls | +/- buttons per item |
| Remove button | Delete item from cart |
| Cart total | Sum of all line items |
| Current balance | Member's existing tab balance |
| New balance | Current + cart total (preview) |
| Balance limit | Maximum allowed balance (from config) |
| Limit warning | Shown if new balance would exceed limit |
| Back button | Return to product view |
| Buy button | Confirm purchase (disabled if over limit) |

## Cart Item Display

| Column | Content |
|--------|---------|
| Product | Name (in member's language) |
| Qty | Quantity with +/- controls |
| Price | Unit price |
| Total | Qty × Price |
| Action | Remove button |

## Variants

### V1: Adjust Quantity
1. Member taps + on item
2. Quantity incremented
3. Line total and cart total updated
4. Balance preview updated

### V2: Decrease Quantity
1. Member taps - on item
2. Quantity decremented
3. If quantity becomes 0, item removed
4. Totals updated

### V3: Remove Item
1. Member taps remove button on item
2. Item removed from cart
3. Totals updated

### V4: Empty Cart
1. Member removes all items
2. Cart shows "Cart is empty" message
3. Buy button disabled
4. Back button available

### V5: Continue Shopping
1. After checkout, member taps "Continue Shopping"
2. Cart is cleared
3. System returns to product view
4. Member can start new purchase

### V6: Return to Products Without Checkout
1. Member taps back button
2. Cart contents preserved
3. Return to product view
4. Product badges still show quantities

### V7: Checkout In Progress
1. Member taps "Buy"
2. Buy button switches to a spinner with a "processing" label and stops accepting taps
3. Quantity and remove controls are frozen for the duration of the checkout
4. Further "Buy" taps are ignored — the cart is submitted exactly once
5. The back, cart and logout buttons are inert: leaving the screen that started the checkout would strand the member without the confirmation they just paid for
6. On completion the confirmation view is shown

## Business Rules
- Cart is transient (not persisted)
- Cart cleared on: new user scan, checkout, continue shopping after checkout
- Minimum quantity is 1 (reaching 0 removes item)
- No maximum quantity limit
- Transactions created only on "Buy" confirmation

## Error Cases

### E1: Session Timeout
- User inactive for 30 seconds
- Cart discarded
- Return to idle screen
- No transactions created

### E2: Network Error During Checkout
- Transactions stored locally
- Success message shown
- Transactions queued for sync

### E3: Balance Limit Exceeded
- New balance (current + cart) exceeds configured maximum
- Warning banner displayed at top of cart
- "Buy" button disabled with tooltip "Balance would exceed limit"
- User must remove items to proceed
- See [UC-T12](./UC-T12-error-scenarios.md) for full details

## Test Derivation
- View cart: add items, open cart, verify all items listed
- Adjust quantity up: tap +, verify quantity and totals update
- Adjust quantity down: tap -, verify quantity decrements
- Remove by decrement: tap - until qty=0, verify item removed
- Remove button: tap remove, verify item gone
- Empty cart: remove all items, verify "empty" state
- Back preserves cart: add items, view cart, go back, verify badges remain
- Checkout clears cart: checkout, continue shopping, verify cart empty
- Balance preview: verify preview = current + total
- Timeout: add items, wait 30s, verify cart discarded
- Balance limit warning: exceed limit, verify warning banner shown
- Buy disabled at limit: exceed limit, verify "Buy" button disabled
- Remove to enable: exceed limit, remove items below limit, verify "Buy" enabled
