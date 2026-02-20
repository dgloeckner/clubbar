# Design: Session ID & Balance Hardening

**Date:** 2026-02-20
**Status:** Approved

## Problem Statement

Two related correctness issues in the terminal:

1. **Confirmation screen total is cart-derived, not DB-driven.** The billed amount shown after checkout is computed from in-memory cart state, which is cleared immediately after checkout. Partial dispense adjustments require fragile bookkeeping across multiple provider fields (`lastCheckoutTotalCents`, `lastPartialDispenseInfo`). If state is lost (app backgrounded, provider reset), the screen shows wrong values.

2. **"Neuer Kontostand" ignores settlements.** The displayed open position after checkout is computed as `balanceCents + unsyncedAmount`. The `balanceCents` field is only updated when the terminal syncs transactions to the backend. If a SEPA settlement occurred on the backend since the last sync — but no new transactions were created since then — `balanceCents` is stale and overstates the member's debt.

## Design

### 1. Session ID

A **session** is the span from member card scan to confirmation screen dismissal. It is a local concept; the backend never sees session IDs.

**Lifecycle:**
1. RFID scan succeeds → `MembersProvider.setSelectedMember()` generates `sessionId = UUID()` and holds it in memory alongside the selected member
2. Every transaction created during `CartProvider.checkout()` receives the current `sessionId`
3. `clearSelectedMember()` drops the in-memory `sessionId` (already persisted on transactions)

The session has no DB table of its own. It exists only as a tag on transactions. Session start time is implicit in `createdAt` of the first tagged transaction.

### 2. Schema Changes (version 5 → 6)

Two nullable columns added to `TransactionsLocal`:

| Column | Type | Purpose |
|--------|------|---------|
| `session_id` | `TEXT` (nullable) | Groups all transactions from one member login |
| `unit_price_cents` | `INTEGER` (nullable) | Price per unit at time of purchase (immutable record) |

Both columns are nullable so existing rows remain valid without backfill.

`unit_price_cents` is stored separately from `amount_cents` because `amount_cents` reflects the *actual* charge (which is less than full price on a partial dispense). Storing the unit price enables reconstruction of the original full-order total from the DB alone.

### 3. Confirmation Screen: DB-Driven

The confirmation screen receives `sessionId` as a route parameter. It reads everything it needs directly from `TransactionsLocal`:

**Billed amount:**
```sql
SELECT SUM(ABS(amount_cents))
FROM transactions_local
WHERE session_id = ?
```

**Partial dispense narrative** ("2 of 5 tokens dispensed"):
```sql
SELECT dispenser_requested, dispenser_actual, unit_price_cents
FROM transactions_local
WHERE session_id = ? AND dispenser_tx_id IS NOT NULL
LIMIT 1
```

From these two queries:
- `isPartial` = `dispenser_actual < dispenser_requested`
- `originalTotalCents` = `dispenser_requested × unit_price_cents`
- `actualTotalCents` = from the SUM query above
- `missedQuantity` = `dispenser_requested - dispenser_actual`

**What is removed from `CartProvider`:**
- `lastCheckoutTotalCents` — replaced by session sum query
- `lastPartialDispenseInfo` — replaced by dispenser columns on transactions

The confirmation screen is now correct even if the app is killed and relaunched mid-session (transactions are persisted). It has no dependency on cart lifecycle.

### 4. Neuer Kontostand: Fresh Balance on Card Scan

**Root cause:** `balanceCents` in `MembersCache` is only updated when the terminal sends transactions to the backend and receives a sync response. A SEPA settlement on the backend reduces the member's open position, but the terminal's cached value does not reflect this until a new sync is triggered by new transactions.

**Fix:** When a member scans their card, fetch their current balance from the backend before starting the session. Update `balanceCents` in `MembersCache` with the fresh value. The session then starts with an accurate baseline.

**Offline behaviour:** If the balance fetch fails (no connectivity), fall back to the cached `balanceCents` and display a small "balance may be outdated" indicator next to the Kontostand. The session is never blocked.

**Kontostand formula (unchanged):**
```
memberDeckel = balanceCents + unsyncedAmount
```

With a fresh `balanceCents` from the backend, this formula is correct: it represents the backend's current open position plus any new charges not yet synced.

## Component Changes

| Component | Change |
|---|---|
| `MembersProvider` | Generate and hold `sessionId` on `setSelectedMember()`; expose it; clear on `clearSelectedMember()` |
| `MembersRepository` | On card scan, call backend to fetch fresh member balance; update `MembersCache.balanceCents` |
| `TransactionsLocal` | Add `session_id` and `unit_price_cents` columns (schema v6) |
| `TransactionsRepository` | Add `getSessionTotal(sessionId)` and `getSessionDispenserInfo(sessionId)` queries |
| `CartService` | Accept `sessionId` and `unitPriceCents` per line item; persist both on created transactions |
| `CartProvider` | Pass `sessionId` into `CartService`; remove `lastCheckoutTotalCents` and `lastPartialDispenseInfo` |
| `CheckoutConfirmationScreen` | Receive `sessionId` via route param; derive all amounts from DB queries; remove cart state dependency |

## What Is Not Changed

- Backend API and sync protocol
- Settlement logic
- Crash recovery / dispenser reconciliation service
- Member sync (profile fields)
- Any admin frontend behaviour
