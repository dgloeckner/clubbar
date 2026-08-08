# Terminal Use Cases

Use cases for the Bar Terminal application (800x480px touchscreen POS interface).

## System Purpose

The terminal records member transactions for products consumed. Transactions are:
- Stored locally (offline-capable)
- Synced to backend periodically
- Settled via SEPA direct debit (handled by backend/admin panel)

**No payments occur at the terminal.** The terminal only records what members consume.

**Administrative functions** (reversals, member management, settlements) are handled in the separate **Admin Panel**.

## Actor

| Actor | Description |
|-------|-------------|
| **Member** | Organization member with RFID card and an **active mandate** |

## Terminal Access Requirements

Members must meet all requirements to use the terminal:

| Requirement | Check | Error if Failed |
|-------------|-------|-----------------|
| Valid card | card_uid exists in cache | "Unknown card" |
| Active account | is_active = true | "Account inactive" |
| SEPA valid | is_sepa_valid = true | "SEPA mandate missing" |

A member without an **active mandate** cannot start a session at all — blocked at card scan, before any product view opens, with no grace period. `is_sepa_valid` is derived from *whether the member has an active mandate*, not from a `NOT NULL` check on two member-table fields: the mandate reference used to be auto-generated the instant an IBAN was typed, so the old predicate collapsed to "somebody typed an IBAN" rather than "somebody signed a mandate". See [ADR-0020](../../adr/0020-sepa-mandate-requirement-terminal-access.md) (amended 2026-08-07) and [ADR-0006](../../adr/0006-sepa-mandate-reference-strategy.md) (amended: a mandate is its own record carrying reference, IBAN and signature date).

## Shopping Cart Model

The terminal uses a transient shopping cart:

| Behavior | Description |
|----------|-------------|
| Add items | Tap product tile in product view |
| Adjust quantity | +/- buttons in cart view |
| Remove items | Remove button or decrement to zero |
| Checkout | "Buy" button creates transactions |
| Cart cleared | On: new user scan, checkout + continue, session timeout |

Transactions are only recorded when the user confirms via "Buy" button.

## Product/Category Visibility

Terminal only shows active categories and products:

| Item | Visible When |
|------|--------------|
| Category tab | `category.is_active = true` AND has active products |
| Product tile | `product.is_active = true` AND `category.is_active = true` |

Products in inactive categories are hidden even if the product itself is active.

## Use Case Index

| ID | Name | Description |
|----|------|-------------|
| [UC-T01](./UC-T01-book-product-to-tab.md) | Book Product to Tab | Browse products, add to cart, checkout |
| [UC-T02](./UC-T02-view-tab-balance.md) | View Tab Balance | View balance and 90-day transaction history |
| [UC-T03](./UC-T03-change-language.md) | Change Language | Change display language preference |
| [UC-T11](./UC-T11-shopping-cart.md) | Shopping Cart | Review cart, adjust quantities, confirm purchase |
| [UC-T12](./UC-T12-error-scenarios.md) | Error Scenarios | Unknown card, balance limit, inactive account, SEPA invalid, timeouts |

## Screen Flow

```
┌─────────────┐     RFID      ┌─────────────┐  tap balance  ┌─────────────┐
│    Idle     │ ───────────►  │  Product    │ ────────────► │  Balance    │
│   Screen    │               │   View      │ ◄──────────── │  Detail     │
└─────────────┘               └──────┬──────┘     back      └─────────────┘
      ▲                              │                       (90-day history)
      │ timeout                      │ tap cart
      │                              ▼
      │                       ┌─────────────┐
      │                       │  Shopping   │
      │    done               │   Cart      │
      └─────────────────────  └──────┬──────┘
                                     │
                              buy    │
                                     ▼
                              ┌─────────────┐
                              │ Confirmation│ ─── continue ──► Product View
                              └─────────────┘                  (cart cleared)
```

## Non-Functional Requirements

| Requirement | Value |
|-------------|-------|
| RFID Response | < 500ms |
| Transaction Time | < 1s |
| Inactivity Timeout | 30s |
| Offline Capability | Queue transactions, sync when online |
| Display | 800x480px touchscreen |
| Minimum Tap Target | 48x48px |

## Use Case Format

Each use case follows this structure:

- **Actor**: Who performs the action
- **Preconditions**: Required state before starting
- **Trigger**: What initiates the use case
- **Main Flow**: Step-by-step happy path
- **Postconditions**: Expected state after completion
- **Variants**: Alternative flows
- **Error Cases**: Failure scenarios
- **Test Derivation**: Suggested test cases
