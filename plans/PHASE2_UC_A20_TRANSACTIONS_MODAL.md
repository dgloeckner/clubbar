# Phase 2: UC-A20 Implementation Plan - Member Transactions Modal

**Status**: Ready to implement (Phase 2 - In Progress)
**Date**: 2026-01-27
**Scope**: Member transaction detail modal/panel for Members page

---

## Overview

**Use Case**: UC-A20 - View Tab (member transaction history)
**Trigger**: Admin clicks transaction/balance icon on member row in Members page
**UI Pattern**: Modal or side panel overlay
**API Used**: `GET /members/{memberId}/transactions` (existing, ready)
**Dependencies**: None (all required APIs and infrastructure exist)

---

## Architecture

### Members Page - Extended

```
Members Page (existing)
├─ Member table (existing)
│  ├─ Column: Name
│  ├─ Column: Balance
│  ├─ Column: IBAN
│  ├─ Column: Mitglied seit (Created Date)
│  ├─ Column: Actions (Edit, Delete - existing)
│  │
│  └─ NEW: Transaction Icon/Button per row ← NEW COLUMN
│     └─ Click → Opens TransactionModal
│
└─ TransactionModal (NEW)
   ├─ Header: "Member Name - Transaction History"
   ├─ Subheader: "Current Balance: €123.45"
   │
   ├─ Filter Section:
   │  └─ Filter buttons: [All] [Purchases] [Corrections]
   │
   ├─ Transaction Table:
   │  ├─ Column: Date (DD.MM.YYYY HH:MM)
   │  ├─ Column: Type (PURCH / KORR badge)
   │  ├─ Column: Description
   │  ├─ Column: Amount (color-coded: ±€X,XX)
   │  └─ Column: Running Total (balance after transaction)
   │
   ├─ Empty State:
   │  └─ "No transactions found for this member"
   │
   └─ Close Button
```

---

## Implementation Tasks

### Task 1: Create TransactionModal Component

**File**: `admin-frontend/src/components/modals/TransactionModal.tsx`

**Requirements**:
- React functional component with TypeScript
- Props:
  - `isOpen: boolean` - Modal visibility state
  - `memberId: string` - Member UUID to load transactions for
  - `memberName: string` - Display in header
  - `currentBalance: number` - Display in subheader (cents)
  - `onClose: () => void` - Close handler
- Features:
  - Filter buttons (All, Purchase, Correction)
  - Transaction table with 5 columns
  - Color-coded amounts (green for negative, orange for positive)
  - Empty state message
  - Loading state
  - Error handling
  - Responsive design (mobile horizontal scroll)

**Design System**:
- Use existing theme colors, spacing, typography
- Inline styles (consistent with MembersPage)
- Modal backdrop: semi-transparent overlay
- Z-index: ensure above all content

**Test IDs** (for E2E tests):
```
data-testid="transaction-modal"
data-testid="transaction-modal-header"
data-testid="transaction-filter-all"
data-testid="transaction-filter-purchase"
data-testid="transaction-filter-correction"
data-testid="transaction-table"
data-testid="transaction-row"
data-testid="transaction-date"
data-testid="transaction-type"
data-testid="transaction-description"
data-testid="transaction-amount"
data-testid="transaction-running-total"
data-testid="empty-transactions"
data-testid="modal-close-button"
```

---

### Task 2: Create Transactions Service

**File**: `admin-frontend/src/services/transactions.ts`

**Requirements**:
- Export TypeScript interfaces:
  - `Transaction` (id, date, type, description, amount_cents, running_total_cents, settlement_id)
  - `MemberTransactionHistory` (member_id, current_balance_cents, transactions[], settlements[])
  - `Settlement` (id, date, amount_cents, type)

- Export function:
  ```typescript
  export async function getMemberTransactionHistory(
    memberId: string,
    filters?: {
      date_from?: string
      date_to?: string
      type?: 'all' | 'purchase' | 'correction'
      include_settled?: boolean
    }
  ): Promise<MemberTransactionHistory>
  ```

- Handle API response patterns:
  - Backend returns unwrapped data directly
  - Fallback to `.data` property if wrapped
  - Throw error if invalid response

---

### Task 3: Update MembersPage Component

