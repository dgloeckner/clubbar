# Font Size Reference

All configurable font sizes live in `AppFontSizes` (`lib/utils/design_tokens.dart`).
They can be overridden at runtime via the `fontSizes` key in `config.json`
(see INSTALL.md § Configuration reference).

## Token map

| Token | Default (px) | UI elements |
|-------|-------------|-------------|
| `xxxl` | 26 | **Member bar — member name**; **Product card — product name** |
| `xxl`  | 22 | Cart item product name; Cart footer — "Gesamt" label; Product card price; Checkout receipt line items (count, name, line total), its "Gesamt" label and the struck-through original amount on a partial dispense |
| `xl`   | 20 | Checkout receipt member name, "Dein Deckel jetzt" caption and the note on a receipt whose details could not be loaded; Cart item quantity badge; Cart item line total; Cart footer "Neuer Kontostand"; Checkout button; Category chip label; Dispenser error dialog title; Member details page AppBar title |
| `lg`   | 18 | Header — club name (secondary colour); Member bar — balance/Deckel and avatar initials; Empty-cart message; Member details page "Account Information" section header; Dispenser error dialog body text and button labels |
| `base` | 16 | Cart item "je €X.XX" unit price; Demo scan button; Member bar button labels; Member details page field labels and values; Dispenser error dialog hint text; Action button label; `PriceDisplay` (small variant) |
| `sm`   | 14 | RFID error message on idle screen; Member info card language indicator |
| `xs`   | 13 | *(not currently used by any widget)* |

The defaults are the kiosk scale #41 introduced (the app used to ship base
`14`; see INSTALL.md § Configuration reference).

## Hierarchy on the product screen

The two loudest strings on the screen are the **member's name** and the
**product names**, both at `xxxl` — in that order of importance, and both a
step above everything they sit next to. This is deliberate and came from the
floor: members reported the logged-in name as *hard to spot* (it was `lg`,
the same step as the balance under it and one below the club name in the
header) and product names as *too small* (`xl`, under a price at `xxl`).

| Element | Before | Now | Why |
|---------|--------|-----|-----|
| Header — club name | `xxl` 600 primary | `lg` 500 secondary | A member at the bar knows which club they are in; the largest bold text on the screen must not be the one string nobody needs |
| Member bar — name | `lg` 600 | `xxxl` 700 | The string that confirms the terminal read the right card |
| Member bar — balance | `lg` 500 | `lg` 500 | Unchanged; now visibly subordinate to the name |
| Member bar — avatar | 43 px | 52 px | Same edge as the buttons beside it |
| Product card — name | `xl` 600 | `xxxl` 700 | A member picks by name and reads the price second |
| Product card — price | `xxl` 700 | `xxl` 700 | Unchanged; now one step under the name |
| Product card — icon | 60 px | 52 px | Pays for the larger name so the kiosk keeps two whole rows (#369) |
| Cart line — product name | `lg` 600 | `xxl` 700 | Same reading order as the tile; the cart list scrolls, so height is not at a premium there |

Rendered at the kiosk's own 1280x800: `tool/screenshots/out/01-product-grid.png`
through `04-long-name-with-banner.png` at the shipped scale, and `11-` through
`14-` at the scale a production terminal runs (`xxxl` 31), regenerated with
`flutter test tool/screenshots/product_screen_screenshot_test.dart --update-goldens`.

Both `xxxl` strings carry a pinned line height: the product card sets 1.2 on
its name and price (`ProductCard.textLineHeight`), and the tile height is
computed from exactly that — Roboto's own ~1.34 was 27 px per two rows the
grid could not spare at the scale a production terminal runs (`xxxl` 31),
where the second row sat cut off behind the summary bar whenever the
credit-limit banner was up. That scale is now one of the four the grid
sizing tests pin, and one of the two the screenshot harness renders.

The member bar keeps its height: the name and balance lines carry pinned
line-height factors (1.15 and 1.2) so the column stays at the 52 px button
edge rather than growing the band above the grid. The product grid's tile
height is computed from `ProductCard.nameFontSize`; its 240 px column bound
is unchanged and gives a 1280 px kiosk five 240 px tiles, wide enough for
"Alkoholfreies" on one line at the larger size (a test holds that floor).

## Intentionally fixed sizes (not affected by config.json)

The following elements use hardcoded literal `fontSize` values by design.
They are display/hero sizes that are part of the kiosk visual identity and
are not intended to be operator-configurable.

| Screen / widget | Element | Size (px) | Rationale |
|-----------------|---------|-----------|-----------|
| `IdleWaitingScreen` | Main title ("Karte scannen") | 42 | Hero display size |
| `ShoppingCartScreen` | Grand total price | 48 | Hero display size |
| `ShoppingCartScreen` | Quantity stepper ＋ / − touch targets | 24 | Touch-target size, same as xxxl |

## Derived sizes (multiples of a token)

The checkout receipt's three hero sizes are multiples of the configured scale
rather than fixed numbers, so a terminal that raises `fontSizes` keeps the
receipt's hierarchy instead of having the lines catch up with the title.

| Screen / widget | Element | Size | On the production scale (`xxl` 27, `xxxl` 31) |
|-----------------|---------|------|-------------------|
| `CheckoutConfirmationScreen` | Receipt title ("Buchung erfolgreich!") | `xxxl` × 1.3 | 40 |
| `CheckoutConfirmationScreen` | Receipt total | `xxl` × 1.35 | 36 |
| `CheckoutConfirmationScreen` | Resulting balance ("Offener Betrag: 26,80 €") | `xxxl` × 1.55 | 48 — the number the member walks away with |

## StatusInfoModal (developer overlay)

`StatusInfoModal` is a technical debug overlay, not part of the kiosk UI.
It uses its own internal sizing (9–30 px) and is intentionally excluded
from the token system.
