# Members Page: Add Created Date Column

**Date:** 2026-02-05
**Status:** Approved Design
**Feature:** Display member creation date in sortable table column

---

## Overview

Add a "Created" column to the members list table to display when each member was created. The column will be sortable via the existing table header sorting mechanism.

---

## Design Decisions

### 1. Column Placement
- **Position:** Between "Name" and "Actions" columns
- **Layout:** `Status | SEPA | Name | Created | Actions`
- **Rationale:** Natural reading flow, keeps actions at far right

### 2. Date Display Format
- **Format:** Short date only (DD.MM.YYYY)
- **Example:** `15.12.2024`
- **Rationale:** Space efficient, matches German locale conventions

### 3. Column Header
- **Label:** "Created" (English)
- **Style:** Fixed width (120px), left-aligned
- **Sortable:** Yes, using existing `SortableTableHeader` component

### 4. Sort Behavior
- **Default:** Keep existing default (`created_at desc` - newest first)
- **Interactive:** Clicking header toggles asc/desc
- **Sync:** Header sort state syncs with dropdown

---

## Implementation Details

### Component Changes

**File:** `admin-frontend/src/pages/MembersPage.tsx`

#### Table Header (lines ~396-416)
Add new `<th>` between "Name" and "Actions":
```tsx
<th style={{ ...headerCellBaseStyle, width: '120px' }}>
  <SortableTableHeader
    label="Created"
    sortKey="created_at"
    currentSort={{ key: sortKey, direction: sortDirection }}
    onSort={(key: string, direction: 'asc' | 'desc') => {
      setSortKey(key as 'first_name' | 'last_name' | 'created_at')
      setSortDirection(direction)
      setSortByValue(`${key}-${direction}`)
      setPage(1)
    }}
  />
</th>
```

#### Table Body (lines ~419-491)
Add new `<TableCell>` for each member row:
```tsx
<TableCell testId={`members-table-cell-created-${member.id}`}>
  {formatDate(member.created_at.split('T')[0])}
</TableCell>
```

### Data Flow

1. **API Response:** `created_at` field already present in Member type (ISO 8601 timestamp)
2. **Processing:** Split on 'T' to extract date portion: `"2024-12-15T14:30:00.000Z"` → `"2024-12-15"`
3. **Formatting:** Pass to existing `formatDate()` helper → `"15.12.2024"`
4. **Display:** Render in table cell

### Sorting Integration

- `SortableTableHeader` component handles click events
- Sort state managed by existing `sortKey` and `sortDirection` state
- Backend API already supports `sort=created_at&order=asc|desc`
- No backend changes required

---

## Testing Strategy

### E2E Tests

**File:** `e2etests/tests/admin/members.spec.ts`

**Test Cases:**

1. **Column Visibility**
   - Verify "Created" header is visible
   - Verify column appears between "Name" and "Actions"

2. **Date Format**
   - Create test member
   - Verify date displays in DD.MM.YYYY format
   - Verify format matches regex: `/\d{2}\.\d{2}\.\d{4}/`

3. **Sorting by Header Click** (PRIMARY TEST)
   - Create multiple members with different creation dates
   - Click "Created" header
   - Verify sort direction indicator changes
   - Verify members reorder correctly (newest/oldest)
   - Verify dropdown sync

4. **Sorting by Dropdown**
   - Select "Newest first" from dropdown
   - Verify "Created" header shows descending indicator
   - Select "Oldest first" from dropdown
   - Verify "Created" header shows ascending indicator

### Manual Verification

1. Start dev environment: `docker compose up -d`
2. Navigate to `/members`
3. Verify column appears in correct position
4. Click "Created" header → verify sort toggles
5. Use dropdown → verify header indicator syncs
6. Check date format matches DD.MM.YYYY

---

## Edge Cases Handled

- `created_at` is non-optional in Member type → no null handling needed
- All existing members have `created_at` populated by database
- Date formatting handles ISO 8601 timestamps consistently
- Sort state syncs between header clicks and dropdown selection

---

## No Breaking Changes

- Existing functionality (create, edit, delete, status toggle) unaffected
- Existing E2E tests remain valid
- No API changes required
- No backend changes required

---

## Files Modified

1. `admin-frontend/src/pages/MembersPage.tsx` - Add column to table
2. `e2etests/tests/admin/members.spec.ts` - Add E2E tests for sorting

---

## Implementation Checklist

- [ ] Add "Created" column header with `SortableTableHeader`
- [ ] Add "Created" cell to each table row with formatted date
- [ ] Add test IDs following Pattern 005 (`data-testid` convention)
- [ ] Write E2E test: verify column visibility
- [ ] Write E2E test: verify date format (DD.MM.YYYY)
- [ ] Write E2E test: verify sorting by clicking header
- [ ] Write E2E test: verify dropdown/header sync
- [ ] Manual verification in browser
- [ ] Run full test suite to verify no regressions
- [ ] Commit implementation with clear commit message
