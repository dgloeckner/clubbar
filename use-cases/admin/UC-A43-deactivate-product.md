# UC-A43: Deactivate Product

## Actor
Admin

## Preconditions
- Admin is logged in
- Product exists

## Trigger
Admin clicks "Deactivate" or "Activate" action on product in list

## Overview

Quick action for toggling product status directly from the product list without opening the edit form. The same functionality is available in [UC-A42: Edit Product](./UC-A42-edit-product.md).

## Main Flow (Deactivate)
1. Admin clicks "Deactivate" on active product
2. System displays confirmation: "Deactivating will hide this product from the terminal."
3. Admin confirms
4. System sets product to inactive
5. System displays success message
6. List updates to show inactive status

## Main Flow (Activate)
1. Admin clicks "Activate" on inactive product
2. System sets product to active (no confirmation needed)
3. System displays success message
4. List updates to show active status

## List Display

| Product Status | Available Action | Icon |
|----------------|------------------|------|
| Active | Deactivate | Toggle on (green) |
| Inactive | Activate | Toggle off (gray) |

Inactive products shown with muted styling in list.

## Visibility Rules

Product visible on terminal when:
- `product.is_active = true` AND
- `category.is_active = true`

**Warning when activating**: If product's category is inactive, show warning:
- "Product's category is inactive. Product will remain hidden until category is activated."

## Postconditions
- Product status updated
- Product hidden/shown on terminal after sync
- Transaction history preserved
- Audit log entry

## Business Rules
- Deactivation does NOT delete product
- Historical transactions retain product reference
- Reactivation makes product available again (if category active)
- Status change takes effect after terminal sync

## Test Derivation
- Deactivate from list: status = inactive, confirmation shown
- Activate from list: status = active, no confirmation
- Terminal visibility: product hidden/shown after sync
- Inactive category warning: shown when activating product in inactive category
- Transaction history: preserved with product name
- Audit log: status change logged
- List styling: inactive products shown muted

## Related

- [UC-A42: Edit Product](./UC-A42-edit-product.md) - Status toggle also available in edit form
- [UC-A44: Manage Categories](./UC-A44-manage-categories.md) - Category activation affects product visibility
