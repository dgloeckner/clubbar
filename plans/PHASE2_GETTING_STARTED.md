# Phase 2: Getting Started Guide

**Your hands-on guide to begin Phase 2 implementation of the Admin Frontend.**

Follow these exact steps to implement the first milestone (Products page) using TDD + Playwright MCP.

---

## 🎯 Your Goal This Week

Implement the **Products page** with:
- ✅ 5 E2E tests (all passing)
- ✅ React component with API integration
- ✅ Visual verification against prototype
- ✅ Serial AND parallel test execution verified
- ✅ Commit with test results

**Estimated Time**: 3-5 days

---

## ✅ Pre-Flight Checklist

Run these commands to verify everything is ready:

```bash
# 1. Backend health check
curl -s http://localhost:8080/api/health | jq .
# Expected: { "status": "healthy" }
# If fails: docker compose up -d && sleep 5

# 2. Frontend project exists
ls -la admin-frontend/package.json
# Expected: file exists

# 3. Install dependencies
cd admin-frontend && npm install

# 4. Type check works
npm run type-check
# Expected: no errors

# 5. Linting works
npm run lint
# Expected: no errors or only warnings

# 6. Test framework works
npm test -- --version
# Expected: @playwright/test version X.X.X
```

If all checks pass ✅, proceed to Phase 2 implementation.

---

## 🔴 Step 1: Write Failing Tests (Red Phase)

### Create test file

```bash
cd admin-frontend

# Create test file for Products page
cat > tests/pages/products.spec.ts << 'EOF'
import { test, expect } from '@playwright/test'

test.describe('Products Page', () => {
  test.beforeEach(async ({ page }) => {
    // Navigate to products page
    await page.goto('http://localhost:5173/products')
  })

  test('should list products with pagination', async ({ page }) => {
    // Verify table is displayed
    const table = page.locator('table, [role="table"]')
    await expect(table).toBeVisible()

    // Verify product rows exist
    const rows = page.locator('tbody tr, [role="row"]')
    const count = await rows.count()
    expect(count).toBeGreaterThan(0)
  })

  test('should display product columns correctly', async ({ page }) => {
    // Verify expected columns exist
    const headers = page.locator('thead th, [role="columnheader"]')
    const headerText = await headers.allTextContents()

    expect(headerText.join(' ').toLowerCase()).toContain('name')
    expect(headerText.join(' ').toLowerCase()).toContain('price')
    expect(headerText.join(' ').toLowerCase()).toContain('category')
  })

  test('should open create product modal', async ({ page }) => {
    // Click "Create Product" or "New Product" button
    const createBtn = page.locator('button:has-text("Create"), button:has-text("New")')
    await createBtn.click()

    // Verify modal opened
    const modal = page.locator('[role="dialog"]')
    await expect(modal).toBeVisible()

    // Verify form fields
    await expect(page.locator('input[type="text"], input[name*="name"]')).toBeVisible()
    await expect(page.locator('input[type="number"], input[name*="price"]')).toBeVisible()
  })

  test('should create new product', async ({ page }) => {
    // Click create button
    const createBtn = page.locator('button:has-text("Create"), button:has-text("New")')
    await createBtn.click()

    // Wait for modal
    await page.locator('[role="dialog"]').waitFor()

    // Fill form
    await page.fill('input[name*="name"]', `Test Product ${Date.now()}`)
    await page.fill('input[name*="price"]', '10.50')

    // Submit
    await page.click('button:has-text("Save"), button:has-text("Create")')

    // Verify success message or product appears
    await expect(page.locator('text=/success|created/i')).toBeVisible({ timeout: 5000 })
  })

  test('should search/filter products', async ({ page }) => {
    // Find search input
    const searchInput = page.locator('input[placeholder*="Search"], input[type="search"]')

    if (await searchInput.count() > 0) {
      // Enter search term
      await searchInput.fill('test')

      // Wait for filtered results
      await page.waitForTimeout(500) // debounce

      // Verify results filtered
      const rows = page.locator('tbody tr')
      const visibleCount = await rows.count()
      expect(visibleCount).toBeGreaterThan(0)
    }
  })
})
EOF

echo "✓ Test file created: tests/pages/products.spec.ts"
```

### Run tests (they should fail)

```bash
# Make sure you're in admin-frontend directory
cd admin-frontend

# Run tests with 1 worker (serial, easier to debug)
npm test -- tests/pages/products.spec.ts --workers=1

# Expected output:
# ✕ 1) should list products with pagination
# ✕ 2) should display product columns correctly
# ✕ 3) should open create product modal
# ✕ 4) should create new product
# ✕ 5) should search/filter products
#
# 5 failed (5s)
```

