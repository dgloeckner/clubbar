# Phase 4: Table Design System - Generify & Reuse Components

**Date**: 2026-01-28
**Status**: Planning
**Scope**: Extract reusable table patterns from ProductsPage; create design system for tables across admin UI

---

## Executive Summary

The Products table already implements many features that will be needed across multiple upcoming tables:
- **Styling**: Dark theme colors, borders, hover effects
- **Pagination**: Page navigation with page size selector
- **Sorting**: Clickable column headers with sort indicators
- **Filtering**: Search and category filtering
- **Actions**: Edit/Delete buttons with icon display
- **Status Toggle**: Active/Inactive state with visual feedback
- **Icon Display**: Product icons in rows
- **Responsive**: Scrollable wrapper with proper spacing

**Goal**: Extract these into reusable, generic components and patterns so new tables (Transactions, Settlements, Users, Categories) can be built quickly and consistently.

**Benefit**: Reduce code duplication, ensure consistent UX, faster implementation of remaining tables

---

## What's Already Been Extracted

### ✅ Existing Reusable Components

| Component | Location | Purpose | Used By |
|-----------|----------|---------|---------|
| **SortableTableHeader** | `tables/SortableTableHeader.tsx` | Clickable column headers with sort indicators | ProductsPage, CategoriesPage |
| **PaginationToolbar** | `tables/PaginationToolbar.tsx` | Pagination controls + page size selector | ProductsPage |
| **SortDropdown** | `tables/SortDropdown.tsx` | Sort order dropdown | ProductsPage search toolbar |
| **SearchAndSortToolbar** | `tables/SearchAndSortToolbar.tsx` | Combined search + sort UI | ProductsPage |
| **CategoryFilter** | `tables/CategoryFilter.tsx` | Product category filter | ProductsPage |

### ✅ What Works Well
- Sortable headers (component reusable, easy to integrate)
- Pagination toolbar (well-designed, supports variants)
- Filter toolbars (SearchAndSort pattern)

### ❌ What Still Lives in Page Components (NOT Extracted)

| Pattern | Location | Status |
|---------|----------|--------|
| **Table wrapper + table element** | ProductsPage lines 484-716 | Inline styles - NO generic component |
| **Table styling** (colors, borders, hover) | Inline styles throughout | NO CSS/utility classes |
| **Table header row** | ProductsPage line 494 | Inline, no component |
| **Table row** | ProductsPage line 583 | Inline, complex row component |
| **Table cells** | ProductsPage lines 605-650 | Multiple cell types (toggle, icon+text, badge, actions) |
| **Row hover effects** | ProductsPage lines 594-603 | Inline event handlers |
| **Status toggle button** | ProductsPage line 606 | Uses Toggle component (good), but wrapped in table logic |
| **Action buttons** (Edit/Delete) | ProductsPage lines 655-711 | Inline button styles, no component |
| **Icon display in row** | ProductsPage lines 614-617 | Inline icon logic |
| **Category badge display** | ProductsPage lines 628-644 | Inline badge styles |
| **Empty state** | ProductsPage (~line 739) | Inline styles |
| **Loading state** | Uses global loading indicator | Works, but not table-specific |
| **Confirmation dialogs** | ProductsPage lines 745+ | Inline, complex logic |

---

## Proposed Architecture

### Layer 1: Design Tokens (Colors, Spacing, etc.)

**File**: `admin-frontend/src/styles/tableTokens.ts`

Extract all hardcoded colors, spacing, and transitions into reusable tokens:

