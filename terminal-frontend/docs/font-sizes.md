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

## Hardcoded sizes (not affected by config.json)

These elements use literal `fontSize` values and are not part of the
`AppFontSizes` token system. They are listed here for completeness.

| Screen / widget | Element | Size (px) |
|-----------------|---------|-----------|
| `IdleWaitingScreen` | Main title ("Karte scannen") | 42 |
| `IdleWaitingScreen` | Subtitle | 21 |
| `ShoppingCartScreen` | Grand total price | 48 |
| `ShoppingCartScreen` | Quantity stepper ＋ / − symbols | 24 |
| `ProductCard` | Cart badge item count | 16 |
| `AppHeader` | Avatar initials in header bar | 18 |
| `AppHeader` | Member name in header bar | 16 |
| `AppHeader` | Balance/Deckel in header bar | 13 |
| `MemberBar` | Avatar initials | 14 |
| `MemberBar` | Member name | 17 |
| `MemberBar` | Balance/Deckel | 17 |
| `MemberBar` | Cart badge item count | 12 |
| `RuderbarHeader` | App title "Ruderbar" | 20 |
| `RuderbarHeader` | Sync status badge | 12 |
| `RuderbarHeader` | Clock | 16 |
| `CartItemRow` | Product name | 16 |
| `CartItemRow` | Unit price | 12 |
| `CartItemRow` | Quantity | 14 |
| `CartItemRow` | Line total | 16 |
| `LoadingOverlay` | Loading message | 16 |
| `MemberDetailsModal` | Modal header "Member Details" | 20 |
| `MemberDetailsModal` | Avatar initials | 18 |
| `MemberDetailsModal` | Member name | 18 |
| `MemberDetailsModal` | Balance/Deckel | 15 |
| `MemberDetailsModal` | Field labels | 14 |
| `MemberDetailsModal` | Transaction amount | 16 |
| `MemberDetailsModal` | Transaction timestamp | 13 |
| `MemberDetailsModal` | Error / offline messages | 13–16 |
| `StatusInfoModal` | Tab button labels | 13 |
| `StatusInfoModal` | Modal section titles | 24 |
| `StatusInfoModal` | Metric card value | 30 |
| `StatusInfoModal` | Metric card label | 9 |
| `StatusInfoModal` | Info row label / value | 13 |
| `StatusInfoModal` | Various detail labels | 10–14 |
