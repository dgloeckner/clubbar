# Phase 4 Phase 2: Categories Section + Products-Categories Integration

## Overview

**Objective**: Implement a dedicated Categories management page and fix the Products page to properly integrate with categories.

**Current Status**:
- Products Page: 13/14 tests passing (product creation failing due to missing category)
- Categories Page: Not yet started (missing from navigation)
- Products use hardcoded/missing category_id - must be properly selected

**Critical Dependencies**:
- UC-A41 (Create Product): **Requires** category selection
- UC-A44 (Manage Categories): Required for category CRUD
- UC-A40 (List Products): Must display category column and filter by category

---

## Phase 1: Categories Page (RED PHASE)

### Task 1.1: Write E2E Tests for Categories Management

**File**: `e2etests/tests/admin/categories.spec.ts`

**Test Coverage** (20+ tests):
- UC-A44: Category Management
  - List categories (table display, empty state)
  - Create category (form, multilingual names, defaults)
  - Edit category (update names, save)
  - Activate/Deactivate category (status toggle, confirmation)
  - Delete category (empty categories only, error for non-empty)
  - Reorder categories (drag & drop positioning)

**Key Test Cases**:
1. **List View** (5 tests)
   - Display categories table with columns: Status, Name, Products Count, Order
   - Show empty state
   - Display active/inactive indicators
   - Sort by display_order

2. **Create Category** (4 tests)
   - Open create form
   - Fill language tabs
   - Require at least one language
   - Save and verify in list

3. **Edit Category** (3 tests)
   - Open edit form
   - Update translations
   - Save and verify changes
   - Verify products stay assigned

4. **Activation** (3 tests)
   - Deactivate category (toggle)
   - Activate category (toggle)
   - Confirm dialogs appear

5. **Delete Category** (3 tests)
   - Delete empty category (success)
   - Attempt delete with products (error message)
   - Verify error suggests deactivation

6. **Responsive Design** (2 tests)
   - Desktop layout
   - Mobile layout

### Task 1.2: Create CategoriesPage Page Object

**File**: `e2etests/pages/CategoriesPage.ts`

**Methods**:
- `navigate()`
- `expectPageVisible()`
- `getCategoryCount(): Promise<number>`
- `getCategories(): Promise<Category[]>`
- `openCreateForm()`
- `fillCategoryName(language: string, name: string)`
- `saveCategory()`
- `editCategory(categoryId: string)`
- `toggleCategoryStatus(categoryId: string)`
- `deleteCategory(categoryId: string)`
- `dragCategory(from: number, to: number)`

### Task 1.3: Update Fixtures

**File**: `e2etests/fixtures/pageObjects.ts`

Add:
- `CategoriesPage` import
- `authenticatedCategoriesPage` fixture

---

## Phase 2: Categories Page (GREEN PHASE)

### Task 2.1: Implement CategoriesPage Component

**File**: `admin-frontend/src/pages/CategoriesPage.tsx`

**Features**:
- Display categories in table (Status | Name | Products Count | Order | Actions)
- Create new category modal with language tabs
- Edit category modal with language tabs
- Activate/Deactivate with confirmation dialog
- Delete button (disabled if has products)
- Reorder categories (drag indicator)
- Error handling and loading states
- All data-testid attributes for E2E testing

**Data Types**:
```typescript
interface Category {
  id: string
  names: { [lang: string]: string }
  display_order: number
  is_active: boolean
  product_count: number
  created_at: string
}

interface CategoriesResponse {
  data: Category[]
  pagination?: { ... }
}
```

**State Management**:
- categories list
- activeModal ('list' | 'create' | 'edit')
- selectedCategory for editing
- loading/error states
- confirmDialog for status changes

**API Calls**:
- GET /admin/categories - List categories
- POST /admin/categories - Create category
- PATCH /admin/categories/{id} - Update category
- PATCH /admin/categories/{id}/status - Toggle active status
- DELETE /admin/categories/{id} - Delete category
- PATCH /admin/categories/{id}/display-order - Reorder

### Task 2.2: Add Route and Navigation

**File**: `admin-frontend/src/App.tsx`

Add route: `/categories`

**File**: `admin-frontend/src/components/layout/MainLayout.tsx`

Add to navigation after Products with PackageIcon (or new category icon)

---

## Phase 3: Products Page - Categories Integration

### Task 3.1: Fix ProductsPage Component

**File**: `admin-frontend/src/pages/ProductsPage.tsx`

**Changes Required**:
1. Load categories on mount (already started, but incomplete)
2. Add category dropdown to product form (REQUIRED field)
3. Display category column in product list (after Name)
4. Add category filter dropdown to filter controls
5. Validate category is selected before submitting form
6. Error message if no categories exist
7. Update table sorting to include category

**Form Changes**:
```typescript
// Add to ProductsPage state
const [selectedCategory, setSelectedCategory] = useState<string>('')

// In form render:
<select
  data-testid="product-category-select"
  value={selectedCategory}
  onChange={(e) => setSelectedCategory(e.target.value)}
  required
>
  <option value="">Select a category...</option>
  {categories.map(cat => (
    <option key={cat.id} value={cat.id}>{cat.names.de || cat.names.en}</option>
  ))}
</select>

// In handleCreateProduct:
if (!selectedCategory) {
  setFormError('Category is required')
  return
}

await post('/admin/products', {
  names: { de: formData.name },
  price_cents: Math.round(parseFloat(formData.price) * 100),
  category_id: selectedCategory,
})
```

