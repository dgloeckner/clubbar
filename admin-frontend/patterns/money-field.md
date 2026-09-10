# Money Field Pattern

**Use `MoneyField` for every euro amount typed in the admin panel. Do not use
`<input type="number">` for money.**

```tsx
import { MoneyField } from '../components/forms/MoneyField'

<MoneyField
  testId="products-form-price-input"
  required
  value={formData.price}                                  // '12.34', or ''
  onChange={(price) => setFormData({ ...formData, price })}
  invalid={Boolean(formErrors.price_cents)}
  style={formInputStyle(Boolean(formErrors.price_cents))}
/>
```

---

## Why not the native control

`<input type="number">` is **not** a locale-aware control. Whatever language
the page is in, its value sanitisation algorithm accepts one decimal separator
— the dot — and an invalid value is reported to script as the **empty string**.

So a Getränkewart typing a price the way German writes one, `3,50`, handed the
form `''`. The field looked filled, the save was refused, and nothing on screen
said why — beside a list that *displays* every price as `3,50 €`. German is the
panel's default language ([`src/i18n/config.ts`](../src/i18n/config.ts)), so
that was the default experience of typing a price ([#863](https://github.com/dgloeckner/clubbar/issues/863)).

The parsers were never the problem: `parseMoneyToCents` has always read a
comma. The control never delivered one.

## What the pattern is

| Decision | Why |
|----------|-----|
| **The locale decides the separator** | Read from `Intl` (`getMoneyFormat`), not from a table of our own — a language added later is right without an edit. `de` writes `3,50`, `en-GB` writes `3.50` |
| **Both separators are accepted, always** | A numeric keypad emits a dot in German, and an admin who learned the panel in German types a comma into the English one. Whichever arrives is rewritten as the locale's, as you type |
| **Canonical on the wire, locale on screen** | Pages hold dot-decimal text (`'3.50'`, `''` for empty) — what `toFixed(2)` produces and what the cents parsers take. Nothing outside the field knows what the user sees |
| **A lone group separator with three digits is thousands** | `1.000` is a thousand euros to a German, not one. Credit ceilings are typed as round thousands, so this is the difference between a €1,000 limit and a €1 one |
| **At most two decimal digits**, and a trailing separator survives | `3,` is a state you can keep typing from; `3,999` cannot be entered at all |
| **The mask is not the validator** | A leading `-` passes through so the page's own refusal explains it, beside the field. Silently making a negative amount positive would be worse than refusing it |
| **Whole euros are completed on blur** | `3` becomes `3,00` when you leave the field, not while you are typing towards `3,50` |

## The two halves

- [`src/utils/money.ts`](../src/utils/money.ts) — the pure half: the locale's
  separators, the masking, the conversions, and `parseMoneyToCents` (the one
  string→cents parser in the panel; ADR-0001 money is integer cents). Unit
  tested.
- [`src/components/forms/MoneyField.tsx`](../src/components/forms/MoneyField.tsx)
  — the control. Covered by Playwright, like `DateField`.

Styling comes from the caller via `style`, because the pages that hold amounts
each dress their inputs to match the form around them; the component is about
the *value*, not the chrome.

## Assert on the canonical value in E2E

The field renders a hidden `{testId}-value` holding the canonical amount, for
the same reason `DateField` does: an assertion on the visible input is an
assertion about the locale.

```ts
// The amount the API will receive
private readonly priceValue = () => this.page.getByTestId('products-form-price-input-value')
expect(await products.getFormPriceValue()).toBe('3.50')

// …and, only when the localisation itself is what is under test
expect(await products.getFormPriceText()).toBe('3,50')
```

`fill()` on the visible input still takes either notation — the mask converts
it — so existing page objects that type `'5.99'` keep working in a German
panel.

## Where amounts are typed

| Field | Page |
|---|---|
| Product price | `ProductsPage` |
| Club credit ceiling | `CreditLimitsTab` (Settings → Treasury) |
| Member credit ceiling | `MembersPage` |

An integer is not money: minimum age and the warning percentage stay
`<input type="number">`, because no decimal separator is involved.

## Displaying an amount

Never format one by hand. `useFormatters().formatPrice(cents)` goes through
`Intl` for the admin's language — a hardcoded `.replace('.', ',')` is right in
German and wrong in English, which is exactly what `ProductPreview` used to do.
