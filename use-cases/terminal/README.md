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
| **Member** | Organization member with RFID card |

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

## Use Case Index

| ID | Name | Description |
|----|------|-------------|
| [UC-T01](./UC-T01-book-product-to-tab.md) | Book Product to Tab | Browse products, add to cart, checkout |
| [UC-T02](./UC-T02-view-tab-balance.md) | View Tab Balance | View balance and 90-day transaction history |
| [UC-T11](./UC-T11-shopping-cart.md) | Shopping Cart | Review cart, adjust quantities, confirm purchase |
| [UC-T12](./UC-T12-error-scenarios.md) | Error Scenarios | Unknown card, balance limit, inactive account, timeouts |

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
