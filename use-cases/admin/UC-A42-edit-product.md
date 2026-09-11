# UC-A42: Edit Product

**Implementation Status**: Implemented

## Actor
Admin

## Preconditions
- Admin is logged in
- Product exists

## Trigger
Admin clicks "Edit" on product

## Main Flow
1. Admin clicks product in list or "Edit" button
2. System displays edit form with current values
3. Admin modifies fields
4. Admin saves changes
5. System validates input
6. System updates product record
7. System displays success message

## Editable Fields

| Field | Description |
|-------|-------------|
| Names | Multilingual names (language tabs) |
| Descriptions | Multilingual descriptions (optional) |
| Price | Price in cents |
| Category | Product category assignment |
| Minimum age | Legal minimum age for this product (Jugendschutz, [ADR-0045](../../adr/0045-age-restricted-products.md)). Empty = unrestricted; clearing it removes the restriction |
| Size | The product's volume, chosen from the sizes the club pours (1000, 500, 330, 300, 250, 200 ml) and stored as whole millilitres ([ADR-0056](../../adr/0056-product-volume.md)). Empty = the product has no size; choosing the empty option removes it. A size from outside the list is offered back unchanged |
| Status | Active / Inactive toggle |

## Renaming a Product That Carries Its Size

This is the edit a club does once, per product, after
[ADR-0056](../../adr/0056-product-volume.md) ships. **Shortening the name and
setting the size are one save**, so nothing is ever left half-renamed:

1. Open the product. The name still reads `Weizenbier (0,5l)`.
2. Delete the suffix from **every** language tab: `Weizenbier`, `Wheat beer`.
3. Choose the size in the Size field: `500 ml`. The preview beside the form
   shows what a member will read — `0,5 l`.
4. Save.

Nothing is backfilled automatically, and that is deliberate: a regex cannot tell
a size from a price, cannot decide what a translation should become, and would
mangle a minority of products silently — on a screen members read.

**A size edit changes how past bookings read**, exactly as a rename already
does: a transaction stores no product snapshot, so every statement, report and
history line joins the product as it stands now. That is a known property of the
model, not something the size introduced.

## Status Toggle

| Current Status | Toggle Action | Result |
|----------------|---------------|--------|
| Active | Click toggle | Product becomes inactive, hidden on terminal |
| Inactive | Click toggle | Product becomes active, visible on terminal |

**Confirmation required** when deactivating:
- "Deactivating will hide this product from the terminal."

**No confirmation** when activating (safe operation).

## Read-Only Fields

| Field | Reason |
|-------|--------|
| UUID | Immutable identifier |
| Created date | Historical |

## Visibility Rules

Product visible on terminal when:
- `product.is_active = true` AND
- `category.is_active = true`

Activating a product in an inactive category will NOT make it visible until the category is also activated.

## Business Rules
- Price changes apply to new transactions only
- Historical transactions retain original price
- Deactivation hides product but preserves history
- Reactivation makes product available again
- Status change takes effect on terminal after sync

## Postconditions
- Product updated
- Terminal shows updated data after sync
- Audit log entry with changes

## Error Cases

### E1: Validation Failed
- Display field-specific error messages
- Form not submitted

### E2: Category Inactive Warning
- When activating product in inactive category
- Display warning: "Product's category is inactive. Product will remain hidden until category is activated."

## Test Derivation

**Edit Fields:**
- Edit name: save → name updated
- Edit price: new price for new transactions
- Change category: product moves to new category
- Historical price: old transactions unchanged

**Status Toggle:**
- Deactivate: product hidden on terminal after sync
- Activate: product visible on terminal after sync
- Deactivate confirmation: dialog shown before deactivating
- Activate in inactive category: warning shown

**Size ([ADR-0056](../../adr/0056-product-volume.md)):**
- Set a size on a product that had none: stored, and the list shows it
- Reopen the form: the stored size comes back selected, labelled `500 ml`, while
  the preview and the list read `0,5 l`
- Clear the size: an explicit null reaches the column, and the list shows the
  name alone
- A size the list does not contain (a product saved before the list existed):
  offered back, selected, and left alone by an edit to anything else
- A price-only edit says nothing about the size

**Validation:**
- Same as create (names, price, category required)

**Audit:**
- All changes logged with old/new values, the size beside the name — the two
  are edited together, so a record of one without the other is incomplete
- Status changes logged

## Related

- [UC-A43: Deactivate Product](./UC-A43-deactivate-product.md) - Quick deactivation from list
- [UC-A44: Manage Categories](./UC-A44-manage-categories.md) - Category activation affects product visibility
