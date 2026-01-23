# UC-A40: List Products

## Actor
Admin

## Preconditions
- Admin is logged in

## Trigger
Admin opens Products section

## Main Flow
1. Admin clicks "Products" in navigation
2. System displays product list
3. List shows for each product:
   - Name (in admin's language)
   - Price
   - Category
   - Status (active/inactive)

## List Columns

| Column | Content |
|--------|---------|
| Name | Product name |
| Price | Price in € |
| Category | Category name |
| Status | Active/Inactive badge |
| Actions | Edit, Deactivate/Activate |

## Filters

| Filter | Options |
|--------|---------|
| Status | All, Active, Inactive |
| Category | All, or specific category |
| Search | Text search on name |

## Sorting
- Name (A-Z, Z-A)
- Price (low-high, high-low)
- Category
- Default: Category, then Name A-Z

## Postconditions
- Product list displayed

## Test Derivation
- List all products: all shown
- Filter by active: only active shown
- Filter by category: only that category
- Search by name: partial match works
- Sorting: order correct
- Empty state: "No products found"
