# Font Size Reference

All configurable font sizes live in `AppFontSizes` (`lib/utils/design_tokens.dart`).
They can be overridden at runtime via the `fontSizes` key in `config.json`
(see INSTALL.md § Configuration reference).

## Token map

| Token | Default (px) | UI elements |
|-------|-------------|-------------|
| `xxxl` | 24 | Checkout confirmation title ("Zahlung erfolgreich" / "Teilweise Ausgabe") |
| `xxl`  | 20 | Cart footer — "Gesamt" label; Product card price |
| `xl`   | 18 | Cart item quantity badge; Cart item line total; Cart footer "Neuer Kontostand"; Checkout button; Category chip label; Product card product name; Dispenser error dialog title; Struck-through original amount on partial checkout; Member details page AppBar title |
| `lg`   | 16 | Cart item product name; Empty-cart message; Member info card — member name and avatar initials; Member details page "Account Information" section header; Dispenser error dialog body text and button labels; Checkout confirmation member name |
| `base` | 14 | Cart item "je €X.XX" unit price; Demo scan button; Member info card balance/Deckel; Member details page field labels and values; Dispenser error dialog hint text; Action button label; Checkout confirmation "Neuer Kontostand" and countdown text; `PriceDisplay` (small variant) |
| `sm`   | 13 | RFID error message on idle screen; Member info card language indicator; Checkout confirmation session reference ID (monospace) |
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

## StatusInfoModal (developer overlay)

`StatusInfoModal` is a technical debug overlay, not part of the kiosk UI.
It uses its own internal sizing (9–30 px) and is intentionally excluded
from the token system.