```typescript
// Colors
export const tableColors = {
  // Header
  headerBg: 'rgba(15, 29, 50, 0.6)',
  headerBorder: '1px solid rgba(71, 85, 105, 0.3)',
  headerText: '#cbd5e1',
  headerTextUppercase: true,
  headerFontSize: '12px',
  headerLetterSpacing: '0.05em',
  headerFontWeight: '600',

  // Row - active state
  rowActiveBg: 'rgba(30, 58, 138, 0.2)',
  rowActiveHoverBg: 'rgba(59, 130, 246, 0.15)',
  rowBorder: '1px solid rgba(71, 85, 105, 0.2)',
  rowOpacity: 1,

  // Row - inactive state
  rowInactiveBg: 'rgba(30, 58, 138, 0.1)',
  rowInactiveOpacity: 0.5,

  // Text
  cellText: '#e2e8f0',
  cellSecondaryText: '#a1aec6',

  // Badges
  badgeBg: 'rgba(71, 85, 105, 0.3)',
  badgeText: '#a1aec6',

  // Buttons
  buttonPrimaryBg: 'rgba(59, 130, 246, 0.15)',
  buttonPrimaryBorder: 'rgba(59, 130, 246, 0.3)',
  buttonPrimaryHoverBg: 'rgba(59, 130, 246, 0.25)',
  buttonPrimaryHoverBorder: 'rgba(59, 130, 246, 0.5)',
  buttonPrimaryText: '#3b82f6',

  buttonDangerBg: 'rgba(239, 68, 68, 0.15)',
  buttonDangerBorder: 'rgba(239, 68, 68, 0.3)',
  buttonDangerHoverBg: 'rgba(239, 68, 68, 0.25)',
  buttonDangerHoverBorder: 'rgba(239, 68, 68, 0.5)',
  buttonDangerText: '#ef4444',
}

// Spacing
export const tableSpacing = {
  cellPadding: '14px 16px',
  actionButtonSize: '40px',
  actionButtonGap: '10px',
}

// Transitions
export const tableTransitions = {
  standard: '150ms',
  slow: '200ms',
}
```

### Layer 2: Reusable Table Components

#### 2.1 **TableContainer** Component
**File**: `admin-frontend/src/components/tables/TableContainer.tsx`

Wraps the table with consistent styling:

```typescript
interface TableContainerProps {
  children: React.ReactNode
  testId?: string
}

export function TableContainer({ children, testId = 'table-wrapper' }: TableContainerProps) {
  return (
    <div
      data-testid={testId}
      style={{
        overflowX: 'auto',
        borderRadius: '16px',
        overflow: 'hidden',
      }}
    >
      <table
        style={{
          width: '100%',
          borderCollapse: 'collapse',
          backgroundColor: 'transparent',
        }}
      >
        {children}
      </table>
    </div>
  )
}
```

#### 2.2 **TableHeader** Component
**File**: `admin-frontend/src/components/tables/TableHeader.tsx`

Renders consistent `<thead>`:

```typescript
interface Column {
  id: string
  label: string
  sortable?: boolean
  sortKey?: string
  align?: 'left' | 'center' | 'right'
  width?: string
  testId?: string
}

interface TableHeaderProps {
  columns: Column[]
  currentSort?: { key: string; direction: 'asc' | 'desc' }
  onSort?: (key: string, direction: 'asc' | 'desc') => void
  testId?: string
}

export function TableHeader({ columns, currentSort, onSort, testId }: TableHeaderProps) {
  return (
    <thead>
      <tr style={{ backgroundColor: tableColors.headerBg, borderBottom: tableColors.headerBorder }}>
        {columns.map((col) => (
          <th
            key={col.id}
            data-testid={col.testId}
            style={{
              padding: tableSpacing.cellPadding,
              textAlign: col.align || 'left',
              fontWeight: tableColors.headerFontWeight,
              color: tableColors.headerText,
              fontSize: tableColors.headerFontSize,
              letterSpacing: tableColors.headerLetterSpacing,
              textTransform: 'uppercase',
              width: col.width,
            }}
          >
            {col.sortable && currentSort && onSort ? (
              <SortableTableHeader
                label={col.label}
                sortKey={col.sortKey || col.id}
                currentSort={currentSort}
                onSort={onSort}
              />
            ) : (
              col.label
            )}
          </th>
        ))}
      </tr>
    </thead>
  )
}
```

#### 2.3 **TableRow** Component
**File**: `admin-frontend/src/components/tables/TableRow.tsx`

Consistent row styling and hover effects:

