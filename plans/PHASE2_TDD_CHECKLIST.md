# Phase 2: TDD Implementation Checklist

**Quick reference for Test-Driven Development during Phase 2 implementation.**

Use this checklist for each milestone (Members, Products, Journal, Settlements, Statistics).

---

## ✅ Pre-Implementation Checklist

Before starting each page/milestone:

- [ ] Backend running: `curl -s http://localhost:8080/api/health`
- [ ] Admin-frontend directory exists: `ls admin-frontend/`
- [ ] Dependencies installed: `cd admin-frontend && npm ls`
- [ ] Dev server can start: `npm run dev` (verify port 5173)
- [ ] Test framework working: `npm test -- --version`
- [ ] Git branch created: `git checkout -b feature/[page-name]`

---

## 🔴 Phase 1: Write Failing Tests (Red)

### 1. Create test file

```bash
# Location: admin-frontend/tests/pages/[page-name].spec.ts
# Template: Copy from PHASE4_TDD_TEMPLATE.md (see bottom of this file)

cat > tests/pages/members.spec.ts << 'EOF'
import { test, expect } from '@playwright/test'

test.describe('Members Page', () => {
  test('should list members', async ({ page }) => {
    await page.goto('http://localhost:5173/members')
    await expect(page.locator('table')).toBeVisible()
  })
  // ... more tests
})
EOF
```

### 2. Run tests (expect failures)

```bash
npm test -- tests/pages/[page-name].spec.ts --workers=1

# Expected output:
# ✕ 1) should list members
# ✕ 2) should search members
# ... (all tests failing - RED phase)
```

### 3. Document failures

```bash
# Save test output
npm test -- tests/pages/[page-name].spec.ts --workers=1 > test-failures.txt

# Review failures:
cat test-failures.txt | grep "Error"
# Should see: "Cannot find page /members" or similar
```

---

## 🟢 Phase 2: Implement Feature (Green)

### 1. Create page component

```bash
# Location: admin-frontend/src/pages/[PageName]Page.tsx

# Example structure:
# - Import hooks (useState, useEffect)
# - Import API service (getMembers, getProducts, etc.)
# - Create component with data fetching
# - Render table/list with data
# - Add create/edit/delete handlers
```

### 2. Add API integration

```bash
# Location: admin-frontend/src/services/[module].ts

# Example:
# export async function getMembers(page = 1, perPage = 20) {
#   const response = await get(`/members`, { params: { page, per_page: perPage }})
#   return response.data
# }
```

### 3. Create React component

```tsx
// src/pages/MembersPage.tsx
import { useEffect, useState } from 'react'
import { getMembers } from '../services/members'

export function MembersPage() {
  const [members, setMembers] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  useEffect(() => {
    getMembers()
      .then(setMembers)
      .catch(setError)
      .finally(() => setLoading(false))
  }, [])

  if (loading) return <div>Loading...</div>
  if (error) return <div>Error: {error.message}</div>

  return (
    <table>
      <thead>
        <tr>
          <th>Name</th>
          <th>RFID</th>
          <th>Balance</th>
        </tr>
      </thead>
      <tbody>
        {members.map(m => (
          <tr key={m.id}>
            <td>{m.first_name} {m.last_name}</td>
            <td>{m.card_uid}</td>
            <td>{m.balance_cents / 100} €</td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}
```

### 4. Add to routing

```tsx
// src/App.tsx - Add route
<Route path="/members" element={<MembersPage />} />
```

### 5. Verify compilation

```bash
# Check for TypeScript errors
npm run type-check

# Check for ESLint warnings
npm run lint

# No errors should appear
```

---

## 🔵 Phase 3: Visual Verification with Playwright MCP

### 1. Start development server

```bash
# In separate terminal:
npm run dev

# Wait for:
# ✨ Vite dev server running at http://localhost:5173
```

### 2. Open page in browser

**Using Playwright MCP**:

