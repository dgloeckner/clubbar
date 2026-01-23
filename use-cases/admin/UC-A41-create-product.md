# UC-A41: Create Product

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
   - Names (per enabled language)
   - Price
   - Category
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

## Test Derivation
- Create with all fields: product created
- Required field empty: validation error
- Invalid price: validation error
- Multilingual names: all translations stored
- Missing translation: fallback works
- Product active: visible on terminal
- Audit log: creation logged