**File**: `admin-frontend/src/pages/MembersPage.tsx`

**Changes**:
1. **Import TransactionModal**:
   ```typescript
   import { TransactionModal } from '../components/modals/TransactionModal'
   ```

2. **Add state for modal**:
   ```typescript
   const [selectedMemberForTransactions, setSelectedMemberForTransactions] = useState<Member | null>(null)
   ```

3. **Add transaction icon to table** (new column):
   - After Actions column
   - Button with transaction/balance icon (📊 or similar)
   - Click handler: `onClick={() => setSelectedMemberForTransactions(member)}`
   - Test ID: `data-testid="view-transactions-button-${member.id}"`

4. **Render TransactionModal at bottom**:
   ```typescript
   {selectedMemberForTransactions && (
     <TransactionModal
       isOpen={!!selectedMemberForTransactions}
       memberId={selectedMemberForTransactions.id}
       memberName={`${selectedMemberForTransactions.first_name} ${selectedMemberForTransactions.last_name}`}
       currentBalance={selectedMemberForTransactions.balance_cents}
       onClose={() => setSelectedMemberForTransactions(null)}
     />
   )}
   ```

---

### Task 4: Update MembersPage Page Object

**File**: `e2etests/pages/MembersPage.ts`

**Add methods**:
```typescript
/**
 * Open transactions modal for a specific member
 */
async viewTransactionsForMember(index: number = 0): Promise<void> {
  const viewButton = this.page.locator('[data-testid="view-transactions-button"]').nth(index)
  await viewButton.click()
}

/**
 * Verify transaction modal is open
 */
async expectTransactionModalVisible(): Promise<void> {
  await this.page.locator('[data-testid="transaction-modal"]').waitFor({ timeout: 5000 })
}

/**
 * Close transaction modal
 */
async closeTransactionModal(): Promise<void> {
  const closeButton = this.page.locator('[data-testid="modal-close-button"]')
  await closeButton.click()
}

/**
 * Get transaction rows from modal
 */
getTransactionRows(): Locator {
  return this.page.locator('[data-testid="transaction-modal"] [data-testid="transaction-row"]')
}

/**
 * Count transaction rows
 */
async getTransactionRowCount(): Promise<number> {
  return await this.getTransactionRows().count()
}

/**
 * Filter transactions by type
 */
async filterTransactionsByType(type: 'all' | 'purchase' | 'correction'): Promise<void> {
  const filterButton = this.page.locator(`[data-testid="transaction-filter-${type}"]`)
  await filterButton.click()
}
```

---

### Task 5: Create E2E Tests

**File**: `e2etests/tests/admin/members-uc-a20-transactions.spec.ts`

**Test Structure**:
```typescript
test.describe('UC-A20: Member Transactions Modal', () => {
  test.describe('Modal Display', () => {
    test('should open transaction modal when clicking view button')
    test('should display member name in modal header')
    test('should display current balance in modal subheader')
  })

  test.describe('Transaction List', () => {
    test('should display transaction table with all columns')
    test('should display transactions for selected member')
    test('should show empty state when no transactions')
  })

  test.describe('Filtering', () => {
    test('should filter by type: all')
    test('should filter by type: purchase')
    test('should filter by type: correction')
  })

  test.describe('Formatting', () => {
    test('should format dates as DD.MM.YYYY HH:MM')
    test('should format amounts as currency (€)')
    test('should color-code transaction amounts')
    test('should show transaction type badges')
  })

  test.describe('Interactions', () => {
    test('should close modal when close button clicked')
    test('should maintain filter when reopening modal')
    test('should handle error state gracefully')
  })
})
```

**Test Coverage** (~10-12 tests):
- Modal opens/closes correctly
- Modal displays correct member data
- Transaction list displays with proper columns
- Filtering works (all/purchase/correction)
- Empty state displays when no transactions
- Date/amount formatting correct
- Error handling
- Loading state
- Responsive layout

---

## Implementation Order

1. **Step 1**: Create `TransactionModal.tsx` component
   - Full UI implementation
   - All data-testid attributes
   - Styling using theme system
   - ~200-300 lines

2. **Step 2**: Create `transactions.ts` service
   - API integration
   - Type definitions
   - Response handling
   - ~50-70 lines