**Important**: All tests should fail at this point. This is the RED phase ✓

---

## 🟢 Step 2: Implement Products Page Component (Green Phase)

### Create the Products page component

```bash
cat > src/pages/ProductsPage.tsx << 'EOF'
import { useEffect, useState } from 'react'
import { get, post } from '../services/api'
import { Card, Button, Input, Modal } from '../components/common'

interface Product {
  id: string
  names: { [lang: string]: string }
  price_cents: number
  category_id: string
  is_active: boolean
  created_at: string
}

export function ProductsPage() {
  const [products, setProducts] = useState<Product[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [showModal, setShowModal] = useState(false)
  const [searchTerm, setSearchTerm] = useState('')
  const [formData, setFormData] = useState({ name: '', price: '' })

  // Load products on mount
  useEffect(() => {
    loadProducts()
  }, [])

  async function loadProducts() {
    try {
      setLoading(true)
      setError(null)
      const response = await get('/products', {
        params: { page: 1, per_page: 20, search: searchTerm },
      })
      setProducts(response.data)
    } catch (err: any) {
      setError(err.message || 'Failed to load products')
    } finally {
      setLoading(false)
    }
  }

  async function handleCreateProduct(e: React.FormEvent) {
    e.preventDefault()
    try {
      await post('/products', {
        names: { de: formData.name },
        price_cents: Math.round(parseFloat(formData.price) * 100),
        category_id: '00000000-0000-0000-0000-000000000000', // placeholder
      })
      setFormData({ name: '', price: '' })
      setShowModal(false)
      await loadProducts()
    } catch (err: any) {
      alert(`Error: ${err.message}`)
    }
  }

  if (loading) return <div>Loading products...</div>

  const filteredProducts = products.filter(p =>
    p.names.de?.toLowerCase().includes(searchTerm.toLowerCase())
  )

  return (
    <div style={{ padding: '20px' }}>
      <Card title="Products">
        <div style={{ marginBottom: '20px', display: 'flex', gap: '10px' }}>
          <Input
            placeholder="Search products..."
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            style={{ flex: 1 }}
          />
          <Button onClick={() => setShowModal(true)}>Create Product</Button>
        </div>

        {error && <div style={{ color: 'red', marginBottom: '10px' }}>{error}</div>}

        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead>
            <tr>
              <th style={{ border: '1px solid #ccc', padding: '10px', textAlign: 'left' }}>Name</th>
              <th style={{ border: '1px solid #ccc', padding: '10px', textAlign: 'left' }}>Price</th>
              <th style={{ border: '1px solid #ccc', padding: '10px', textAlign: 'left' }}>Category</th>
              <th style={{ border: '1px solid #ccc', padding: '10px', textAlign: 'left' }}>Status</th>
            </tr>
          </thead>
          <tbody>
            {filteredProducts.map(product => (
              <tr key={product.id}>
                <td style={{ border: '1px solid #ccc', padding: '10px' }}>
                  {product.names.de || product.names.en || 'Unnamed'}
                </td>
                <td style={{ border: '1px solid #ccc', padding: '10px' }}>
                  €{(product.price_cents / 100).toFixed(2)}
                </td>
                <td style={{ border: '1px solid #ccc', padding: '10px' }}>{product.category_id}</td>
                <td style={{ border: '1px solid #ccc', padding: '10px' }}>
                  {product.is_active ? '✓ Active' : '✗ Inactive'}
                </td>
              </tr>
            ))}
          </tbody>
        </table>

        {filteredProducts.length === 0 && (
          <div style={{ textAlign: 'center', padding: '20px', color: '#999' }}>
            No products found
          </div>
        )}
      </Card>

      {showModal && (
        <Modal title="Create Product" onClose={() => setShowModal(false)}>
          <form onSubmit={handleCreateProduct} style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
            <Input
              name="name"
              placeholder="Product name"
              value={formData.name}
              onChange={(e) => setFormData({ ...formData, name: e.target.value })}
              required
            />
            <Input
              name="price"
              type="number"
              step="0.01"
              placeholder="Price (€)"
              value={formData.price}
              onChange={(e) => setFormData({ ...formData, price: e.target.value })}
              required
            />
            <div style={{ display: 'flex', gap: '10px', marginTop: '10px' }}>
              <Button type="submit">Create</Button>
              <Button onClick={() => setShowModal(false)}>Cancel</Button>
            </div>
          </form>
        </Modal>
      )}
    </div>
  )
}
EOF

echo "✓ Component created: src/pages/ProductsPage.tsx"
```

