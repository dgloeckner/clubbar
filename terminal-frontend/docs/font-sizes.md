# Font Size Reference

All configurable font sizes live in `AppFontSizes` (`lib/utils/design_tokens.dart`).
They can be overridden at runtime via the `fontSizes` key in `config.json`
(see INSTALL.md § Configuration reference).

## Token map

| Token | Default (px) | UI elements |
|-------|-------------|-------------|
| `xxxl` | 24 | *(not currently used by any widget)* |
| `xxl`  | 20 | Cart footer — "Gesamt" label; Product card price; Checkout receipt line items (count, name, line total), its "Gesamt" label and the struck-through original amount on a partial dispense |
| `xl`   | 18 | Checkout receipt member name, "Dein Deckel jetzt" caption and the note on a receipt whose details could not be loaded; Cart item quantity badge; Cart item line total; Cart footer "Neuer Kontostand"; Checkout button; Category chip label; Product card product name; Dispenser error dialog title; Member details page AppBar title |
| `lg`   | 16 | Cart item product name; Empty-cart message; Member info card — member name and avatar initials; Member details page "Account Information" section header; Dispenser error dialog body text and button labels |
| `base` | 14 | Cart item "je €X.XX" unit price; Demo scan button; Member info card balance/Deckel; Member details page field labels and values; Dispenser error dialog hint text; Action button label; `PriceDisplay` (small variant) |
| `sm`   | 13 | RFID error message on idle screen; Member info card language indicator |
| `xs`   | 12 | *(not currently used by any widget)* |

## Intentionally fixed sizes (not affected by config.json)

The following elements use hardcoded literal `fontSize` values by design.
They are display/hero sizes that are part of the kiosk visual identity and
are not intended to be operator-configurable.

| Screen / widget | Element | Size (px) | Rationale |
|-----------------|---------|-----------|-----------|
| `IdleWaitingScreen` | Main title ("Karte scannen") | 42 | Hero display size |
| `ShoppingCartScreen` | Grand total price | 48 | Hero display size |
| `ShoppingCartScreen` | Quantity stepper ＋ / − touch targets | 24 | Touch-target size, same as xxxl |
| `CheckoutConfirmationScreen` | Receipt title ("Buchung erfolgreich!") | 40 | Hero display size — read standing up, from across the counter |
| `CheckoutConfirmationScreen` | Receipt total | 34 | Hero display size, sized against the balance below it |
| `CheckoutConfirmationScreen` | Resulting balance ("Offener Betrag: 26,80 €") | 48 | Hero display size, the same as the cart's grand total — the number the member walks away with |

## StatusInfoModal (developer overlay)

`StatusInfoModal` is a technical debug overlay, not part of the kiosk UI.
It uses its own internal sizing (9–30 px) and is intentionally excluded
from the token system.
