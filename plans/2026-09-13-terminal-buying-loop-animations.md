# Terminal: Animating the Buying Loop

**Status**: Implemented (M1–M4 done and each verified; `flutter analyze` clean of
new issues, `flutter test` green)
**Branch**: `claude/adoring-lamport-grg9yy`
**Issue**: [#921](https://github.com/dgloeckner/clubbar/issues/921)
**Scope**: `terminal-frontend/` only — no backend, no API, no data-model change

## Context

The terminal animates the *edges* of a session well — the login burst, the
screen fade, the receipt's drain bar — and almost nothing in the middle, where a
member actually taps. Four one-shot animations on the buying loop, each a small
bounded reward for a tap:

1. **Fly-to-cart** — the tapped tile's icon arcs into the running total, which
   pops on arrival.
2. **Counting totals** — the summary bar's total and the receipt's balance tween
   between values instead of jumping.
3. **Cart line exit** — a removed line slides out while the rows below close the
   gap; the quantity digit bumps when it changes.
4. **Category stagger** — a new category's tiles enter with a very short
   stagger.

### The constraints, and where they come from

These are the reason the code looks the way it does; none of them is taste.

| Rule | Why |
|------|-----|
| Every animation is **one-shot**, started by a tap or a state change | #760: a screen that keeps producing frames cost the Pi 4B 27.7 % of a core with the display blanked. There is no `repeat()` and no ticker that outlives its effect; each new widget asserts `hasRunningAnimations` is false at rest |
| **Transforms and opacity only**, behind a `RepaintBoundary` | #41: an animated `BoxShadow.blurRadius` rebuilds a Skia blur every frame and pinned a core |
| **Reduced motion skips the motion, never the state** | `MediaQuery.maybeDisableAnimationsOf`, as `LoginBurst` already does. Both branches are tested for every effect |
| **Nothing drawn over the screen takes a tap** | The flight sprite sits under `IgnorePointer`; so does a row on its way out |
| **State first, motion second** | `CartProvider` is updated synchronously on the tap exactly as before. The existing `verify(addItem)` screen tests pass unchanged |
| Durations live in `AppAnimations` | 150–450 ms, well under the login burst's 1250 ms ceiling |
| Anything that must survive a route change goes in the **root overlay** | #644 |

## Milestones

### M1 — Fly-to-cart `[x]`

- `[x]` `lib/widgets/cart_flight.dart`: `CartFlight.launch(context, from:, to:,
  iconName:, onLanded:)` inserts one `OverlayEntry` into the root overlay; a
  quadratic Bézier lifted 35 % of the distance (clamped 40–160 px), 350 ms,
  position on `easeInOutCubic`, scale 1.0 → 0.4, opacity out over the last 20 %.
  At most 8 sprites in the air; the slot is released whether the flight
  completes *or* is discarded, so a torn-down overlay cannot leak the cap.
- `[x]` `PopOnSignal` / `PopOnChange`: the landing pop (1.0 → 1.18 → 1.0,
  `easeOutBack`) for the running total and the tile's `Nx` badge. No signal, no
  ticker.
- `[x]` `ProductCard.onAdded(Rect iconRect)` — fired after `onTap`, from a
  `GlobalKey` on the icon; never from a disabled tile or the minus button.
- `[x]` `CartSummaryBar` takes a `totalKey` and a `landingSignal`;
  `ProductSelectionScreen` owns both and resolves the target rect at tap time
  (the bar moves when a banner appears).
- **Verified**: `test/widgets/cart_flight_test.dart` (7),
  `test/widgets/product_card_test.dart` (+4), the screen's
  `buying loop animations (#921)` group.

### M2 — Counting totals `[x]`

- `[x]` `lib/widgets/counting_amount.dart`: tweens **cents as an int** and
  formats every frame through the caller's `formatPrice` / `formatBalance`, so
  the separator, the currency and the balance wording are never reimplemented.
  `easeOutCubic`, tabular figures so neighbours never reflow, retargeting from
  the *displayed* value.
- `[x]` `CartSummaryBar`'s total counts on every change, up or down, over
  250 ms.
- `[x]` `CheckoutConfirmationScreen`'s balance counts once on entry, over
  600 ms, starting after the receipt's 300 ms scale-in — from `final balance −
  what this receipt says was billed`, so a partial dispense counts from the
  amount actually billed and the two numbers on screen add up while it runs.
  Captured once, like `_balanceCents` (ADR-0027 rule 9).
- `[x]` The cart screen's own total still jumps: that screen is one tap from
  checkout and a moving number there is noise.
- **Verified**: `test/widgets/counting_amount_test.dart` (7),
  `test/widgets/cart_summary_bar_test.dart` (4), two new receipt cases.

### M3 — Cart line exit and stepper bounce `[x]`

- `[x]` `lib/widgets/removable_list.dart`: the removed row plays out from a
  **snapshot** — 250 ms, slide out left on `easeInCubic`, height collapsing on
  `easeOut` — while the rows below close the gap. `removeItem` / `decreaseItem`
  stay synchronous; the provider's list stays the source of truth and the list
  reconciles by `productId`.
- `[x]` A cart emptied *outright* (checkout) plays nothing.
- `[x]` The quantity digit bumps on change (`PopOnChange`, 180 ms); the line
  total beside it does not.
- **Verified**: `test/widgets/removable_list_test.dart` (7), the cart screen's
  `line exit and stepper bounce (#921)` group.

### M4 — Category switch stagger `[x]`

- `[x]` `lib/widgets/staggered_entry.dart`: fade 0 → 1 and an 8 px rise per
  tile, 20 ms apart in grid order, capped at 120 ms so the whole stagger is
  ~220 ms whatever the category's size. `easeOut`.
- `[x]` Tied to the **selected category id changing**, not to a rebuild: a
  re-tap of the chip already shown, a provider refresh, a cart change and the
  first paint after login all leave the grid alone. A tile built later than the
  stagger's window — one scrolled to below the fold — renders at rest with no
  controller.
- `[x]` `ProductGridLayout` and the tile's geometry are untouched: the
  animation wraps the tile, it does not change what the grid measures.
- **Verified**: the screen's `buying loop animations (#921)` group, including
  the existing `plays categorySwitch sound` test still passing.

## Verification

```bash
cd terminal-frontend
flutter analyze     # no new issues (3 pre-existing unused-import warnings remain)
flutter test        # whole suite green
```

## Deliberately not done

**Profiling on the target.** The issue asks for a `flutter run --profile` pass
on a Pi 4B kiosk (or at the kiosk's 1280x800 on a dev machine) before the last
change lands. The cloud session this was built in has no display and no device,
so it could not be run; the mechanical constraints it is there to protect —
one-shot controllers, transforms and opacity only, a `RepaintBoundary` around
every moving raster, a cap on sprites in flight — are held by the tests
instead. The profile pass still wants doing on real hardware before this is
called finished.

## Out of scope

Failure feedback (scan shake, blocked-checkout shake, credit gauge), dispensing
slots, logout mirror, hold-to-logout, reconnect ping — each its own issue.