```javascript
// In Claude Code terminal:

// Navigate to page
mcp__playwright__browser_navigate("http://localhost:5173/members")

// Wait for page load
mcp__playwright__browser_wait_for(text="Loading", seconds=2)

// Take screenshot
mcp__playwright__browser_take_screenshot("phase2-members-loaded.png")
```

### 3. Visual Verification Checklist

Compare screenshot with prototype (`/prototypes/frgs-admin.html`):

- [ ] **Layout**: Table displayed, columns visible
- [ ] **Colors**: Match design system (backgrounds, text colors)
- [ ] **Typography**: Font sizes, weights correct
- [ ] **Spacing**: Padding/margins look balanced
- [ ] **Data**: Members listed with correct columns
- [ ] **Loading**: Loading state appears during fetch
- [ ] **Error**: Error message displays if API fails

### 4. Test interactions

```javascript
// Click button
mcp__playwright__browser_click(
  element="Create Member button",
  ref="[button ref from snapshot]"
)

// Verify modal appeared
mcp__playwright__browser_snapshot()
mcp__playwright__browser_take_screenshot("modal-opened.png")

// Check modal matches prototype
```

### 5. Test form submission

```javascript
// Fill form fields
mcp__playwright__browser_fill_form(fields=[
  { name: "First Name", ref: "...", type: "textbox", value: "John" },
  { name: "Last Name", ref: "...", type: "textbox", value: "Doe" },
])

// Click submit button
mcp__playwright__browser_click(element="Submit button", ref="...")

// Verify success message or new item in list
mcp__playwright__browser_wait_for(text="Member created successfully")
```

### 6. Debug failures

```javascript
// Take snapshot (accessibility tree)
mcp__playwright__browser_snapshot()

// Get console errors
mcp__playwright__browser_console_messages(level="error")

// Check network requests
mcp__playwright__browser_network_requests(includeStatic=false)
```

---

## ✅ Phase 4: Run Tests & Verify (Green Phase Complete)

### 1. Serial execution (1 worker - debugging)

```bash
npm test -- tests/pages/[page-name].spec.ts --workers=1

# Expected output:
# ✓ 1) should list members
# ✓ 2) should search members
# ... (all tests passing - GREEN phase)

# Record count: "N/N tests passing"
```

### 2. Fix failing tests

If any tests fail:

```bash
# 1. Read error message carefully
npm test -- tests/pages/[page-name].spec.ts --workers=1 2>&1 | tail -50

# 2. Use Playwright MCP to debug
mcp__playwright__browser_navigate("http://localhost:5173/members")
mcp__playwright__browser_take_screenshot("debug.png")

# 3. Check console errors
mcp__playwright__browser_console_messages(level="error")

# 4. Verify API response
curl -s http://localhost:8080/api/admin/members | jq .

# 5. Fix code, re-run tests
npm test -- tests/pages/[page-name].spec.ts --workers=1
```

### 3. Record test results

```bash
# Capture passing tests
npm test -- tests/pages/[page-name].spec.ts --workers=1 > test-results.txt

# Count: "N/N tests passing ✓"
```

---

## 🔶 Phase 5: Parallel Execution Safety Check

### 1. Run with 4 workers

```bash
npm test -- tests/pages/[page-name].spec.ts --workers=4

# Expected output:
# ✓ 1) should list members
# ✓ 2) should search members
# ... (all tests passing with all workers)

# If ANY test fails only in parallel mode:
# → Test isolation issue (see next step)
```

### 2. Fix flaky tests (if needed)

If tests pass with `--workers=1` but fail with `--workers=4`:

```bash
# Problem: Shared test state or database cleanup

# Solution: Check
# 1. Each test creates unique data (timestamps, UUIDs)
# 2. Each test cleans up its own data
# 3. No hardcoded IDs or shared state
# 4. Database cleanup in afterEach hook

# Reference: E2E Testing Patterns (001-004)

# Retry with 1 worker, fix issues, retry with 4
npm test -- tests/pages/[page-name].spec.ts --workers=1 --grep "flaky test"
# Fix code
npm test -- tests/pages/[page-name].spec.ts --workers=4 --grep "flaky test"
```

