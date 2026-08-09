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

### Use `useListQuery` — never hand-roll list state

Page, page size, sort key and direction, filters, search and its debounce all
live in **[`src/hooks/useListQuery.ts`](../src/hooks/useListQuery.ts)**. Six
pages used to declare those eight state variables each; the copies drifted, and
the drift is where the dropped-filter, stale-response and page-reset bugs came
from (#121).

```tsx
type ItemSortKey = 'name' | 'created_at'

interface ItemFilters {
  status: 'all' | 'active' | 'inactive'
}

const list = useListQuery<Item, ItemFilters, ItemSortKey>({
  initialFilters: { status: 'all' },
  initialSortKey: 'created_at',
  initialSortDirection: 'desc',
  initialPageSize: 20,
  // `signal` must be forwarded so an abandoned request is actually cancelled.
  fetcher: async ({ page, pageSize, sortKey, sortDirection, search, filters, signal }) => {
    const params: ListItemsParams = { page, per_page: pageSize, sort_by: `${sortKey}_${sortDirection}` }
    if (search) params.search = search
    if (filters.status !== 'all') params.status = filters.status

    const response = await getItems().listItems(params, { signal })
    return { items: response.data ?? [], total: response.pagination?.total ?? 0 }
  },
  parseError: (err) => (err instanceof Error ? err.message : 'Failed to load items'),
})

const { items, total: totalItems, totalPages, loading, error, setError } = list
```

Everything the page still owns is genuinely page-specific:

```tsx
// Modal state
const [showModal, setShowModal] = useState(false)
const [editingItem, setEditingItem] = useState<Item | null>(null)
const [deleteConfirm, setDeleteConfirm] = useState<string | null>(null)

// Global loading context — mirror the list's own loading into it
const { setIsLoading } = useLoading()
useEffect(() => {
  setIsLoading(loading)
  return () => setIsLoading(false)
}, [loading, setIsLoading])
```

### What the hook guarantees

| Guarantee | Why it matters |
|---|---|
| One fetch path | The loader and every post-mutation reload send the same query, so a reload cannot drop the active filters |
| Abort per run | A superseded request is cancelled and its late answer never overwrites a newer page — the hook holds a `useLatestRequest` slot, so a list page needs no cancellation code of its own ([Data Fetching](./data-fetching.md)) |
| Debounce on search only | `search ? 500 : 0` — filters and paging stay instant |
| Page reset on every query change | Desktop sort headers and the mobile sort dropdown behave identically |
| Page clamp after a reload | Deleting the last item on the last page lands on the last page that exists, instead of an empty out-of-range one |
| `hasLoaded` alongside `loading` | Tells "no result yet" apart from "re-running a query that already came back empty" — the distinction a page needs before it replaces itself with a spinner (see [pitfall 5](#5--a-loading-state-that-unmounts-the-toolbar)) |

---

## Data Loading

There is no loader effect to write. Wire the controls to the hook's setters and
call `list.reload()` after a mutation:

```tsx
// Search box — the hook debounces and resets to page 1
<input value={list.search} onChange={(e) => list.setSearch(e.target.value)} />

// Filter pill / dropdown
<StatusFilterPills value={list.filters.status} onChange={(v) => list.setFilter('status', v)} />

// Desktop sort header — same key flips direction, a new key takes the default
<SortableTableHeader
  sortKey="name"
  currentSort={{ key: list.sortKey, direction: list.sortDirection }}
  onSort={(key, direction) => list.setSort(key as ItemSortKey, direction)}
/>

// Mobile sort dropdown — values are `"<key>_<direction>"`
<MobileToolbar sort={{ options, value: list.sortValue, onChange: list.setSortValue }} />

// After a create / update / delete
await deleteItem(id)
list.reload()
```

**Key Points:**
- Never re-fetch by hand in a mutation handler — `reload()` re-runs the *current* query
- Never call `setPage(1)` alongside a filter/sort/search change; the hook does it
- Always forward `signal` from the fetcher into the generated client call
- A page's *other* streams — a second fetch, an interval, a tab switch — need their own `useLatestRequest` slot; see [Data Fetching](./data-fetching.md)

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

### 1. ❌ Re-fetching by Hand in a Mutation Handler

**Problem:** A hand-written re-fetch has to restate the whole query. Every
restatement is a chance to forget a filter — and it forgot `sepa_status`,
`has_card_uid` and the current page in turn. `setPage(1)` is no better: it is a
no-op when the page is already 1, so nothing reloads at all.

```tsx
// ❌ DON'T — a second query that has to be kept in sync with the first
const handleDelete = async (itemId: string) => {
  await deleteItem(itemId)
  const response = await getItems(page, 20, search, filter, sortKey, sortDirection)
  setItems(response.items)
  setTotalItems(response.total)
}

// ✅ DO — re-run the query that is already active
const handleDelete = async (itemId: string) => {
  await deleteItem(itemId)
  list.reload()
}
```

`reload()` also clamps the page afterwards, so deleting the last row on the last
page lands on the last page that still exists rather than an empty one whose
pagination control is hidden.

### 1b. ❌ Declaring List State on the Page

**Problem:** `useState` for page/sort/filters/search on the page is exactly the
duplication #121 removed. The copies drift: one resets the page on sort, another
does not; one aborts stale requests, the rest do not.

**Solution:** `useListQuery`. See [State Management](#state-management).

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

### 5. ❌ A Loading State That Unmounts the Toolbar

**Problem:** Two different things are called "loading", and only one of them may
take space away from the admin. `loading && items.length === 0` reads like "the
first load", but it is also what a **search that matched nothing** looks like:
the next debounced keystroke unmounted the toolbar with the focused search box
inside it, and the rest of the word was typed into nothing (#137).

```tsx
// ❌ DON'T — the whole page disappears on every keystroke after an empty search
if (loading && items.length === 0) return <div>Loading…</div>
```

**Solution:** Gate the page-replacing state on `hasLoaded` (the first query has
settled, with items or with an error), and cover the *results region only* for
every later fetch:

```tsx
const { items, loading, hasLoaded } = list

// First load: nothing to show yet, so the page may take over.
if (!hasLoaded) return <div data-testid="items-loading">{t('common.loading')}</div>

// Every later fetch: the toolbar stays, the results are dimmed.
<ListLoadingOverlay loading={loading} label={t('common.loading')} testId="items-list-loading">
  <div data-testid="items-table-wrapper" style={tableWrapperStyles}>
    <table>…</table>
  </div>
  {totalItems === 0 && !loading && <EmptyState />}
</ListLoadingOverlay>
```

**Also:** keep the empty state gated on `!loading`, or a refresh over an empty
list claims "No items found" about a question it has not answered yet.

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

- **List Query Hook**: `/admin-frontend/src/hooks/useListQuery.ts` (page/sort/filter/search state)
- **Data Fetching Pattern**: `/admin-frontend/patterns/data-fetching.md` (cancellation for streams outside the list query)
- **Members Page**: `/admin-frontend/src/pages/MembersPage.tsx` (reference implementation)
- **Products Page**: `/admin-frontend/src/pages/ProductsPage.tsx` (reference implementation)
- **Table Components**: `/admin-frontend/src/components/tables/`
- **Table Tokens**: `/admin-frontend/src/styles/tableTokens.ts`
- **E2E Patterns**: `/e2etests/patterns/`