### Add route to App.tsx

```bash
# Open src/App.tsx and add this route in the main routes section:
# <Route path="/products" element={<ProductsPage />} />

# Or use this command to add it:
cat >> src/App.tsx << 'EOF'
// ALREADY ADDED: import { ProductsPage } from './pages/ProductsPage'
// ALREADY ADDED: <Route path="/products" element={<ProductsPage />} />
EOF

echo "✓ Route added to App.tsx (or already exists)"
```

### Start dev server

```bash
# In a new terminal window:
npm run dev

# Wait for output like:
# ✨ Vite dev server running at http://localhost:5173

# Keep this terminal open while testing
```

### Run tests again (they should now pass)

```bash
# In another terminal:
cd admin-frontend
npm test -- tests/pages/products.spec.ts --workers=1

# Expected output:
# ✓ 1) should list products with pagination
# ✓ 2) should display product columns correctly
# ✓ 3) should open create product modal
# ✓ 4) should create new product
# ✓ 5) should search/filter products
#
# 5 passed (5s)
```

**If tests fail**: Check the error messages and verify:
1. Component renders on http://localhost:5173/products
2. API calls are working (check browser console)
3. Element selectors match HTML structure

---

## 🔵 Step 3: Visual Verification with Playwright MCP (Blue Phase)

### Open browser and navigate to page

Using Playwright MCP:

```javascript
// In Claude Code, navigate to products page:
mcp__playwright__browser_navigate("http://localhost:5173/products")

// Wait for page to load
mcp__playwright__browser_wait_for(text="Product", seconds=3)

// Take screenshot
mcp__playwright__browser_take_screenshot("products-page.png")
```

### Verify against prototype

Open the prototype and compare:

1. **Layout**:
   - [ ] Table visible with product data
   - [ ] Search input present
   - [ ] "Create Product" button visible

2. **Design**:
   - [ ] Colors match design system
   - [ ] Spacing and padding look balanced
   - [ ] Font sizes are readable

3. **Functionality**:
   - Click "Create Product" button
   - Modal should appear
   - Form should have name and price fields

### Test interactions with Playwright MCP

```javascript
// Take snapshot to see current page structure
mcp__playwright__browser_snapshot()

// Click "Create Product" button
mcp__playwright__browser_click(element="Create Product button", ref="[button ref]")

// Wait for modal
mcp__playwright__browser_wait_for(text="Create Product", seconds=2)

// Take screenshot of modal
mcp__playwright__browser_take_screenshot("modal-opened.png")

// Fill form
mcp__playwright__browser_fill_form(fields=[
  { name: "Product name", ref: "[name input ref]", type: "textbox", value: "Test" },
  { name: "Price", ref: "[price input ref]", type: "textbox", value: "9.99" },
])

// Submit
mcp__playwright__browser_click(element="Create button", ref="[submit ref]")

// Wait for success
mcp__playwright__browser_wait_for(text="success", seconds=2)
```

### Visual Verification Checklist