```typescript
interface TableRowProps {
  id: string
  isActive?: boolean
  children: React.ReactNode
  onClick?: () => void
  testId?: string
}

export function TableRow({ id, isActive = true, children, onClick, testId }: TableRowProps) {
  const [hoverBg, setHoverBg] = React.useState<string | null>(null)

  const baseBg = isActive ? tableColors.rowActiveBg : tableColors.rowInactiveBg
  const opacity = isActive ? tableColors.rowOpacity : tableColors.rowInactiveOpacity

  return (
    <tr
      data-testid={testId || id}
      onClick={onClick}
      style={{
        borderBottom: tableColors.rowBorder,
        backgroundColor: hoverBg || baseBg,
        opacity,
        transition: `background-color ${tableTransitions.standard}`,
        cursor: onClick ? 'pointer' : 'default',
      }}
      onMouseEnter={(e) => {
        if (isActive) {
          setHoverBg(tableColors.rowActiveHoverBg)
        }
      }}
      onMouseLeave={() => {
        setHoverBg(null)
      }}
    >
      {children}
    </tr>
  )
}
```

#### 2.4 **TableCell** Component (Generic)
**File**: `admin-frontend/src/components/tables/TableCell.tsx`

```typescript
interface TableCellProps {
  children: React.ReactNode
  align?: 'left' | 'center' | 'right'
  monospace?: boolean
  testId?: string
}

export function TableCell({ children, align = 'left', monospace, testId }: TableCellProps) {
  return (
    <td
      data-testid={testId}
      style={{
        padding: tableSpacing.cellPadding,
        color: tableColors.cellText,
        textAlign: align,
        fontFamily: monospace ? 'JetBrains Mono, monospace' : 'inherit',
        fontSize: monospace ? '14px' : 'inherit',
        fontWeight: monospace ? '700' : 'inherit',
      }}
    >
      {children}
    </td>
  )
}
```

#### 2.5 **ActionButtons** Component
**File**: `admin-frontend/src/components/tables/ActionButtons.tsx`

Reusable action button container with Edit/Delete:

```typescript
interface ActionButtonsProps {
  onEdit?: () => void
  onDelete?: () => void
  editTestId?: string
  deleteTestId?: string
  size?: 'small' | 'medium'
}

export function ActionButtons({
  onEdit,
  onDelete,
  editTestId,
  deleteTestId,
  size = 'small',
}: ActionButtonsProps) {
  const buttonSize = size === 'small' ? '40px' : '44px'
  const iconSize = size === 'small' ? 18 : 20

  return (
    <td
      style={{
        padding: tableSpacing.cellPadding,
        display: 'flex',
        gap: tableSpacing.actionButtonGap,
        justifyContent: 'center',
      }}
    >
      {onEdit && (
        <ActionButton
          onClick={onEdit}
          icon={<EditIcon size={iconSize} />}
          color="primary"
          size={buttonSize}
          testId={editTestId}
        />
      )}
      {onDelete && (
        <ActionButton
          onClick={onDelete}
          icon={<TrashIcon size={iconSize} />}
          color="danger"
          size={buttonSize}
          testId={deleteTestId}
        />
      )}
    </td>
  )
}

// Internal helper for single action button
function ActionButton({
  onClick,
  icon,
  color,
  size,
  testId,
}: {
  onClick: () => void
  icon: React.ReactNode
  color: 'primary' | 'danger'
  size: string
  testId?: string
}) {
  const colors = color === 'primary' ? {
    bg: tableColors.buttonPrimaryBg,
    border: tableColors.buttonPrimaryBorder,
    text: tableColors.buttonPrimaryText,
    hoverBg: tableColors.buttonPrimaryHoverBg,
    hoverBorder: tableColors.buttonPrimaryHoverBorder,
  } : {
    bg: tableColors.buttonDangerBg,
    border: tableColors.buttonDangerBorder,
    text: tableColors.buttonDangerText,
    hoverBg: tableColors.buttonDangerHoverBg,
    hoverBorder: tableColors.buttonDangerHoverBorder,
  }

  const [isHovered, setIsHovered] = React.useState(false)

  return (
    <button
      data-testid={testId}
      onClick={onClick}
      style={{
        width: size,
        height: size,
        padding: '0',
        backgroundColor: isHovered ? colors.hoverBg : colors.bg,
        border: `1px solid ${isHovered ? colors.hoverBorder : colors.border}`,
        borderRadius: '8px',
        color: colors.text,
        cursor: 'pointer',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        transition: `all ${tableTransitions.standard}`,
      }}
      onMouseEnter={() => setIsHovered(true)}
      onMouseLeave={() => setIsHovered(false)}
    >
      {icon}
    </button>
  )
}
```