### 3. Final verification

```bash
# Run all tests for this page one more time
npm test -- tests/pages/[page-name].spec.ts --workers=4

# Expected: ✓ N/N tests passing (all workers, no failures)
```

---

## 💾 Phase 6: Commit & Close Milestone

### 1. Verify no uncommitted changes besides code

```bash
git status
# Should show: tests/pages/[page].spec.ts (new)
#              src/pages/[Page]Page.tsx (new)
#              src/services/[module].ts (modified)
```

### 2. Stage and commit

```bash
git add admin-frontend/tests/pages/[page].spec.ts
git add admin-frontend/src/pages/[Page]Page.tsx
git add admin-frontend/src/services/[module].ts
git add admin-frontend/src/App.tsx  # if routing changed

git commit -m "[Phase 2 Milestone 2.X] Implement [Page Name] page

- Write E2E tests for [page] CRUD workflows
- Implement [Page Name] React component with TypeScript
- Full API integration: GET/POST/PATCH/DELETE endpoints
- Form validation and error handling
- Loading states and user feedback
- 7/7 E2E tests passing (serial and parallel execution)
- Visual verification complete against prototype design
- All backend patterns applied

Tests: ✓ 7/7 passing
Performance: < 2s page load
Design: ✓ Matches prototype screenshot"
```

### 3. Update plan

```bash
# Edit phase4-admin-frontend.md
# Update milestone status:
# ✅ Milestone 2.1: Members Page - COMPLETE
# Tests: 7/7 passing ✓
```

---

## 📊 Milestone Checklist Template

**Use this for each page/milestone**:

```
# Milestone 2.X: [Page Name] Page

## Red Phase (Tests Written)
- [ ] Test file created: tests/pages/[page].spec.ts
- [ ] All test cases written (X tests)
- [ ] Tests run and fail as expected: "X/X tests failing ✗"

## Green Phase (Implementation)
- [ ] Page component created: src/pages/[Page]Page.tsx
- [ ] API service methods created: src/services/[module].ts
- [ ] Routes added to App.tsx
- [ ] TypeScript compilation: npm run type-check ✓
- [ ] Linting: npm run lint ✓
- [ ] Tests now pass: "X/X tests passing ✓"

## Blue Phase (Visual Verification)
- [ ] Dev server running: http://localhost:5173
- [ ] Page visually verified with Playwright MCP
- [ ] Screenshot compared with prototype
- [ ] Interactions tested (modals, forms, buttons)
- [ ] Design system colors/spacing verified
- [ ] Error handling verified
- [ ] Loading states verified

## Parallel Execution
- [ ] Tests pass with 1 worker: "X/X tests passing ✓"
- [ ] Tests pass with 4 workers: "X/X tests passing ✓"
- [ ] No flaky tests detected
- [ ] Execution time acceptable (< 30s total)

## Commit & Review
- [ ] Changes staged for commit
- [ ] Commit message written with test results
- [ ] Commit pushed to feature branch
- [ ] PR/review request created
- [ ] Plan updated with completion status
```

---

## 🚀 Quick Command Reference

**Most frequently used commands**:

```bash
# Start dev server (separate terminal)
npm run dev

# Run tests for current page (1 worker - debugging)
npm test -- tests/pages/[page].spec.ts --workers=1

# Run tests with all workers (parallel - final check)
npm test -- tests/pages/[page].spec.ts --workers=4

# Run specific test by name
npm test -- --grep "should list members" --workers=1

# Type check
npm run type-check

# Lint
npm run lint

# Check console errors in browser
mcp__playwright__browser_console_messages(level="error")

# Take screenshot
mcp__playwright__browser_take_screenshot("[page]-screenshot.png")

# Navigate to page
mcp__playwright__browser_navigate("http://localhost:5173/[page]")

# Open test UI (interactive debugging)
npm test -- tests/pages/[page].spec.ts --ui
```

