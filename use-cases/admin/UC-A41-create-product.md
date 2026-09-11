# UC-A41: Create Product

**Implementation Status**: Implemented

## Actor
Admin

## Preconditions
- Admin is logged in
- At least one category exists

## Trigger
Admin clicks "New Product"

## Main Flow
1. Admin clicks "New Product"
2. System displays product form
3. Admin enters product data:
   - Names (per enabled language) — **without the size**
   - Price
   - Category
   - Size, chosen from the predefined sizes (optional)
4. Admin submits form
5. System validates input
6. System generates UUID
7. System creates product record
8. System displays success message

## Form Fields

| Field | Required | Validation |
|-------|----------|------------|
| Name (default lang) | Yes | Non-empty, max 100 chars |
| Name (other langs) | No | Max 100 chars each |
| Price | Yes | > 0, max 2 decimals |
| Category | Yes | Existing category |
| Minimum age | No | Integer 1–99. Empty = unrestricted |
| Size | No | Chosen from the sizes the club pours — 1000, 500, 330, 300, 250 or 200 ml. Stored as whole millilitres. Empty = the product has no size |

## Size ([ADR-0056](../../adr/0056-product-volume.md))

The size does **not** go in the name. `Weizenbier (0,5l)` is entered as
`Weizenbier` plus a size of `500 ml`.

- **Chosen from a list**, not typed: `1000 ml`, `500 ml`, `330 ml`, `300 ml`,
  `250 ml`, `200 ml` — the sizes a club pours, labelled the way a crate is. Stored and sent
  as whole **millilitres**, which is what the option carries.
- **Empty means the product has no size** — a Sauna-Token, a Kaffee — which is
  not a size of zero. `0` is refused for that reason, and the empty option is
  what the form starts on.
- A product that already carries a size the list does not contain keeps it: the
  size is offered back alongside the others, so an edit to anything else cannot
  quietly clear it.
- The preview tile shows the badge the terminal will draw, in a row whose height
  is reserved even when there is no size.
- Every surface that prints the product's name prints the size after it, each in
  its own reader's notation: `0,5 l` in German, `0.5 l` in English. Below 100 ml
  it reads in millilitres (`20 ml`).

## Multilingual Names
- Tab per enabled language
- Default language required
- Other languages optional (fallback to default)

## Postconditions
- Product created with UUID
- Product is active
- Product visible on terminal after sync
- Audit log entry

## Error Cases

### E1: Name Empty
- Display "Name is required"

### E2: Invalid Price
- Display "Price must be greater than 0"

### E3: No Category
- Display "Category is required"

### E4: Size Out of Range
- A size below 0,001 l or above 10 l is refused by the form, in the admin's
  language, before the request is sent. The typed value stays in the field —
  it is not silently clamped

## Test Derivation
- Create with all fields: product created
- Required field empty: validation error
- Invalid price: validation error
- Multilingual names: all translations stored
- Missing translation: fallback works
- Product active: visible on terminal
- Audit log: creation logged, with the size beside the name (the two are edited
  together)
- Size typed as `0,5` in the German panel: stored as `500`, listed as `0,5 l`
- Size typed as `0.5` in the German panel: the same size
- No size typed: stored as null, and the list shows the name alone
- Size of `0`, `50` (litres) or prose: refused with a message beside the field