#### 2.6 **StatusToggleCell** Component
**File**: `admin-frontend/src/components/tables/StatusToggleCell.tsx`

Wraps Toggle for table use:

```typescript
interface StatusToggleCellProps {
  enabled: boolean
  onChange: (enabled: boolean) => void
  testId?: string
}

export function StatusToggleCell({ enabled, onChange, testId }: StatusToggleCellProps) {
  return (
    <td style={{ padding: tableSpacing.cellPadding, textAlign: 'center' }}>
      <Toggle enabled={enabled} onChange={() => onChange(!enabled)} size="small" testId={testId} />
    </td>
  )
}
```

#### 2.7 **IconCell** Component
**File**: `admin-frontend/src/components/tables/IconCell.tsx`

Display icon + text in a cell:

```typescript
interface IconCellProps {
  icon: React.ReactNode
  text: string
  iconSize?: number
  testId?: string
  iconTestId?: string
  textTestId?: string
}

export function IconCell({ icon, text, iconSize = 20, testId, iconTestId, textTestId }: IconCellProps) {
  return (
    <td
      data-testid={testId}
      style={{
        padding: tableSpacing.cellPadding,
        color: tableColors.cellText,
        display: 'flex',
        alignItems: 'center',
        gap: '12px',
      }}
    >
      <div data-testid={iconTestId} style={{ display: 'flex', alignItems: 'center' }}>
        {icon}
      </div>
      <span data-testid={textTestId} style={{ fontWeight: '500' }}>
        {text}
      </span>
    </td>
  )
}
```

#### 2.8 **BadgeCell** Component
**File**: `admin-frontend/src/components/tables/BadgeCell.tsx`

Display badge in a cell:

```typescript
interface BadgeCellProps {
  text: string
  variant?: 'default' | 'success' | 'warning' | 'danger'
  testId?: string
}

export function BadgeCell({ text, variant = 'default', testId }: BadgeCellProps) {
  const variantColors = {
    default: { bg: tableColors.badgeBg, text: tableColors.badgeText },
    // Additional variants can be added
  }
  const colors = variantColors[variant] || variantColors.default

  return (
    <td style={{ padding: tableSpacing.cellPadding, color: tableColors.cellText }}>
      <span
        data-testid={testId}
        style={{
          display: 'inline-block',
          padding: '6px 12px',
          backgroundColor: colors.bg,
          color: colors.text,
          borderRadius: '16px',
          fontSize: '13px',
          fontWeight: '500',
        }}
      >
        {text}
      </span>
    </td>
  )
}
```

### Layer 3: Page-Level Hooks

#### 3.1 **useTableSorting** Hook
**File**: `admin-frontend/src/hooks/useTableSorting.ts`

```typescript
export function useTableSorting(initialSort = { key: 'id', direction: 'asc' as const }) {
  const [sort, setSort] = React.useState(initialSort)

  const handleSort = (key: string, direction: 'asc' | 'desc') => {
    setSort({ key, direction })
  }

  return { sortKey: sort.key, sortDirection: sort.direction, handleSort }
}
```

#### 3.2 **useTablePagination** Hook
**File**: `admin-frontend/src/hooks/useTablePagination.ts`

```typescript
export function useTablePagination(initialPageSize = 25) {
  const [currentPage, setCurrentPage] = React.useState(1)
  const [pageSize, setPageSize] = React.useState(initialPageSize)

  const handlePageChange = (page: number) => {
    setCurrentPage(Math.max(1, page))
  }

  const handlePageSizeChange = (size: number) => {
    setCurrentPage(1) // Reset to first page
    setPageSize(size)
  }

  return { currentPage, pageSize, handlePageChange, handlePageSizeChange }
}
```

---

## Implementation Phases

### Phase 1: Extract Design Tokens (1-2 hours)
- [ ] Create `tableTokens.ts` with all hardcoded values
- [ ] Update ProductsPage to use tokens (verify no style changes)
- [ ] Update CategoriesPage to use tokens