**Table Changes**:
- Add column after Name: Category
- Add category value display from product.category_id
- Maintain default sort: Category → Name

**Filters**:
- Add category filter dropdown
- Filter by selected category (or "All")

### Task 3.2: Update ProductsPage E2E Tests

**File**: `e2etests/tests/admin/products.spec.ts`

**Changes**:
1. Create test category before product creation tests (beforeEach hook in UC-A07)
2. Update createProduct form to select category from dropdown
3. Verify category is displayed in product list
4. Test category filter
5. Test category in product details

**Key Fix**:
```typescript
test.beforeEach(async ({ page }) => {
  // In UC-A07 describe block:
  // Create a test category for product creation tests
  const testCategory = await createTestCategory(page)
  // Use this category_id for product creation
})

// Helper function:
async function createTestCategory(page: Page) {
  const response = await post('/admin/categories', {
    names: { de: `Test Category ${Date.now()}` }
  })
  return response.data.id
}
```

### Task 3.3: Update ProductsPage Page Object

**File**: `e2etests/pages/ProductsPage.ts`

**Add Methods**:
- `selectCategory(categoryId: string)`
- `getSelectedCategory(): Promise<string>`
- `getCategoryColumnValue(productId: string): Promise<string>`
- `filterByCategory(categoryId: string)`

---

## Phase 4: Verification & Integration

### Task 4.1: Blue Phase - Visual Verification

- Verify Categories page layout matches prototype
- Verify Products page shows category column
- Verify category filter works
- Verify error states and confirmations

### Task 4.2: Fix All Tests

- Categories: All tests should pass (36-40 tests)
- Products: All 14 tests should pass (currently 13/14)
  - Fix product creation test with category
  - Add tests for category display and filtering

### Task 4.3: Integration Testing

Run full E2E suite:
```bash
npm test -- --workers=4
```

Verify:
- Can create category
- Can create product in category
- Can edit product's category
- Can deactivate category (hides products)
- Cannot delete category with products
- Category appears in product list

---

## Implementation Order

**Phase 1: Categories Page (RED - Tests)**
1. Write E2E tests for categories (20+ tests)
2. Create CategoriesPage page object
3. Update fixtures

**Phase 2: Categories Page (GREEN - Implementation)**
4. Implement CategoriesPage component
5. Add route and navigation
6. Wire up API integration

**Phase 3: Products-Categories Integration**
7. Fix ProductsPage component (add category dropdown, column, filter)
8. Update ProductsPage tests (use test categories)
9. Update ProductsPage page object (category methods)

**Phase 4: Verification**
10. Blue phase - visual verification
11. Run full E2E suite
12. Fix any remaining test failures
13. Verify all integration points

---

## Success Criteria

**Categories Page**:
- [ ] 36+ E2E tests passing (serial & parallel)
- [ ] Full CRUD operations working
- [ ] Activate/Deactivate with confirmations
- [ ] Delete validation (no products)
- [ ] Proper error messages

**Products-Categories Integration**:
- [ ] All 14 product tests passing
- [ ] Product form requires category selection
- [ ] Products display category in list
- [ ] Category filter works
- [ ] Category column visible in table

**Overall**:
- [ ] Full test suite passing (150+ tests across all pages)
- [ ] No hardcoded/placeholder category UUIDs
- [ ] Proper category validation throughout
- [ ] Categories navigation visible in main nav
- [ ] Products sorted by category then name by default

---

## Files to Create/Modify

### New Files:
- `e2etests/tests/admin/categories.spec.ts` (20-40 tests)
- `e2etests/pages/CategoriesPage.ts` (page object)
- `admin-frontend/src/pages/CategoriesPage.tsx` (component)

### Modified Files:
- `e2etests/fixtures/pageObjects.ts` (add CategoriesPage)
- `e2etests/pages/index.ts` (export CategoriesPage)
- `e2etests/tests/admin/products.spec.ts` (fix tests)
- `e2etests/pages/ProductsPage.ts` (add category methods)
- `admin-frontend/src/pages/ProductsPage.tsx` (add category dropdown, column, filter)
- `admin-frontend/src/App.tsx` (add /categories route)
- `admin-frontend/src/components/layout/MainLayout.tsx` (add Categories nav)
- `plans/INDEX.md` (update progress)

---

## Related Use Cases

- UC-A44: Manage Categories (Categories page)
- UC-A40: List Products (category column, filter, sort)
- UC-A41: Create Product (category required)
- UC-A42: Edit Product (category selection)
- UC-A43: Deactivate Product (show with category)

---

## Notes

**Important Constraints**:
- Categories are REQUIRED for products (not optional)
- Cannot delete category with products (deactivate instead)
- Deactivating category hides all its products on terminal
- At least one language name required for category
- Display order determines terminal tab order
- Multilingual support for category names

**Testing Strategy**:
- Create test categories in beforeEach for product tests
- Use unique names (timestamps) for test isolation
- Verify category appears in product list
- Test both creation with category and filtering by category
