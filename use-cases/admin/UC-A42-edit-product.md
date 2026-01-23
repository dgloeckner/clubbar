# UC-A42: Edit Product

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
| Status | Active / Inactive toggle |

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

**Validation:**
- Same as create (names, price, category required)

**Audit:**
- All changes logged with old/new values
- Status changes logged

## Related

- [UC-A43: Deactivate Product](./UC-A43-deactivate-product.md) - Quick deactivation from list
- [UC-A44: Manage Categories](./UC-A44-manage-categories.md) - Category activation affects product visibility