**Commit**: "Extract table design tokens into reusable constants"

### Phase 2: Create Base Components (3-4 hours)
- [ ] TableContainer
- [ ] TableHeader
- [ ] TableRow
- [ ] TableCell (generic)
- [ ] Write component tests (snapshot + behavior)

**Commit**: "Create generic base table components"

### Phase 3: Create Feature Components (2-3 hours)
- [ ] ActionButtons
- [ ] StatusToggleCell
- [ ] IconCell
- [ ] BadgeCell
- [ ] Write component tests

**Commit**: "Create feature-specific table cell components"

### Phase 4: Refactor ProductsPage (2 hours)
- [ ] Replace inline table code with new components
- [ ] Verify no style/behavior changes
- [ ] Run E2E tests (should all pass)
- [ ] Update Page Object if needed

**Commit**: "Refactor ProductsPage to use generic table components"

### Phase 5: Refactor CategoriesPage (1-2 hours)
- [ ] Replace inline table code with new components
- [ ] Run E2E tests

**Commit**: "Refactor CategoriesPage to use generic table components"

### Phase 6: Create Pattern Documentation (1 hour)
- [ ] Document `admin-frontend/patterns/table-design-system.md`
- [ ] Provide template for new tables
- [ ] Include code examples for each component

**Commit**: "Document table design system and pattern for future tables"

---

## Expected Benefits

| Metric | Before | After | Impact |
|--------|--------|-------|--------|
| **Code duplication** | High (table code in each page) | Low (shared components) | -30-40% table code |
| **Time to implement new table** | ~4-5 hours | ~1-2 hours | 3x faster |
| **Style consistency** | Manual (errors possible) | Automatic (tokens enforce) | 100% consistency |
| **Component testability** | Low (mixed with page logic) | High (isolated components) | Easier to test |
| **Maintenance burden** | High (update every page) | Low (update once) | Single source of truth |

---

## Tables to Implement (Next Priority)

After Phase 6 completes, these tables can be implemented quickly:

1. **Transactions Table** (UC-A20 Member Transactions Modal)
   - Columns: Date, Type, Amount, Balance, Memo
   - Actions: None (display only)
   - Sorting: By date
   - Status: No toggle needed

2. **Settlements Table** (UC-A30)
   - Columns: Date, Amount, Status, Created By, Actions
   - Actions: Edit, Delete (maybe Export)
   - Sorting: By date
   - Status: Toggle (draft/confirmed/exported)

3. **Admin Users Table** (UC-A70)
   - Columns: Name, Email, Role, Status, Created, Actions
   - Actions: Edit, Delete, Reset Password
   - Sorting: By name, date
   - Status: Toggle (active/inactive)

4. **Audit Log Table** (UC-A81)
   - Columns: Date, User, Entity, Action, Changes, Details
   - Actions: None (view only)
   - Sorting: By date
   - Status: No toggle

---

## Questions & Decisions

1. **Should we create a high-level `<Table>` component that combines all of these?**
   - Pro: Even simpler API for page developers
   - Con: Less flexible, might restrict unique table needs
   - **Decision needed**: Start with composition, add wrapper later if pattern emerges

2. **How to handle responsive design (mobile tables)?**
   - Current design assumes desktop (horizontal scroll)
   - Need decision: Cards on mobile? Horizontal scroll? Collapsible columns?
   - **Decision needed**: Defer to Phase 5 (advanced features)

3. **Should confirmation dialogs be extracted?**
   - Yes, but separate pattern document
   - Can reference example in ProductsPage for now
   - **Plan**: Create `dialogs.md` pattern after table work complete

---

## Success Criteria

- ✅ All table tokens extracted and reusable
- ✅ All table components created and tested
- ✅ ProductsPage refactored (tests still 16/16 passing)
- ✅ CategoriesPage refactored (tests still passing)
- ✅ Pattern documentation complete
- ✅ Time to implement next table < 2 hours

---

## Next Steps

1. Review this plan and approve scope
2. Begin Phase 1 (extract tokens)
3. Test at each phase to ensure no regressions
4. Document patterns as we go
5. Prepare to implement Transactions table as Phase 1 of using new system
