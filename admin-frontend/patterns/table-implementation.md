# Table Implementation Pattern

This document defines the standardized pattern for implementing data tables in the admin panel. The pattern is derived from the Members and Products pages and ensures consistency across all table-based interfaces.

## Table of Contents
1. [Page Structure](#page-structure)
2. [State Management](#state-management)
3. [Data Loading](#data-loading)
4. [UI Components](#ui-components)
5. [Search & Filter Controls](#search--filter-controls)
6. [Table Components](#table-components)
7. [Pagination](#pagination)
8. [Modals](#modals)
9. [E2E Testing](#e2e-testing)
10. [Common Pitfalls](#common-pitfalls)

---

## Page Structure

Every data table page follows this layout:

```
┌─────────────────────────────────────────┐
│  <h1>Title</h1>                         │
├─────────────────────────────────────────┤
│  [Count] [Search] [Filters] [Create]    │
├─────────────────────────────────────────┤
│  [Table Header]                         │
│  [Table Rows] × N                       │
│  [Table Empty State / Loading State]    │
├─────────────────────────────────────────┤
│  [Pagination Toolbar]                   │
└─────────────────────────────────────────┘
```

**Don't wrap table in Card component** - Use simple `<div>` with `padding: '20px'`

```tsx
return (
  <div data-testid="page-name-page" style={{ padding: '20px' }}>
    <h1 style={{ margin: '0 0 20px 0' }}>Page Title</h1>

    {/* Optional: Stats cards at top */}
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: theme.spacing.xl, marginBottom: theme.spacing['2xl'] }}>
      <StatCard {...} />
    </div>

    {/* Search/Filter toolbar */}
    {/* Table */}
    {/* Pagination */}
  </div>
)
```

---

## State Management

### Required State Variables

```tsx
const [items, setItems] = useState<Item[]>([])
const [totalItems, setTotalItems] = useState(0)
const [loading, setLoading] = useState(true)
const [error, setError] = useState<string | null>(null)

// Pagination
const [page, setPage] = useState(1)

// Search
const [search, setSearch] = useState('')

// Sorting
const [sortKey, setSortKey] = useState<'field1' | 'field2'>('field1')
const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('desc')
const [sortByValue, setSortByValue] = useState('field1-desc') // For dropdown display

// Filtering
const [filterStatus, setFilterStatus] = useState<'all' | 'active' | 'inactive'>('all')

// Modal state
const [showModal, setShowModal] = useState(false)
const [editingItem, setEditingItem] = useState<Item | null>(null)
const [deleteConfirm, setDeleteConfirm] = useState<string | null>(null)

// Global loading context
const { setIsLoading } = useLoading()
```

---

## Data Loading

### useEffect Pattern

```tsx
useEffect(() => {
  const loadItems = async () => {
    try {
      setLoading(true)
      setIsLoading(true)

      // Build filter object
      const filter: { is_active?: boolean } = {}
      if (filterStatus === 'active') {
        filter.is_active = true
      } else if (filterStatus === 'inactive') {
        filter.is_active = false
      }

      // Call API with all parameters
      const response = await getItems(page, 20, search || undefined, filter, sortKey, sortDirection)

      // Update state
      setItems(response.items)
      setTotalItems(response.total)
      setError(null)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load items')
    } finally {
      setLoading(false)
      setIsLoading(false)
    }
  }

  // Debounce search
  const timer = setTimeout(loadItems, search ? 500 : 0)
  return () => clearTimeout(timer)
}, [page, search, filterStatus, sortKey, sortDirection, setIsLoading])
```

**Key Points:**
- Always debounce search (500ms)
- Include all filter/sort dependencies in dependency array
- Catch errors and set error state
- Clean up debounce timer on unmount

---

## UI Components

### Required Imports

```tsx
import { useBreakpoint } from '../../hooks/useBreakpoint'
import { useLoading } from '../../context/LoadingContext'
import { theme } from '../../styles/design-system'
import { formatPrice, formatDate } from '../../styles/design-system'
import {
  tableWrapperStyles,
  tableElementStyles,
  headerRowStyle,
  headerCellBaseStyle,
  tableColors,
  tableSpacing,
  getRowStyle,
} from '../../styles/tableTokens'
```

### Icon Imports

Always import from `'../components/icons'`:
```tsx
import {
  UsersIcon,
  PackageIcon,
  TrashIcon,
  EditIcon,
  PlusIcon,
  BookIcon
} from '../components/icons'
```

---

## Search & Filter Controls

### Single-Row Control Layout

Combine search, filters, and create button on one row:

```tsx
<div
  style={{
    display: 'flex',
    gap: theme.spacing.md,
    padding: `${theme.spacing.md} ${theme.spacing.lg}`,
    borderBottom: `1px solid ${tableColors.rowActiveBorder}`,
    alignItems: 'center',
    justifyContent: 'space-between',
  }}
>
  {/* LEFT: Count summary */}
  <span data-testid="items-count-summary" style={{ color: theme.colors.text.secondary, fontSize: '14px', whiteSpace: 'nowrap' }}>
    <strong style={{ color: theme.colors.text.primary }}>{totalItems}</strong> Items gefunden
  </span>

  {/* CENTER-LEFT: Search input (max-width 400px) */}
  <input
    type="text"
    value={search}
    onChange={(e) => {
      setSearch(e.target.value)
      setPage(1)
    }}
    placeholder="Search items..."
    data-testid="items-search-input"
    style={{
      flex: 1,
      padding: '8px 12px',
      backgroundColor: '#0d1829',
      border: '1px solid #2d3748',
      borderRadius: 8,
      color: '#e2e8f0',
      fontSize: '14px',
      maxWidth: '400px',
      height: '40px',
      boxSizing: 'border-box',
      transition: 'all 0.15s',
    }}
    onFocus={(e) => {
      e.currentTarget.style.borderColor = 'rgba(59,130,246,0.5)'
    }}
    onBlur={(e) => {
      e.currentTarget.style.borderColor = '#2d3748'
    }}
  />

  {/* CENTER-RIGHT: Filter + Sort dropdowns */}
  <div style={{ display: 'flex', gap: theme.spacing.md, alignItems: 'center' }}>
    <StatusFilter
      value={filterStatus}
      onChange={(status) => {
        setFilterStatus(status)
        setPage(1)
      }}
      testId="items-filter-status"
    />

    <SortDropdown
      options={[
        { value: 'created_at-desc', label: 'Newest first', direction: 'desc' },
        { value: 'name-asc', label: 'Name (A-Z)', direction: 'asc' },
      ]}
      value={sortByValue}
      onChange={(value) => {
        const [key, direction] = value.split('-')
        setSortKey(key as 'created_at' | 'name')
        setSortDirection(direction as 'asc' | 'desc')
        setSortByValue(value)
        setPage(1)
      }}
      testId="items-sort"
    />
  </div>

  {/* RIGHT: Create button */}
  <button
    data-testid="items-create-button"
    onClick={() => {
      setEditingItem(null)
      setShowModal(true)
    }}
    style={{
      display: 'flex',
      alignItems: 'center',
      gap: theme.spacing.sm,
      padding: `${tableSpacing.cellPaddingVertical} ${tableSpacing.cellPaddingHorizontal}`,
      background: theme.colors.semantic.primary,
      border: 'none',
      borderRadius: '6px',
      color: 'white',
      cursor: 'pointer',
      fontSize: '14px',
      fontWeight: '500',
      whiteSpace: 'nowrap',
    }}
  >
    <PlusIcon size={18} />
    <span>Erstellen</span>
  </button>
</div>
```

**Key Points:**
- Count on LEFT (no wrapping)
- Search input max-width 400px with dark styling
- Filters + Sort in CENTER
- Create button on RIGHT
- All on ONE row
- Search resets to page 1
- Filter/sort changes reset to page 1

---

## Table Components

### Import Cell Components

```tsx
import { StatusToggleCell } from '../components/tables/StatusToggleCell'
import { TableCell } from '../components/tables/TableCell'
import { PriceCell } from '../components/tables/PriceCell'           // For currency values
import { BadgeCell } from '../components/tables/BadgeCell'           // For category tags
import { IconCell } from '../components/tables/IconCell'             // For icons + labels
import { ActionButtons } from '../components/tables/ActionButtons'   // For edit/delete buttons
```

### Table Structure

```tsx
<div data-testid="items-table-wrapper" style={tableWrapperStyles}>
  <table data-testid="items-table" style={tableElementStyles}>

    {/* HEADER */}
    <thead>
      <tr style={headerRowStyle}>
        <th style={{ ...headerCellBaseStyle, width: '80px', textAlign: 'center' }}>Status</th>
        <th style={headerCellBaseStyle}>
          <SortableTableHeader
            label="Name"
            sortKey="name"
            currentSort={{ key: sortKey, direction: sortDirection }}
            onSort={(key: string, direction: 'asc' | 'desc') => {
              setSortKey(key as 'name' | 'created_at')
              setSortDirection(direction)
              setSortByValue(`${key}-${direction}`)
              setPage(1)
            }}
          />
        </th>
        <th style={{ ...headerCellBaseStyle, width: '200px', textAlign: 'center' }}>Actions</th>
      </tr>
    </thead>

    {/* BODY */}
    <tbody>
      {items.map((item) => (
        <tr
          key={item.id}
          data-testid={`items-table-row-${item.id}`}
          style={getRowStyle(item.is_active)}
          onMouseEnter={(e: React.MouseEvent<HTMLTableRowElement>) => {
            if (item.is_active) {
              e.currentTarget.style.backgroundColor = tableColors.rowActiveHoverBg
            }
          }}
          onMouseLeave={(e: React.MouseEvent<HTMLTableRowElement>) => {
            e.currentTarget.style.backgroundColor = item.is_active
              ? tableColors.rowActiveBg
              : tableColors.rowInactiveBg
          }}
        >
          {/* Status Toggle */}
          <StatusToggleCell
            enabled={item.is_active}
            onChange={() => handleStatusToggle(item)}
            testId={`items-status-toggle-${item.id}`}
          />

          {/* Name/Title */}
          <TableCell testId={`items-table-cell-name-${item.id}`}>
            {item.name}
          </TableCell>

          {/* Actions */}
          <TableCell align="center">
            <button
              data-testid={`items-table-action-edit-${item.id}`}
              onClick={() => handleEdit(item)}
              style={{
                background: 'transparent',
                border: 'none',
                color: theme.colors.semantic.primary,
                cursor: 'pointer',
                padding: theme.spacing.sm,
              }}
              title="Edit"
            >
              <EditIcon size={18} />
            </button>
            <button
              data-testid={`items-table-action-delete-${item.id}`}
              onClick={() => setDeleteConfirm(item.id)}
              style={{
                background: 'transparent',
                border: 'none',
                color: theme.colors.semantic.danger,
                cursor: 'pointer',
                padding: theme.spacing.sm,
                marginLeft: theme.spacing.md,
              }}
              title="Delete"
            >
              <TrashIcon size={18} />
            </button>
          </TableCell>
        </tr>
      ))}
    </tbody>
  </table>
</div>
```

**Key Points:**
- Use `getRowStyle(item.is_active)` for consistent row styling
- Add `onMouseEnter`/`onMouseLeave` for hover effects
- Use cell components (StatusToggleCell, TableCell, etc.) not raw `<td>`
- All cells have `data-testid` attributes
- Action buttons have semantic colors (primary for edit, danger for delete)
- Icon size: 18px

---

## Pagination

### Always include pagination when loading paginated data

```tsx
{!loading && items.length > 0 && (
  <PaginationToolbar
    currentPage={page}
    totalPages={Math.ceil(totalItems / 20)}
    totalItems={totalItems}
    pageSize={20}
    onPageChange={setPage}
    onPageSizeChange={() => {}} // Not implemented - always use 20
    variant="default"
    showPageSize={false}
    showInfo={true}
    testId="items-pagination"
  />
)}
```

---

## Modals

### Create/Edit Modal Pattern

```tsx
{showModal && (
  <div
    data-testid="items-form-modal"
    style={{
      position: 'fixed',
      top: 0,
      left: 0,
      right: 0,
      bottom: 0,
      background: 'rgba(0, 0, 0, 0.5)',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      zIndex: 1000,
    }}
    onClick={() => setShowModal(false)}
  >
    <div
      data-testid="items-form-modal-content"
      style={{
        background: theme.colors.bg.secondary,
        borderRadius: theme.borderRadius.lg,
        padding: theme.spacing.xl,
        maxWidth: '500px',
        width: '90%',
        boxShadow: '0 25px 50px rgba(0, 0, 0, 0.5)',
      }}
      onClick={(e) => e.stopPropagation()}
    >
      <h2 data-testid="items-form-title" style={{ margin: '0 0 20px 0' }}>
        {editingItem ? 'Edit Item' : 'Create Item'}
      </h2>

      <form onSubmit={handleSubmit}>
        {/* Form fields */}
      </form>
    </div>
  </div>
)}
```

### Delete Confirmation Modal Pattern

```tsx
{deleteConfirm && (
  <div
    data-testid="items-delete-confirm-modal"
    style={{
      position: 'fixed',
      top: 0,
      left: 0,
      right: 0,
      bottom: 0,
      background: 'rgba(0, 0, 0, 0.5)',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      zIndex: 1001,
    }}
    onClick={() => setDeleteConfirm(null)}
  >
    <div
      data-testid="items-delete-confirm-content"
      style={{
        background: theme.colors.bg.secondary,
        borderRadius: theme.borderRadius.lg,
        padding: theme.spacing.xl,
        maxWidth: '400px',
        width: '90%',
        boxShadow: '0 25px 50px rgba(0, 0, 0, 0.5)',
      }}
      onClick={(e) => e.stopPropagation()}
    >
      <h2 style={{ margin: 0, marginBottom: theme.spacing.lg, fontSize: theme.typography.fontSize.lg }}>
        Confirm Delete
      </h2>
      <p style={{ color: theme.colors.text.secondary, marginBottom: theme.spacing.lg }}>
        Are you sure? This action cannot be undone.
      </p>

      <div style={{ display: 'flex', gap: theme.spacing.lg, justifyContent: 'flex-end' }}>
        <button
          data-testid="items-delete-confirm-cancel"
          onClick={() => setDeleteConfirm(null)}
          style={{
            padding: `${theme.spacing.md} ${theme.spacing.lg}`,
            background: 'transparent',
            border: `1px solid ${theme.colors.border.light}`,
            borderRadius: theme.borderRadius.md,
            color: theme.colors.text.primary,
            cursor: 'pointer',
            fontSize: theme.typography.fontSize.sm,
            fontWeight: theme.typography.fontWeight.semibold,
          }}
        >
          Cancel
        </button>
        <button
          data-testid="items-delete-confirm-ok"
          onClick={() => handleDelete(deleteConfirm)}
          style={{
            padding: `${theme.spacing.md} ${theme.spacing.lg}`,
            background: theme.colors.semantic.danger,
            border: 'none',
            borderRadius: theme.borderRadius.md,
            color: 'white',
            cursor: 'pointer',
            fontSize: theme.typography.fontSize.sm,
            fontWeight: theme.typography.fontWeight.semibold,
          }}
        >
          Delete
        </button>
      </div>
    </div>
  </div>
)}
```

---

## E2E Testing

### Page Object Pattern

```tsx
export class ItemsPage extends BasePage {
  // Search
  private readonly searchInput = () => this.page.getByTestId('items-search-input')

  // Filter
  private readonly statusFilterTrigger = () => this.page.getByTestId('items-filter-status-trigger')

  // Create button
  private readonly createBtn = () => this.page.getByTestId('items-create-button')

  // Table
  private readonly table = () => this.page.getByTestId('items-table')
  private readonly tableRows = () => this.page.locator('[data-testid^="items-table-row-"]')

  // Pagination
  private readonly pagination = () => this.page.getByTestId('items-pagination')

  // SEMANTIC METHODS (high-level, not raw locators)
  async search(query: string) {
    await this.searchInput().fill(query)
    await this.waitForDebounce(500)
  }

  async setFilter(status: 'All Items' | 'Active Only' | 'Inactive Only') {
    await this.statusFilterTrigger().click()
    await this.page.getByText(status).click()
  }

  async expectTableVisible() {
    await expect(this.table()).toBeVisible()
  }

  async getItemCount(): Promise<number> {
    return await this.tableRows().count()
  }
}
```

### E2E Test Pattern

```tsx
test('should create item and display in table', async ({ authenticatedItemsPage }) => {
  const initialCount = await authenticatedItemsPage.getItemCount()

  // Create item
  await authenticatedItemsPage.createItem('Test Item', '10.00')

  // Verify modal closed
  await authenticatedItemsPage.expectFormModalHidden()

  // Verify no errors
  const error = await authenticatedItemsPage.getErrorMessage()
  expect(error).toBeNull()

  // Verify count increased
  const newCount = await authenticatedItemsPage.getItemCount()
  expect(newCount).toBe(initialCount + 1)
})

test('should filter items correctly', async ({ authenticatedItemsPage }) => {
  await authenticatedItemsPage.navigate()

  // Set filter
  await authenticatedItemsPage.setFilter('Active Only')

  // Verify only active items shown
  const rows = await authenticatedItemsPage.page.locator('[data-testid^="items-table-row-"]').all()
  for (const row of rows) {
    const toggle = row.locator('[data-testid*="items-status-toggle"]')
    const isChecked = await toggle.getAttribute('data-checked')
    expect(isChecked).toBe('true')
  }
})
```

---

## Common Pitfalls

### 1. ❌ Relying on `setPage(1)` to Trigger Reload

**Problem:** When page is already 1, `setPage(1)` doesn't trigger useEffect re-run

**Solution:** Directly call API in mutation handlers:
```tsx
// ❌ DON'T
const handleDelete = async (itemId: string) => {
  await deleteItem(itemId)
  setPage(1)  // Might not trigger if already page 1
}

// ✅ DO
const handleDelete = async (itemId: string) => {
  await deleteItem(itemId)

  // Directly reload with current filters
  const response = await getItems(page, 20, search, filter, sortKey, sortDirection)
  setItems(response.items)
  setTotalItems(response.total)
}
```

### 2. ❌ Using Native `<select>` for Filters

**Problem:** `selectOption()` doesn't trigger React synthetic events

**Solution:** Use custom dropdown components (StatusFilter, SortDropdown)

### 3. ❌ Inconsistent Cell Styling

**Problem:** Using inline `<td>` with custom styles instead of reusable components

**Solution:** Use cell components:
- `StatusToggleCell` - status toggle
- `TableCell` - generic text cell
- `PriceCell` - currency values
- `BadgeCell` - category tags
- `IconCell` - icons with labels
- `ActionButtons` - edit/delete buttons

### 4. ❌ Missing Error States in UI

**Problem:** Error messages not displayed to user

**Solution:** Always display error state:
```tsx
{error && (
  <div
    data-testid="items-error-message"
    style={{
      padding: theme.spacing.lg,
      background: `${theme.colors.semantic.danger}20`,
      borderBottom: `1px solid ${theme.colors.semantic.danger}`,
      color: theme.colors.semantic.danger,
    }}
  >
    {error}
  </div>
)}
```

### 5. ❌ Missing Loading State

**Problem:** No indication while data is loading

**Solution:** Always show loading state:
```tsx
{loading ? (
  <div style={{ padding: theme.spacing.xl, textAlign: 'center', color: theme.colors.text.secondary }}>
    Loading items...
  </div>
) : items.length === 0 ? (
  <div style={{ padding: theme.spacing.xl, textAlign: 'center', color: theme.colors.text.secondary }}>
    No items found
  </div>
) : (
  /* Table */
)}
```

### 6. ❌ Forgetting `data-testid` Attributes

**Problem:** E2E tests can't find elements reliably

**Solution:** Add `data-testid` to every interactive element:
- `{page}-page`
- `{page}-count-summary`
- `{page}-search-input`
- `{page}-filter-{type}-trigger`
- `{page}-sort-trigger`
- `{page}-create-button`
- `{page}-table`
- `{page}-table-row-{id}`
- `{page}-table-cell-{name}`
- `{page}-table-action-edit-{id}`
- `{page}-table-action-delete-{id}`
- `{page}-pagination`
- `{page}-form-modal`
- `{page}-delete-confirm-modal`
- `{page}-error-message`
- `{page}-loading`
- `{page}-empty-state`

---

## References

- **Members Page**: `/admin-frontend/src/pages/MembersPage.tsx` (reference implementation)
- **Products Page**: `/admin-frontend/src/pages/ProductsPage.tsx` (reference implementation)
- **Table Components**: `/admin-frontend/src/components/tables/`
- **Table Tokens**: `/admin-frontend/src/styles/tableTokens.ts`
- **E2E Patterns**: `/e2etests/patterns/`
