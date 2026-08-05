# Terminal: Checkout Double-Tap Creates Duplicate Transactions

**Issue:** [#14](https://github.com/dgloeckner/clubbar/issues/14)
**Goal:** A rapid double-tap on the terminal's "Bezahlen" button must produce exactly one set of transactions, and the button must visibly show that a checkout is running.

**Related:** [UC-T11](../use-cases/terminal/UC-T11-shopping-cart.md) (Shopping Cart Management), [UC-T01](../use-cases/terminal/UC-T01-book-product-to-tab.md) (Book Product to Tab), [ADR-0004](../adr/0004-immutable-transaction-storage.md) (Immutable transaction storage — a duplicate charge cannot be deleted, only reversed)

**Status:** Implemented (2026-08-05). All tasks verified green.

| Task | Status | Evidence |
|------|--------|----------|
| 1. Re-entrancy guard in `CartProvider.checkout` | `[x]` | `if (_isLoading) return;` set synchronously before the first `await` |
| 2. Checkout button disabled + loading state | `[x]` | `_CheckoutButton` renders spinner + "Wird verarbeitet…", `InkWell.onTap == null` while in flight |
| 3. Press feedback on the checkout button | `[x]` | `GestureDetector` on a `Container` replaced by `Material` + `InkWell` (ripple) |
| 4. Cart frozen during checkout | `[x]` | Item list wrapped in `IgnorePointer(ignoring: isLoading)`; +/−/delete taps are no-ops |
| 5. Unit tests: `CartProvider.checkout` re-entrancy | `[x]` | 3 new tests in `test/providers/cart_provider_test.dart`; verified red without task 1 |
| 6. Widget tests: disabled/loading button | `[x]` | 5 new tests in `test/screens/shopping_cart_screen_test.dart` |
| 7. E2E integration test (UI → provider → service → SQLite) | `[x]` | `integration_test/checkout_flow_test.dart` (group `Checkout double-tap`), 3/3 passing on `xvfb-run flutter test -d linux`; verified red without task 1 |
| 8. Full unit/widget suite | `[x]` | `flutter test` → 329/329 passing |
| 9. `flutter analyze` clean for touched files | `[x]` | No new issues; remaining warnings are pre-existing |

---

## Analysis

### Root cause

`CartProvider.checkout` is a long `async` method. It sets `_isLoading = true` but **nothing consumed `isLoading`** — the getter had zero readers in the whole app. The cart itself is only emptied at the very end, after every `await`:

```
checkout()
  _isLoading = true
  await validateCartBeforeCheckout(...)      ← cart still full
  await createDispenserOperation(...)        ← cart still full
  await showDispensingDialog(...)            ← cart still full (seconds!)
  await createTransaction(...)               ← cart still full
  _items = []                                ← only now
```

The button was a plain `GestureDetector` wrapping a `Container` — no ripple, no press state, no disabled state. During the await window the screen is visually frozen, so a member with a drink in one hand taps again. The second tap runs the whole method a second time against a still-full cart, producing a second set of transactions.

Because transactions are immutable ([ADR-0004](../adr/0004-immutable-transaction-storage.md)), the duplicate cannot be deleted — it needs a manual reverse transaction by an admin.

### Why a guard in the widget alone is not enough

The UI only learns about `isLoading` on the next frame. Two taps dispatched within the same frame both reach the handler before any repaint. The provider-level guard is the airtight fix: `_isLoading` is set **synchronously**, before the first `await`, so a re-entrant call can never observe a half-finished checkout. The UI-level disabled state is the user-facing half — it stops the taps that arrive after a repaint and, more importantly, tells the member the terminal is working.

Both layers are covered by their own test.

---

## Changes

| File | Change |
|------|--------|
| `terminal-frontend/lib/providers/cart_provider.dart` | Early-return when `_isLoading` is already true |
| `terminal-frontend/lib/screens/shopping_cart_screen.dart` | New private `_CheckoutButton` (`Material` + `InkWell`, spinner + processing label, `onTap: null` when loading); item list wrapped in `IgnorePointer` while loading |
| `terminal-frontend/lib/l10n/app_de.arb`, `app_en.arb` (+ generated) | New `checkoutProcessing` string ("Wird verarbeitet…" / "Processing…") |
| `terminal-frontend/integration_test/test_helpers.dart` | `buildTestApp` accepts an optional `cartService` so a test can slow the checkout down |
| `terminal-frontend/integration_test/checkout_flow_test.dart` | New `Checkout double-tap (UC-T11)` group (3 E2E tests) + `SlowCartService` |
| `terminal-frontend/test/providers/cart_provider_test.dart` | New `CartProvider checkout re-entrancy` group (3 tests) |
| `terminal-frontend/test/screens/shopping_cart_screen_test.dart` | New press-feedback test + `checkout in flight` group (4 tests) |
| `use-cases/terminal/UC-T11-shopping-cart.md` | New variant V7 "Checkout In Progress" |

---

## Test Commands

```bash
cd terminal-frontend

# Unit + widget tests
flutter test                                            # 329 passed
flutter test test/providers/cart_provider_test.dart
flutter test test/screens/shopping_cart_screen_test.dart

# End-to-end (real app, real SQLite, Linux desktop build)
xvfb-run flutter test integration_test/ --exclude-tags=walkthrough -d linux
```

> **Why the new E2E tests live in `checkout_flow_test.dart` rather than their own file:**
> `flutter test -d linux` launches the built desktop app once per test *file*, and the
> second launch in a run reliably fails with
> `Error waiting for a debug connection: The log reader stopped unexpectedly`.
> Reproduced twice locally: a separate `checkout_double_tap_test.dart` passed 3/3, then
> broke the *next* file in the same run. Keeping the integration suite to a single
> non-walkthrough file avoids the second launch entirely.

### E2E coverage

`integration_test/checkout_flow_test.dart` (group `Checkout double-tap`) drives the full stack — RFID scan → product tap → cart screen → checkout button → `CartProvider` → `CartService` → SQLite — and asserts against the database, not the UI:

1. **`two taps in the same frame create exactly one persisted transaction`** — two `tester.tap` calls with no frame between them (only the provider guard can catch this). Asserts `transactions_local` holds exactly one row with the right member, product and amount.
2. **`tap while checkout is in flight is ignored and button shows progress`** — asserts the button shows the spinner and "Wird verarbeitet…", that a second tap does nothing, that exactly one row is persisted, and that the app lands on the confirmation screen.
3. **`cart cannot be edited while the checkout is in flight`** — +/− and delete taps during checkout are no-ops; the originally submitted line is what gets charged.

A `SlowCartService` (2s delay on `createTransaction`) reproduces the slow DB / dispenser window in which the bug is reachable.

### Negative control

Each layer's tests were confirmed **red** against the unfixed code, then green again with the fix restored:

| Layer | Fix removed | Result |
|-------|-------------|--------|
| Provider guard | `if (_isLoading) return;` commented out | E2E test 1 fails at `createTransactionCalls == 1` (got 2); unit tests "second checkout while first is in flight…" and "rapid double checkout…" fail |
| UI disabled state | — | E2E tests 2 and 3 and the `checkout in flight` widget group assert on `Wird verarbeitet…` / `InkWell.onTap == null`, neither of which existed before |

Note that E2E tests 2 and 3 still **pass** with only the provider guard removed — the button's disabled state catches a tap that arrives after a repaint. Only test 1 (two taps in the same frame, before any repaint) can reach the provider guard, which is exactly why both layers are needed.