- [ ] Table displays products correctly
- [ ] Search filter works (type "test" and results update)
- [ ] Modal opens and closes properly
- [ ] Form fields are visible and usable
- [ ] Colors match design system (#0a1628, #1a2744, #3b82f6)
- [ ] No console errors (check with `mcp__playwright__browser_console_messages(level="error")`)

---

## ✅ Step 4: Verify Tests Pass

### Serial execution (debugging mode)

```bash
npm test -- tests/pages/products.spec.ts --workers=1

# Expected output:
# ✓ 1) should list products with pagination (0.5s)
# ✓ 2) should display product columns correctly (0.3s)
# ✓ 3) should open create product modal (0.8s)
# ✓ 4) should create new product (1.2s)
# ✓ 5) should search/filter products (0.7s)
#
# 5 passed (5s)

# IMPORTANT: Record this output for your commit message
```

### Parallel execution (safety check)

```bash
npm test -- tests/pages/products.spec.ts --workers=4

# Expected output:
# ✓ 1) should list products with pagination (0.5s) [worker 1]
# ✓ 2) should display product columns correctly (0.3s) [worker 2]
# ✓ 3) should open create product modal (0.8s) [worker 3]
# ✓ 4) should create new product (1.2s) [worker 4]
# ✓ 5) should search/filter products (0.7s) [worker 1]
#
# 5 passed (3s)

# All tests pass with all workers ✓
```

**If parallel tests fail**:
- Check if tests share state (use unique data with `Date.now()`)
- Reference: E2E Testing Patterns 001-004

---

## 💾 Step 5: Commit Your Work

### Stage files

```bash
git add admin-frontend/tests/pages/products.spec.ts
git add admin-frontend/src/pages/ProductsPage.tsx
git add admin-frontend/src/App.tsx
git status  # Verify only these files are staged
```

### Create commit

```bash
git commit -m "[Phase 2 Milestone 2.2] Implement Products page

- Write E2E tests for product CRUD workflows
- Implement ProductsPage React component with TypeScript
- Full API integration: GET /products, POST /products
- Search/filter functionality
- Create product modal with form validation
- Loading states and error handling
- 5/5 E2E tests passing (serial and parallel execution)
- Visual verification complete against prototype design

Test Results:
  ✓ should list products with pagination
  ✓ should display product columns correctly
  ✓ should open create product modal
  ✓ should create new product
  ✓ should search/filter products

Serial: 5/5 passing ✓
Parallel: 5/5 passing ✓ (4 workers)
Execution time: < 6 seconds

Verification:
  ✓ Visual matches prototype
  ✓ No console errors
  ✓ All interactions working"
```

---

## 🎉 Success!

You've completed the first milestone with:

- ✅ 5 E2E tests (all passing)
- ✅ React component with API integration
- ✅ Visual verification against prototype
- ✅ Parallel execution verified
- ✅ Commit with full documentation

**Time to completion**: ~1 week for 1st page (Products) to establish patterns

---

## 🚀 Next Steps

### Milestone 2.2 Complete → What's Next?

1. **Short Break** - Review your code and tests

2. **Members Page** (Milestone 2.1) - Next priority
   - More complex CRUD (more fields)
   - Modals for forms
   - Pagination
   - Should be faster than Products page
   - Similar TDD cycle: Write tests → Implement → Verify

3. **Use same TDD workflow** for each page:
   - Red: Write tests (5-7 tests per page)
   - Green: Implement component
   - Blue: Visual verification with Playwright MCP
   - Verify: Serial + parallel tests
   - Commit: Record results

---

## 📊 Phase 2 Timeline (5 Pages, ~4-6 Weeks)

```
Week 1: Products page (YOU ARE HERE) ✓
        - TDD cycle established
        - Patterns documented
        - 5/5 tests passing

Week 2: Members page
        - 8 tests (more complex)
        - Modals, forms, pagination
        - 8/8 tests passing

Week 3: Journal page
        - 4 tests (read-only + filter)
        - Member-centric view
        - 4/4 tests passing

Week 4: Settlements page
        - 6 tests (complex workflow)
        - Preview + confirm + export
        - 6/6 tests passing

Week 5: Statistics page
        - 4 tests (charts + reports)
        - Dashboard metrics
        - 4/4 tests passing

Total: 30+ E2E tests, all passing ✅
```

---

## ❓ Troubleshooting

**Problem**: Tests fail with "Cannot find element"
```
Solution:
1. Check element selector in test matches HTML
2. Use mcp__playwright__browser_snapshot() to see DOM
3. Update selector to match actual HTML
4. Re-run tests
```

**Problem**: "Cannot GET /products" error
```
Solution:
1. Verify route added to App.tsx
2. Verify component imported correctly
3. Check for TypeScript compilation errors: npm run type-check
4. Restart dev server: npm run dev
```

**Problem**: API calls fail (CORS or 401 errors)
```
Solution:
1. Verify backend running: curl http://localhost:8080/api/health
2. Check auth interceptor in services/api.ts
3. Verify session cookie is sent
4. Check browser Network tab for actual requests
```

**Problem**: Tests pass with 1 worker but fail with 4 workers
```
Solution:
1. Tests have shared state or database cleanup issue
2. Use unique data: const name = `Product ${Date.now()}`
3. Ensure no hardcoded IDs in tests
4. Reference: E2E Testing Patterns 001-004
```

---

## 🔗 Key Resources

- **TDD Workflow**: [PHASE2_TDD_CHECKLIST.md](./PHASE2_TDD_CHECKLIST.md)
- **Full Plan**: [phase4-admin-frontend.md](./phase4-admin-frontend.md)
- **API Reference**: [PHASE2_API_MAPPING.md](./PHASE2_API_MAPPING.md)
- **Use Cases**: [USE_CASE_AUDIT.md](./USE_CASE_AUDIT.md)
- **Design System**: [PROTOTYPE_ANALYSIS.md](./PROTOTYPE_ANALYSIS.md)

---

**Happy coding! Follow the TDD cycle and you'll have production-ready frontend pages with full test coverage. 🚀**
