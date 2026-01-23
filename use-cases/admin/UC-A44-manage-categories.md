# UC-A44: Manage Categories

## Actor
Admin

## Preconditions
- Admin is logged in

## Trigger
Admin opens Products → Categories

## Main Flow
1. Admin clicks "Categories" in Products section
2. System displays category list
3. Admin can:
   - Add new category
   - Edit category (names, translations)
   - Reorder categories (drag & drop)
   - Activate/deactivate category
   - Delete empty category

## Category List Display

| Column | Content |
|--------|---------|
| Status | Active/Inactive indicator |
| Name | Category name (in admin's language) |
| Products | Count of products in category |
| Order | Display position (drag handle) |
| Actions | Edit, Activate/Deactivate, Delete |

**Inactive categories**: Shown with muted styling (grayed out)

## Category Operations

### Add Category
1. Admin clicks "Add Category"
2. System displays form with language tabs
3. Admin enters name for each enabled language
4. At least one language required
5. Admin saves
6. System creates category with display_order at end
7. Category appears in list (active by default)

### Edit Category
1. Admin clicks "Edit" on category
2. System displays form with language tabs
3. Admin edits names per language
4. Admin saves
5. Products retain category assignment

### Reorder Categories
1. Admin drags category to new position
2. System updates display_order for affected categories
3. Terminal shows new tab order after sync

### Activate/Deactivate Category
1. Admin clicks status toggle or "Deactivate"/"Activate" action
2. System confirms action:
   - Deactivate: "Deactivating will hide this category and its X products from the terminal."
   - Activate: "Activating will show this category and its active products on the terminal."
3. Admin confirms
4. System updates is_active flag
5. Terminal hides/shows category after sync

### Delete Category
1. Admin clicks "Delete" on category
2. System checks for assigned products
3. If empty: confirm and delete
4. If has products: show error

## Deactivation Behavior

| Scenario | Terminal Behavior |
|----------|-------------------|
| Category inactive | Category tab hidden |
| Category inactive, product active | Product hidden (category not shown) |
| Category active, product inactive | Product hidden (category shown if has other active products) |
| Category active, product active | Both shown |

**Note**: Deactivating a category effectively hides all its products without changing each product's individual `is_active` status.

## Multilingual Names

| Element | Description |
|---------|-------------|
| Language tabs | One tab per enabled language |
| Name field | Required for at least one language |
| Missing indicator | Warning icon on tabs with empty name |
| Fallback | Terminal uses org default if translation missing |

## Business Rules
- Categories with products cannot be deleted (deactivate instead)
- At least one language translation required
- Display order determines terminal tab order
- Same fallback logic as products (selected → default → any)
- New categories are active by default
- Deactivating a category hides all its products on terminal

## Postconditions
- Category changes saved
- Terminal reflects changes after sync
- Audit log entries

## Error Cases

### E1: Delete Non-Empty Category
- Display "Category has X products, cannot delete"
- Suggest: "Deactivate the category to hide it, or move/delete products first"

### E2: No Translation Provided
- Display "At least one language is required"

## Test Derivation

**CRUD:**
- Add category: appears in list with all translations, active by default
- Edit translations: names update per language
- Missing translation: fallback works on terminal
- Reorder: terminal shows new tab order
- Delete empty: category removed
- Delete with products: error shown with suggestion

**Activation:**
- Deactivate category: terminal hides category tab
- Deactivate category: terminal hides all products in category
- Activate category: terminal shows category and active products
- Product in inactive category: product hidden even if product.is_active = true

**Audit:**
- Activation changes logged with old/new values
- All operations logged

## Related

- [UC-A40: List Products](./UC-A40-list-products.md)
- [UC-A43: Deactivate Product](./UC-A43-deactivate-product.md)