---

## 🎯 Phase 2 Milestone Order

**Recommended implementation sequence**:

1. **Products** (1st - simplest)
   - Simple CRUD: create, read, update, delete
   - Establishes patterns
   - Low risk of complications

2. **Members** (2nd - moderate)
   - More complex: multiple fields, validations
   - Modals for forms
   - Adds pagination

3. **Journal** (3rd - filtering)
   - Member-centric view
   - Filtering and date range
   - Read-only (no CRUD)

4. **Settlements** (4th - complex workflow)
   - Multi-step creation (preview → confirm)
   - Export functionality
   - Business logic complexity

5. **Statistics** (5th - data visualization)
   - Dashboard metrics
   - Chart rendering
   - Report generation

---

## 📚 Test Template Examples

### Example 1: Simple CRUD Test

```typescript
test('should create member', async ({ page }) => {
  // Navigate
  await page.goto('http://localhost:5173/members')

  // Click create button
  await page.click('[data-testid="create-button"]')

  // Fill form
  await page.fill('[data-testid="first-name"]', 'John')
  await page.fill('[data-testid="last-name"]', 'Doe')

  // Submit
  await page.click('[data-testid="submit-button"]')

  // Verify success
  await expect(page.locator('text=Member created')).toBeVisible()
  await expect(page.locator('text=John Doe')).toBeVisible()
})
```

### Example 2: Search/Filter Test

```typescript
test('should filter by search term', async ({ page }) => {
  await page.goto('http://localhost:5173/members')

  // Search
  await page.fill('[data-testid="search"]', 'John')

  // Verify filtered results
  const rows = await page.locator('tbody tr')
  expect(rows).not.toContainText('Jane')
  expect(rows).toContainText('John')
})
```

### Example 3: Error Handling Test

```typescript
test('should show error on API failure', async ({ page, context }) => {
  // Mock API to return error
  await context.route('**/api/admin/members', route => {
    route.abort('failed')
  })

  await page.goto('http://localhost:5173/members')

  // Verify error message
  await expect(page.locator('text=Error loading members')).toBeVisible()
})
```

---

## ❓ FAQ

**Q: Test fails with "Cannot find element"**
A: Element doesn't exist in rendered page. Check:
   1. Component renders with correct data-testid or selector
   2. Use `mcp__playwright__browser_snapshot()` to see DOM tree
   3. Wait for element: `await page.waitForSelector('[testid]')`

**Q: Test passes with 1 worker but fails with 4 workers**
A: Test isolation issue. Check:
   1. Each test uses unique data (add timestamp to names)
   2. Database cleaned between tests
   3. No shared state between tests
   4. Follow E2E Testing Patterns 001-004

**Q: How do I debug a failing test?**
A: Use Playwright MCP:
   1. `npm run dev` (start server)
   2. `mcp__playwright__browser_navigate("http://localhost:5173/page")`
   3. `mcp__playwright__browser_take_screenshot("debug.png")`
   4. `mcp__playwright__browser_snapshot()` (see DOM)
   5. `mcp__playwright__browser_console_messages(level="error")`

**Q: When should I use --workers=1 vs --workers=4?**
A:
   - Use `--workers=1` during development (faster debugging)
   - Use `--workers=4` before committing (verify no flaky tests)
   - Default in CI/CD: `--workers=4` (fast execution)

**Q: How long should each test take?**
A: Ideal: < 2 seconds per test
   - Average page load: 500ms
   - User interactions: 200-500ms
   - Assertions: < 100ms
   - If tests take > 5s each, investigate bottlenecks

---

**🎉 Follow this checklist and you'll have working, tested, visually verified frontend pages!**