3. **Step 3**: Update `MembersPage.tsx`
   - Add modal state
   - Add transaction icon column
   - Render modal
   - ~20-30 lines

4. **Step 4**: Update `MembersPage.ts` page object
   - Add transaction-related methods
   - ~10-15 methods

5. **Step 5**: Create E2E tests
   - Full test coverage (10-12 tests)
   - ~300-400 lines

---

## Success Criteria

✅ **Modal Display**:
- Opens when clicking transaction icon
- Closes on close button or backdrop click
- Shows correct member name and balance

✅ **Transaction Data**:
- Displays all columns correctly
- Formats dates (DD.MM.YYYY HH:MM)
- Formats amounts as currency (€)
- Color-codes amounts (green/orange)
- Shows type badges (PURCH/KORR)

✅ **Filtering**:
- All filter: shows all transactions
- Purchase filter: shows only purchases
- Correction filter: shows only corrections
- Filter state updates UI immediately

✅ **States**:
- Loading state shows while fetching
- Empty state displays when no transactions
- Error state displays if API fails

✅ **E2E Tests**:
- All 10-12 tests pass in serial (1 worker)
- All tests pass in parallel (4 workers)
- No flaky tests detected
- Tests run in < 30 seconds total

✅ **Responsive Design**:
- Desktop: Full table visible
- Tablet: Horizontal scroll available
- Mobile: Touch-friendly interactions

---

## Technical Considerations

### State Management
- Use React `useState` for modal visibility and filters
- No additional state library needed
- Parent (MembersPage) manages modal open/close
- Child (TransactionModal) manages filters

### Loading State
- Show "Loading transactions..." text while fetching
- Disable filters during load
- Handle API errors gracefully

### Performance
- Transactions are paginated client-side (no pagination UI)
- Single API call per modal open
- Filter is instant (no API call needed)

### Accessibility
- Modal has focus management
- Escape key closes modal
- Clear heading hierarchy
- Semantic HTML (table structure)

---

## Integration Points

**Within MembersPage**:
- ✅ Uses existing `Member` type
- ✅ Uses existing theme system
- ✅ Uses existing `useLoading` context
- ✅ Uses existing `useBreakpoint` hook

**E2E Testing**:
- ✅ Uses existing fixtures (`authenticatedMembersPage`)
- ✅ Uses existing Page Object pattern
- ✅ Follows existing test patterns (005/006)

**API**:
- ✅ Uses existing `GET /members/{id}/transactions` endpoint
- ✅ No backend changes required
- ✅ Follows existing API response handling patterns

---

## Deferred Items

❌ **Not in this task**:
- Settlement history display (included in response but not displayed)
- Manual transaction correction workflow
- Export transactions to CSV
- Date range filtering UI

These can be added in future enhancements or Phase 3.

---

## Rollback Plan

If issues arise:
1. Remove TransactionModal import from MembersPage
2. Remove transaction icon column from members table
3. Keep TransactionModal component for future use
4. Services/tests remain for reference

---

## Related Documents

- **Spec**: `use-cases/admin/UC-A20-view-tab.md`
- **API Mapping**: `plans/PHASE2_API_MAPPING.md` (Page 1: Members)
- **Test Patterns**: `e2etests/patterns/` (005, 006, 003)
- **Design System**: `admin-frontend/src/styles/design-system.ts`

---

## Timeline

**Estimated**: 1-2 development sessions
- Component creation: ~30-45 minutes
- Service + Page Object: ~15-20 minutes
- E2E tests: ~45-60 minutes
- Testing & debugging: ~30 minutes

**Total**: 2.5-3 hours

---

## Next Steps After Completion

1. **Verify**: Run all members tests (including UC-A20)
   ```bash
   npm test -- tests/admin/members.spec.ts --workers=4
   ```

2. **Visual Check**: Inspect modal in browser at http://localhost:5173/members

3. **Code Review**: Verify:
   - ✅ TypeScript compiles
   - ✅ No console errors
   - ✅ Responsive on mobile
   - ✅ Matches design system

4. **Commit**:
   ```
   [Phase 2 Milestone 2.1] Implement UC-A20: Member Transactions Modal
   ```

5. **Continue**: Move to next Phase 2 page (Products or Settlements)
